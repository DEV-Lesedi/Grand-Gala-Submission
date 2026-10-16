import { Link } from 'react-router-dom'
import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import Fact from '../../../components/Fact'
import Button from '../../../components/Button'
import { MY_BOOKING_STATUSES } from '../../../../lib/statuses'
import './BookingRow.css'

// On phone the photo sits on top and the whole card is the link.
export default function BookingRow({ image, status, title, facts, price, actionLabel, actionTo }) {
  return (
    <Card className="booking-row">
      <img src={image} alt="" className="booking-row-thumb" />
      <div className="booking-row-body">
        <StatusChip statuses={MY_BOOKING_STATUSES} status={status} />
        <h3>
          {/* On phone the link covers the card */}
          <Link to={actionTo} className="booking-row-link">
            {title}
          </Link>
        </h3>
        <Fact items={facts} className="booking-row-facts" />
        <Fact items={[...facts.slice(0, 2), price]} className="booking-row-facts-phone" />
      </div>
      <div className="booking-row-action">
        <p className="booking-row-price">{price}</p>
        <Button variant="secondary" size="sm" to={actionTo}>
          {actionLabel}
        </Button>
      </div>
    </Card>
  )
}
