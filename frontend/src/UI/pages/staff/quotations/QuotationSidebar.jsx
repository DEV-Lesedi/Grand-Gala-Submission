import { useState } from 'react'
import Card from '../../../components/Card'
import EnquiryDetails from '../enquiries/EnquiryDetails'
import { formatDate } from '../../../../lib/dates'
import './QuotationSidebar.css'

export default function QuotationSidebar({ quotation, enquiry }) {
  const [isEnquiryOpen, setIsEnquiryOpen] = useState(false)

  // "v1 superseded 01 Sep 2026", "v2 sent 01 Sep 2026"
  const history = quotation
    ? [
        ...quotation.previousVersions.map((old) => `v${old.version} ${old.status} ${formatDate(old.date)}`),
        `v${quotation.version} sent ${formatDate(quotation.sentDate)}`,
      ]
    : ['No quotation sent yet']

  return (
    <aside className="quotation-sidebar">
      <Card className="quotation-sidebar-card quotation-guest">
        <div className="quotation-sidebar-header">
          <h2>Guest</h2>
          <button type="button" className="link-button quotation-sidebar-link" onClick={() => setIsEnquiryOpen(true)}>
            Open enquiry →
          </button>
        </div>
        <p className="quotation-guest-name">{enquiry.guest}</p>
        <p className="quotation-guest-contact">{enquiry.contact}</p>
        {history.map((line) => (
          <p key={line} className="quotation-guest-history">
            {line}
          </p>
        ))}
      </Card>

      {/* No quotation buttons (they'd link back here) */}
      {isEnquiryOpen && (
        <EnquiryDetails enquiry={enquiry} onClose={() => setIsEnquiryOpen(false)} showQuotationButtons={false} />
      )}
    </aside>
  )
}
