// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { checkTicketPromo, createTicketOrder, loadTicketOrder } from './ticketOrders'
import { startPayment } from './payments'

// Tests fake the API. The access token is kept in localStorage, cleared before each test.

function fakeApi(status, body) {
  const fetchMock = vi.fn(async () => new Response(JSON.stringify(body), { status }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

const CONTACT = { fullName: '  Thandi  van der Merwe ', email: ' thandi@example.com ', phone: '' }

describe('ticket orders', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
    localStorage.clear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
  })

  it('creates a ticket order with the buyer split into name and surname', async () => {
    const fetchMock = fakeApi(201, { reference: 'GGG-TKT-2026-0001' })

    const order = await createTicketOrder({ eventId: 7, items: [{ tierId: 3, quantity: 2 }] }, CONTACT, 'SPRING10')

    expect(order.reference).toBe('GGG-TKT-2026-0001')
    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toBe('http://localhost:8000/api/ticket-orders')
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({
      eventId: 7,
      items: [{ tierId: 3, quantity: 2 }],
      name: 'Thandi',
      surname: 'van der Merwe',
      email: 'thandi@example.com',
      phone: null,
      promoCode: 'SPRING10',
    })
  })

  it('sends a signed-out buyer’s access token when loading and paying for the order it made', async () => {
    fakeApi(201, { reference: 'GGG-TKT-2026-0002', accessToken: 'secret-token' })
    await createTicketOrder({ eventId: 7, items: [] }, CONTACT)

    const fetchMock = fakeApi(200, { reference: 'GGG-TKT-2026-0002', status: 'pending_payment' })
    const order = await loadTicketOrder('GGG-TKT-2026-0002')
    await startPayment('ticket_order', 'GGG-TKT-2026-0002')

    expect(order.status).toBe('pending_payment')
    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/ticket-orders/GGG-TKT-2026-0002')
    expect(fetchMock.mock.calls[0][1].headers['X-Ticket-Access']).toBe('secret-token')
    expect(fetchMock.mock.calls[1][0]).toBe('http://localhost:8000/api/payments/checkout')
    expect(fetchMock.mock.calls[1][1].headers['X-Ticket-Access']).toBe('secret-token')
  })

  it('uses the email link’s token, and remembers it for next time', async () => {
    const fetchMock = fakeApi(200, { reference: 'GGG-TKT-2026-0003' })

    await loadTicketOrder('GGG-TKT-2026-0003', 'link-token')
    await loadTicketOrder('GGG-TKT-2026-0003')

    expect(fetchMock.mock.calls[0][1].headers['X-Ticket-Access']).toBe('link-token')
    expect(fetchMock.mock.calls[1][1].headers['X-Ticket-Access']).toBe('link-token')
  })

  it('sends no access header without a token (a signed-in buyer)', async () => {
    const fetchMock = fakeApi(200, { reference: 'GGG-TKT-2026-0004' })

    await loadTicketOrder('GGG-TKT-2026-0004')

    expect(fetchMock.mock.calls[0][1].headers['X-Ticket-Access']).toBeUndefined()
  })

  it.each([404, 401])('answers null when the API says %i (not theirs or not there)', async (status) => {
    fakeApi(status, { error: 'Ticket order not found' })

    expect(await loadTicketOrder('GGG-TKT-2026-9999')).toBe(null)
  })

  it('checks a discount code for tickets and works out the new total', async () => {
    const fetchMock = fakeApi(200, { discount: 45.5 })

    const found = await checkTicketPromo('SPRING10', 455)

    expect(found).toEqual({ discount: 45.5, total: 409.5 })
    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/promotions/validate')
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ code: 'SPRING10', kind: 'tickets', amount: 455 })
  })

  it('throws the API’s message for a code that can’t be used', async () => {
    fakeApi(422, { error: 'This code has expired' })

    await expect(checkTicketPromo('OLD', 455)).rejects.toThrow('This code has expired')
  })
})
