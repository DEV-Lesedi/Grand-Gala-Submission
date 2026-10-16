import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { TODAY } from '../../../../lib/dates'
import { newId } from '../../../../lib/ids'
import './spaceForms.css'
import DatePicker from '../../../components/DatePicker'

// "+ Add" blackout. rooms: blocked automatically · otherSpaces: "Also block" options
// onAdd(blackout, alsoSpaceIds): one space_blackout row per space
export default function AddBlackoutForm({ space, rooms, otherSpaces, onAdd, onClose }) {
  const [form, setForm] = useState({ start: TODAY, end: TODAY, reason: '' })
  const [alsoBlocked, setAlsoBlocked] = useState([]) // ids of the other spaces ticked

  function toggleAlso(id) {
    setAlsoBlocked(alsoBlocked.includes(id) ? alsoBlocked.filter((item) => item !== id) : [...alsoBlocked, id])
  }
  const [error, setError] = useState('')

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  function handleSubmit(event) {
    event.preventDefault()
    if (!form.start || !form.end) return setError('Choose the first and last day.')
    if (form.start < TODAY) return setError('Choose a first day from today onwards.')
    if (form.end < form.start) return setError('The last day must be on or after the first day.')
    onAdd({ id: newId('blackout'), start: form.start, end: form.end, reason: form.reason.trim() }, alsoBlocked)
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="add-blackout-form">
        Add dates
      </Button>
    </>
  )

  return (
    <Modal title="Block out dates" subtitle={space.name} footer={footer} onClose={onClose}>
      <form id="add-blackout-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <div className="space-form-row">
          <label className="space-form-field">
            First day
            <DatePicker name="start" min={TODAY} value={form.start} onChange={(date) => setForm({ ...form, start: date })} />
          </label>
          <label className="space-form-field">
            Last day
            <DatePicker name="end" value={form.end} onChange={(date) => setForm({ ...form, end: date })} />
          </label>
        </div>
        <label className="space-form-field">
          <span>
            Reason <span className="space-form-optional">(optional, staff only)</span>
          </span>
          <input name="reason" value={form.reason} onChange={handleChange} placeholder="e.g. Family use" />
        </label>
        {rooms.length > 0 && (
          <p className="space-form-note">
            Its {rooms.length} rooms are blocked too ({rooms.map((room) => room.name).join(', ')}), because they're inside it.
          </p>
        )}

        {/* Other spaces only when ticked */}
        {otherSpaces.length > 0 && (
          <fieldset className="space-form-checks">
            <legend>Also block</legend>
            {otherSpaces.map((other) => (
              <label key={other.id} className="space-form-check">
                <input type="checkbox" checked={alsoBlocked.includes(other.id)} onChange={() => toggleAlso(other.id)} />
                {other.name}
              </label>
            ))}
          </fieldset>
        )}

        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
