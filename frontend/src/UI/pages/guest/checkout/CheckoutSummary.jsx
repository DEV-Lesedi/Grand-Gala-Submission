import { formatRand } from '../../../../lib/money'
import './CheckoutSummary.css'

// A negative line (a discount) shows as "– R3 000".
export default function CheckoutSummary({ order }) {
  return (
    <aside className="checkout-summary" aria-label="Your order">
      <div className="checkout-summary-top">
        <img src={order.photo} alt="" />
        <span>
          <strong>{order.title}</strong>
          <span>{order.subtitle}</span>
        </span>
      </div>

      <dl className="checkout-summary-lines">
        {order.lines.map((line) => (
          <div key={line.label}>
            <dt>{line.label}</dt>
            <dd>{line.amount < 0 ? `– ${formatRand(-line.amount)}` : formatRand(line.amount)}</dd>
          </div>
        ))}
        <div className="is-total">
          <dt>Total (ZAR)</dt>
          <dd>{formatRand(order.total)}</dd>
        </div>
      </dl>
    </aside>
  )
}
