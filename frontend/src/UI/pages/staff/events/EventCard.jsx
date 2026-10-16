import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import ProgressBar from './ProgressBar'
import { TODAY, formatDate } from '../../../../lib/dates'
import { EVENT_SALE_STATUSES } from '../../../../lib/statuses'
import './EventCard.css'

// sold and status come from loadStaffEvents.
export default function EventCard({ event, onClick }) {
  return (
    <Card as="button" type="button" className="event-card" onClick={onClick}>
      <span className="event-card-top">
        <img src={event.image} alt="" />
        <span className="event-card-text">
          <span className="event-card-title">{event.title}</span>
          <span className="event-card-meta">
            {event.date === TODAY ? 'Tonight' : formatDate(event.date)} · {event.spaceNames}
          </span>
        </span>
        <StatusChip statuses={EVENT_SALE_STATUSES} status={event.saleStatus} />
      </span>
      <span className="event-card-sold">
        <ProgressBar value={event.ticketsSold} max={event.capacity} label={`${event.title} sold`} />
        <span>
          {event.ticketsSold} / {event.capacity}
        </span>
      </span>
    </Card>
  )
}
