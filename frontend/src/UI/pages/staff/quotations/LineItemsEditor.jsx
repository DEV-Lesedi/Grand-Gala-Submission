import Card from '../../../components/Card'
import { formatRand } from '../../../../lib/money'
import { lineAmount } from '../../../../lib/pricing'
import { withoutMinus } from './quotationHelpers'
import './LineItemsEditor.css'

// The builder owns line state.
export default function LineItemsEditor({ items, onChange, onRemove, onAddLine, onAddFromRateCard, readOnly = false }) {
  return (
    <Card className="line-items">
      <div className="line-items-head" aria-hidden="true">
        <span>Line item</span>
        <span>Qty</span>
        <span>Unit</span>
        <span className="line-item-amount">Amount</span>
        <span />
      </div>

      {items.map((item, index) => (
        <div key={item.id} className="line-item">
          <div className="line-item-text">
            <input
              className="line-item-description"
              value={item.description}
              onChange={(event) => onChange(item.id, 'description', event.target.value)}
              placeholder="What is it? e.g. Buffet dinner"
              aria-label={`Line ${index + 1} description`}
              disabled={readOnly}
            />
            <input
              className="line-item-note"
              value={item.note}
              onChange={(event) => onChange(item.id, 'note', event.target.value)}
              placeholder="Note, e.g. per head"
              aria-label={`Line ${index + 1} note`}
              disabled={readOnly}
            />
          </div>

          <label className="line-item-field line-item-quantity">
            <span className="line-item-field-label">Qty</span>
            <input
              type="number"
              min="0"
              inputMode="numeric"
              value={item.quantity}
              aria-label={`Line ${index + 1} quantity`}
              onChange={(event) => onChange(item.id, 'quantity', withoutMinus(event.target.value))}
              disabled={readOnly}
            />
          </label>

          <label className="line-item-field line-item-unit">
            <span className="line-item-field-label">Unit</span>
            <span className="line-item-rand">
              R
              <input
                type="number"
                min="0"
                inputMode="numeric"
                value={item.unitPrice}
                aria-label={`Line ${index + 1} unit price`}
                onChange={(event) => onChange(item.id, 'unitPrice', withoutMinus(event.target.value))}
                disabled={readOnly}
              />
            </span>
          </label>

          <p className="line-item-amount">{formatRand(lineAmount(item))}</p>

          {!readOnly && (
            <button type="button" className="line-item-remove" onClick={() => onRemove(item.id)} aria-label={`Remove line ${index + 1}`}>
              ×
            </button>
          )}
        </div>
      ))}

      {items.length === 0 && <p className="line-items-empty">No lines yet. Add one, or pick from the rate card.</p>}

      {!readOnly && (
        <div className="line-items-add">
          <button type="button" className="link-button" onClick={onAddLine}>
            + Add line
          </button>
          <button type="button" className="link-button" onClick={onAddFromRateCard}>
            + Add from rate card
          </button>
        </div>
      )}
    </Card>
  )
}
