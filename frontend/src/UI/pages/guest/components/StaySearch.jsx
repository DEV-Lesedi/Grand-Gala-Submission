import { useState } from 'react'
import Button from '../../../components/Button'
import DatePicker from '../../../components/DatePicker'
import { useBlockedDates } from '../../../hooks/useBlockedDates'
import { TODAY, addDays, formatDateRange } from '../../../../lib/dates'
import { SEARCH_KINDS } from '../../../../lib/guest/staysData'
import './StaySearch.css'
import Select from '../../../components/Select'

// search: { kind, checkIn, checkOut, guests }. staySpaceIds are villa ids to grey out.
export default function StaySearch({ search, onSearch, staySpaceIds = [] }) {
  const [isOpen, setIsOpen] = useState(false)
  const [draft, setDraft] = useState(search) // what's being typed, until Search is pressed
  const [error, setError] = useState('')
  const blocked = useBlockedDates(staySpaceIds)

  // Each part has its words, and shorter ones for very small phones (CSS shows one or the other)
  const kind = SEARCH_KINDS.find((item) => item.value === search.kind)
  const datesLabel = search.checkIn ? formatDateRange(search.checkIn, search.checkOut) : null
  const guestsLabel = search.guests ? `${search.guests} ${search.guests === 1 ? 'guest' : 'guests'}` : null

  function open() {
    setDraft(search)
    setError('')
    setIsOpen(true)
  }

  function change(event) {
    setDraft({ ...draft, [event.target.name]: event.target.value })
  }

  function submit(event) {
    event.preventDefault()
    // Dates are optional, but if one is set both are, and check-out comes after check-in
    if (Boolean(draft.checkIn) !== Boolean(draft.checkOut)) return setError('Choose both dates, or neither.')
    if (draft.checkIn && draft.checkOut <= draft.checkIn) return setError('Check-out must be after check-in.')
    const guests = Number(draft.guests)
    onSearch({ ...draft, guests: Number.isInteger(guests) && guests >= 1 ? guests : null })
    setIsOpen(false)
  }

  return (
    <div className="stay-search">
      {/* The pill: one button showing the current search; the round ⌕ is decoration inside it */}
      <button type="button" className="stay-search-pill" onClick={open} aria-expanded={isOpen}>
        <span className="is-strong">
          <span className="is-long">{kind?.label}</span>
          <span className="is-short">{kind?.short}</span>
        </span>
        <span>
          <span className="is-long">{datesLabel ?? 'Any dates'}</span>
          <span className="is-short">{datesLabel ?? 'Dates'}</span>
        </span>
        <span>
          <span className="is-long">{guestsLabel ?? 'Add guests'}</span>
          <span className="is-short">{guestsLabel ?? 'Guests'}</span>
        </span>
        <span className="stay-search-icon" aria-hidden="true">
          ⌕
        </span>
      </button>

      {isOpen && (
        <form className="stay-search-panel" onSubmit={submit} noValidate>
          <label>
            What
            <Select name="kind" value={draft.kind} onChange={change}>
              {SEARCH_KINDS.map((kind) => (
                <option key={kind.value} value={kind.value}>
                  {kind.label}
                </option>
              ))}
            </Select>
          </label>
          <label>
            Check-in
            <DatePicker
              value={draft.checkIn}
              min={TODAY}
              blocked={blocked}
              onChange={(checkIn) => setDraft({ ...draft, checkIn })}
            />
          </label>
          <label>
            Check-out
            <DatePicker
              value={draft.checkOut}
              min={draft.checkIn ? addDays(draft.checkIn, 1) : TODAY}
              blocked={blocked}
              stayStart={draft.checkIn}
              onChange={(checkOut) => setDraft({ ...draft, checkOut })}
            />
          </label>
          <label>
            Guests
            <input
              type="number"
              name="guests"
              min="1"
              inputMode="numeric"
              value={draft.guests ?? ''}
              onChange={change}
            />
          </label>
          {error && (
            <p className="stay-search-error" role="alert">
              {error}
            </p>
          )}
          <div className="stay-search-actions">
            <Button variant="secondary" onClick={() => setIsOpen(false)}>
              Cancel
            </Button>
            <Button type="submit">Search</Button>
          </div>
        </form>
      )}
    </div>
  )
}
