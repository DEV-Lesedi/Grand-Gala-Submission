import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './BookingCard.css'

export default function BookingCard({ booking, onClick }) {
  return (
    <Card as="button" type="button" className="booking-card" onClick={onClick}>
      <div className="booking-card-main">
        <p className="booking-card-guest">{booking.guest}</p>
        <p className="booking-card-details">
          {booking.startDate ? `${booking.description} · ${formatDate(booking.startDate)}` : booking.description}
        </p>
        <p className="booking-card-reference">{booking.reference}</p>
      </div>
      <div className="booking-card-side">
        <p className="booking-card-total">{formatRand(booking.total)}</p>
        <StatusChip statuses={BOOKING_STATUSES} status={booking.status} />
      </div>
    </Card>
  )
}
