import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import Button from '../../../components/Button'
import SpacePicker from './SpacePicker'
import DatePicker from '../../../components/DatePicker'
import { useApiData } from '../../../hooks/useApiData'
import { useBlockedDates } from '../../../hooks/useBlockedDates'
import { useGuestUser } from '../../../hooks/guestUser'
import { apiPost } from '../../../../services/api'
import { loadGuestSpaces } from '../../../../services/stays'
import { formatRand } from '../../../../lib/money'
import { cardPrice } from '../../../../lib/stays'
import { formatDayDate } from '../../../../lib/dates'
import { CATERING_CHOICES, DECOR_CHOICES, NEXT_STEPS } from '../../../../lib/guest/enquireData'
import { MEAL_TYPES } from '../../../../lib/staff/enquiriesData'
import { EARLIEST_ENQUIRY, EMPTY_FORM, toEnquiry, validate } from './enquiryForm'
import './EventEnquiryPage.css'
import Select from '../../../components/Select'

// Error sits outside the label; aria-describedby links it to the box.
function Field({ name, label, optional = false, error, children }) {
  return (
    <div className="enquire-field">
      <label htmlFor={`enquire-${name}`}>
        {label}
        {optional && <span className="enquire-optional"> (optional)</span>}
      </label>
      {children}
      {error && (
        <span id={`enquire-${name}-error`} className="enquire-error">
          {error}
        </span>
      )}
    </div>
  )
}

