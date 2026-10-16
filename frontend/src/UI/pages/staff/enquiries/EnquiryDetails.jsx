import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import StatusChip from '../../../components/StatusChip'
import DetailFacts from '../components/DetailFacts'
import { BOOKING_STATUSES, QUOTATION_STATUSES, statusLabel } from '../../../../lib/statuses'
import { enquiryStageText, fullEnquirySections, isOpenEnquiry, receivedFullText } from './enquiryHelpers'
import './EnquiryDetails.css'

// showQuotationButtons: false inside the builder (they'd link back to it)
export default function EnquiryDetails({ enquiry, onClose, showQuotationButtons = true }) {

  const title = (
    <span className="enquiry-details-title">
      {enquiry.guest}
      <StatusChip statuses={BOOKING_STATUSES} status={enquiry.status}>{enquiryStageText(enquiry)}</StatusChip>
    </span>
  )

  // Same actions as the panel
  const footer = showQuotationButtons && (
    <>
      {enquiry.quote && (
        <Button variant="secondary" to={`/staff/quotations/${enquiry.quote.reference}`}>
          Open quotation
        </Button>
      )}
      {isOpenEnquiry(enquiry) && (
        <Button to={`/staff/quotations/new?booking=${enquiry.reference}`}>Create quotation</Button>
      )}
    </>
  )

  return (
    <Modal
      title={title}
      subtitle={`${enquiry.reference} · received ${receivedFullText(enquiry.received)}`}
      footer={footer}
      onClose={onClose}
    >
      <div className="enquiry-details">
        {fullEnquirySections(enquiry).map((section) => (
          <section key={section.title} className="enquiry-details-section">
            <h3 className="enquiry-details-heading">{section.title}</h3>
            <DetailFacts facts={section.facts} columns={2} />
          </section>
        ))}

        <section className="enquiry-details-section">
          <h3 className="enquiry-details-heading">Notes and requirements</h3>
          <blockquote className="enquiry-details-notes">“{enquiry.requirements}”</blockquote>
        </section>

        {/* What the guest asked to change on a quotation, newest first */}
        {enquiry.changeRequests?.length > 0 && (
          <section className="enquiry-details-section">
            <h3 className="enquiry-details-heading">Requested changes</h3>
            <ul className="enquiry-details-changes">
              {enquiry.changeRequests.map((change) => (
                <li key={change.at + change.note}>
                  <blockquote className="enquiry-details-notes">“{change.note}”</blockquote>
                  <p className="enquiry-details-change-date">Asked {receivedFullText(change.at)}</p>
                </li>
              ))}
            </ul>
          </section>
        )}

        <section className="enquiry-details-section">
          <h3 className="enquiry-details-heading">Quotation</h3>
          <p className="enquiry-details-quote">
            {enquiry.quote
              ? `${enquiry.quote.reference} · v${enquiry.quote.version} · ${statusLabel(QUOTATION_STATUSES, enquiry.quote.status)}`
              : 'No quotation sent yet'}
          </p>
        </section>
      </div>
    </Modal>
  )
}
