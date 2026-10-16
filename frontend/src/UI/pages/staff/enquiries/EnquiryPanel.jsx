import { useState } from 'react'
import Button from '../../../components/Button'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import DetailFacts from '../components/DetailFacts'
import DetailPanel from '../components/DetailPanel'
import EnquiryDetails from './EnquiryDetails'
import { briefFacts, enquiryStageText, isOpenEnquiry, receivedFullText } from './enquiryHelpers'
import './EnquiryPanel.css'

export default function EnquiryPanel({ enquiry, isOpen, onClose }) {
  const [isDetailsOpen, setIsDetailsOpen] = useState(false) // the "Open full enquiry" popup

  return (
    <DetailPanel className="enquiry-panel" isOpen={isOpen} onClose={onClose} backLabel="Back to enquiries">
      <div>
        <div className="enquiry-panel-top">
          <h2 className="enquiry-panel-name">{enquiry.guest}</h2>
          <StatusChip statuses={BOOKING_STATUSES} status={enquiry.status}>{enquiryStageText(enquiry)}</StatusChip>
        </div>
        <p className="enquiry-panel-contact">
          {enquiry.reference} · {enquiry.contact}
        </p>
      </div>

      <section className="enquiry-brief">
        <div className="enquiry-brief-header">
          <h3 className="enquiry-brief-title">Brief · as submitted</h3>
          <button type="button" className="link-button enquiry-brief-link" onClick={() => setIsDetailsOpen(true)}>
            Open full enquiry →
          </button>
        </div>

        <DetailFacts facts={briefFacts(enquiry)} columns={2} />

        {/* The guest's own words, quoted as they wrote them */}
        <blockquote className="enquiry-brief-notes">“{enquiry.requirements}”</blockquote>
      </section>

      {/* The guest's latest change request; the full list is in the popup */}
      {enquiry.changeRequests?.length > 0 && (
        <section className="enquiry-brief">
          <h3 className="enquiry-brief-title">Requested changes</h3>
          <blockquote className="enquiry-brief-notes">“{enquiry.changeRequests[0].note}”</blockquote>
          <p className="enquiry-panel-change-date">
            Asked {receivedFullText(enquiry.changeRequests[0].at)}
            {enquiry.changeRequests.length > 1 && ` · ${enquiry.changeRequests.length - 1} earlier in the full enquiry`}
          </p>
        </section>
      )}

      <div className="enquiry-panel-actions">
        {/* Quotations start here (a new version if one exists) */}
        {isOpenEnquiry(enquiry) && (
          <Button to={`/staff/quotations/new?booking=${enquiry.reference}`}>Create quotation</Button>
        )}
        {enquiry.quote && (
          <Button variant="secondary" to={`/staff/quotations/${enquiry.quote.reference}`}>
            Open quotation
          </Button>
        )}
        {isOpenEnquiry(enquiry) && (
          <button type="button" className="link-button enquiry-panel-cancel">
            Cancel enquiry
          </button>
        )}
      </div>

      {/* Mounted only while open */}
      {isDetailsOpen && <EnquiryDetails enquiry={enquiry} onClose={() => setIsDetailsOpen(false)} />}
    </DetailPanel>
  )
}
