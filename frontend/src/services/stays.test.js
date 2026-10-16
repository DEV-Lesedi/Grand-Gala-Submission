import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { checkStay, findSpace, loadBlockedDates, loadGuestSpace, loadGuestSpaces, unavailableReasons } from './stays'
import { TODAY, addDays } from '../lib/dates'
import { formatRand } from '../lib/money'
import { cardPrice, featuredSpaces } from '../lib/stays'
import { clearCache } from './api'

// Tests fake fetch and check what checkStay answers.

const VILLA = {
  id: 1,
  name: 'The Villa',
  capacity: 4,
  amenities: [
    { id: 3, name: 'Breakfast', price: 250 },
    { id: 5, name: 'Late check-out', price: 300 },
  ],
}
const CHECK_IN = addDays(TODAY, 10)
const CHECK_OUT = addDays(TODAY, 12)

// GET /availability uses these day states. POST /stays/price uses this status and body.
function fakeApi({ states = ['free', 'free'], price = { status: 200, body: priceBody() } } = {}) {
  const fetchMock = vi.fn(async (url) => {
    if (url.includes('/availability')) {
      const days = states.map((state, index) => ({ date: addDays(CHECK_IN, index), state }))
      return new Response(JSON.stringify(days), { status: 200 })
    }
    return new Response(JSON.stringify(price.body), { status: price.status })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

function priceBody(extra = {}) {
  return {
    nights: 2,
    lines: [
      { kind: 'space', description: 'Night', quantity: 1, unitPrice: 2800, total: 2800 },
      { kind: 'space', description: 'Night', quantity: 1, unitPrice: 2800, total: 2800 },
    ],
    subtotal: 5600,
    discount: 0,
    total: 5600,
    ...extra,
  }
}

// The JSON body checkStay posted to /stays/price
function postedPrice(fetchMock) {
  const call = fetchMock.mock.calls.find(([url]) => url.endsWith('/stays/price'))
  return JSON.parse(call[1].body)
}

describe('checkStay', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('says yes with the price when every night is free', async () => {
    fakeApi()

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2 })

    expect(check.ok).toBe(true)
    expect(check.reason).toBe(null)
    expect(check.quote.total).toBe(5600)
    expect(check.quote.lines).toEqual([{ label: `${formatRand(2800)} × 2 nights`, amount: 5600 }])
  })

  it('asks the calendar for the nights only (not the check-out day)', async () => {
    const fetchMock = fakeApi()

    await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2 })

    const calendarUrl = fetchMock.mock.calls[0][0]
    expect(calendarUrl).toBe(`http://localhost:8000/api/availability?spaceId=1&from=${CHECK_IN}&to=${addDays(CHECK_OUT, -1)}`)
  })

  it('says no when a night is already booked, without asking for a price', async () => {
    const fetchMock = fakeApi({ states: ['free', 'booked'] })

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2 })

    expect(check).toEqual({ ok: false, reason: 'Already booked on these dates', quote: null })
    expect(fetchMock.mock.calls.some(([url]) => url.endsWith('/stays/price'))).toBe(false)
  })

  it('says no when a night is blacked out', async () => {
    fakeApi({ states: ['blacked_out', 'free'] })

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2 })

    expect(check.reason).toBe('Not available on these dates')
  })

  it('says no to more guests than the space sleeps, without asking the API', async () => {
    const fetchMock = fakeApi()

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 5 })

    expect(check).toEqual({ ok: false, reason: 'The Villa sleeps 4 at most', quote: null })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('asks how many guests when the number makes no sense, and counts no number yet as 1', async () => {
    const fetchMock = fakeApi()

    expect((await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 0 })).reason).toBe('How many guests?')
    expect((await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 1.5 })).reason).toBe('How many guests?')

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: null })
    expect(check.ok).toBe(true)
    expect(postedPrice(fetchMock).guests).toBe(1)
  })

  it.each([
    ['no dates yet', '', ''],
    ['a date that is not YYYY-MM-DD', '12/12/2026', CHECK_OUT],
    ['a check-in in the past', addDays(TODAY, -1), CHECK_OUT],
    ['check-out on the check-in day', CHECK_IN, CHECK_IN],
    ['check-out before check-in', CHECK_OUT, CHECK_IN],
  ])('asks for dates when given %s, without asking the API', async (_, checkIn, checkOut) => {
    const fetchMock = fakeApi()

    const check = await checkStay({ space: VILLA, checkIn, checkOut, guests: 2 })

    expect(check).toEqual({ ok: false, reason: 'Choose your dates', quote: null })
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('sends the ticked extras the space offers as amenities, and ignores unknown ids', async () => {
    const fetchMock = fakeApi()

    await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2, extras: [5, 99] })

    expect(postedPrice(fetchMock)).toEqual({
      spaceId: 1,
      checkIn: CHECK_IN,
      checkOut: CHECK_OUT,
      guests: 2,
      amenities: [{ amenityId: 5, quantity: 1 }],
    })
  })

  it('shows each extra as its own price line', async () => {
    const lines = [...priceBody().lines, { kind: 'amenity', description: 'Breakfast', quantity: 2, unitPrice: 250, total: 500 }]
    fakeApi({ price: { status: 200, body: priceBody({ lines, total: 6100 }) } })

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2, extras: [3] })

    expect(check.quote.lines).toEqual([
      { label: `${formatRand(2800)} × 2 nights`, amount: 5600 },
      { label: 'Breakfast × 2', amount: 500 },
    ])
  })

  it('sends a promo code and gives back the discounted price', async () => {
    const fetchMock = fakeApi({ price: { status: 200, body: priceBody({ discount: 560, total: 5040 }) } })

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2, promoCode: 'SPRING10' })

    expect(postedPrice(fetchMock).promoCode).toBe('SPRING10')
    expect(check.quote.discount).toBe(560)
    expect(check.quote.total).toBe(5040)
  })

  it("gives the API's words as the reason when it won't price the stay (e.g. a bad promo code)", async () => {
    fakeApi({ price: { status: 422, body: { error: 'This code has expired' } } })

    const check = await checkStay({ space: VILLA, checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2, promoCode: 'OLD' })

    expect(check).toEqual({ ok: false, reason: 'This code has expired', quote: null })
  })
})

