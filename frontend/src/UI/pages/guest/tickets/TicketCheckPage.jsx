import { useParams } from 'react-router-dom'
import { useApiData } from '../../../hooks/useApiData'
import { apiGet, orNullOn404 } from '../../../../services/api'
import { formatDayDate } from '../../../../lib/dates'
import './TicketCheckPage.css'

// What each ticket status means at the gate: the big line, and its colour
const STATES = {
  valid: { title: 'Valid ticket', mark: '✓', tone: 'success' },
  used: { title: 'Already scanned', mark: '!', tone: 'warning' },
  cancelled: { title: 'Cancelled ticket', mark: '✕', tone: 'danger' },
  refunded: { title: 'Refunded ticket', mark: '✕', tone: 'danger' },
}

// Public /ticket/<code> page. API shows only first name and surname initial.
export default function TicketCheckPage() {
  const { code } = useParams()
  // The ticket, or null when the code isn't a ticket
  const loaded = useApiData(() => orNullOn404(apiGet('/tickets/' + encodeURIComponent(code))), [code])
  const ticket = loaded.data

  if (loaded.loading && !ticket) {
    return (
      <div className="ticket-check">
        <p className="page-loading">Checking the ticket…</p>
      </div>
    )
  }

  if (loaded.error) {
    return (
      <div className="ticket-check">
        <section className="ticket-check-card is-danger" role="status">
          <span className="ticket-check-mark" aria-hidden="true">!</span>
          <h1>We couldn’t check this ticket</h1>
          <p>{loaded.error.message}</p>
        </section>
      </div>
    )
  }

  if (!ticket) {
    return (
      <div className="ticket-check">
        <section className="ticket-check-card is-danger" role="status">
          <span className="ticket-check-mark" aria-hidden="true">✕</span>
          <h1>Not a ticket</h1>
          <p>This code doesn’t match any Grand Gala Gardens ticket.</p>
        </section>
      </div>
    )
  }

  const state = STATES[ticket.status] ?? STATES.cancelled
  const usedTime = ticket.usedAt ? ticket.usedAt.replace('T', ' at ') : null

  return (
    <div className="ticket-check">
      <section className={`ticket-check-card is-${state.tone}`} role="status">
        <span className="ticket-check-mark" aria-hidden="true">
          {state.mark}
        </span>
        <h1>{state.title}</h1>
        {usedTime && <p className="ticket-check-note">Scanned at the gate on {usedTime}.</p>}

        <dl className="ticket-check-facts">
          <div>
            <dt>Name</dt>
            <dd>{ticket.holder}</dd>
          </div>
          <div>
            <dt>Event</dt>
            <dd>{ticket.event.title}</dd>
          </div>
          <div>
            <dt>When</dt>
            <dd>
              {formatDayDate(ticket.event.date)} · gates {ticket.event.startTime}
            </dd>
          </div>
          <div>
            <dt>Ticket</dt>
            <dd>{ticket.tier}</dd>
          </div>
        </dl>
        <p className="ticket-check-code">{code.slice(0, 12).toUpperCase()}</p>
      </section>
    </div>
  )
}
