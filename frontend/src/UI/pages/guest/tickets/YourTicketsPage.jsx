import { Link, useParams } from 'react-router-dom'
import Button from '../../../components/Button'
import EmptyState from '../../../components/EmptyState'
import Loaded from '../../../components/Loaded'
import StatusChip from '../../../components/StatusChip'
import { useApiData } from '../../../hooks/useApiData'
import { useGuestUser } from '../../../hooks/guestUser'
import { loadTicketOrder } from '../../../../services/ticketOrders'
import { formatDayDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { TICKET_ORDER_STATUSES } from '../../../../lib/statuses'
import './YourTicketsPage.css'

// One ticket order: a QR card per ticket, then the receipt. /tickets/<reference>
export default function YourTicketsPage() {
  const { reference } = useParams()
  const linkToken = new URLSearchParams(window.location.search).get('token')
  // Buyer's order (signed in, checkout token, or email ?token=), or null
  const loaded = useApiData(() => loadTicketOrder(reference, linkToken), [reference, linkToken])

  return (
    <div className="your-tickets">
      <Loaded
        state={loaded}
        what="your tickets"
        notFound={
          <EmptyState
            titleAs="h1"
            title="Tickets not found"
            description="The link may be wrong. Your tickets are in My bookings."
            actionLabel="My bookings"
            actionTo="/my-bookings?tab=tickets"
          />
        }
      >
        {(order) => <YourTickets order={order} />}
      </Loaded>
    </div>
  )
}

function YourTickets({ order }) {
  const { user } = useGuestUser()
  const isPaid = order.status === 'paid'
  // Paid: issued tickets with codes. Unpaid: one placeholder per seat held.
  const tickets = isPaid
    ? (order.tickets ?? []).map((ticket) => ({ id: ticket.code, tierName: ticket.tier, qr: ticket.qrDataUri, status: ticket.status }))
    : (order.items ?? []).flatMap((item) =>
        Array.from({ length: item.quantity }, (_, index) => ({ id: `${item.tierId}-${index}`, tierName: item.name, qr: null })),
      )

  return (
    <>
      <header className="your-tickets-header">
        <p className="your-tickets-crumb">
          <Link to="/my-bookings?tab=tickets">My bookings</Link> / {order.reference}
        </p>
        <div className="your-tickets-title">
          <h1>{order.eventTitle}</h1>
          <StatusChip statuses={TICKET_ORDER_STATUSES} status={order.status} />
        </div>
        <p className="your-tickets-when">
          {formatDayDate(order.eventDate)} · gates {order.eventStartTime} · Grand Gala Gardens
        </p>
      </header>

      <div className="your-tickets-body">
        <section aria-label="Tickets">
          <ul className="your-tickets-list">
            {tickets.map((ticket) => (
              <li key={ticket.id} className={`your-ticket ${isPaid && ticket.status !== 'cancelled' ? '' : 'is-void'}`.trim()}>
                {ticket.qr ? <img src={ticket.qr} alt={`QR code for ticket ${ticket.id}`} /> : <img src="/photos/event.jpg" alt="" />}
                <div>
                  <strong>{ticket.tierName}</strong>
                  {isPaid && <span className="your-ticket-number">{ticket.id.slice(0, 12).toUpperCase()}</span>}
                  {ticket.status === 'used' && <span>Scanned at the gate</span>}
                </div>
              </li>
            ))}
          </ul>
          {isPaid && <p className="your-tickets-note">Show these QR codes (or the ones in your email) at the gate.</p>}
          {order.status === 'pending_payment' && (
            <p className="your-tickets-note">
              Not paid yet. <Link to={`/checkout?event=${order.eventId}`}>Choose tickets again</Link> if your hold has run out.
            </p>
          )}
        </section>

        <aside className="your-tickets-side" aria-label="Receipt">
          <dl>
            {(order.items ?? []).map((item) => (
              <div key={item.tierId}>
                <dt>
                  {item.name} × {item.quantity}
                </dt>
                <dd>{formatRand(item.quantity * item.unitPrice)}</dd>
              </div>
            ))}
            {order.discount > 0 && (
              <div>
                <dt>Discount</dt>
                <dd>– {formatRand(order.discount)}</dd>
              </div>
            )}
            <div className="is-total">
              <dt>Total (ZAR)</dt>
              <dd>{formatRand(order.total)}</dd>
            </div>
          </dl>
          {isPaid && <p className="your-tickets-paid">Paid · PayFast</p>}
          {order.status === 'refunded' && <p className="your-tickets-paid">Refunded</p>}
          <Button variant="secondary" onClick={() => window.print()}>
            Print tickets
          </Button>
          {/* No account: back to events. Otherwise back to tickets. */}
          <Button variant={isPaid ? 'primary' : 'secondary'} to={user ? '/my-bookings?tab=tickets' : '/events'}>
            Continue
          </Button>
        </aside>
      </div>
    </>
  )
}