// GET /spaces: a villa, one room, a venue, and a switched-on space that is neither.
const API_SPACES = [
  {
    id: 1,
    name: 'The Villa',
    type: 'villa',
    capacity: 10,
    isAccommodation: true,
    isHireable: false,
    parentId: null,
    description: 'The whole villa',
    photo: '/photos/villa.jpg',
    currentRates: [{ basis: 'per_night', label: 'Standard', price: 2800 }],
  },
  {
    id: 7,
    name: 'Garden Room',
    type: 'room',
    capacity: 2,
    isAccommodation: true,
    isHireable: false,
    parentId: 1,
    description: null,
    photo: null,
    currentRates: [{ basis: 'per_night', label: 'Standard', price: 950 }],
  },
  {
    id: 3,
    name: 'Open Garden',
    type: 'venue',
    capacity: 150,
    isAccommodation: false,
    isHireable: true,
    parentId: null,
    description: null,
    photo: '/photos/garden.jpg',
    currentRates: [{ basis: 'per_event', label: 'Hire', price: 8000 }],
  },
  {
    id: 9,
    name: 'Store Room',
    type: 'venue',
    capacity: 0,
    isAccommodation: false,
    isHireable: false,
    parentId: null,
    description: null,
    photo: null,
    currentRates: [],
  },
]

