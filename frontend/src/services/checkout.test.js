// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { orderFrom, pay, priceWithCode } from './checkout'
import { TODAY, addDays } from '../lib/dates'
import { clearCache } from './api'

// Tests fake the API and check what Checkout answers.
// Keys are "METHOD path". Unknown paths are 404. reply(status, body) sets another status.

const EVENT_DATE = addDays(TODAY, 20)
const CHECK_IN = addDays(TODAY, 10)
const CHECK_OUT = addDays(TODAY, 12)

const EVENT = {
  id: 12,
  title: 'Jazz in the Garden',
  venue: 'The Garden',
  date: EVENT_DATE,
  startTime: '17:00',
  endTime: '22:00',
  image: '/photos/jazz.jpg',
  saleStatus: 'on_sale',
  status: 'published',
  ticketTiers: [
    { id: 31, name: 'General', price: 250, allocation: 100, sold: 10, available: 90, saleStatus: 'on_sale', active: true },
    { id: 32, name: 'VIP', price: 600, allocation: 10, sold: 9, available: 1, saleStatus: 'on_sale', active: true },
  ],
}

const QUOTATION = {
  reference: 'GGG-Q-0034',
  bookingReference: 'GGG-2026-0448',
  status: 'accepted',
  paidDate: null,
  version: 2,
  eventDate: EVENT_DATE,
  startTime: '12:00',
  endTime: '18:00',
  spaces: [{ name: 'The Garden' }],
  items: [{ label: 'Garden hire', amount: 8000 }],
  discount: 500,
  total: 7500,
}

const VILLA = { id: 1, name: 'The Villa', type: 'villa', capacity: 4, isAccommodation: true, isHireable: false, photo: '/photos/villa.jpg' }

// An answer that isn't a plain 200, e.g. reply(401, { error: 'Sign in first' })
class Reply {
  constructor(status, body) {
    this.status = status
    this.body = body
  }
}
const reply = (status, body) => new Reply(status, body)