// A brief, not a booking: no price. Signed-in only; the server returns the reference.
export default function EventEnquiryPage() {
  const { user } = useGuestUser()
  const loaded = useApiData(loadGuestSpaces, [])
  const allSpaces = loaded.data ?? []
  // Spaces a guest can ask to hire, and places to stay
  const HIRE_SPACES = allSpaces.filter((space) => space.isHireable)
  const STAY_SPACES = allSpaces.filter((space) => space.isAccommodation)
  // The villa and its rooms: what a group can ask for ("The Villa has 8 rooms and sleeps 10")
  const VILLA = STAY_SPACES.find((space) => !space.parentId)
  const ROOM_COUNT = STAY_SPACES.filter((space) => space.parentId).length

  // /enquire?space=<id> starts ticked. Contact fields start from the account.
  const [searchParams] = useSearchParams()
  const [form, setForm] = useState(() => {
    const asked = searchParams.get('space')
    const contact = { fullName: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' }
    return asked ? { ...EMPTY_FORM, ...contact, spaces: [Number(asked)] } : { ...EMPTY_FORM, ...contact }
  })
  const [errors, setErrors] = useState({}) // shown only after "Send enquiry" is pressed
  const [sentTo, setSentTo] = useState(null) // the email the quotation will go to, once sent
  const [reference, setReference] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState('')
  const eventBlocked = useBlockedDates(
    HIRE_SPACES.filter((space) => form.spaces.includes(space.id)).map((space) => space.id),
  )
  const stayBlocked = useBlockedDates(VILLA ? [VILLA.id] : [])

  // One function for every input: its name says which field it changes (checkboxes use checked)
  function handleChange(event) {
    const { name, type, value, checked } = event.target
    setForm({ ...form, [name]: type === 'checkbox' ? checked : value })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const found = validate(form, eventBlocked, stayBlocked)
    setErrors(found)
    if (Object.keys(found).length > 0) return
    setSending(true)
    setSendError('')
    try {
      const saved = await apiPost('/enquiries', toEnquiry(form, allSpaces, VILLA))
      setReference(saved.reference)
      setSentTo(user?.email ?? form.email.trim())
      window.scrollTo(0, 0)
    } catch (error) {
      setSendError(error.message)
    }
    setSending(false)
  }

  // Shared props: value, onChange, and the error id for screen readers.
  function field(name) {
    return {
      id: `enquire-${name}`,
      name,
      value: form[name],
      onChange: handleChange,
      'aria-invalid': Boolean(errors[name]),
      'aria-describedby': errors[name] ? `enquire-${name}-error` : undefined,
    }
  }

  // More guests than the biggest space ticked can hold: not an error, staff will suggest options
  const chosen = HIRE_SPACES.filter((space) => form.spaces.includes(space.id))
  const biggest = Math.max(0, ...chosen.map((space) => space.capacity))
  const tooMany = chosen.length > 0 && Number(form.guests) > biggest

  // Whole villa, or picked rooms. Over capacity is a hint, not an error.
  const VILLA_ROOMS = VILLA ? STAY_SPACES.filter((space) => space.parentId === VILLA.id) : []
  const pickedRooms = VILLA_ROOMS.filter((room) => form.pickedRooms.includes(room.id))
  const pickedSleep = pickedRooms.reduce((sum, room) => sum + room.capacity, 0)
  const staying = Number(form.guestsStaying) || 0
  const sleeps = form.stayChoice === 'rooms' ? pickedSleep : (VILLA?.capacity ?? 0)

  function toggleRoom(id) {
    const picked = form.pickedRooms.includes(id) ? form.pickedRooms.filter((item) => item !== id) : [...form.pickedRooms, id]
    setForm({ ...form, pickedRooms: picked })
  }

  if (sentTo) {
    return (
      <div className="enquire">
        <section className="enquire-sent" role="status">
          <h1>Enquiry sent</h1>
          <p>
            {form.occasion} · {formatDayDate(form.preferredDate)} · {form.guests} guests
          </p>
          <p>
            Reference <strong>{reference}</strong>. We’ll email your quotation to {sentTo}.
          </p>
          <div className="enquire-sent-actions">
            <Button to="/my-bookings">My bookings</Button>
            <Button variant="secondary" to="/events">
              Back to events
            </Button>
          </div>
        </section>
      </div>
    )
  }

  return (
    <div className="enquire">
      <div className="enquire-main">
        <p className="enquire-crumb">Home / Plan an event</p>
        <h1>Tell us about your event</h1>
        <p className="enquire-notice">
          <strong>Enquiry only.</strong> We quote within 2 working days. Nothing is charged now. Dates must be at
          least 12 days from today.
        </p>

        {Object.keys(errors).length > 0 && (
          <p className="enquire-error-summary" role="alert">
            Please check the {Object.keys(errors).length === 1 ? 'field' : 'fields'} marked below.
          </p>
        )}

        {/* noValidate: the page checks the fields itself, with its own messages */}
        <form onSubmit={handleSubmit} noValidate>
          <h2 className="enquire-heading">Your event</h2>
          <div className="enquire-grid">
            <Field name="occasion" label="Event type" error={errors.occasion}>
              <input {...field('occasion')} placeholder="Wedding reception" />
            </Field>
            <Field name="preferredDate" label="Preferred date" error={errors.preferredDate}>
              <DatePicker
                id="enquire-preferredDate"
                value={form.preferredDate}
                min={EARLIEST_ENQUIRY}
                blocked={eventBlocked}
                onChange={(preferredDate) => setForm({ ...form, preferredDate })}
                aria-invalid={Boolean(errors.preferredDate)}
                aria-describedby={errors.preferredDate ? 'enquire-preferredDate-error' : undefined}
              />
            </Field>
            <Field name="alternateDate" label="Other date" optional error={errors.alternateDate}>
              <DatePicker
                id="enquire-alternateDate"
                value={form.alternateDate}
                min={EARLIEST_ENQUIRY}
                blocked={eventBlocked}
                onChange={(alternateDate) => setForm({ ...form, alternateDate })}
                aria-invalid={Boolean(errors.alternateDate)}
                aria-describedby={errors.alternateDate ? 'enquire-alternateDate-error' : undefined}
              />
            </Field>
            <Field name="guests" label="Expected guests" error={errors.guests}>
              <input {...field('guests')} type="number" min="1" inputMode="numeric" placeholder="80" />
            </Field>
            <Field name="startTime" label="Start time" error={errors.startTime}>
              <input {...field('startTime')} type="time" />
            </Field>
            <Field name="endTime" label="End time" error={errors.endTime}>
              <input {...field('endTime')} type="time" />
            </Field>
          </div>

          <SpacePicker spaces={HIRE_SPACES} chosen={form.spaces} onChange={(spaces) => setForm({ ...form, spaces })} />
          {errors.spaces && <span className="enquire-error">{errors.spaces}</span>}
          {tooMany && (
            <p className="enquire-note">
              That’s more guests than {chosen.length === 1 ? chosen[0].name : 'these spaces'} can hold (up to {biggest}
              ). We’ll suggest options.
            </p>
          )}

          <fieldset className="enquire-rooms">
            <legend>Do you need accommodation for your guests?</legend>
            <div className="enquire-yes-no">
              {[true, false].map((answer) => (
                <button
                  key={String(answer)}
                  type="button"
                  aria-pressed={form.needsRooms === answer}
                  onClick={() => setForm({ ...form, needsRooms: answer })}
                >
                  {answer ? 'Yes' : 'No'}
                </button>
              ))}
            </div>

            {form.needsRooms && (
              <>
                <div className="enquire-grid enquire-grid-3">
                  <Field name="checkIn" label="Check-in" error={errors.checkIn}>
                    <DatePicker
                      id="enquire-checkIn"
                      value={form.checkIn}
                      min={EARLIEST_ENQUIRY}
                      blocked={stayBlocked}
                      onChange={(checkIn) => setForm({ ...form, checkIn })}
                      aria-invalid={Boolean(errors.checkIn)}
                      aria-describedby={errors.checkIn ? 'enquire-checkIn-error' : undefined}
                    />
                  </Field>
                  <Field name="nights" label="Nights" error={errors.nights}>
                    <input {...field('nights')} type="number" min="1" inputMode="numeric" placeholder="1" />
                  </Field>
                  <Field name="guestsStaying" label="Guests staying" error={errors.guestsStaying}>
                    <input {...field('guestsStaying')} type="number" min="1" inputMode="numeric" placeholder="2" />
                  </Field>
                </div>

                {VILLA && (
                  <>
                    <div className="enquire-stay-choice" role="radiogroup" aria-label="Where your guests stay">
                      <label className={`enquire-stay-option ${form.stayChoice === 'villa' ? 'is-selected' : ''}`.trim()}>
                        <input
                          type="radio"
                          name="stayChoice"
                          checked={form.stayChoice === 'villa'}
                          onChange={() => setForm({ ...form, stayChoice: 'villa' })}
                        />
                        <span>
                          <strong>Whole villa</strong>
                          <span>
                            All {ROOM_COUNT} rooms · sleeps {VILLA.capacity} · {cardPrice(VILLA).text}
                          </span>
                        </span>
                      </label>
                      <label className={`enquire-stay-option ${form.stayChoice === 'rooms' ? 'is-selected' : ''}`.trim()}>
                        <input
                          type="radio"
                          name="stayChoice"
                          checked={form.stayChoice === 'rooms'}
                          onChange={() => setForm({ ...form, stayChoice: 'rooms' })}
                        />
                        <span>
                          <strong>Choose rooms</strong>
                          <span>Pick the rooms your guests need</span>
                        </span>
                      </label>
                    </div>

                    {form.stayChoice === 'rooms' && (
                      <ul className="enquire-room-list" aria-label="Rooms">
                        {VILLA_ROOMS.map((room) => {
                          const isPicked = form.pickedRooms.includes(room.id)
                          return (
                            <li key={room.id}>
                              <label className={`enquire-room ${isPicked ? 'is-selected' : ''}`.trim()}>
                                <input type="checkbox" checked={isPicked} onChange={() => toggleRoom(room.id)} />
                                <img src={room.photo} alt="" />
                                <span className="enquire-room-text">
                                  <strong>{room.name}</strong>
                                  <span>Sleeps {room.capacity}</span>
                                </span>
                                <span className="enquire-room-price">
                                  {cardPrice(room).amount !== null ? formatRand(cardPrice(room).amount) : ''}
                                  <span>per night</span>
                                </span>
                              </label>
                            </li>
                          )
                        })}
                      </ul>
                    )}
                    {errors.pickedRooms && <span className="enquire-error">{errors.pickedRooms}</span>}

                    <p className="enquire-rooms-hint">
                      {form.stayChoice === 'rooms' && pickedRooms.length === 0
                        ? 'Tick the rooms you’d like. We’ll confirm them in your quotation.'
                        : staying > sleeps
                          ? `That sleeps ${sleeps}, fewer than your ${staying} guests staying. We’ll suggest options.`
                          : form.stayChoice === 'rooms'
                            ? `${pickedRooms.length} ${pickedRooms.length === 1 ? 'room' : 'rooms'} · sleeps ${sleeps}.`
                            : `The whole villa sleeps ${VILLA.capacity}.`}
                    </p>
                  </>
                )}
              </>
            )}
          </fieldset>

          <h2 className="enquire-heading">Food and décor</h2>
          <div className="enquire-grid">
            <Field name="catering" label="Catering" error={errors.catering}>
              <Select {...field('catering')}>
                <option value="">Choose…</option>
                {CATERING_CHOICES.map((choice) => (
                  <option key={choice.value} value={choice.value}>
                    {choice.label}
                  </option>
                ))}
              </Select>
            </Field>
            {form.catering === 'venue' && (
              <>
                <Field name="mealType" label="Meal" error={errors.mealType}>
                  <Select {...field('mealType')}>
                    <option value="">Choose…</option>
                    {Object.entries(MEAL_TYPES).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </Select>
                </Field>
                <Field name="cateringRequest" label="Style" optional error={errors.cateringRequest}>
                  <input {...field('cateringRequest')} placeholder="Buffet" />
                </Field>
              </>
            )}
          </div>
          <div className="enquire-grid">
            <Field name="decor" label="Décor" error={errors.decor}>
              <Select {...field('decor')}>
                <option value="">Choose…</option>
                {DECOR_CHOICES.map((choice) => (
                  <option key={choice.value} value={choice.value}>
                    {choice.label}
                  </option>
                ))}
              </Select>
            </Field>
            {form.decor === 'venue' && (
              <Field name="decorRequest" label="What you have in mind" optional error={errors.decorRequest}>
                <input {...field('decorRequest')} placeholder="Long tables, linen and flowers" />
              </Field>
            )}
          </div>

          <Field name="requirements" label="Notes and requirements" optional error={errors.requirements}>
            <textarea {...field('requirements')} rows={3} placeholder="Live band from 20:00, wheelchair access…" />
          </Field>

          <h2 className="enquire-heading">Your details</h2>
          {/* Saved on the account, so the quote goes to that email. */}
          <p className="enquire-account">
            Sending as <strong>{user?.name}</strong> · {user?.email}
            {user?.phone ? ` · ${user.phone}` : ''}. Change these on <a href="/my-bookings?tab=profile">your profile</a>.
          </p>

          <div className="enquire-send">
            <label className="enquire-consent">
              <input
                type="checkbox"
                name="consent"
                checked={form.consent}
                onChange={handleChange}
                aria-invalid={Boolean(errors.consent)}
                aria-describedby={errors.consent ? 'enquire-consent-error' : undefined}
              />
              <span>GG Connect may contact me about this enquiry (POPIA)</span>
            </label>
            {errors.consent && (
              <span id="enquire-consent-error" className="enquire-error">
                {errors.consent}
              </span>
            )}
            {sendError && (
              <span className="enquire-error" role="alert">
                {sendError}
              </span>
            )}
            <Button type="submit" disabled={sending}>
              {sending ? 'Sending…' : 'Send enquiry'}
            </Button>
          </div>
        </form>
      </div>

      <aside className="enquire-steps">
        <h2>What happens next</h2>
        <ol>
          {NEXT_STEPS.map((step) => (
            <li key={step.title}>
              <strong>{step.title}</strong>
              <span>{step.detail}</span>
            </li>
          ))}
        </ol>
        <p>No price on this page: it’s worked out in your quotation.</p>
      </aside>
    </div>
  )
}
