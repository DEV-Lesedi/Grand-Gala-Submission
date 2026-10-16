import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { addDays, TODAY } from '../../../../lib/dates'
import { ENQUIRE_LEAD_DAYS } from '../../../../lib/guest/enquireData'
import { newId } from '../../../../lib/ids'
import '../spaces/spaceForms.css'
import './EventForm.css'
import DatePicker from '../../../components/DatePicker'
import Select from '../../../components/Select'

// Cover photo for a new event: the space's own photo from the API, or this one if it has none
const DEFAULT_PHOTO = '/photos/event.jpg'

// The same notice a guest needs on the enquiry form; the API refuses anything sooner
const EARLIEST_EVENT = addDays(TODAY, ENQUIRE_LEAD_DAYS)

const emptyTier = () => ({ key: newId('tier'), name: '', price: '', allocation: '' })

// Public, venue-hosted event. spaces come from loadStaffEvents; the form stores the chosen id.
// One transaction: a booking, a public event_booking, and ticket_type rows.
export default function EventForm({ spaces, onAdd, onClose }) {
  const [form, setForm] = useState({ title: '', spaceId: spaces[0]?.id ?? null, date: '', startTime: '18:00', endTime: '22:00', salesOpen: TODAY, about: '', goodToKnow: '' })
  const [tiers, setTiers] = useState([emptyTier()])
  const chosenSpace = spaces.find((space) => space.id === form.spaceId)
  // Tickets across every tier (blank boxes count as 0)
  const ticketsTotal = tiers.reduce((sum, tier) => sum + (Number(tier.allocation) || 0), 0)
  const [error, setError] = useState('')

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  function changeTier(key, field, value) {
    setTiers(tiers.map((tier) => (tier.key === key ? { ...tier, [field]: value } : tier)))
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (!form.title.trim()) return setError('Give the event a title.')
    const space = chosenSpace
    if (!space) return setError('Choose a space for the event.')
    if (!form.date || form.date < EARLIEST_EVENT) return setError(`Event dates must be at least ${ENQUIRE_LEAD_DAYS} days from today.`)
    if (form.endTime <= form.startTime) return setError('The event must end after it starts.')
    if (!form.salesOpen || form.salesOpen > form.date) return setError('Ticket sales must open on or before the event date.')
    for (const [index, tier] of tiers.entries()) {
      if (!tier.name.trim()) return setError(`Give tier ${index + 1} a name.`)
      if (tier.price === '' || !(Number(tier.price) >= 0)) return setError(`Enter a price of R0 or more for ${tier.name}.`)
      if (!(Number.isInteger(Number(tier.allocation)) && Number(tier.allocation) >= 1)) {
        return setError(`${tier.name} needs a whole number of tickets, at least 1.`)
      }
    }
    // The API refuses more tickets than the space holds; say so here, with the numbers
    if (ticketsTotal > space.capacity) {
      return setError(`${space.name} holds ${space.capacity} guests, but the tiers add up to ${ticketsTotal} tickets.`)
    }
    // "Seat" and "seat " count as the same name
    const firstWithName = {} // lower-case name → the name as first typed
    for (const tier of tiers) {
      const name = tier.name.trim()
      const sameAs = firstWithName[name.toLowerCase()]
      if (sameAs) return setError(`Two tiers are called ${sameAs}. Give each tier its own name.`)
      firstWithName[name.toLowerCase()] = name
    }

    onAdd({
      title: form.title.trim(),
      spaceId: space.id,
      date: form.date,
      startTime: form.startTime,
      endTime: form.endTime,
      image: space.photo ?? DEFAULT_PHOTO,
      // For the guest event page: paragraphs split at blank lines, one tip per line
      about: splitText(form.about, /\n\s*\n/),
      goodToKnow: splitText(form.goodToKnow, /\n/),
      tiers: tiers.map((tier) => ({
        name: tier.name.trim(),
        price: Number(tier.price),
        allocation: Number(tier.allocation),
        salesOpen: form.salesOpen,
      })),
    })
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="event-form">
        Add event
      </Button>
    </>
  )

  return (
    <Modal title="New event" subtitle="A public event at the venue, with tickets." footer={footer} onClose={onClose}>
      <form id="event-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <div className="space-form-row">
          <label className="space-form-field">
            Title
            <input name="title" value={form.title} onChange={handleChange} placeholder="e.g. Movie Night" />
          </label>
          <label className="space-form-field">
            Space
            {/* Option values are text, so the chosen id is turned back into a number */}
            <Select name="spaceId" value={form.spaceId ?? ''} onChange={(event) => setForm({ ...form, spaceId: Number(event.target.value) })}>
              {spaces.map((space) => (
                <option key={space.id} value={space.id}>
                  {space.name} · up to {space.capacity} guests
                </option>
              ))}
            </Select>
            {chosenSpace && (
              <span className="space-form-hint">
                Holds {chosenSpace.capacity} guests, so the tickets can add up to {chosenSpace.capacity} at most
                {ticketsTotal > 0 && ` (now ${ticketsTotal})`}
              </span>
            )}
          </label>
        </div>

        <div className="event-form-when">
          <label className="space-form-field">
            Date
            <DatePicker name="date" min={EARLIEST_EVENT} value={form.date} onChange={(date) => setForm({ ...form, date })} aria-describedby="event-date-hint" />
            <span id="event-date-hint" className="space-form-hint">At least {ENQUIRE_LEAD_DAYS} days from today</span>
          </label>
          <label className="space-form-field">
            Starts
            <input name="startTime" type="time" value={form.startTime} onChange={handleChange} />
          </label>
          <label className="space-form-field">
            Ends
            <input name="endTime" type="time" value={form.endTime} onChange={handleChange} />
          </label>
        </div>

        <label className="space-form-field">
          Ticket sales open
          <DatePicker name="salesOpen" value={form.salesOpen} onChange={(date) => setForm({ ...form, salesOpen: date })} />
        </label>

        {/* Shown to guests on the event page (/events/:id) */}
        <label className="space-form-field">
          <span>
            About this event <span className="space-form-optional">(for guests; leave a blank line between paragraphs)</span>
          </span>
          <textarea name="about" rows="4" value={form.about} onChange={handleChange} />
        </label>
        <label className="space-form-field">
          <span>
            Good to know <span className="space-form-optional">(one tip per line)</span>
          </span>
          <textarea name="goodToKnow" rows="3" value={form.goodToKnow} onChange={handleChange} placeholder="Free parking on site" />
        </label>

        <fieldset className="event-form-tiers">
          <legend>Ticket tiers</legend>
          {tiers.map((tier, index) => (
            <div key={tier.key} className="event-form-tier">
              <input aria-label={`Tier ${index + 1} name`} placeholder="Name, e.g. Adult" value={tier.name} onChange={(e) => changeTier(tier.key, 'name', e.target.value)} />
              <input aria-label={`Tier ${index + 1} price`} placeholder="Price (R)" type="number" min="0" inputMode="numeric" value={tier.price} onChange={(e) => changeTier(tier.key, 'price', e.target.value)} />
              <input aria-label={`Tier ${index + 1} tickets`} placeholder="Tickets" type="number" min="1" inputMode="numeric" value={tier.allocation} onChange={(e) => changeTier(tier.key, 'allocation', e.target.value)} />
              {tiers.length > 1 && (
                <button type="button" className="event-form-remove" onClick={() => setTiers(tiers.filter((item) => item.key !== tier.key))} aria-label={`Remove tier ${index + 1}`}>
                  ×
                </button>
              )}
            </div>
          ))}
          <button type="button" className="link-button event-form-add" onClick={() => setTiers([...tiers, emptyTier()])}>
            + Add tier
          </button>
        </fieldset>

        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}

// "a\n\nb" → ['a', 'b']; nothing typed → undefined, so the guest page leaves the section out
function splitText(text, separator) {
  const parts = text
    .split(separator)
    .map((part) => part.trim())
    .filter(Boolean)
  return parts.length > 0 ? parts : undefined
}
