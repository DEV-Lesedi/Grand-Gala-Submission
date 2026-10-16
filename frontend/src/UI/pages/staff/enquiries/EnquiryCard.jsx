import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import { BOOKING_STATUSES } from '../../../../lib/statuses'
import { formatDate } from '../../../../lib/dates'
import { enquiryStageText, eventText } from './enquiryHelpers'
import './EnquiryCard.css'

export default function EnquiryCard({ enquiry, onClick }) {

  return (
    <Card as="button" type="button" className="enquiry-card" onClick={onClick}>
      <div className="enquiry-card-top">
        <p className="enquiry-card-guest">{enquiry.guest}</p>
        <StatusChip statuses={BOOKING_STATUSES} status={enquiry.status}>{enquiryStageText(enquiry)}</StatusChip>
      </div>
      <p className="enquiry-card-event">{eventText(enquiry)}</p>
      <p className="enquiry-card-meta">
        {formatDate(enquiry.preferredDate)} · {enquiry.estimatedGuests} guests
      </p>
    </Card>
  )
}
