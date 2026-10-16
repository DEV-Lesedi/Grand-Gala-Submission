import Button from '../../../components/Button'
import StatusChip from '../../../components/StatusChip'
import { EVENT_SALE_STATUSES } from '../../../../lib/statuses'
import { formatDayDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './EventListCard.css'

const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC']

// status and fromPrice come from the API. Phone shows date, time and venue on one line.
export default function EventListCard({ event }) {
  const isSoldOut = event.status === 'sold_out'
  const isOnSale = event.status === 'on_sale'
  const date = formatDayDate(event.date) // "Mon 14 Sep 2026"

  return (
    <article className={`event-list-card ${isSoldOut ? 'is-sold-out' : ''}`.trim()}>
      <div className="event-list-card-photo">
        <img src={event.photo} alt="" />
        <span className="event-list-card-date">
          <strong>{event.date.slice(8, 10)}</strong>
          {MONTHS[Number(event.date.slice(5, 7)) - 1]}
        </span>
        <span className="event-list-card-status">
          <StatusChip statuses={EVENT_SALE_STATUSES} status={event.status} />
        </span>
      </div>

      <div className="event-list-card-body">
        <h2 className="event-list-card-title">{event.title}</h2>
        <p className="event-list-card-meta is-desktop">
          {date} · {event.startTime}
        </p>
        <p className="event-list-card-meta is-desktop">{event.space}</p>
        {/* Phone: the same facts on one line, without the year */}
        <p className="event-list-card-meta is-phone">
          {date.slice(0, -5)} · {event.startTime} · {event.space}
        </p>

        {/* Price and button stay at the bottom, so buttons line up across a row */}
        <div className="event-list-card-footer">
          {event.fromPrice !== null && <p className="event-list-card-price">From {formatRand(event.fromPrice)}</p>}

          {/* Sold out opens nothing. aria-label is the full name on both layouts. */}
          {isSoldOut ? (
            <Button className="event-list-card-button" variant="secondary" disabled>
              Sold out
            </Button>
          ) : (
            <Button
              className="event-list-card-button"
              variant={isOnSale ? 'primary' : 'secondary'}
              to={`/events/${event.id}`}
              aria-label={`${isOnSale ? 'View tickets' : 'View event'}: ${event.title}`}
            >
              <span className="is-desktop">{isOnSale ? 'View tickets' : 'View event'}</span>
              <span className="is-phone">{isOnSale ? 'Tickets' : 'Details'}</span>
            </Button>
          )}
        </div>
      </div>
    </article>
  )
}
