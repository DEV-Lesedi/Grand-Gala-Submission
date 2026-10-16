import { useState } from 'react'
import Button from '../../../components/Button'
import { checkoutLink, ticketTotal } from '../../../../lib/eventTickets'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './TicketPicker.css'

// Starts at 0. Phone keeps the total in a bar stuck to the bottom.
export default function TicketPicker({ event }) {
  const [quantities, setQuantities] = useState({}) // { tierId: how many }, missing = 0
  const { count, total } = ticketTotal(event.tiers, quantities)

  // The most of one tier in one order: what's left, and the event's per-order limit
  const most = (tier) => Math.min(tier.left, tier.maxPerOrder ?? tier.left)

  // Never below 0, never more than the most allowed
  function change(tier, amount) {
    const next = Math.min(most(tier), Math.max(0, (quantities[tier.id] ?? 0) + amount))
    setQuantities({ ...quantities, [tier.id]: next })
  }

  const continueButton =
    count > 0 ? (
      <Button to={checkoutLink(event, quantities)} className="ticket-picker-continue">
        Continue to payment
      </Button>
    ) : (
      <Button className="ticket-picker-continue" disabled>
        Choose tickets
      </Button>
    )

  return (
    <aside className="ticket-picker">
      {/* Desktop: the "From" price. Phone: a plain heading (the price is on each row) */}
      <h2 className="ticket-picker-heading">Tickets</h2>
      {event.fromPrice !== null && (
        <p className="ticket-picker-from">
          From {formatRand(event.fromPrice)} <span>per ticket</span>
        </p>
      )}

      <ul className="ticket-picker-tiers">
        {event.tiers.map((tier) => {
          const quantity = quantities[tier.id] ?? 0
          return (
            <li key={tier.id} className="ticket-tier">
              <span className="ticket-tier-text">
                <strong>{tier.name}</strong>
                <span>{tier.description}</span>
              </span>
              <span className="ticket-tier-price">{formatRand(tier.price)}</span>

              {tier.canBuy ? (
                <span className="ticket-tier-stepper">
                  <button
                    type="button"
                    onClick={() => change(tier, -1)}
                    disabled={quantity === 0}
                    aria-label={`One less ${tier.name}`}
                  >
                    −
                  </button>
                  {/* aria-live: screen readers hear the new number after each press */}
                  <output aria-live="polite" aria-label={`${tier.name} tickets`}>
                    {quantity}
                  </output>
                  <button
                    type="button"
                    onClick={() => change(tier, 1)}
                    disabled={quantity >= most(tier)}
                    aria-label={`One more ${tier.name}`}
                  >
                    +
                  </button>
                </span>
              ) : (
                <span className="ticket-tier-note">
                  {tier.opensLater ? `Opens ${formatDate(tier.salesOpen)}` : tier.left === 0 ? 'Sold out' : 'Closed'}
                </span>
              )}
            </li>
          )
        })}
      </ul>

      <p className="ticket-picker-total">
        <span>
          {count} {count === 1 ? 'ticket' : 'tickets'}
        </span>
        <strong>{formatRand(total)}</strong>
      </p>
      {continueButton}

      {/* Phone: the total and button stay at the bottom of the screen while scrolling */}
      <div className="ticket-picker-bar">
        <span>
          <strong>{formatRand(total)}</strong>
          {count} {count === 1 ? 'ticket' : 'tickets'}
        </span>
        {continueButton}
      </div>
    </aside>
  )
}
