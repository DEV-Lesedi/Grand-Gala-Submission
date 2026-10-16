import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { SPACE_TYPES } from '../../../../lib/staff/spacesData'
import { newId } from '../../../../lib/ids'
import './spaceForms.css'
import Select from '../../../components/Select'

// "Add space": basics only; rates and blackouts come after
export default function AddSpaceForm({ onAdd, onClose }) {
  const [form, setForm] = useState({ name: '', type: 'room', capacity: '', isAccommodation: true, isHireable: false })
  const [error, setError] = useState('')

  // Checkboxes use .checked (true/false) instead of .value
  function handleChange(event) {
    const { name, type, checked, value } = event.target
    setForm({ ...form, [name]: type === 'checkbox' ? checked : value })
  }

  function handleSubmit(event) {
    event.preventDefault()
    const capacity = Number(form.capacity)
    if (!form.name.trim()) return setError('Give the space a name.')
    if (!(capacity >= 1)) return setError('Capacity must be at least 1 guest.')
    if (!form.isAccommodation && !form.isHireable) return setError('Tick at least one: overnight stays or event hire.')

    onAdd({
      id: newId('space'),
      name: form.name.trim(),
      type: form.type,
      capacity,
      isAccommodation: form.isAccommodation,
      isHireable: form.isHireable,
      parentId: null,
      isActive: false, // new spaces start switched off until they have a rate
      photo: '/photos/event.jpg', // placeholder until photo upload exists
      rates: [],
      blackouts: [],
    })
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="add-space-form">
        Add space
      </Button>
    </>
  )

  return (
    <Modal title="Add a space" subtitle="It starts switched off, so guests can't book it until it has a rate." footer={footer} onClose={onClose}>
      <form id="add-space-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <label className="space-form-field">
          Name
          <input name="name" value={form.name} onChange={handleChange} placeholder="e.g. Birdsong Cottage" />
        </label>
        <div className="space-form-row">
          <label className="space-form-field">
            Type
            <Select name="type" value={form.type} onChange={handleChange}>
              {SPACE_TYPES.map((type) => (
                <option key={type.value} value={type.value}>
                  {type.label}
                </option>
              ))}
            </Select>
          </label>
          <label className="space-form-field">
            Capacity (guests)
            <input name="capacity" type="number" min="1" inputMode="numeric" value={form.capacity} onChange={handleChange} />
          </label>
        </div>
        <label className="space-form-check">
          <input name="isAccommodation" type="checkbox" checked={form.isAccommodation} onChange={handleChange} />
          Overnight stays
        </label>
        <label className="space-form-check">
          <input name="isHireable" type="checkbox" checked={form.isHireable} onChange={handleChange} />
          Event hire
        </label>
        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