function fakeApi(routes) {
  const fetchMock = vi.fn(async (url, options) => {
    const path = url.replace('http://localhost:8000/api', '').split('?')[0]
    const answer = routes[`${options.method} ${path}`]
    if (!answer) return new Response(JSON.stringify({ error: 'Not found' }), { status: 404 })
    const { status, body } = answer instanceof Reply ? answer : { status: 200, body: answer }
    return new Response(JSON.stringify(body), { status })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

// The JSON body of the first call to this "METHOD path"
function posted(fetchMock, path) {
  const call = fetchMock.mock.calls.find(([url, options]) => url.endsWith(path) && options.method === 'POST')
  return call && JSON.parse(call[1].body)
}

const params = (query) => new URLSearchParams(query)

describe('orderFrom', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('builds a ticket order from the event and the ticket counts', async () => {
    fakeApi({ 'GET /events/12': EVENT })

    const { order } = await orderFrom(params('event=12&31=2&32=1'))

    expect(order.kind).toBe('tickets')
    expect(order.title).toBe('Jazz in the Garden')
    expect(order.photo).toBe('/photos/jazz.jpg')
    expect(order.lines.map((line) => line.label)).toEqual(['General × 2', 'VIP × 1'])
    expect(order.total).toBe(1100)
    expect(order.needsContact).toBe(true)
    expect(order.canUsePromo).toBe(true)
    expect(order.facts.at(-1)).toEqual({ label: 'Tickets', value: '3 tickets' })
    expect(order.payment).toEqual({ type: 'ticket_order', create: { eventId: 12, items: [{ tierId: 31, quantity: 2 }, { tierId: 32, quantity: 1 }] } })
  })

  it('says how many are left when more tickets are asked for', async () => {
    fakeApi({ 'GET /events/12': EVENT })

    const answer = await orderFrom(params('event=12&32=3'))

    expect(answer).toEqual({ problem: 'Only 1 VIP tickets are left.', backTo: '/events', backLabel: 'Browse events' })
  })

  it('asks for tickets when none are chosen, and says an unknown event isn’t on', async () => {
    fakeApi({ 'GET /events/12': EVENT })

    expect((await orderFrom(params('event=12'))).problem).toBe('Choose at least one ticket.')
    expect((await orderFrom(params('event=99&31=1'))).problem).toBe('This event isn’t on.')
    expect((await orderFrom(params(''))).problem).toBe('Choose your tickets first.')
  })

  it('builds an order for an accepted quotation, paid by the quotation', async () => {
    fakeApi({ 'GET /me/quotations/GGG-Q-0034': QUOTATION })

    const { order } = await orderFrom(params('quotation=GGG-Q-0034'))

    expect(order.kind).toBe('quotation')
    expect(order.lines).toEqual([
      { label: 'Garden hire', amount: 8000 },
      { label: 'Discount', amount: -500 },
    ])
    expect(order.total).toBe(7500)
    expect(order.reference).toBe('GGG-2026-0448')
    expect(order.canUsePromo).toBe(false)
    expect(order.facts.at(-1)).toEqual({ label: 'Spaces', value: 'The Garden' })
    expect(order.payment).toEqual({ type: 'quotation', reference: 'GGG-Q-0034' })
  })

  it('says a paid quotation is already paid', async () => {
    fakeApi({ 'GET /me/quotations/GGG-Q-0034': { ...QUOTATION, paidDate: '2026-10-01' } })

    const answer = await orderFrom(params('quotation=GGG-Q-0034'))

    expect(answer.problem).toBe('This quotation is already paid.')
  })

  it('asks to accept a quotation that is only sent', async () => {
    fakeApi({ 'GET /me/quotations/GGG-Q-0034': { ...QUOTATION, status: 'sent' } })

    const answer = await orderFrom(params('quotation=GGG-Q-0034'))

    expect(answer).toEqual({ problem: 'Accept the quotation first, then pay.', backTo: '/quotations/GGG-Q-0034', backLabel: 'Back to the quotation' })
  })

  it('says not found for a quotation or booking the API doesn’t have (or isn’t theirs)', async () => {
    fakeApi({})

    expect(await orderFrom(params('quotation=GGG-Q-9999'))).toEqual({
      problem: 'Quotation not found.',
      backTo: '/my-bookings?tab=quotations',
      backLabel: 'My bookings',
    })
    expect(await orderFrom(params('booking=GGG-2026-9999'))).toEqual({ problem: 'Booking not found.', backTo: '/my-bookings', backLabel: 'My bookings' })
  })

  it('answers needsSignIn when a signed-out guest opens a quotation or booking', async () => {
    fakeApi({
      'GET /me/quotations/GGG-Q-0034': reply(401, { error: 'Sign in first' }),
      'GET /me/bookings/GGG-2026-0448': reply(401, { error: 'Sign in first' }),
    })

    expect(await orderFrom(params('quotation=GGG-Q-0034'))).toEqual({ needsSignIn: true })
    expect(await orderFrom(params('booking=GGG-2026-0448'))).toEqual({ needsSignIn: true })
  })

  it('builds a stay order priced by the API', async () => {
    fakeApi({
      'GET /spaces': [VILLA],
      'GET /spaces/1': { ...VILLA, amenities: [] },
      'GET /availability': [
        { date: CHECK_IN, state: 'free' },
        { date: addDays(CHECK_IN, 1), state: 'free' },
      ],
      'POST /stays/price': {
        lines: [{ kind: 'space', description: 'Night', quantity: 2, unitPrice: 2800, total: 5600 }],
        discount: 0,
        total: 5600,
      },
    })

    const { order } = await orderFrom(params(`stay=1&checkin=${CHECK_IN}&checkout=${CHECK_OUT}&guests=2`))

    expect(order.kind).toBe('stay')
    expect(order.title).toBe('The Villa')
    expect(order.total).toBe(5600)
    expect(order.needsAccount).toBe(true)
    expect(order.canUsePromo).toBe(true)
    expect(order.payment.type).toBe('stay')
    expect(order.payment.create).toMatchObject({ checkIn: CHECK_IN, checkOut: CHECK_OUT, guests: 2 })
  })

  it('turns a stay that can’t be booked into a sentence', async () => {
    fakeApi({
      'GET /spaces': [VILLA],
      'GET /spaces/1': { ...VILLA, amenities: [] },
      'GET /availability': [
        { date: CHECK_IN, state: 'booked' },
        { date: addDays(CHECK_IN, 1), state: 'free' },
      ],
    })

    const answer = await orderFrom(params(`stay=1&checkin=${CHECK_IN}&checkout=${CHECK_OUT}&guests=2`))

    expect(answer).toEqual({ problem: 'Already booked on these dates.', backTo: '/spaces/1', backLabel: 'Choose other dates' })
  })

  it('builds an order for a stay booking waiting for payment, with the photo the API sends', async () => {
    fakeApi({
      'GET /me/bookings/GGG-2026-0448': {
        reference: 'GGG-2026-0448',
        type: 'stay',
        status: 'pending_payment',
        title: 'The Villa',
        startDate: CHECK_IN,
        endDate: CHECK_OUT,
        guests: 2,
        price: 5600,
        image: '/uploads/villa-pool.jpg',
      },
    })

    const { order } = await orderFrom(params('booking=GGG-2026-0448'))

    expect(order.kind).toBe('booking')
    expect(order.photo).toBe('/uploads/villa-pool.jpg')
    expect(order.lines).toEqual([{ label: 'Booking GGG-2026-0448', amount: 5600 }])
    expect(order.reference).toBe('GGG-2026-0448')
    expect(order.payment).toEqual({ type: 'stay', reference: 'GGG-2026-0448' })
  })

  it('pays a private event booking through its accepted quotation', async () => {
    const booking = { reference: 'GGG-2026-0448', type: 'event', status: 'pending_payment', title: 'Wedding', startDate: EVENT_DATE, guests: 80, price: 7500, image: null }
    fakeApi({ 'GET /me/bookings/GGG-2026-0448': booking, 'GET /me/quotations': { quotations: [QUOTATION] } })

    const { order } = await orderFrom(params('booking=GGG-2026-0448'))

    expect(order.payment).toEqual({ type: 'quotation', reference: 'GGG-Q-0034' })
    expect(order.photo).toBe('/photos/garden.jpg') // no photo from the API
  })

  it('says so when a private event booking has no accepted quotation', async () => {
    const booking = { reference: 'GGG-2026-0448', type: 'event', status: 'pending_payment', title: 'Wedding', startDate: EVENT_DATE, price: 7500 }
    fakeApi({ 'GET /me/bookings/GGG-2026-0448': booking, 'GET /me/quotations': { quotations: [] } })

    const answer = await orderFrom(params('booking=GGG-2026-0448'))

    expect(answer.problem).toBe('We couldn’t find the accepted quotation for this booking.')
  })

  it('says a booking that isn’t waiting for payment can’t be paid', async () => {
    fakeApi({ 'GET /me/bookings/GGG-2026-0448': { reference: 'GGG-2026-0448', type: 'stay', status: 'confirmed' } })

    expect((await orderFrom(params('booking=GGG-2026-0448'))).problem).toBe('This booking isn’t waiting for payment.')
  })
})

describe('priceWithCode', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  const ticketsOrder = { total: 500, payment: { type: 'ticket_order', create: { eventId: 12, items: [] } } }

  it('checks a ticket code with the API', async () => {
    const fetchMock = fakeApi({ 'POST /promotions/validate': { discount: 50 } })

    expect(await priceWithCode(ticketsOrder, 'SPRING10')).toEqual({ code: 'SPRING10', discount: 50, total: 450 })
    expect(posted(fetchMock, '/promotions/validate')).toEqual({ code: 'SPRING10', kind: 'tickets', amount: 500 })
  })

  it('says the order is too small when the code takes nothing off', async () => {
    fakeApi({ 'POST /promotions/validate': { discount: 0 } })

    await expect(priceWithCode(ticketsOrder, 'BIG100')).rejects.toThrow('This code needs a bigger order.')
  })
})

describe('pay', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
    localStorage.clear()
  })

  afterEach(() => {
    clearCache() // each test fakes its own spaces
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  const contact = { fullName: 'Thandi Nkosi', email: 'thandi@example.com', phone: '082 000 0000', requests: '' }
  const payApi = {
    'POST /ticket-orders': reply(201, { reference: 'GGG-TKT-2026-0001', accessToken: 'secret-token' }),
    'POST /payments/checkout': { url: 'https://sandbox.payfast.co.za/eng/process', fields: { m_payment_id: 'PAY-1' } },
    'POST /dev/payfast/complete': { ok: true },
  }

  it('makes the ticket order, starts the payment with its token and confirms it (demo)', async () => {
    const fetchMock = fakeApi(payApi)
    const order = { kind: 'tickets', reference: null, payment: { type: 'ticket_order', create: { eventId: 12, items: [{ tierId: 31, quantity: 2 }] } } }

    const result = await pay(order, { contact, promo: { code: 'SPRING10' } })

    expect(result).toEqual({ reference: 'GGG-TKT-2026-0001', paid: true })
    expect(posted(fetchMock, '/ticket-orders')).toMatchObject({ eventId: 12, name: 'Thandi', surname: 'Nkosi', promoCode: 'SPRING10' })
    expect(posted(fetchMock, '/payments/checkout')).toEqual({ type: 'ticket_order', reference: 'GGG-TKT-2026-0001' })
    const checkoutCall = fetchMock.mock.calls.find(([url]) => url.endsWith('/payments/checkout'))
    expect(checkoutCall[1].headers['X-Ticket-Access']).toBe('secret-token')
    expect(posted(fetchMock, '/dev/payfast/complete')).toEqual({ merchantReference: 'PAY-1' })
  })

  it('doesn’t make a second ticket order when Pay is pressed again', async () => {
    const fetchMock = fakeApi(payApi)
    const order = { kind: 'tickets', reference: null, payment: { type: 'ticket_order', create: { eventId: 12, items: [] } } }

    await pay(order, { contact, reference: 'GGG-TKT-2026-0001' })

    expect(posted(fetchMock, '/ticket-orders')).toBeUndefined()
  })

  it('pays a quotation by its reference and answers with the booking it confirms', async () => {
    const fetchMock = fakeApi(payApi)
    const order = { kind: 'quotation', reference: 'GGG-2026-0448', payment: { type: 'quotation', reference: 'GGG-Q-0034' } }

    const result = await pay(order, { contact })

    expect(result).toEqual({ reference: 'GGG-2026-0448', paid: true })
    expect(posted(fetchMock, '/payments/checkout')).toEqual({ type: 'quotation', reference: 'GGG-Q-0034' })
  })

  it('answers with the API’s message instead of throwing, and keeps the reference it made', async () => {
    fakeApi({ ...payApi, 'POST /payments/checkout': reply(409, { error: 'These tickets are no longer held' }) })
    const order = { kind: 'tickets', reference: null, payment: { type: 'ticket_order', create: { eventId: 12, items: [] } } }

    const result = await pay(order, { contact })

    expect(result).toEqual({ reference: 'GGG-TKT-2026-0001', error: 'These tickets are no longer held' })
  })
})
