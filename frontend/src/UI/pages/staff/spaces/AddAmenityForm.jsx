import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { TODAY } from '../../../../lib/dates'
import { newId } from '../../../../lib/ids'
import './spaceForms.css'

export default function AddAmenityForm({ onAdd, onClose }) {
  const [form, setForm] = useState({ name: '', price: '' })
  const [error, setError] = useState('')

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  function handleSubmit(event) {
    event.preventDefault()
    const price = Number(form.price)
    if (!form.name.trim()) return setError('Give the amenity a name.')
    if (form.price === '' || !(price >= 0)) return setError('Enter a price of R0 or more.')
    onAdd({ id: newId('amenity'), name: form.name.trim(), price, validFrom: TODAY, isActive: true })
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="add-amenity-form">
        Add amenity
      </Button>
    </>
  )

  return (
    <Modal title="Add an amenity" footer={footer} onClose={onClose}>
      <form id="add-amenity-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <div className="space-form-row">
          <label className="space-form-field">
            Name
            <input name="name" value={form.name} onChange={handleChange} placeholder="e.g. Picnic basket" />
          </label>
          <label className="space-form-field">
            Price (R)
            <input name="price" type="number" min="0" inputMode="numeric" value={form.price} onChange={handleChange} />
          </label>
        </div>
        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
