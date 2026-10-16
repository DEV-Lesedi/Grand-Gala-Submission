import Button from '../../../components/Button'
import { formatRand } from '../../../../lib/money'
import './CheckoutConfirmed.css'

// After payment: ticket orders show where tickets went; stays show the booking reference.
export default function CheckoutConfirmed({ order, fullName, email }) {
  const firstName = fullName.trim().split(' ')[0]
  const isTickets = order.kind === 'tickets'

  return (
    <div className="checkout-confirmed">
      <section className="checkout-confirmed-card" role="status">
        <span className="checkout-confirmed-tick" aria-hidden="true">
          ✓
        </span>
        <h1>You’re booked, {firstName}</h1>
        <p className="checkout-confirmed-sub">
          {isTickets ? (
            <>Tickets emailed to {email.trim()}</>
          ) : order.reference ? (
            <>
              Reference <strong>{order.reference}</strong> · confirmation emailed
            </>
          ) : (
            // A new stay gets its reference from the server; the email has it
            <>Confirmation emailed to {email.trim()}</>
          )}
        </p>

        <div className="checkout-confirmed-booking">
          <div className="checkout-confirmed-what">
            <img src={order.photo} alt="" />
            <div>
              <h2>{order.title}</h2>
              <dl>
                {order.facts.map((fact) => (
                  <div key={fact.label}>
                    <dt>{fact.label}</dt>
                    <dd>{fact.value}</dd>
                  </div>
                ))}
              </dl>
            </div>
          </div>

          <div className="checkout-confirmed-receipt">
            {order.lines.map((line) => (
              <p key={line.label}>
                <span>{line.label}</span>
                {/* A discount is negative: "– R3 000", as in the order summary */}
                <span>{line.amount < 0 ? `– ${formatRand(-line.amount)}` : formatRand(line.amount)}</span>
              </p>
            ))}
            <p className="checkout-confirmed-total">
              <span>Paid with PayFast</span>
              <strong>{formatRand(order.total)}</strong>
            </p>
          </div>
        </div>

        {order.nextStep && (
          <p className="checkout-confirmed-next">
            <strong>What’s next:</strong> {order.nextStep}
          </p>
        )}

        <div className="checkout-confirmed-actions">
          <Button
            className="checkout-confirmed-view"
            to={isTickets ? `/tickets/${order.reference}` : order.reference ? `/my-bookings/${order.reference}` : '/my-bookings'}
          >
            {isTickets ? 'View my tickets' : 'View booking'}
          </Button>
          {/* Until a real PDF exists, the browser's print dialog saves the page as one */}
          <Button variant="secondary" onClick={() => window.print()}>
            Download receipt
          </Button>
        </div>
      </section>
    </div>
  )
}
