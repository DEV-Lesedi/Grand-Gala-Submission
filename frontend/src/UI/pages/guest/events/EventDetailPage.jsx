import { Link, useParams } from 'react-router-dom'
import Button from '../../../components/Button'
import EmptyState from '../../../components/EmptyState'
import Loaded from '../../../components/Loaded'
import StatusChip from '../../../components/StatusChip'
import EventListCard from './EventListCard'
import TicketPicker from './TicketPicker'
import { useApiData } from '../../../hooks/useApiData'
import { loadGuestEvent, loadGuestEvents } from '../../../../services/events'
import { EVENT_SALE_STATUSES, statusLabel } from '../../../../lib/statuses'
import { TODAY, formatDate, formatDayDate } from '../../../../lib/dates'
import './EventDetailPage.css'

// /events/<id>. Ticket picker sits beside the event; below it on iPad and phone.
export default function EventDetailPage() {
  const { id } = useParams()
  const loaded = useApiData(() => loadGuestEvent(id), [id])
  const others = useApiData(loadGuestEvents, [])

  // A cancelled or past event counts as not found, the same as a wrong address
  return (
    <div className="event-detail">
      <Loaded
        state={loaded}
        what="this event"
        find={(event) => (event && !event.isCancelled && event.date >= TODAY ? event : null)}
        notFound={
          <EmptyState
            titleAs="h1"
            title="This event isn’t on"
            description="It may have ended, or the link is wrong."
            actionLabel="All events"
            actionTo="/events"
          />
        }
      >
        {(event) => <EventDetail event={event} others={others.data ?? []} />}
      </Loaded>
    </div>
  )
}

// others: upcoming events shown under "More events".
function EventDetail({ event, others }) {
  const date = formatDayDate(event.date) // "Mon 14 Sep 2026"
  const ticketsLeft = event.tiers.reduce((sum, tier) => sum + tier.left, 0)
  // Up to three other upcoming events, soonest first
  const moreEvents = others.filter((other) => other.id !== event.id).slice(0, 3)

  return (
    <>
      <Link to="/events" className="event-detail-back">
        ← All events
      </Link>

      {/* Intro and info left, tickets right. Phone: intro, tickets, then info. */}
      <div className="event-detail-body">
        <header className="event-detail-intro">
          <div className="event-detail-photo">
            <img src={event.photo} alt="" />
            {/* Phone: a round back button on the photo, in place of the "All events" link */}
            <Link to="/events" className="event-detail-photo-back" aria-label="All events">
              ←
            </Link>
            <span className="event-detail-status">
              <StatusChip statuses={EVENT_SALE_STATUSES} status={event.status} />
            </span>
          </div>
          <h1 className="event-detail-title">{event.title}</h1>
          <p className="event-detail-when">
            {/* The year is hidden on phone */}
            {date.slice(0, -5)}
            <span className="event-detail-year">{date.slice(-5)}</span> · {event.startTime} · {event.space}
          </p>
        </header>

        <div className="event-detail-info">
          <dl className="event-detail-facts">
            <div>
              <dt>Date</dt>
              <dd>{date}</dd>
            </div>
            <div>
              <dt>Time</dt>
              <dd>
                {event.startTime}, ends {event.endTime}
              </dd>
            </div>
            <div>
              <dt>Where</dt>
              <dd>{event.space}</dd>
            </div>
            {event.status === 'on_sale' && (
              <div>
                <dt>Tickets left</dt>
                <dd>{ticketsLeft}</dd>
              </div>
            )}
          </dl>

          {event.about && (
            <section className="event-detail-section">
              <h2>About this event</h2>
              {event.about.map((paragraph) => (
                <p key={paragraph}>{paragraph}</p>
              ))}
            </section>
          )}
        </div>

        {event.status === 'on_sale' ? (
          <TicketPicker event={event} />
        ) : (
          <aside className="event-detail-closed">
            <h2>{statusLabel(EVENT_SALE_STATUSES, event.status)}</h2>
            <p>{closedText(event)}</p>
            <Button variant="secondary" to="/events">
              Browse events
            </Button>
          </aside>
        )}
      </div>

      {moreEvents.length > 0 && (
        <section className="event-detail-more">
          <div className="event-detail-more-heading">
            <h2>More events</h2>
            <Link to="/events">All events →</Link>
          </div>
          <div className="event-detail-more-grid">
            {moreEvents.map((other) => (
              <EventListCard key={other.id} event={other} />
            ))}
          </div>
        </section>
      )}
    </>
  )
}

// Why tickets can't be bought, in one short line
function closedText(event) {
  if (event.status === 'sold_out') return 'Every ticket has gone.'
  if (event.status === 'not_on_sale_yet') {
    const opens = event.tiers
      .filter((tier) => tier.opensLater)
      .map((tier) => tier.salesOpen)
      .sort()[0]
    return `Tickets go on sale ${formatDate(opens)}.`
  }
  return 'Ticket sales have closed.'
}
