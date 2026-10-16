import { useState } from 'react'
import Modal from '../../../components/Modal'
import { formatRand } from '../../../../lib/money'
import './AttendeeList.css'

// Paid orders only, newest first. Resend emails the tickets; Refund asks before it runs.
// The gate scans tk_code.
export default function AttendeeList({ event, orders, onRefund, onClose }) {
  const [confirming, setConfirming] = useState(null) // the reference being refunded, or null
  const [sentTo, setSentTo] = useState(null) // the order whose tickets were just resent
  // While another event's orders are still loading, show none of them
  const paid = orders.filter((order) => order.eventId === event.id)

  return (
    <Modal
      title={`Attendees · ${event.title}`}
      subtitle={`${event.ticketsSold} tickets · ${paid.length} paid orders`}
      onClose={onClose}
    >
      <ul className="attendee-list">
        {paid.map((order) => (
          <li key={order.reference} className="attendee">
            <span>
              <span className="attendee-name">
                {order.buyerName} {order.buyerSurname}
              </span>
              <span className="attendee-detail">{order.buyerEmail}</span>
            </span>
            <span className="attendee-tickets">
              {order.items.map((item) => `${item.quantity} × ${item.name}`).join(', ')}
              <span className="attendee-detail">
                {order.reference} · paid
              </span>
              {confirming === order.reference ? (
                <span className="attendee-confirm">
                  <button type="button" className="link-button attendee-refund" onClick={() => onRefund(order.reference)}>
                    Yes, refund {formatRand(order.total)}
                  </button>
                  <button type="button" className="link-button" onClick={() => setConfirming(null)}>
                    Keep
                  </button>
                </span>
              ) : (
                <span className="attendee-actions">
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => setSentTo(order.reference)}
                    aria-label={`Resend tickets ${order.reference}`}
                  >
                    Resend
                  </button>
                  <button
                    type="button"
                    className="link-button"
                    onClick={() => setConfirming(order.reference)}
                    aria-label={`Refund ${order.reference}`}
                  >
                    Refund
                  </button>
                </span>
              )}
              {/* Emails the buyer their ticket codes again */}
              {sentTo === order.reference && <span className="attendee-sent">Tickets sent to {order.buyerEmail}</span>}
            </span>
          </li>
        ))}
        {paid.length === 0 && <li className="attendee-empty">No tickets sold yet.</li>}
      </ul>
    </Modal>
  )
}
