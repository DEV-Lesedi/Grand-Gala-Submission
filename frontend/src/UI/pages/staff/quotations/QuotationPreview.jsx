import Modal from '../../../components/Modal'
import { formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import QuotationTotals from './QuotationTotals'
import { lineAmount, toNumber } from '../../../../lib/pricing'
import './QuotationPreview.css'

// The quotation as the guest will see it (read-only)
export default function QuotationPreview({ enquiry, version, items, discount, notes, validUntil, onClose }) {
  // Skip blank lines
  const visibleItems = items.filter((item) => item.description.trim() !== '' || lineAmount(item) > 0)

  return (
    <Modal
      title={`Your quotation · v${version}`}
      subtitle={`For ${enquiry.fullName} · ${enquiry.occasion} · ${formatDate(enquiry.preferredDate)} · ${enquiry.estimatedGuests} guests`}
      onClose={onClose}
    >
      <div className="quotation-preview">
        <ul className="quotation-preview-lines">
          {visibleItems.map((item) => (
            <li key={item.id}>
              <span>
                <span className="quotation-preview-name">{item.description}</span>
                <span className="quotation-preview-detail">
                  {toNumber(item.quantity)} × {formatRand(toNumber(item.unitPrice))}
                  {item.note && ` · ${item.note}`}
                </span>
              </span>
              <span className="quotation-preview-amount">{formatRand(lineAmount(item))}</span>
            </li>
          ))}
        </ul>

        <QuotationTotals items={visibleItems} discount={discount} />

        {notes.trim() && <p className="quotation-preview-notes">{notes}</p>}
        {/* Date can be empty while editing */}
        <p className="quotation-preview-valid">{validUntil ? `Valid until ${formatDate(validUntil)}` : 'No valid-until date set'}</p>
      </div>
    </Modal>
  )
}