// Answers GET /spaces with these spaces and GET /spaces/:id with that space and its amenities
function fakeSpacesApi(apiSpaces = API_SPACES) {
  const fetchMock = vi.fn(async (url) => {
    if (url.endsWith('/spaces')) return new Response(JSON.stringify(apiSpaces), { status: 200 })
    const id = Number(url.split('/').pop())
    const space = apiSpaces.find((item) => item.id === id)
    return new Response(JSON.stringify({ ...space, amenities: [{ id: 3, name: 'Breakfast', price: 250 }] }), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('loadGuestSpaces', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it("keeps the API's fields and its one id, and leaves out spaces a guest can't stay in or hire", async () => {
    fakeSpacesApi()

    const spaces = await loadGuestSpaces()

    expect(spaces.map((space) => space.id)).toEqual([1, 7, 3])
    expect(spaces[1]).toEqual({
      id: 7,
      name: 'Garden Room',
      type: 'room',
      capacity: 2,
      isAccommodation: true,
      isHireable: false,
      parentId: 1,
      description: null,
      photo: '/photos/villa.jpg', // no photo yet: the default
      currentRates: [{ basis: 'per_night', label: 'Standard', price: 950 }],
    })
  })

  it('finds one space by the id in the address, with its amenities', async () => {
    const fetchMock = fakeSpacesApi()

    const space = await loadGuestSpace('3')

    expect(space.name).toBe('Open Garden')
    expect(space.amenities).toEqual([{ id: 3, name: 'Breakfast', price: 250 }])
    expect(fetchMock.mock.calls.map(([url]) => url)).toContain('http://localhost:8000/api/spaces/3')
  })

  it('gives null for an id that is not a space (e.g. an old /spaces/villa link)', async () => {
    fakeSpacesApi()

    expect(await loadGuestSpace('villa')).toBe(null)
    expect(await loadGuestSpace('9')).toBe(null) // switched on, but not for guests
  })
})

describe('findSpace', () => {
  it('matches the id from the address (text) with the API id (a number)', () => {
    const spaces = [{ id: 1 }, { id: 12 }]

    expect(findSpace(spaces, '12')).toEqual({ id: 12 })
    expect(findSpace(spaces, 1)).toEqual({ id: 1 })
    expect(findSpace(spaces, 'villa')).toBe(undefined)
  })
})

describe('the guest cards, from the mapped spaces', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it("prices each card from the API's currentRates", async () => {
    fakeSpacesApi()
    const [villa, , garden] = await loadGuestSpaces()

    expect(cardPrice(villa)).toEqual({ amount: 2800, text: `${formatRand(2800)} night` })
    expect(cardPrice(garden)).toEqual({ amount: 8000, text: `From ${formatRand(8000)}` })
  })

  it('features the villa, a room and the venues by kind, so a renamed space still shows', async () => {
    fakeSpacesApi(API_SPACES.map((space) => (space.id === 1 ? { ...space, name: 'The Big House' } : space)))

    const featured = featuredSpaces(await loadGuestSpaces())

    expect(featured.map((space) => space.id)).toEqual([1, 7, 3])
    expect(featured[0].name).toBe('The Big House')
  })
})

describe('availability for several spaces', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  // { [spaceId]: day states } from CHECK_IN on, answered as the API does for ?spaceIds=
  function fakeCalendars(statesById) {
    const answer = {}
    for (const [id, states] of Object.entries(statesById)) {
      answer[id] = states.map((state, index) => ({ date: addDays(CHECK_IN, index), state }))
    }
    const fetchMock = vi.fn(async () => new Response(JSON.stringify(answer), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
    return fetchMock
  }

  it('gives each space its reason from one request, not one request each', async () => {
    const fetchMock = fakeCalendars({ 1: ['free', 'free'], 2: ['free', 'booked'], 3: ['blacked_out', 'free'] })

    const reasons = await unavailableReasons([{ id: 1 }, { id: 2 }, { id: 3 }], CHECK_IN, CHECK_OUT)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toContain('spaceIds=1,2,3')
    expect(reasons).toEqual({ 1: null, 2: 'Already booked on these dates', 3: 'Not available on these dates' })
  })

  it('collects the blocked days of several spaces from one request', async () => {
    const fetchMock = fakeCalendars({ 1: ['free', 'booked'], 2: ['held', 'free'] })

    const blocked = await loadBlockedDates([1, 2], CHECK_IN, CHECK_OUT)

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect([...blocked].sort()).toEqual([CHECK_IN, addDays(CHECK_IN, 1)])
  })

  it('asks nothing for an empty list', async () => {
    const fetchMock = fakeCalendars({})

    expect(await unavailableReasons([], CHECK_IN, CHECK_OUT)).toEqual({})
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
