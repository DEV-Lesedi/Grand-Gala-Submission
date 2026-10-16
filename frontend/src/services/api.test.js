import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, allPages, apiDelete, apiGet, apiPost, cachedGet, clearCache, orNullOn404 } from './api'

// Tests fake fetch and check what api.js does with it.
function fakeFetch(status, body) {
  const fetchMock = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
  vi.stubGlobal('fetch', fetchMock)
  return fetchMock
}

describe('api', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_API_BASE_URL', 'http://localhost:8000/api')
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.unstubAllEnvs()
    vi.useRealTimers()
    clearCache()
  })

  // Lists that hardly change (the spaces) are asked for once and reused for a few minutes
  it('reuses a cached answer, and asks again once it is too old', async () => {
    vi.useFakeTimers()
    const fetchMock = fakeFetch(200, [{ id: 1 }])

    await cachedGet('/spaces', 60_000)
    await cachedGet('/spaces', 60_000)
    expect(fetchMock).toHaveBeenCalledTimes(1)

    vi.advanceTimersByTime(61_000)
    await cachedGet('/spaces', 60_000)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('shares one request between callers asking at the same time', async () => {
    const fetchMock = fakeFetch(200, [{ id: 1 }])

    const [first, second] = await Promise.all([cachedGet('/spaces', 60_000), cachedGet('/spaces', 60_000)])

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(first).toEqual(second)
  })

  it('does not keep a failed answer, so the next try asks again', async () => {
    fakeFetch(500, { error: 'Down' })
    await expect(cachedGet('/spaces', 60_000)).rejects.toBeInstanceOf(ApiError)

    const fetchMock = fakeFetch(200, [{ id: 1 }])
    expect(await cachedGet('/spaces', 60_000)).toEqual([{ id: 1 }])
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('gets JSON from the API address plus the path', async () => {
    const fetchMock = fakeFetch(200, [{ id: 1, name: 'The Villa' }])

    const spaces = await apiGet('/spaces')

    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/spaces')
    expect(spaces).toEqual([{ id: 1, name: 'The Villa' }])
  })

  it("throws an ApiError with the API's message and status when the request fails", async () => {
    fakeFetch(404, { error: 'Space not found' })

    const error = await apiGet('/spaces/999').catch((caught) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toBe('Space not found')
    expect(error.status).toBe(404)
  })

  it('throws an ApiError when an error response is not JSON (e.g. a 502 page while the server restarts)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<html>Bad Gateway</html>', { status: 502 })))

    const error = await apiGet('/spaces').catch((caught) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toBe('Something went wrong. Please try again.')
    expect(error.status).toBe(502)
  })

  it('posts a JSON body and returns the JSON answer', async () => {
    const fetchMock = fakeFetch(201, { reference: 'GGG-2026-0449' })

    const result = await apiPost('/bookings', { spaceId: 1, guests: 2 })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toBe('http://localhost:8000/api/bookings')
    expect(options.method).toBe('POST')
    expect(options.headers['Content-Type']).toBe('application/json')
    expect(JSON.parse(options.body)).toEqual({ spaceId: 1, guests: 2 })
    expect(result).toEqual({ reference: 'GGG-2026-0449' })
  })

  it('deletes without a body', async () => {
    const fetchMock = fakeFetch(200, { ok: true })

    await apiDelete('/me/favourites/3')

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toBe('http://localhost:8000/api/me/favourites/3')
    expect(options.method).toBe('DELETE')
    expect(options.body).toBeUndefined()
  })

  it("throws an ApiError with status 0 when the server can't be reached", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch') }))

    const error = await apiGet('/spaces').catch((caught) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.message).toBe("Can't reach the server. Check your connection and try again.")
    expect(error.status).toBe(0)
  })

  it('stops with a setup message, and sends nothing, when VITE_API_BASE_URL is missing', async () => {
    vi.stubEnv('VITE_API_BASE_URL', '')
    const fetchMock = fakeFetch(200, [])

    const error = await apiGet('/spaces').catch((caught) => caught)

    expect(error.message).toBe('VITE_API_BASE_URL is not set. Copy .env.example to .env and restart npm run dev.')
    expect(error).not.toBeInstanceOf(ApiError)
    expect(fetchMock).not.toHaveBeenCalled()
  })

  it('gives null instead of an error when the API says not found', async () => {
    fakeFetch(404, { error: 'Booking not found' })

    const booking = await orNullOn404(apiGet('/me/bookings/GGG-2026-9999'))

    expect(booking).toBeNull()
  })

  it('still throws other errors when not found is allowed', async () => {
    fakeFetch(500, { error: 'Database is down' })

    const error = await orNullOn404(apiGet('/me/bookings/GGG-2026-0417')).catch((caught) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(500)
  })

  it('gives the answer as it is when it was found', async () => {
    fakeFetch(200, { reference: 'GGG-2026-0417' })

    expect(await orNullOn404(apiGet('/me/bookings/GGG-2026-0417'))).toEqual({ reference: 'GGG-2026-0417' })
  })

  it('collects every page of a paged list, in page order', async () => {
    const pages = {
      1: { pageCount: 3, bookings: [{ id: 1 }, { id: 2 }] },
      2: { pageCount: 3, bookings: [{ id: 3 }] },
      3: { pageCount: 3, bookings: [{ id: 4 }] },
    }
    const fetchMock = vi.fn(async (url) => {
      const page = new URL(url).searchParams.get('page')
      return new Response(JSON.stringify(pages[page]), { status: 200 })
    })
    vi.stubGlobal('fetch', fetchMock)

    const bookings = await allPages('/staff/bookings?view=past', 'bookings')

    expect(bookings).toEqual([{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }])
    expect(fetchMock.mock.calls.map((call) => call[0])).toEqual([
      'http://localhost:8000/api/staff/bookings?view=past&page=1',
      'http://localhost:8000/api/staff/bookings?view=past&page=2',
      'http://localhost:8000/api/staff/bookings?view=past&page=3',
    ])
  })

  it('asks once for a list with only one page, adding ?page= to a path without a query', async () => {
    const fetchMock = fakeFetch(200, { pageCount: 1, quotations: [{ reference: 'GGG-Q-0034' }] })

    const quotations = await allPages('/staff/quotations', 'quotations')

    expect(quotations).toEqual([{ reference: 'GGG-Q-0034' }])
    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock.mock.calls[0][0]).toBe('http://localhost:8000/api/staff/quotations?page=1')
  })
})
