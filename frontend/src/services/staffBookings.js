// Staff bookings. The page filters the list, so active and past are both loaded in full.
import { allPages, apiGet } from './api'

// Each booking remembers which list it came from (active or past)
async function listOf(view) {
  const bookings = await allPages(`/staff/bookings?view=${view}`, 'bookings')
  return bookings.map((booking) => ({ ...booking, view }))
}

export async function loadAllStaffBookings() {
  const [active, past] = await Promise.all([listOf('active'), listOf('past')])
  return [...active, ...past]
}

// Drawer details, including payment as a label, date, note, and tone.
export async function loadStaffBooking(reference) {
  const booking = await apiGet('/staff/bookings/' + encodeURIComponent(reference))
  return { ...booking, contact: [booking.email, booking.phone].filter(Boolean).join(' · '), payment: paymentOf(booking) }
}

function paymentOf(booking) {
  const payment = booking.payment ?? {}
  if (payment.refundedAt) return { label: 'Refunded', date: payment.refundedAt.slice(0, 10), note: null, tone: 'muted' }
  if (payment.paidAt) return { label: 'Paid', date: payment.paidAt.slice(0, 10), note: 'PayFast', tone: 'success' }
  if (booking.status === 'pending_payment' && payment.holdExpires) {
    return { label: 'Awaiting payment · held until', date: payment.holdExpires.slice(0, 10), note: null, tone: 'warning' }
  }
  return null
}
