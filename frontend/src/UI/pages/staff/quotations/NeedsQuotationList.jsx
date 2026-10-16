import Button from '../../../components/Button'
import { formatDate } from '../../../../lib/dates'
import { receivedText } from '../enquiries/enquiryHelpers'
import './QuotationCard.css'
import './NeedsQuotationList.css'

// Enquiries needing a quotation. Reuses QuotationCard classes so both columns line up
export default function NeedsQuotationList({ enquiries }) {
  if (enquiries.length === 0) return <p className="needs-quotation-empty">Every enquiry has a quotation.</p>

  return (
    <ul className="needs-quotation-list">
      {enquiries.map((enquiry) => (
        <li key={enquiry.reference} className="quotation-card needs-quotation-card">
          <div className="quotation-card-main">
            <p className="quotation-card-title">{enquiry.guest}</p>
            <p className="quotation-card-summary needs-quotation-detail">
              {enquiry.occasion} · {enquiry.estimatedGuests} guests · {formatDate(enquiry.preferredDate)}
            </p>
            <p className="quotation-card-status-line is-new">Received {receivedText(enquiry.received)}</p>
          </div>
          <Button variant="secondary" size="sm" to={`/staff/quotations/new?booking=${enquiry.reference}`}>
            Create →
          </Button>
        </li>
      ))}
    </ul>
  )
}
