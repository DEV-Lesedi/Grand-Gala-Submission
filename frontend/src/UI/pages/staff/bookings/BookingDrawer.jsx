import Button from '../../../components/Button'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import DetailFacts from '../components/DetailFacts'
import DetailPanel from '../components/DetailPanel'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { useApiData } from '../../../hooks/useApiData'
import { loadStaffBooking } from '../../../../services/staffBookings'
import { isActive } from './bookingFilters'
import './BookingDrawer.css'

function bookingFacts(booking) {
  if (booking.type === 'stay') {
    return [
      { label: 'Check-in', value: formatDate(booking.startDate) },
      { label: 'Check-out', value: formatDate(booking.endDate) },
      { label: 'Guests', value: booking.guests },
    ]
  }
  return [
    { label: 'Date', value: formatDate(booking.startDate) },
    { label: 'Hours', value: booking.hours },
    { label: 'Guests', value: booking.guests },
  ]
}

// Only open bookings have action buttons.
const ACTION_LABELS = {
  quoted: { resend: 'Resend quotation', cancel: 'Cancel booking' },
  pending_payment: { resend: 'Resend payment link', cancel: 'Cancel booking' },
  confirmed: { resend: 'Resend confirmation', cancel: 'Cancel & refund' },
}

function actionLabels(booking) {
  return ACTION_LABELS[booking.status]
}

// → "Paid 28 Aug 2026 · PayFast"
function paymentText(payment) {
  const text = `${payment.label} ${formatDate(payment.date)}`
  return payment.note ? `${text} · ${payment.note}` : text
}

// List row, plus lines, contact, and payment loaded when the drawer opens.
export default function BookingDrawer({ booking: row, isOpen, onClose }) {
  const detail = useApiData(() => loadStaffBooking(row.reference), [row.reference])
  const booking = { lines: [], discount: 0, contact: '', payment: null, ...row, ...(detail.data ?? {}), view: row.view }
  const labels = actionLabels(booking)

  return (
    <DetailPanel className="booking-drawer" isOpen={isOpen} onClose={onClose} backLabel="Back to bookings">
      <div className="booking-drawer-top">
        <h2 className="booking-drawer-reference">{booking.reference}</h2>
        <StatusChip statuses={BOOKING_STATUSES} status={booking.status} />
        <span className="booking-drawer-type">{booking.type}</span>
      </div>

      <div>
        <p className="booking-drawer-name">{booking.fullName}</p>
        <p className="booking-drawer-contact">{booking.contact}</p>
      </div>

      <DetailFacts facts={bookingFacts(booking)} />

      <div className="booking-drawer-lines">
        {booking.lines.map((line) => (
          <p key={line.label} className="booking-line">
            <span>{line.label}</span>
            <span>{formatRand(line.amount)}</span>
          </p>
        ))}
        {booking.discount > 0 && (
          <p className="booking-line is-discount">
            <span>Discount</span>
            <span>– {formatRand(booking.discount)}</span>
          </p>
        )}
      </div>

      <p className="booking-line is-total">
        <span>Total</span>
        <span>{formatRand(booking.total)}</span>
      </p>

      {detail.error && <p className="page-problem">{detail.error.message}</p>}
      {booking.payment && (
        <p className={`booking-drawer-payment is-${booking.payment.tone}`}>{paymentText(booking.payment)}</p>
      )}

      {/* Finished bookings are read-only */}
      {isActive(booking) && labels && (
        <div className="booking-drawer-actions">
          <Button className="booking-drawer-resend">{labels.resend}</Button>
          <button type="button" className="link-button booking-drawer-cancel">
            {labels.cancel}
          </button>
        </div>
      )}
    </DetailPanel>
  )
}
