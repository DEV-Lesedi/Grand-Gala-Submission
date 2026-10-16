import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Button from '../../../components/Button'
import { apiPost } from '../../../../services/api'
import Modal from '../../../components/Modal'
import { TODAY, formatDate, nightsBetween } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import './QuotationDecision.css'

// Accept holds the date, then Checkout gets only the reference. Changes prompt a new version.
export default function QuotationDecision({ quotation, status, total, onChanged }) {
  const navigate = useNavigate()
  const [openPopup, setOpenPopup] = useState(null) // null, 'changes' or 'decline'
  const [changes, setChanges] = useState('') // what the guest wants changed
  const [message, setMessage] = useState('') // what just happened, read out by screen readers
  const [busy, setBusy] = useState(false)

  const daysLeft = nightsBetween(TODAY, quotation.validUntil)
  const checkoutLink = `/checkout?${new URLSearchParams({ quotation: quotation.reference })}`
  const base = `/me/quotations/${encodeURIComponent(quotation.reference)}`

  // Runs one answer; returns true when the API took it
  async function answer(path, body) {
    setBusy(true)
    try {
      await apiPost(base + path, body)
      return true
    } catch (error) {
      setMessage(error.message)
      return false
    } finally {
      setBusy(false)
    }
  }

  async function accept() {
    if (await answer('/accept')) navigate(checkoutLink)
  }

  async function sendChanges() {
    setOpenPopup(null)
    if (await answer('/request-changes', { note: changes.trim() })) {
      setChanges('')
      setMessage('Sent. We’ll email you a new version.')
      onChanged?.()
    }
  }

  async function decline() {
    setOpenPopup(null)
    if (await answer('/decline')) {
      setMessage('Quotation declined.')
      onChanged?.()
    }
  }

  return (
    <div className="quotation-decision">
      {status === 'sent' && (
        <>
          <p className="quotation-decision-valid">
            <strong>Valid until {formatDate(quotation.validUntil)}</strong>
            {daysLeft === 0 ? 'Last day' : `${daysLeft} ${daysLeft === 1 ? 'day' : 'days'} left`}
          </p>
          <Button onClick={accept} disabled={busy}>
            {busy ? 'Accepting…' : `Accept and pay ${formatRand(total)}`}
          </Button>
          <Button variant="secondary" onClick={() => setOpenPopup('changes')} disabled={busy}>
            Request changes
          </Button>
          <Button variant="ghost" onClick={() => setOpenPopup('decline')}>
            Decline quotation
          </Button>
          <p className="quotation-decision-note">Pay in full to hold the date</p>
        </>
      )}

      {status === 'accepted' && <Button to={checkoutLink}>Pay {formatRand(total)}</Button>}
      {status === 'paid' && (
        <p className="quotation-decision-done">Paid {formatDate(quotation.paidDate)}. Your date is confirmed.</p>
      )}
      {status === 'expired' && (
        <>
          <p className="quotation-decision-done">This quotation expired on {formatDate(quotation.validUntil)}.</p>
          <Button variant="secondary" onClick={() => setOpenPopup('changes')}>
            Ask for a new version
          </Button>
        </>
      )}
      {status === 'declined' && <p className="quotation-decision-done">You declined this quotation.</p>}

      {message && (
        <p className="quotation-decision-message" role="status">
          {message}
        </p>
      )}

      <div className="quotation-decision-copy">
        <Button variant="secondary" size="sm" onClick={() => window.print()}>
          Download PDF
        </Button>

      </div>

      {openPopup === 'changes' && (
        <Modal
          title="Request changes"
          subtitle={`${quotation.reference} · v${quotation.version}`}
          onClose={() => setOpenPopup(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setOpenPopup(null)}>
                Cancel
              </Button>
              <Button onClick={sendChanges} disabled={!changes.trim()}>
                Send request
              </Button>
            </>
          }
        >
          <label className="quotation-decision-field">
            What would you like changed?
            <textarea rows={4} value={changes} onChange={(event) => setChanges(event.target.value)} />
          </label>
        </Modal>
      )}

      {openPopup === 'decline' && (
        <Modal
          className="modal-confirm"
          title="Decline this quotation?"
          onClose={() => setOpenPopup(null)}
          footer={
            <>
              <Button variant="secondary" onClick={() => setOpenPopup(null)}>
                Keep it
              </Button>
              <Button variant="danger" onClick={decline}>
                Decline
              </Button>
            </>
          }
        >
          <p>The date won’t be held. You can send a new enquiry later.</p>
        </Modal>
      )}
    </div>
  )
}
