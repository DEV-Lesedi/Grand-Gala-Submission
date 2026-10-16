import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { TODAY, formatDate } from '../../../../lib/dates'
import { RATE_BASES } from '../../../../lib/staff/spacesData'
import { basisInfo, overlappingSeasons, seasonToday } from '../../../../lib/rateSchedule'
import { newId } from '../../../../lib/ids'
import './spaceForms.css'
import DatePicker from '../../../components/DatePicker'
import Select from '../../../components/Select'

// mode "change": new normal price (no end) · mode "season": dated price on top
// basis null: staff choose the kind. start prefills. rates drive the overlap warning.
export default function RateForm({ mode, space, basis, rates = [], start = {}, onAdd, onClose }) {
  const isSeason = mode === 'season'
  const [form, setForm] = useState({
    basis: basis ?? (space.isAccommodation ? 'per_night' : 'per_event'),
    label: start.label ?? (isSeason ? '' : 'Standard'),
    price: start.price ?? '',
    validFrom: TODAY,
    validTo: '',
  })
  const [error, setError] = useState('')

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  // Overlapping seasons: the later start wins. A tie goes to the new one.
  const overlaps =
    isSeason && form.validFrom && form.validTo >= form.validFrom
      ? overlappingSeasons(rates, form.basis, form.validFrom, form.validTo)
      : []

  // A new normal price starting today ends the season running today
  const endingSeason = !isSeason && form.validFrom === TODAY ? seasonToday(rates, form.basis) : undefined

  function handleSubmit(event) {
    event.preventDefault()
    const price = Number(form.price)
    // min/required don't stop typing, so check here
    if (form.price === '' || !(price >= 0)) return setError('Enter a price of R0 or more.')
    if (!form.validFrom || form.validFrom < TODAY) return setError('Choose a start date from today onwards.')
    if (isSeason && !form.label.trim()) return setError('Give the seasonal price a name, e.g. Peak season.')
    if (isSeason && (!form.validTo || form.validTo < form.validFrom)) return setError('Choose an end date on or after the start date.')

    onAdd({
      id: newId('rate'),
      basis: form.basis,
      label: form.label.trim() || 'Standard',
      price,
      validFrom: form.validFrom,
      validTo: isSeason ? form.validTo : null,
      isActive: true,
    })
    onClose()
  }

  const title = isSeason ? 'Add a seasonal price' : basis ? 'Change the price' : 'Add a price'
  const unit = basisInfo(form.basis).unit

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      {/* Submits the form from the footer */}
      <Button type="submit" form="rate-form">
        {isSeason ? 'Add seasonal price' : 'Save price'}
      </Button>
    </>
  )

  return (
    <Modal title={title} subtitle={space.name} footer={footer} onClose={onClose}>
      <form id="rate-form" className="space-form" onSubmit={handleSubmit} noValidate>
        {/* Only when adding a new kind of price */}
        {!basis && (
          <label className="space-form-field">
            Charged
            <Select name="basis" value={form.basis} onChange={handleChange}>
              {RATE_BASES.map((item) => (
                <option key={item.value} value={item.value}>
                  {item.chip}
                </option>
              ))}
            </Select>
          </label>
        )}

        <div className="space-form-row">
          <label className="space-form-field">
            {isSeason ? 'Name' : <span>Name <span className="space-form-optional">(optional)</span></span>}
            <input name="label" value={form.label} onChange={handleChange} placeholder={isSeason ? 'e.g. Peak season' : 'Standard'} />
          </label>
          <label className="space-form-field">
            Price (R / {unit})
            <input name="price" type="number" min="0" inputMode="numeric" value={form.price} onChange={handleChange} />
          </label>
        </div>

        <div className="space-form-row">
          <label className="space-form-field">
            {isSeason ? 'From' : 'Starts'}
            <DatePicker name="validFrom" min={TODAY} value={form.validFrom} onChange={(date) => setForm({ ...form, validFrom: date })} />
          </label>
          {isSeason && (
            <label className="space-form-field">
              Until
              <DatePicker name="validTo" min={form.validFrom} value={form.validTo} onChange={(date) => setForm({ ...form, validTo: date })} />
            </label>
          )}
        </div>

        <p className="space-form-note">
          {isSeason
            ? 'Applies only between these dates, then the normal price comes back by itself.'
            : 'Replaces the normal price from this date. Bookings already made keep the price they were charged.'}
        </p>

        {endingSeason && (
          <p className="space-form-warning">
            {endingSeason.label} ({formatDate(endingSeason.validFrom)} – {formatDate(endingSeason.validTo)}) ends today, so this
            price applies straight away.
          </p>
        )}

        {overlaps.map((other) => (
          <p key={other.id} className="space-form-warning">
            Overlaps {other.label} ({formatDate(other.validFrom)} – {formatDate(other.validTo)}).{' '}
            {form.validFrom >= other.validFrom
              ? 'This price applies on the shared days, because it starts later.'
              : `${other.label} applies from ${formatDate(other.validFrom)}, because it starts later.`}
          </p>
        ))}

        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
