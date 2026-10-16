// The one place every page talks to the backend.
// The API address comes from VITE_API_BASE_URL in .env, e.g. http://localhost:8000/api
import { getToken } from './session'
import { endRequest, startRequest } from './activity'

// Thrown when the API says no. message is the API's text; status is 404, 409, 422, and so on.
export class ApiError extends Error {
  constructor(message, status) {
    super(message)
    this.name = 'ApiError'
    this.status = status
  }
}

// options.as is 'guest' or 'staff'. Omitted: /staff/ paths use staff, the rest guest.
// options.headers adds headers, such as X-Ticket-Access for a ticket order.
export function apiGet(path, options = {}) {
  return request('GET', path, undefined, options)
}

export function apiPost(path, body, options = {}) {
  return request('POST', path, body, options)
}

export function apiPatch(path, body, options = {}) {
  return request('PATCH', path, body, options)
}

export function apiPut(path, body, options = {}) {
  return request('PUT', path, body, options)
}

export function apiDelete(path, options = {}) {
  return request('DELETE', path, undefined, options)
}

// Uploads a file as a form (photos and videos). formData: a FormData with the file in it.
export function apiUpload(path, formData, options = {}) {
  return request('POST', path, formData, options)
}

// The full address of an API path, e.g. for an <img src> of a ticket's QR code
export function apiUrl(path) {
  return import.meta.env.VITE_API_BASE_URL + path
}

// Answers kept for a while: path → { promise, at }. Only for lists that hardly change (the spaces),
// so going from page to page doesn't wait for the same answer again.
const cache = new Map()

// apiGet, but an answer younger than maxAgeMs is reused. Callers asking at the same time share one
// request. A failed request isn't kept, so the next caller tries again.
export function cachedGet(path, maxAgeMs) {
  const saved = cache.get(path)
  if (saved && Date.now() - saved.at < maxAgeMs) return saved.promise

  const promise = apiGet(path)
  cache.set(path, { promise, at: Date.now() })
  promise.catch(() => {
    if (cache.get(path)?.promise === promise) cache.delete(path)
  })
  return promise
}

// Forget every kept answer (tests, and after staff change something)
export function clearCache() {
  cache.clear()
}

// 404 becomes null. Any other error still throws.
export async function orNullOn404(promise) {
  try {
    return await promise
  } catch (error) {
    if (error.status === 404) return null
    throw error
  }
}

// Every item of a paged staff list: asks for page 1, then the other pages at the same time.
// allPages('/staff/bookings?view=past', 'bookings') → every booking on every page, in order.
// listName: the field of each page's answer that holds the items (the answer also has pageCount).
export async function allPages(path, listName) {
  const join = path.includes('?') ? '&' : '?'
  const first = await apiGet(path + join + 'page=1')
  const rest = []
  for (let page = 2; page <= first.pageCount; page++) {
    rest.push(apiGet(path + join + 'page=' + page))
  }
  const pages = [first, ...(await Promise.all(rest))]
  return pages.flatMap((answer) => answer[listName])
}

// Sends one request, and counts it as running for the progress bar at the top of the page
async function request(method, path, body, options) {
  startRequest()
  try {
    return await send(method, path, body, options)
  } finally {
    endRequest()
  }
}

// A body is sent as JSON (or as a form for uploads); GET and DELETE send none.
async function send(method, path, body, options) {
  // Without this every request would go to "undefined/spaces" and fail in a confusing way
  const baseUrl = import.meta.env.VITE_API_BASE_URL
  if (!baseUrl) {
    throw new Error('VITE_API_BASE_URL is not set. Copy .env.example to .env and restart npm run dev.')
  }

  const headers = { Accept: 'application/json', ...(options.headers ?? {}) }
  const side = options.as ?? (path.startsWith('/staff') ? 'staff' : 'guest')
  const token = getToken(side)
  if (token) headers.Authorization = 'Bearer ' + token

  const fetchOptions = { method, headers }
  if (body instanceof FormData) {
    fetchOptions.body = body // the browser sets the form's Content-Type itself
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    fetchOptions.body = JSON.stringify(body)
  }

  // fetch throws only when the server can't be reached. status 0 means no answer.
  let response
  try {
    response = await fetch(baseUrl + path, fetchOptions)
  } catch {
    throw new ApiError("Can't reach the server. Check your connection and try again.", 0)
  }

  const data = await readJson(response)

  // Problems are { error }. Anything else (a 502 page) gets a general message.
  if (!response.ok) {
    const message = data && data.error ? data.error : 'Something went wrong. Please try again.'
    throw new ApiError(message, response.status)
  }

  return data
}

// The body as JSON, or null if it isn't JSON
async function readJson(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch {
    return null
  }
}
