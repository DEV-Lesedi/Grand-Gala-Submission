import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { loadGuestEvent, loadGuestEvents } from './events'

// Tests fake fetch and check the shape the pages receive.

// One event as GET /events/:id sends it to a guest (switched-off tiers are already left out)
function apiEvent(changes = {}) {
  return {
    id: 12,
    slug: 'event-12',
    title: 'Movie Night',
    date: '2026-11-14',
    startTime: '18:30',
    endTime: '22:00',
    venue: 'Grand Gala Gardens',
    venueId: 1,
    capacity: 120,
    description: 'Bring a blanket.\n\nPopcorn is on us.',
    image: '/photos/garden.jpg',
    status: 'confirmed',
    saleStatus: 'on_sale',
    fromPrice: 60,
    ticketTiers: [
      {
        id: 31,
        name: 'Adult',
        description: null,
        price: 120,
        allocation: 100,
        available: 40,
        sold: 60,
        maxPerOrder: 6,
        salesOpen: '2026-10-01T00:00',
        salesClose: null,
        active: true,
        saleStatus: 'on_sale',
      },
      {
        id: 32,
        name: 'Child',
        description: 'Under 12',
        price: 60,
        allocation: 20,
        available: 20,
        sold: 0,
        maxPerOrder: null,
        salesOpen: '2026-11-01T00:00',
        salesClose: null,
        active: true,
        saleStatus: 'not_on_sale_yet',
      },
    ],
    ...changes,
  }
}

function fakeApi(status, body) {
  const fetchMock = vi.fn(async () => new Response(JSON.stringify(body), { status }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('guest events', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('gives each event and tier one id, the database id, and keeps what the API works out', async () => {
    fakeApi(200, apiEvent())

    const event = await loadGuestEvent('12')

    expect(event).toEqual({
      id: 12,
      title: 'Movie Night',
      space: 'Grand Gala Gardens',
      date: '2026-11-14',
      startTime: '18:30',
      endTime: '22:00',
      photo: '/photos/garden.jpg',
      about: ['Bring a blanket.', 'Popcorn is on us.'],
      status: 'on_sale',
      isCancelled: false,
      fromPrice: 60,
      capacity: 120,
      tiers: [
        {
          id: 31,
          name: 'Adult',
          description: '',
          price: 120,
          left: 40,
          maxPerOrder: 6,
          salesOpen: '2026-10-01T00:00',
          salesClose: null,
          canBuy: true,
          opensLater: false,
        },
        {
          id: 32,
          name: 'Child',
          description: 'Under 12',
          price: 60,
          left: 20,
          maxPerOrder: null,
          salesOpen: '2026-11-01T00:00',
          salesClose: null,
          canBuy: false,
          opensLater: true,
        },
      ],
    })
  })

  it('gives no paragraphs and the default photo when the API has none', async () => {
    fakeApi(200, apiEvent({ description: '', image: null }))

    const event = await loadGuestEvent('12')

    expect(event.about).toBe(null)
    expect(event.photo).toBe('/photos/event.jpg')
  })

  it('gives null for an event that is not on (404)', async () => {
    fakeApi(404, { error: 'Event not found' })

    expect(await loadGuestEvent('99')).toBe(null)
  })

  it('reads the list from the items of GET /events', async () => {
    const fetchMock = fakeApi(200, { items: [apiEvent(), apiEvent({ id: 13, title: 'Jazz Evening' })] })

    const events = await loadGuestEvents()

    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/events')
    expect(events.map((event) => [event.id, event.title])).toEqual([
      [12, 'Movie Night'],
      [13, 'Jazz Evening'],
    ])
  })
})
