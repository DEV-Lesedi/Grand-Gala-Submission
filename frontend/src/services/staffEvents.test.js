import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createEvent, loadEventOrders, loadStaffEvents, saveTiers } from './staffEvents'

// Tests fake fetch and check the shape the page receives.

const STAFF_SPACES = [
  { id: 3, name: 'Open Garden', venueId: 1, isActive: true, isHireable: true, photo: '/photos/garden.jpg' },
  { id: 4, name: 'Marquee Tent', venueId: 1, isActive: true, isHireable: true, photo: null },
  { id: 5, name: 'Old Barn', venueId: 1, isActive: false, isHireable: true, photo: null },
  { id: 1, name: 'The Villa', venueId: 1, isActive: true, isHireable: false, photo: null },
]

const STAFF_EVENT = {
  id: 12,
  reference: 'GGG-EV-2026-0012',
  title: 'Movie Night',
  date: '2026-11-14',
  startTime: '18:30',
  endTime: '22:00',
  venue: 'Grand Gala Gardens',
  venueId: 1,
  capacity: 120,
  description: '',
  image: '/photos/garden.jpg',
  status: 'confirmed',
  saleStatus: 'on_sale',
  fromPrice: 120,
  spaceIds: [3, 4],
  ticketsSold: 60,
  revenue: 7200,
  ticketTiers: [
    {
      id: 31,
      name: 'Adult',
      description: null,
      price: 120,
      allocation: 120,
      available: 60,
      sold: 60,
      maxPerOrder: 6,
      salesOpen: '2026-10-01T00:00',
      salesClose: null,
      active: true,
      saleStatus: 'on_sale',
    },
  ],
}

// One paid order as GET /staff/events/:id/orders sends it
const API_ORDER = {
  reference: 'GGG-TKT-2026-0904',
  eventId: 12,
  eventTitle: 'Movie Night',
  eventDate: '2026-11-14',
  eventStartTime: '18:30',
  status: 'paid',
  subtotal: 240,
  discount: 24,
  total: 216,
  holdExpires: '2026-09-04T12:15',
  items: [{ tierId: 31, name: 'Adult', quantity: 2, unitPrice: 120 }],
  tickets: [],
  buyerName: 'Thandi',
  buyerSurname: 'Mokoena',
  buyerEmail: 'thandi@example.com',
  buyerPhone: null,
}

function fakeApi(answers) {
  const fetchMock = vi.fn(async (url, options) => {
    const path = url.replace('http://localhost:8000/api', '').split('?')[0]
    const body = answers[path] ?? (options.method === 'POST' ? { id: 13 } : null)
    return new Response(JSON.stringify(body), { status: 200 })
  })
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('staff events', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it("keeps the API's fields, names the event's spaces, and offers only switched-on spaces for hire", async () => {
    fakeApi({ '/staff/events': { items: [STAFF_EVENT] }, '/staff/spaces': STAFF_SPACES })

    const { events, spaces } = await loadStaffEvents()

    expect(events[0]).toEqual({
      id: 12,
      reference: 'GGG-EV-2026-0012',
      title: 'Movie Night',
      venue: 'Grand Gala Gardens',
      spaceIds: [3, 4],
      spaceNames: 'Open Garden + Marquee Tent',
      date: '2026-11-14',
      startTime: '18:30',
      endTime: '22:00',
      image: '/photos/garden.jpg',
      status: 'confirmed',
      saleStatus: 'on_sale',
      ticketsSold: 60,
      revenue: 7200,
      capacity: 120,
      tiers: [
        {
          id: 31,
          name: 'Adult',
          description: '',
          price: 120,
          allocation: 120,
          sold: 60,
          maxPerOrder: 6,
          salesOpen: '2026-10-01T00:00',
          salesClose: null,
          active: true,
        },
      ],
    })
    expect(spaces.map((space) => space.id)).toEqual([3, 4])
  })

  it('maps an order with the fields the API really sends (no paid date), and asks for up to 100', async () => {
    const fetchMock = fakeApi({ '/staff/events/12/orders': { items: [API_ORDER], total: 1, page: 1, pageSize: 100 } })

    const orders = await loadEventOrders(12)

    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/staff/events/12/orders?pageSize=100')
    expect(orders).toEqual([
      {
        reference: 'GGG-TKT-2026-0904',
        eventId: 12,
        status: 'paid',
        buyerName: 'Thandi',
        buyerSurname: 'Mokoena',
        buyerEmail: 'thandi@example.com',
        total: 216,
        items: [{ tierId: 31, name: 'Adult', quantity: 2, unitPrice: 120 }],
      },
    ])
  })

  it("creates an event on the chosen space's id and venue, whatever the space is called", async () => {
    const fetchMock = fakeApi({})
    const renamed = STAFF_SPACES.map((space) => (space.id === 4 ? { ...space, name: 'The Big Top' } : space))
    const form = {
      title: 'Jazz Evening',
      spaceId: 4,
      date: '2026-12-05',
      startTime: '18:00',
      endTime: '22:00',
      image: '/photos/event.jpg',
      about: ['Live music.'],
      tiers: [{ name: 'General', price: 150, allocation: 80, salesOpen: '2026-10-20' }],
    }

    await createEvent(form, renamed)

    const posted = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(posted).toEqual({
      title: 'Jazz Evening',
      description: 'Live music.',
      venueId: 1,
      date: '2026-12-05',
      startTime: '18:00',
      endTime: '22:00',
      spaceIds: [4],
      capacity: 80,
      image: '/photos/event.jpg',
      tiers: [{ name: 'General', price: 150, allocation: 80, salesOpen: '2026-10-20T00:00' }],
    })
  })

  // A missing tier field is wiped, so the save must send each tier whole.
  it('sends each tier whole when saving, so descriptions and sales times survive', async () => {
    const fetchMock = fakeApi({})
    await saveTiers(12, [
      { id: 31, name: 'Adult', description: 'Ages 13 and up', price: 120, allocation: 120, maxPerOrder: 6, active: true, salesOpen: '2026-10-01T09:00', salesClose: '2026-11-14T18:30' },
      { id: 'new-1', name: 'Child', description: '', price: 60, allocation: 40, active: true, salesOpen: null, salesClose: null },
    ])

    const sent = JSON.parse(fetchMock.mock.calls[0][1].body)
    expect(fetchMock.mock.calls[0][1].method).toBe('PUT')
    expect(sent.tiers).toEqual([
      { id: 31, name: 'Adult', description: 'Ages 13 and up', price: 120, allocation: 120, maxPerOrder: 6, active: true, salesOpen: '2026-10-01T09:00', salesClose: '2026-11-14T18:30' },
      { name: 'Child', description: null, price: 60, allocation: 40, active: true, salesOpen: null, salesClose: null },
    ])
  })

  it('refuses to create an event without a known space, before asking the API', () => {
    const fetchMock = fakeApi({})

    expect(() => createEvent({ spaceId: 99, tiers: [] }, STAFF_SPACES)).toThrow('Choose a space for the event.')
    expect(fetchMock).not.toHaveBeenCalled()
  })
})
