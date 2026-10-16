import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { TODAY, addDays } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { discountFor } from './promotionHelpers'
import { newId } from '../../../../lib/ids'
import { PROMOTION_SCOPES } from '../../../../lib/staff/promotionsData'
import './spaceForms.css'
import DatePicker from '../../../components/DatePicker'
import Select from '../../../components/Select'

// Amount for the live preview
const EXAMPLE_AMOUNT = 10000

// Add a promotion, or edit one (promotion given). existingCodes: codes must be unique.
// Editing: the code is fixed (guests may already have it); limits can't drop below uses made.
export default function PromotionForm({ promotion, existingCodes, onSave, onClose }) {
  const isEdit = Boolean(promotion)
  // Inputs hold text, so numbers become text ("" for none)
  const asText = (value) => (value === null || value === undefined ? '' : String(value))
  const [form, setForm] = useState({
    code: promotion?.code ?? '',
    description: promotion?.description ?? '',
    appliesTo: promotion?.appliesTo ?? ['stays', 'events', 'tickets'],
    type: promotion?.type ?? 'percentage',
    value: asText(promotion?.value),
    maxDiscount: asText(promotion?.maxDiscount),
    minSpend: promotion?.minSpend ? String(promotion.minSpend) : '',
    usageLimit: asText(promotion?.usageLimit),
    limitPerUser: asText(promotion?.limitPerUser),
    validFrom: promotion?.validFrom ?? TODAY,
    validTo: promotion?.validTo ?? addDays(TODAY, 30),
  })
  const [error, setError] = useState('')

  function handleChange(event) {
    const { name, value } = event.target
    // Capitals, no spaces: "spring 500" → "SPRING500"
    const cleaned = name === 'code' ? value.toUpperCase().replace(/[^A-Z0-9]/g, '') : value
    setForm({ ...form, [name]: cleaned })
  }

  function toggleScope(value) {
    const appliesTo = form.appliesTo.includes(value) ? form.appliesTo.filter((item) => item !== value) : [...form.appliesTo, value]
    setForm({ ...form, appliesTo })
  }

  // Empty → null, else a number
  const optional = (text) => (text === '' ? null : Number(text))

  // The promotion as saved (preview + Save)
  const promo = {
    type: form.type,
    value: Number(form.value) || 0,
    minSpend: Number(form.minSpend) || 0,
    maxDiscount: form.type === 'percentage' ? optional(form.maxDiscount) : null,
  }

  // Could this make the booking R0? Fixed above the minimum, or 100% with no cap.
  const makesFree =
    (promo.type === 'fixed' && promo.value > 0 && promo.minSpend < promo.value) ||
    (promo.type === 'percentage' && promo.value >= 100 && promo.maxDiscount === null)

  function handleSubmit(event) {
    event.preventDefault()
    const value = Number(form.value)
    // Same checks the database enforces.
    if (form.code.length < 3) return setError('The code needs at least 3 letters or numbers.')
    if (!isEdit && existingCodes.includes(form.code)) return setError(`${form.code} is already used by another promotion.`)
    if (form.appliesTo.length === 0) return setError('Tick at least one: stays, events or tickets.')
    if (!(value > 0)) return setError('Enter how much it takes off.')
    if (form.type === 'percentage' && value > 100) return setError('A percentage can be at most 100.')
    if (form.minSpend !== '' && !(Number(form.minSpend) >= 0)) return setError('The minimum spend must be R0 or more.')
    // A 0 cap takes nothing off; a 0 limit is used up before anyone uses it.
    for (const field of ['maxDiscount', 'usageLimit', 'limitPerUser']) {
      if (form[field] !== '' && !(Number(form[field]) >= 1)) return setError('Caps and limits must be at least 1 (leave empty for none).')
    }
    const usedSoFar = promotion?.usageCount ?? 0
    if (form.usageLimit !== '' && Number(form.usageLimit) < usedSoFar) return setError(`It's already been used ${usedSoFar} times, so the limit can't be lower.`)
    // A past start is allowed only when it's the date this promotion already has.
    const keepsStart = isEdit && form.validFrom === promotion.validFrom
    if (!form.validFrom || (form.validFrom < TODAY && !keepsStart)) return setError('Choose a start date from today onwards.')
    if (!form.validTo || form.validTo < form.validFrom) return setError('The end date must be on or after the start date.')

    onSave({
      id: promotion?.id ?? newId('promo'),
      code: form.code,
      description: form.description.trim(),
      appliesTo: form.appliesTo,
      ...promo,
      value,
      usageLimit: optional(form.usageLimit),
      usageCount: usedSoFar,
      limitPerUser: optional(form.limitPerUser),
      validFrom: form.validFrom,
      validTo: form.validTo,
      isActive: promotion?.isActive ?? true,
    })
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="promotion-form">
        {isEdit ? 'Save promotion' : 'Add promotion'}
      </Button>
    </>
  )

  return (
    <Modal
      title={isEdit ? `Edit ${promotion.code}` : 'Add a promotion'}
      subtitle={isEdit ? `Used ${promotion.usageCount} times so far. The code can't change.` : 'A code guests type at checkout for a discount.'}
      footer={footer}
      onClose={onClose}
    >
      <form id="promotion-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <div className="space-form-row">
          <label className="space-form-field">
            Code
            <input name="code" value={form.code} onChange={handleChange} placeholder="e.g. SPRING500" maxLength={30} disabled={isEdit} />
          </label>
          <label className="space-form-field">
            <span>
              Description <span className="space-form-optional">(staff only)</span>
            </span>
            <input name="description" value={form.description} onChange={handleChange} placeholder="e.g. Spring stays" />
          </label>
        </div>

        <fieldset className="space-form-checks is-row">
          <legend>Works on</legend>
          {PROMOTION_SCOPES.map((scope) => (
            <label key={scope.value} className="space-form-check">
              <input type="checkbox" checked={form.appliesTo.includes(scope.value)} onChange={() => toggleScope(scope.value)} />
              {scope.label}
            </label>
          ))}
        </fieldset>

        <div className="space-form-row">
          <label className="space-form-field">
            Discount
            <Select name="type" value={form.type} onChange={handleChange}>
              <option value="percentage">Percentage (%)</option>
              <option value="fixed">Fixed amount (R)</option>
            </Select>
          </label>
          <label className="space-form-field">
            {form.type === 'percentage' ? 'Percentage off' : 'Rands off'}
            <input name="value" type="number" min="0" inputMode="numeric" value={form.value} onChange={handleChange} />
          </label>
        </div>

        <div className="space-form-row">
          <label className="space-form-field">
            <span>
              Minimum spend (R) <span className="space-form-optional">(optional)</span>
            </span>
            <input name="minSpend" type="number" min="0" inputMode="numeric" value={form.minSpend} onChange={handleChange} />
          </label>
          {/* A cap only for percentages */}
          {form.type === 'percentage' && (
            <label className="space-form-field">
              <span>
                Most it can take off (R) <span className="space-form-optional">(optional)</span>
              </span>
              <input name="maxDiscount" type="number" min="0" inputMode="numeric" value={form.maxDiscount} onChange={handleChange} />
            </label>
          )}
        </div>

        <div className="space-form-row">
          <label className="space-form-field">
            <span>
              Uses in total <span className="space-form-optional">(empty = unlimited)</span>
            </span>
            <input name="usageLimit" type="number" min="0" inputMode="numeric" value={form.usageLimit} onChange={handleChange} />
          </label>
          <label className="space-form-field">
            <span>
              Uses per guest <span className="space-form-optional">(empty = unlimited)</span>
            </span>
            <input name="limitPerUser" type="number" min="0" inputMode="numeric" value={form.limitPerUser} onChange={handleChange} />
          </label>
        </div>

        <div className="space-form-row">
          <label className="space-form-field">
            From
            <DatePicker name="validFrom" min={isEdit ? undefined : TODAY} value={form.validFrom} onChange={(date) => setForm({ ...form, validFrom: date })} />
          </label>
          <label className="space-form-field">
            Until
            <DatePicker name="validTo" min={form.validFrom} value={form.validTo} onChange={(date) => setForm({ ...form, validTo: date })} />
          </label>
        </div>

        <p className="space-form-note">
          A {formatRand(EXAMPLE_AMOUNT)} booking gets <strong>{formatRand(discountFor(promo, EXAMPLE_AMOUNT))} off</strong>
          {promo.minSpend > EXAMPLE_AMOUNT && ` (it's below the ${formatRand(promo.minSpend)} minimum)`}.
        </p>

        {makesFree && (
          <p className="space-form-warning">
            {promo.type === 'fixed'
              ? `Bookings under ${formatRand(promo.value)} would be free. Set a minimum spend of at least ${formatRand(promo.value)}.`
              : '100% off with no cap makes bookings free. Add a cap, or use a lower percentage.'}
          </p>
        )}

        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
