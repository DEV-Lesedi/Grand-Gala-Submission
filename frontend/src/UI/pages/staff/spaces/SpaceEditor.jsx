import { useEffect, useState } from 'react'
import Button from '../../../components/Button'
import DetailPanel from '../components/DetailPanel'
import AddBlackoutForm from './AddBlackoutForm'
import RatesSection from './RatesSection'
import { blackoutText, coversDate } from './spaceHelpers'
import { SPACE_TYPES } from '../../../../lib/staff/spacesData'
import { formatDate } from '../../../../lib/dates'
import './SpaceEditor.css'
import Select from '../../../components/Select'

// Fields that "Save changes" saves (switch, rates and blackouts save straight away)
function editableParts(space) {
  return {
    type: space.type,
    capacity: space.capacity,
    isAccommodation: space.isAccommodation,
    isHireable: space.isHireable,
  }
}

// Draft until Save. blackouts are own plus inherited.
// onUnsavedChange tells the page when the draft is dirty.
export default function SpaceEditor({
  space,
  roomCount,
  blackouts,
  blackedOutToday,
  rooms,
  otherSpaces,
  isOpen,
  onClose,
  onSave,
  onAddBlackout,
  onRemoveBlackout,
  onRateAction,
  onUnsavedChange,
}) {
  const [draft, setDraft] = useState(() => editableParts(space))
  const [openPopup, setOpenPopup] = useState(null) // null or 'blackout'
  const [justSaved, setJustSaved] = useState(false)

  // Changed? Compare as text; capacity as a number first
  const hasChanges = JSON.stringify({ ...draft, capacity: Number(draft.capacity) }) !== JSON.stringify(editableParts(space))
  const capacityIsValid = Number(draft.capacity) >= 1
  const canSave = hasChanges && capacityIsValid && (draft.isAccommodation || draft.isHireable)

  // Keep the page informed, so it can ask before switching to another space
  useEffect(() => {
    onUnsavedChange?.(hasChanges)
  }, [hasChanges, onUnsavedChange])

  // Every change goes through here (clears "Saved")
  function change(parts) {
    setDraft({ ...draft, ...parts })
    setJustSaved(false)
  }

  function save() {
    onSave(space.id, { ...draft, capacity: Number(draft.capacity) })
    setJustSaved(true)
  }

  return (
    <DetailPanel className="space-editor" isOpen={isOpen} onClose={onClose} backLabel="Back to spaces">
      <div
        className={`space-editor-cover ${blackedOutToday ? 'is-blacked-out' : ''}`.trim()}
        style={{ backgroundImage: `url(${space.photo})` }}
      >
        {/* Blacked out beats Active */}
        <span
          className={`space-editor-state ${space.isActive && !blackedOutToday ? 'is-active' : ''} ${blackedOutToday ? 'is-blacked-out' : ''}`.trim()}
        >
          {!space.isActive ? 'Switched off' : blackedOutToday ? `Blacked out until ${formatDate(blackedOutToday.end)}` : 'Active'}
        </span>
        <h2 className="space-editor-name">{space.name}</h2>
      </div>

      {/* What it can be booked for: tap a chip to switch it */}
      <div className="space-editor-flags">
        <button
          type="button"
          className="space-flag"
          aria-pressed={draft.isAccommodation}
          onClick={() => change({ isAccommodation: !draft.isAccommodation })}
        >
          {draft.isAccommodation ? '✓ ' : ''}Overnight stays
        </button>
        <button
          type="button"
          className="space-flag"
          aria-pressed={draft.isHireable}
          onClick={() => change({ isHireable: !draft.isHireable })}
        >
          {draft.isHireable ? '✓ ' : ''}Event hire
        </button>
        {roomCount > 0 && <span className="space-flag is-info">✓ Whole or by room · {roomCount} rooms</span>}
      </div>
      {!draft.isAccommodation && !draft.isHireable && (
        <p className="space-editor-error">Pick at least one: overnight stays or event hire.</p>
      )}

      <div className="space-editor-fields">
        <label className="space-editor-field">
          Type
          <Select value={draft.type} onChange={(event) => change({ type: event.target.value })}>
            {SPACE_TYPES.map((type) => (
              <option key={type.value} value={type.value}>
                {type.label}
              </option>
            ))}
          </Select>
        </label>
        <label className="space-editor-field">
          Capacity
          <span className="space-editor-capacity">
            <input
              type="number"
              min="1"
              inputMode="numeric"
              value={draft.capacity}
              onChange={(event) => change({ capacity: event.target.value.replace('-', '') })}
              aria-invalid={!capacityIsValid}
            />
            guests
          </span>
        </label>
      </div>

      {/* Each rate action is its own database row, so it saves straight away (like blackouts) */}
      <RatesSection
        space={space}
        rates={space.rates}
        onAddRate={(rate) => onRateAction('add', space.id, rate)}
        onCancelRate={(rate) => onRateAction('cancel', space.id, rate)}
        onEndRateNow={(rate) => onRateAction('end', space.id, rate)}
      />

      <section>
        <div className="space-editor-heading">
          <h3>
            Blackout dates <span>saved straight away</span>
          </h3>
          <button type="button" className="link-button space-editor-link" onClick={() => setOpenPopup('blackout')}>
            + Add
          </button>
        </div>

        <ul className="space-blackouts">
          {blackouts.map((blackout) => (
            <li key={blackout.id} className={`space-blackout ${coversDate(blackout) ? 'is-today' : ''}`.trim()}>
              {/* Dates on top, the reason underneath with the full width */}
              <span className="space-blackout-text">
                <span className="space-blackout-dates">
                  {blackoutText(blackout)}
                  {coversDate(blackout) && <span className="space-blackout-now">Now</span>}
                </span>
                {blackout.reason && <span className="space-blackout-reason">{blackout.reason}</span>}
                {/* Inherited from the villa: remove it there */}
                {blackout.from && (
                  <span className="space-blackout-from">With {blackout.from.name}</span>
                )}
              </span>
              {!blackout.from && (
                <button
                  type="button"
                  className="space-blackout-remove"
                  onClick={() => onRemoveBlackout(space.id, blackout.id)}
                  aria-label={`Remove blackout ${blackoutText(blackout)}`}
                >
                  ×
                </button>
              )}
            </li>
          ))}
          {blackouts.length === 0 && <li className="space-editor-empty">None. Every date can be booked.</li>}
        </ul>
      </section>

      <div className="space-editor-save">
        <Button onClick={save} disabled={!canSave}>
          Save changes
        </Button>
        <p className="space-editor-status" aria-live="polite">
          {justSaved ? 'Saved.' : hasChanges ? 'You have unsaved changes.' : ''}
        </p>
      </div>

      {openPopup === 'blackout' && (
        <AddBlackoutForm
          space={space}
          rooms={rooms}
          otherSpaces={otherSpaces}
          onAdd={(blackout, alsoIds) => onAddBlackout([space.id, ...alsoIds], blackout)}
          onClose={() => setOpenPopup(null)}
        />
      )}
    </DetailPanel>
  )
}
