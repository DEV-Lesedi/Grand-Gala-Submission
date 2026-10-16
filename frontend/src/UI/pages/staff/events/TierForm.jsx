import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import '../spaces/spaceForms.css'
import './TierForm.css'
import DatePicker from '../../../components/DatePicker'

// Tickets can't go below sold. New prices apply from now; sold tickets keep theirs.
// event.tiers include sold (loadStaffEvents).

// 'YYYY-MM-DDTHH:MM' or null. Same day keeps its time; a new day uses the given time.
function saleTime(saved, date, time) {
  if (!date) return null
  if (saved?.startsWith(date)) return saved
  return `${date}T${time}`
}

export default function TierForm({ event, onSave, onClose }) {
  // Inputs hold text, so numbers become text
  const [rows, setRows] = useState(() =>
    event.tiers.map((tier) => ({
      ...tier,
      price: String(tier.price),
      allocation: String(tier.allocation),
      // The pickers edit only the day ('2026-11-14'); saleTime() puts the time back on
      openDate: tier.salesOpen?.slice(0, 10) ?? '',
      closeDate: tier.salesClose?.slice(0, 10) ?? '',
    })),
  )
  const [error, setError] = useState('')

  function change(id, field, value) {
    setRows(rows.map((row) => (row.id === id ? { ...row, [field]: value } : row)))
  }

  function handleSubmit(formEvent) {
    formEvent.preventDefault()
    for (const row of rows) {
      const price = Number(row.price)
      const allocation = Number(row.allocation)
      if (row.price === '' || !(price >= 0)) return setError(`Enter a price of R0 or more for ${row.name}.`)
      if (!Number.isInteger(allocation)) return setError(`${row.name} tickets must be a whole number.`)
      if (!(allocation >= row.sold)) return setError(`${row.name} has already sold ${row.sold} tickets, so it needs at least ${row.sold}.`)
      if (row.openDate && row.closeDate && row.closeDate < row.openDate) return setError(`${row.name}: sales must close on or after they open.`)
      if (row.closeDate > event.date) return setError(`${row.name}: sales can't close after the event.`)
    }
    // Keep only the tier's own fields (sold is counted by the API, never saved).
    // Sales closing on the event day close when it starts; the API refuses anything later.
    onSave(
      rows.map(({ sold, openDate, closeDate, ...tier }) => ({ // eslint-disable-line no-unused-vars
        ...tier,
        price: Number(tier.price),
        allocation: Number(tier.allocation),
        salesOpen: saleTime(tier.salesOpen, openDate, '00:00'),
        salesClose: saleTime(tier.salesClose, closeDate, closeDate === event.date ? event.startTime : '23:59'),
      })),
    )
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="tier-form">
        Save tiers
      </Button>
    </>
  )

  return (
    <Modal title={`Edit ticket tiers · ${event.title}`} subtitle="New prices apply to tickets sold from now on." footer={footer} onClose={onClose}>
      <form id="tier-form" className="space-form" onSubmit={handleSubmit} noValidate>
        {rows.map((row) => (
          <fieldset key={row.id} className="tier-fields">
            <legend>
              {row.name} <span>{row.sold} sold</span>
            </legend>
            <div className="space-form-row">
              <label className="space-form-field">
                Price (R)
                <input type="number" min="0" inputMode="numeric" value={row.price} aria-label={`${row.name} price`} onChange={(e) => change(row.id, 'price', e.target.value)} />
              </label>
              <label className="space-form-field">
                Tickets
                <input type="number" min={row.sold} inputMode="numeric" value={row.allocation} aria-label={`${row.name} tickets`} onChange={(e) => change(row.id, 'allocation', e.target.value)} />
              </label>
            </div>
            <div className="space-form-row">
              <label className="space-form-field">
                Sales open
                <DatePicker max={event.date} value={row.openDate} onChange={(date) => change(row.id, 'openDate', date)} />
              </label>
              <label className="space-form-field">
                Sales close
                <DatePicker max={event.date} value={row.closeDate} onChange={(date) => change(row.id, 'closeDate', date)} />
              </label>
            </div>
            {(!row.openDate || !row.closeDate) && (
              <p className="space-form-note">No date: on sale straight away, until the event starts.</p>
            )}
          </fieldset>
        ))}
        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
