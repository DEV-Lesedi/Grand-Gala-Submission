import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import StatusChip from '../../../components/StatusChip'
import StaffPageHeader from '../components/StaffPageHeader'
import LineItemsEditor from './LineItemsEditor'
import QuotationPreview from './QuotationPreview'
import QuotationSidebar from './QuotationSidebar'
import QuotationTotals from './QuotationTotals'
import RateCardPicker from './RateCardPicker'
import { canRevise } from './quotationHelpers'
import { QUOTATION_STATUSES, statusLabel } from '../../../../lib/statuses'
import { TODAY, addDays, formatDate } from '../../../../lib/dates'
import { DAYS_VALID, DEFAULT_GUEST_NOTE } from '../../../../lib/staff/quotationsData'
import { newId } from '../../../../lib/ids'
import { sendQuotation } from '../../../../services/staffQuotations'
import './QuotationBuilder.css'
import DatePicker from '../../../components/DatePicker'

// quotation is the latest version, or null for the first.
export default function QuotationBuilder({ quotation, enquiry }) {
  const navigate = useNavigate()

  // Draft starts from the latest version.
  const [items, setItems] = useState(quotation ? quotation.items : [])
  const [discount, setDiscount] = useState(quotation ? quotation.discount : 0)
  const [notes, setNotes] = useState(quotation ? quotation.notes : DEFAULT_GUEST_NOTE)
  const [validUntil, setValidUntil] = useState(
    quotation && quotation.status !== 'expired' ? quotation.validUntil : addDays(TODAY, DAYS_VALID),
  )
  const [openPopup, setOpenPopup] = useState(null) // which popup is open: null, 'rateCard' or 'preview'
  const [sentVersion, setSentVersion] = useState(null) // set once the API has saved the new version
  const [sentReference, setSentReference] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState('')

  // Saves the next version and emails it. The API numbers it and replaces the last.
  async function send() {
    setSending(true)
    setSendError('')
    try {
      const saved = await sendQuotation({ bookingReference: enquiry.reference, items, discount, notes, validUntil })
      setSentVersion(saved.version)
      setSentReference(saved.reference)
    } catch (error) {
      setSendError(error.message)
    }
    setSending(false)
  }

  const nextVersion = quotation ? quotation.version + 1 : 1
  const isFinal = quotation !== null && !canRevise(quotation) // accepted: can only be looked at
  const readOnly = isFinal || sentVersion !== null
  const hasLines = items.length > 0
  // Date set and not in the past (min doesn't stop typing)
  const hasValidDate = validUntil !== '' && validUntil >= TODAY
  const canSend = !readOnly && hasLines && hasValidDate && !sending

  // New arrays only; don't mutate line state.

  function changeItem(id, field, value) {
    setItems(items.map((item) => (item.id === id ? { ...item, [field]: value } : item)))
  }

  function removeItem(id) {
    setItems(items.filter((item) => item.id !== id))
  }

  // newId() gives each new line a unique id, for its React key
  function addItem(line) {
    setItems([...items, { id: newId('line'), ...line }])
  }

  function addBlankLine() {
    addItem({ description: '', note: '', quantity: 1, unitPrice: 0 })
  }

  // Per head: start at the guest count
  function addFromRateCard(rate) {
    addItem({
      description: rate.description,
      note: rate.note,
      quantity: rate.perHead ? enquiry.estimatedGuests : 1,
      unitPrice: rate.unitPrice,
    })
  }

  // Back to where staff came from; opened directly → the list
  function goBack() {
    const visitNumber = window.history.state?.idx ?? 0
    if (visitNumber > 0) navigate(-1)
    else navigate('/staff/quotations')
  }


  const title = (
    <span className="quotation-builder-title">
      {quotation ? `Quotation ${quotation.reference} · v${quotation.version}` : 'New quotation'}
      {quotation ? (
        <StatusChip statuses={QUOTATION_STATUSES} status={quotation.status} />
      ) : (
        <StatusChip statuses={QUOTATION_STATUSES} status="draft" />
      )}
    </span>
  )
  const subtitle = `${enquiry.guest} · ${enquiry.occasion.toLowerCase()} · ${formatDate(enquiry.preferredDate)} · ${enquiry.estimatedGuests} guests`

  // Desktop header and phone bottom buttons.
  // The API numbers the version, replaces the old one, and marks the booking quoted.
  function actionButtons(className) {
    return (
      <div className={className}>
        <Button variant="secondary" onClick={() => setOpenPopup('preview')} disabled={!hasLines}>
          Preview as guest
        </Button>
        {!isFinal && (
          <Button onClick={send} disabled={!canSend}>
            {sentVersion ? `v${sentVersion} sent ✓` : sending ? 'Sending…' : `Send v${nextVersion} to guest`}
          </Button>
        )}
      </div>
    )
  }

  return (
    <div className="quotation-builder">
      <button type="button" className="link-button quotation-builder-back" onClick={goBack}>
        ← Back
      </button>

      <StaffPageHeader
        title={title}
        subtitle={subtitle}
        actions={actionButtons('quotation-builder-actions')}
        mobileTitle="Quotation"
        mobileSubtitle={quotation ? `${quotation.reference} · v${quotation.version}` : `New · ${enquiry.guest}`}
      />

      {/* One message at a time, most important first */}
      {sentVersion ? (
        <p className="quotation-builder-banner is-success">
          v{sentVersion} ({sentReference}) sent to {enquiry.fullName}, valid until {formatDate(validUntil)}.
          {quotation && ` v${quotation.version} is now superseded.`}
        </p>
      ) : sendError ? (
        <p className="quotation-builder-banner is-warning" role="alert">
          {sendError}
        </p>
      ) : isFinal ? (
        <p className="quotation-builder-banner">Accepted by the guest, so this quotation can't be changed.</p>
      ) : quotation?.status === 'expired' ? (
        <p className="quotation-builder-banner is-warning">
          v{quotation.version} lapsed on {formatDate(quotation.validUntil)}. Send v{nextVersion} to give the guest a new date to accept by.
        </p>
      ) : null}

      <div className="quotation-builder-body">
        <div className="quotation-builder-main">
          <div className="quotation-builder-toolbar">
            <div className="quotation-versions">
              {quotation?.previousVersions.map((old) => (
                <span key={old.version} className="quotation-version">
                  v{old.version} · {old.status}
                </span>
              ))}
              {quotation && (
                <span className="quotation-version is-latest">
                  v{quotation.version} · {statusLabel(QUOTATION_STATUSES, quotation.status).toLowerCase()}
                  {quotation.status === 'sent' && ` · valid until ${formatDate(quotation.validUntil)}`}
                </span>
              )}
              {!isFinal && <span className="quotation-version is-draft">v{nextVersion} · {sentVersion ? 'sent' : 'draft'}</span>}
            </div>

            <label className="quotation-valid-until">
              Valid until
              <DatePicker
                className="quotation-valid-until-picker"
                value={validUntil}
                min={TODAY}
                onChange={setValidUntil}
                disabled={readOnly}
                aria-invalid={!readOnly && !hasValidDate}
                aria-describedby="valid-until-error"
              />
            </label>
            {!readOnly && !hasValidDate && (
              <p id="valid-until-error" className="quotation-valid-until-error">
                Pick a date from today onwards to send.
              </p>
            )}
          </div>

          <LineItemsEditor
            items={items}
            onChange={changeItem}
            onRemove={removeItem}
            onAddLine={addBlankLine}
            onAddFromRateCard={() => setOpenPopup('rateCard')}
            readOnly={readOnly}
          />

          <div className="quotation-builder-bottom">
            <Card className="quotation-notes">
              <label htmlFor="quotation-notes" className="quotation-card-label">
                Notes shown to guest
              </label>
              <textarea
                id="quotation-notes"
                rows={3}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                disabled={readOnly}
              />
            </Card>

            <Card className="quotation-totals-card">
              <QuotationTotals items={items} discount={discount} onDiscountChange={readOnly ? undefined : setDiscount} />
            </Card>
          </div>

          {actionButtons('quotation-builder-mobile-actions')}
        </div>

        <QuotationSidebar quotation={quotation} enquiry={enquiry} />
      </div>

      {/* Popups: only one can be open, so one state says which */}
      {openPopup === 'rateCard' && <RateCardPicker onPick={addFromRateCard} onClose={() => setOpenPopup(null)} />}
      {openPopup === 'preview' && (
        <QuotationPreview
          enquiry={enquiry}
          version={isFinal ? quotation.version : (sentVersion ?? nextVersion)}
          items={items}
          discount={discount}
          notes={notes}
          validUntil={validUntil}
          onClose={() => setOpenPopup(null)}
        />
      )}
    </div>
  )
}
