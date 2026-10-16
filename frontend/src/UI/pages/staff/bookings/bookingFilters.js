import { TODAY } from '../../../../lib/dates'

// ISO dates compare as text.

// Statuses that still need something from staff or the guest.
const OPEN_STATUSES = ['quoted', 'pending_payment', 'confirmed']

// A stay ends on check-out; an event ends on its day.
function lastDay(booking) {
  return booking.type === 'stay' ? booking.endDate : booking.startDate
}

// API view wins. Without it: still open, and not past check-out (stay) or the event day.
export function isActive(booking) {
  if (booking.view) return booking.view === 'active'
  return OPEN_STATUSES.includes(booking.status) && lastDay(booking) >= TODAY
}

export function isPast(booking) {
  return !isActive(booking)
}

const VIEW_CHECKS = { active: isActive, past: isPast }

export function matchesFilter(booking, filter) {
  const inView = VIEW_CHECKS[filter.view](booking)
  const hasStatus = !filter.status || booking.status === filter.status
  return inView && hasStatus
}

// By date, then reference (stable order). A booking with no date yet comes last, newest or oldest.
function dateText(booking) {
  return booking.startDate ?? ''
}

export function sortByDate(bookings, newestFirst = false) {
  const sorted = [...bookings].sort(
    (a, b) => dateText(a).localeCompare(dateText(b)) || (a.reference ?? '').localeCompare(b.reference ?? ''),
  )
  return newestFirst ? sorted.reverse() : sorted
}
