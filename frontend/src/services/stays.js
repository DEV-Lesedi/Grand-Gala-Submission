// Guest spaces and stays. A space keeps the API fields and its database id.
import { apiGet, apiPost, cachedGet } from './api'
import { TODAY, addDays } from '../lib/dates'
import { blockedDatesFromCalendars } from '../lib/bookingCalendar'
import { formatRand } from '../lib/money'

// Guest space. Only the photo gets a default. currentRates are today's prices per kind.
export function toGuestSpace(apiSpace) {
  return {
    id: apiSpace.id,
    name: apiSpace.name,
    type: apiSpace.type,
    capacity: apiSpace.capacity,
    isAccommodation: apiSpace.isAccommodation,
    isHireable: apiSpace.isHireable,
    parentId: apiSpace.parentId, // the villa's id for a room, else null
    description: apiSpace.description,
    photo: apiSpace.photo ?? '/photos/villa.jpg',
    currentRates: apiSpace.currentRates ?? [],
  }
}

// Every space a guest can see (switched on, to stay in or to hire)
export async function loadGuestSpaces() {
  // Kept for 2 minutes: almost every guest page needs the spaces, and they rarely change
  const all = await cachedGet('/spaces', 2 * 60 * 1000)
  return all.filter((space) => space.isAccommodation || space.isHireable).map(toGuestSpace)
}

// One space with its amenities (for Checkout), or null. id: from the address, e.g. "3"
export async function loadGuestSpace(id) {
  const space = findSpace(await loadGuestSpaces(), id)
  if (!space) return null
  return withAmenities(space)
}

// Match by id. Address ids are text; the API's are numbers.
export function findSpace(spaces, id) {
  return spaces.find((space) => String(space.id) === String(id))
}

// Adds amenities from GET /spaces/:id. Skips a second /spaces fetch when the list is loaded.
export async function withAmenities(space) {
  const detail = await apiGet('/spaces/' + space.id)
  return { ...space, amenities: detail.amenities ?? [] }
}

// One space's calendar: each day is free, booked, held, blacked_out, or mode_locked.
export function loadAvailability(spaceId, from, to) {
  return apiGet(`/availability?spaceId=${spaceId}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
}

// Several spaces' calendars in one request: { [spaceId]: days }. At most 20 ids (the venue has 13 spaces).
// Switched-off spaces are left out of the answer.
export async function loadAvailabilities(spaceIds, from, to) {
  const answer = await apiGet(`/availability?spaceIds=${spaceIds.join(',')}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`)
  return Array.isArray(answer) ? {} : answer // [] when none of them is active
}

// Days none of these spaces can take, for the date picker. spaceIds: database ids.
export async function loadBlockedDates(spaceIds, from, to) {
  if (spaceIds.length === 0) return new Set()
  const calendars = await loadAvailabilities(spaceIds, from, to)
  return blockedDatesFromCalendars(Object.values(calendars))
}

// null when every night is free, else a short reason. Browse uses this without pricing.
// The calendar treats the villa and its rooms as one: a room booking blocks the villa.
export async function unavailableReason(space, checkIn, checkOut) {
  const lastNight = addDays(checkOut, -1)
  return reasonFrom(await loadAvailability(space.id, checkIn, lastNight))
}

// unavailableReason for a whole list, in one request: { [spaceId]: null or a reason }
export async function unavailableReasons(spaces, checkIn, checkOut) {
  if (spaces.length === 0) return {}
  const calendars = await loadAvailabilities(spaces.map((space) => space.id), checkIn, addDays(checkOut, -1))
  const reasons = {}
  for (const space of spaces) reasons[space.id] = reasonFrom(calendars[space.id] ?? [])
  return reasons
}

function reasonFrom(days) {
  if (days.some((day) => day.state === 'blacked_out')) return 'Not available on these dates'
  if (days.some((day) => day.state !== 'free')) return 'Already booked on these dates'
  return null
}

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

// Shared stay check for detail, Reserve, and Checkout: dates, guests, free nights, then price.
// A failed calendar request throws; a refused price (bad code) comes back as the reason.
export async function checkStay({ space, checkIn, checkOut, guests, extras = [], promoCode }) {
  if (!ISO_DATE.test(checkIn ?? '') || !ISO_DATE.test(checkOut ?? '') || checkIn < TODAY || checkOut <= checkIn) {
    return refuse('Choose your dates')
  }
  const count = guests ?? 1
  if (!Number.isInteger(count) || count < 1) return refuse('How many guests?')
  if (count > space.capacity) return refuse(`${space.name} sleeps ${space.capacity} at most`)

  const unavailable = await unavailableReason(space, checkIn, checkOut)
  if (unavailable) return refuse(unavailable)

  const body = { spaceId: space.id, checkIn, checkOut, guests: count, amenities: amenitiesFor(space, extras) }
  if (promoCode) body.promoCode = promoCode
  try {
    const quote = await apiPost('/stays/price', body)
    return { ok: true, reason: null, quote: { ...quote, lines: groupLines(quote.lines) } }
  } catch (error) {
    return refuse(error.message)
  }
}

function refuse(reason) {
  return { ok: false, reason, quote: null }
}

// Amenity ids the space offers, once each, as { amenityId, quantity }. Unknown ids are ignored.
function amenitiesFor(space, extras) {
  return (space.amenities ?? [])
    .filter((amenity) => extras.includes(amenity.id))
    .map((amenity) => ({ amenityId: amenity.id, quantity: 1 }))
}

// Groups nights at one price ("R2 800 × 2 nights"), then one line per amenity.
function groupLines(apiLines) {
  const groups = []
  for (const line of apiLines) {
    if (line.kind !== 'space') {
      const each = line.quantity > 1 ? ` × ${line.quantity}` : ''
      groups.push({ label: line.description + each, amount: line.total })
      continue
    }
    const last = groups[groups.length - 1]
    if (last && last.night && last.unitPrice === line.unitPrice) {
      last.count += 1
      last.amount += line.total
    } else {
      groups.push({ night: true, unitPrice: line.unitPrice, count: 1, amount: line.total })
    }
  }
  return groups.map((group) =>
    group.night
      ? { label: `${formatRand(group.unitPrice)} × ${group.count} ${group.count === 1 ? 'night' : 'nights'}`, amount: group.amount }
      : group,
  )
}

// The API rechecks dates and price under a lock, and holds them while the guest pays.
export function createStay(space, { checkIn, checkOut, guests, extras = [], promoCode, notes }) {
  const body = { spaceId: space.id, checkIn, checkOut, guests, amenities: amenitiesFor(space, extras) }
  if (promoCode) body.promoCode = promoCode
  if (notes) body.notes = notes
  return apiPost('/bookings', body)
}
