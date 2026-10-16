import { Link, useParams } from 'react-router-dom'
import EmptyState from '../../../components/EmptyState'
import Loaded from '../../../components/Loaded'
import StatusChip from '../../../components/StatusChip'
import QuotationDecision from './QuotationDecision'
import { useApiData } from '../../../hooks/useApiData'
import { apiGet, orNullOn404 } from '../../../../services/api'
import { TODAY, formatDate, formatDayDate, nightsBetween } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { QUOTATION_STATUSES, guestQuotationStatus } from '../../../../lib/statuses'
import './QuotationReviewPage.css'

// Facts row: "Garden Room, 2 nights · Pool Room, 2 nights".
function stayText(rooms) {
  if (!rooms || rooms.length === 0) return 'None'
  return rooms
    .map((room) => {
      const nights = nightsBetween(room.checkIn, room.checkOut)
      return `${room.space}, ${nights} ${nights === 1 ? 'night' : 'nights'}`
    })
    .join(' · ')
}

// /quotations/<reference>. Another guest's quote, or a bad reference, is not found.
export default function QuotationReviewPage() {
  const { reference } = useParams()
  const loaded = useApiData(() => orNullOn404(apiGet('/me/quotations/' + encodeURIComponent(reference))), [reference])

  return (
    <div className="guest-quotation">
      <Loaded
        state={loaded}
        what="this quotation"
        notFound={
          <EmptyState
            titleAs="h1"
            title="Quotation not found"
            description="The link may be wrong. Your quotations are in My bookings."
            actionLabel="My bookings"
            actionTo="/my-bookings?tab=quotations"
          />
        }
      >
        {(quotation) => <QuotationReview quotation={quotation} onChanged={loaded.reload} />}
      </Loaded>
    </div>
  )
}

function QuotationReview({ quotation, onChanged }) {
  const status = guestQuotationStatus(quotation, TODAY) // sent, accepted, paid, expired, declined
  const facts = [
    ...(quotation.eventDate
      ? [{ label: 'Date and hours', value: `${formatDayDate(quotation.eventDate)} · ${quotation.startTime} – ${quotation.endTime}` }]
      : []),
    { label: 'Spaces', value: (quotation.spaces ?? []).map((space) => space.name).join(' + ') || '—' },
    { label: 'Accommodation', value: stayText(quotation.rooms) },
  ]

  return (
    <>
      <header className="guest-quotation-header">
        <div>
          <p className="guest-quotation-crumb">
            <Link to="/my-bookings?tab=quotations">My bookings</Link> / {quotation.reference}
          </p>
          <div className="guest-quotation-title">
            <h1>Quotation · {quotation.bookingReference}</h1>
            <StatusChip statuses={QUOTATION_STATUSES} status={status} audience="guest" />
          </div>
          <p className="guest-quotation-issued">Sent {formatDate(quotation.sentDate)}</p>
        </div>

        {/* Every version: older ones were replaced by a newer one, never changed */}
        <ul className="guest-quotation-versions" aria-label="Versions">
          {quotation.previousVersions.map((old) => (
            <li key={old.version}>
              v{old.version} · {formatDate(old.date)} · replaced
            </li>
          ))}
          <li className="is-current">
            v{quotation.version} · {formatDate(quotation.sentDate)} · current
          </li>
        </ul>
      </header>

      <div className="guest-quotation-body">
        <div className="guest-quotation-main">
          <dl className="guest-quotation-facts">
            {facts.map((fact) => (
              <div key={fact.label}>
                <dt>{fact.label}</dt>
                <dd>{fact.value}</dd>
              </div>
            ))}
          </dl>

          <table className="guest-quotation-lines">
            <thead>
              <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              {quotation.items.map((item) => (
                <tr key={item.position ?? item.label}>
                  <td>
                    <strong>{item.label}</strong>
                  </td>
                  <td>{item.quantity}</td>
                  <td>{formatRand(item.unitPrice)}</td>
                  <td>
                    <strong>{formatRand(item.amount)}</strong>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {quotation.notes && (
            <section className="guest-quotation-text">
              <h2>Notes</h2>
              <p>{quotation.notes}</p>
            </section>
          )}
          <section className="guest-quotation-text">
            <h2>Terms</h2>
            <p>Pay in full to confirm the date. This quotation is valid until {formatDate(quotation.validUntil)}.</p>
          </section>
        </div>

        <aside className="guest-quotation-side">
          <dl className="guest-quotation-totals">
            <div>
              <dt>Subtotal</dt>
              <dd>{formatRand(quotation.subtotal)}</dd>
            </div>
            {quotation.discount > 0 && (
              <div>
                <dt>Goodwill discount</dt>
                <dd>– {formatRand(quotation.discount)}</dd>
              </div>
            )}
            <div>
              <dt>VAT 15% (incl.)</dt>
              <dd>{formatRand(quotation.vatIncluded)}</dd>
            </div>
            <div className="is-total">
              <dt>Total</dt>
              <dd>{formatRand(quotation.total)}</dd>
            </div>
          </dl>

          <QuotationDecision
            quotation={quotation}
            status={status}
            total={quotation.total}
            onChanged={onChanged}
          />
        </aside>
      </div>
    </>
  )
}
