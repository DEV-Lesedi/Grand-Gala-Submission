import { Link } from 'react-router-dom'
import Button from '../../../components/Button'
import { SkeletonCards } from '../../../components/Skeleton'
import EventListCard from './EventListCard'
import { couldNotLoad, useApiData } from '../../../hooks/useApiData'
import { loadGuestEvents } from '../../../../services/events'
import './EventListPage.css'

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// "Sep – Dec 2026" (or "Dec 2026 – Jan 2027" across a new year)
function monthRange(events) {
  if (events.length === 0) return ''
  const monthYear = (date) => [MONTHS[Number(date.slice(5, 7)) - 1], date.slice(0, 4)]
  const [firstMonth, firstYear] = monthYear(events[0].date)
  const [lastMonth, lastYear] = monthYear(events[events.length - 1].date)
  if (firstYear !== lastYear) return `${firstMonth} ${firstYear} – ${lastMonth} ${lastYear}`
  return firstMonth === lastMonth ? `${firstMonth} ${firstYear}` : `${firstMonth} – ${lastMonth} ${firstYear}`
}

// Phone hides the site header, so this page has its own top bar and no host band.
export default function EventListPage() {
  // From the API: upcoming public events, not cancelled, soonest first
  const loaded = useApiData(loadGuestEvents, [])
  const EVENTS = loaded.data ?? []

  return (
    <div className="event-list">
      <div className="event-list-content">
        <header className="event-list-header">
          <div>
            <p className="event-list-crumb">Home / Events</p>
            <h1>What’s on</h1>
            <p className="event-list-subtitle">Ticketed nights at Grand Gala Gardens.</p>
          </div>
          {EVENTS.length > 0 && (
            <p className="event-list-count">
              {EVENTS.length} events · {monthRange(EVENTS)}
            </p>
          )}
          <Link to="/enquire" className="event-list-phone-link">
            Enquire about hosting →
          </Link>
        </header>

        <div className="event-list-grid fade-in">
          {EVENTS.map((event) => (
            <EventListCard key={event.id} event={event} />
          ))}
        </div>
        {loaded.loading && !loaded.data && <SkeletonCards className="event-list-grid" count={3} />}
        {loaded.error && <p className="page-problem">{couldNotLoad('the events', loaded.error)}</p>}
        {loaded.data && EVENTS.length === 0 && <p className="event-list-empty">No events coming up. Check back soon.</p>}
      </div>

      <section className="event-list-host">
        <div className="event-list-host-inner">
          <div>
            <h2>Want to host your own event here?</h2>
            <p>Send a brief and we’ll quote.</p>
          </div>
          <Button to="/enquire">Enquire about hosting</Button>
        </div>
      </section>
    </div>
  )
}
