import { Link } from 'react-router-dom'
import StatusChip from '../../../components/StatusChip'
import { QUOTATION_STATUSES } from '../../../../lib/statuses'
import { formatRand } from '../../../../lib/money'
import { quotationStatusLine, quotationSummary } from './quotationHelpers'
import './QuotationCard.css'

// enquiry is the guest and event (missing on old bookings).
export default function QuotationCard({ quotation, enquiry }) {
  const statusLine = quotationStatusLine(quotation)
  const total = quotation.total

  return (
    <Link to={`/staff/quotations/${quotation.reference}`} className="quotation-card">
      <div className="quotation-card-main">
        <p className="quotation-card-title">
          {quotation.reference} · {enquiry?.guest ?? quotation.bookingReference}
        </p>
        {enquiry && <p className="quotation-card-summary">{quotationSummary(quotation, enquiry)}</p>}
        <p className={`quotation-card-status-line is-${statusLine.tone}`}>{statusLine.text}</p>
      </div>
      <div className="quotation-card-side">
        <p className="quotation-card-total">{formatRand(total)}</p>
        <StatusChip statuses={QUOTATION_STATUSES} status={quotation.status} />
      </div>
    </Link>
  )
}
