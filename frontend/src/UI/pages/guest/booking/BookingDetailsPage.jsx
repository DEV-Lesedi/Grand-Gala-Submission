import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import Button from '../../../components/Button'
import EmptyState from '../../../components/EmptyState'
import Loaded from '../../../components/Loaded'
import StatusChip from '../../../components/StatusChip'
import { useApiData } from '../../../hooks/useApiData'
import { apiGet, apiPost, orNullOn404 } from '../../../../services/api'
import { formatDayDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { stayFacts } from '../../../../lib/stays'
import { MY_BOOKING_STATUSES } from '../../../../lib/statuses'
import { accommodationText, cateringText, decorText, receivedFullText, spacesText, timesText } from '../../staff/enquiries/enquiryHelpers'
import './BookingDetailsPage.css'

// /my-bookings/<reference>, the signed-in guest's own booking only.
export default function BookingDetailsPage() {
  const { reference } = useParams()
  // Someone else's booking, or a wrong reference, comes back as not found
  const loaded = useApiData(() => orNullOn404(apiGet('/me/bookings/' + encodeURIComponent(reference))), [reference])

  return (
    <div className="booking-details">
      <Loaded
        state={loaded}
        what="this booking"
        notFound={
          <EmptyState
            titleAs="h1"
            title="Booking not found"
            description="The link may be wrong. Your bookings are in My bookings."
            actionLabel="My bookings"
            actionTo="/my-bookings"
          />
        }
      >
        {(booking) => <BookingDetails booking={booking} onChanged={loaded.reload} />}
      </Loaded>
    </div>
  )
}

// onChanged reloads the booking after a cancel.
function BookingDetails({ booking, onChanged }) {
  const [cancelState, setCancelState] = useState({ busy: false, message: '', isError: false })

  // API decides: unpaid bookings, and paid stays sent for a refund review.
  async function cancel() {
    if (!window.confirm('Cancel this booking?')) return
    setCancelState({ busy: true, message: '', isError: false })
    try {
      const answer = await apiPost(`/me/bookings/${encodeURIComponent(booking.reference)}/cancel`)
      setCancelState({
        busy: false,
        isError: false,
        message: answer.refundReviewRequired ? 'Cancelled. We’ll review your refund and email you.' : 'Cancelled.',
      })
      onChanged()
    } catch (error) {
      setCancelState({ busy: false, message: error.message, isError: true })
    }
  }

  const isStay = booking.type === 'stay'
  const canPay = booking.status === 'pending_payment'
  const canCancel = ['enquiry', 'quote_sent', 'pending_payment'].includes(booking.status) || (isStay && booking.status === 'confirmed')
  const facts = isStay
    ? stayFacts(booking.title, booking.startDate, booking.endDate, booking.guests)
    : [
        { label: 'Date', value: booking.startDate ? formatDayDate(booking.startDate) : 'To agree' },
        ...(booking.guests ? [{ label: 'Guests', value: `${booking.guests} guests` }] : []),
      ]

  return (
    <>
      <header className="booking-details-header">
        <p className="booking-details-crumb">
          <Link to="/my-bookings">My bookings</Link> / {booking.reference}
        </p>
        <div className="booking-details-title">
          <h1>{booking.title}</h1>
          <StatusChip statuses={MY_BOOKING_STATUSES} status={booking.status} />
        </div>
      </header>

      <div className="booking-details-body">
        <section className="booking-details-main">
          <img src={booking.image ?? '/photos/villa.jpg'} alt="" />
          <dl className="booking-details-facts">
            {facts.map((fact) => (
              <div key={fact.label}>
                <dt>{fact.label}</dt>
                <dd>{fact.value}</dd>
              </div>
            ))}
            <div>
              <dt>Reference</dt>
              <dd>{booking.reference}</dd>
            </div>
          </dl>

          {booking.enquiry && <EnquirySummary enquiry={booking.enquiry} />}
        </section>

        <aside className="booking-details-side" aria-label="Receipt">
          <dl className="booking-details-lines">
            <div className="is-total">
              <dt>Total (ZAR)</dt>
              <dd>{booking.price === null ? 'To be quoted' : formatRand(booking.price)}</dd>
            </div>
          </dl>

          {booking.status === 'quote_sent' && <Button to={booking.actionTo}>Review quote</Button>}
          {canPay && <Button to={`/checkout?booking=${booking.reference}`}>Pay {formatRand(booking.price)}</Button>}
          {canCancel && (
            <Button variant="secondary" onClick={cancel} disabled={cancelState.busy}>
              {cancelState.busy ? 'Cancelling…' : 'Cancel booking'}
            </Button>
          )}
          {cancelState.message && (
            <p className={cancelState.isError ? 'page-problem' : 'booking-details-payment is-success'} role="status">
              {cancelState.message}
            </p>
          )}
          {/* Until a real PDF exists, the browser's print dialog saves the page as one */}
          <Button variant="secondary" onClick={() => window.print()}>
            Download receipt
          </Button>
          {/* Continue is primary once there's nothing left to pay. */}
          <Button variant={canPay ? 'secondary' : 'primary'} to="/my-bookings">
            Continue
          </Button>
        </aside>
      </div>
    </>
  )
}

// Enquiry answers, plus quote change requests, newest first.
function EnquirySummary({ enquiry }) {
  const facts = [
    { label: 'Occasion', value: enquiry.occasion },
    { label: 'Spaces', value: spacesText(enquiry) },
    { label: 'Preferred date', value: formatDayDate(enquiry.preferredDate) },
    { label: 'Alternate date', value: enquiry.alternateDate ? formatDayDate(enquiry.alternateDate) : 'None given' },
    ...(enquiry.startTime ? [{ label: 'Times', value: timesText(enquiry) }] : []),
    { label: 'Estimated guests', value: enquiry.estimatedGuests },
    { label: 'Catering', value: cateringText(enquiry) },
    { label: 'Décor', value: decorText(enquiry) },
    { label: 'Accommodation', value: accommodationText(enquiry) },
  ]

  return (
    <section className="booking-details-enquiry" aria-label="Your enquiry">
      <h2>Your enquiry</h2>
      <p className="booking-details-enquiry-sent">Sent {receivedFullText(enquiry.received)}</p>
      <dl className="booking-details-facts">
        {facts.map((fact) => (
          <div key={fact.label}>
            <dt>{fact.label}</dt>
            <dd>{fact.value}</dd>
          </div>
        ))}
      </dl>

      {enquiry.requirements && (
        <>
          <h3>Notes and requirements</h3>
          <blockquote className="booking-details-enquiry-note">“{enquiry.requirements}”</blockquote>
        </>
      )}

      {enquiry.changeRequests.length > 0 && (
        <>
          <h3>Changes you asked for</h3>
          <ul className="booking-details-enquiry-changes">
            {enquiry.changeRequests.map((change) => (
              <li key={change.at + change.note}>
                <blockquote className="booking-details-enquiry-note">“{change.note}”</blockquote>
                <p className="booking-details-enquiry-sent">Asked {receivedFullText(change.at)}</p>
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  )
}
