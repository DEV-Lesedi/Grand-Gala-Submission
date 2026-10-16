import { formatRand } from '../../../../lib/money'
import { quotationTotals } from '../../../../lib/pricing'
import { withoutMinus } from './quotationHelpers'
import './QuotationTotals.css'

// Totals from the lines. onDiscountChange: discount is an input
export default function QuotationTotals({ items, discount, onDiscountChange }) {
  const totals = quotationTotals(items, discount)

  return (
    <div className="quotation-totals">
      <p className="quotation-totals-row">
        <span>Line items</span>
        <span>{formatRand(totals.lineItems)}</span>
      </p>

      {onDiscountChange ? (
        <label className="quotation-totals-row is-discount">
          <span>Goodwill discount</span>
          <span className="quotation-discount-input">
            – R
            <input type="number" min="0" inputMode="numeric" value={discount} onChange={(event) => onDiscountChange(withoutMinus(event.target.value))} />
          </span>
        </label>
      ) : (
        totals.discount > 0 && (
          <p className="quotation-totals-row is-discount">
            <span>Goodwill discount</span>
            <span>– {formatRand(totals.discount)}</span>
          </p>
        )
      )}

      <p className="quotation-totals-row">
        <span>VAT 15% (included)</span>
        <span>{formatRand(totals.vat)}</span>
      </p>

      <p className="quotation-totals-row is-total">
        <span>Total due</span>
        <span>{formatRand(totals.total)}</span>
      </p>
    </div>
  )
}
