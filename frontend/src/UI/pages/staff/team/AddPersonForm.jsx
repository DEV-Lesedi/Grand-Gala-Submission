import { useState } from 'react'
import Button from '../../../components/Button'
import Modal from '../../../components/Modal'
import { newPersonProblem, rolesICanGive } from './teamRules'
import { STAFF_ROLES } from '../../../../lib/statuses'
import '../spaces/spaceForms.css'
import Select from '../../../components/Select'

// The API emails a 72-hour link; nobody else types the password.
// onAdd returns the API error, or '' once added.
export default function AddPersonForm({ accounts, me, onAdd, onClose }) {
  const roles = rolesICanGive(me)
  const [form, setForm] = useState({ name: '', surname: '', email: '', phone: '', role: roles.at(-1) })
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const problem = newPersonProblem(accounts, me, form)
    if (problem) return setError(problem)
    setSaving(true)
    const apiProblem = await onAdd({
      name: form.name.trim(),
      surname: form.surname.trim(),
      email: form.email.trim().toLowerCase(),
      phone: form.phone.trim(),
      role: form.role,
    })
    setSaving(false)
    if (apiProblem) return setError(apiProblem)
    onClose()
  }

  const footer = (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancel
      </Button>
      <Button type="submit" form="add-person-form" disabled={saving}>
        {saving ? 'Adding…' : 'Add person'}
      </Button>
    </>
  )

  return (
    <Modal title="Add a person" subtitle="We email them a link to choose their password." footer={footer} onClose={onClose}>
      <form id="add-person-form" className="space-form" onSubmit={handleSubmit} noValidate>
        <div className="space-form-row">
          <label className="space-form-field">
            First name
            <input name="name" value={form.name} onChange={handleChange} />
          </label>
          <label className="space-form-field">
            Surname
            <input name="surname" value={form.surname} onChange={handleChange} />
          </label>
        </div>
        <div className="space-form-row">
          <label className="space-form-field">
            Email
            <input name="email" type="email" value={form.email} onChange={handleChange} placeholder="name@grandgala.co.za" />
          </label>
          <label className="space-form-field">
            <span>
              Phone <span className="space-form-optional">(optional)</span>
            </span>
            <input name="phone" type="tel" value={form.phone} onChange={handleChange} />
          </label>
        </div>
        <div className="space-form-row">
          <label className="space-form-field">
            Role
            <Select name="role" value={form.role} onChange={handleChange}>
              {roles.map((role) => (
                <option key={role} value={role}>
                  {STAFF_ROLES[role].label}
                </option>
              ))}
            </Select>
          </label>
        </div>
        <p className="space-form-note">
          {form.role === 'admin'
            ? 'Admins can add and manage staff, and change spaces and rates.'
            : 'Staff handle bookings, enquiries, quotations and events.'}{' '}
          The link in their email works for 72 hours.
        </p>
        {error && <p className="space-form-error">{error}</p>}
      </form>
    </Modal>
  )
}
