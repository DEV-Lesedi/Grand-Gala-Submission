import { useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import { detailsProblem } from './accountRules'
import '../spaces/spaceForms.css'

// The email is the sign-in, so it isn't edited here.
// onSave({ name, surname, phone }) → the API's error message, or '' once saved
export default function DetailsForm({ user, onSave }) {
  const [form, setForm] = useState({ name: user.name, surname: user.surname, phone: user.phone ?? '' })
  const [message, setMessage] = useState({ text: '', isError: false })

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
    setMessage({ text: '', isError: false }) // an old "Details saved." would be wrong now
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const problem = detailsProblem(form) || (await onSave({ name: form.name.trim(), surname: form.surname.trim(), phone: form.phone.trim() }))
    if (problem) return setMessage({ text: problem, isError: true })
    setMessage({ text: 'Details saved.', isError: false })
  }

  return (
    <Card as="form" className="account-card space-form" onSubmit={handleSubmit} noValidate aria-labelledby="details-title">
      <h2 id="details-title" className="account-card-title">
        Your details
      </h2>
      <div className="space-form-row">
        <label className="space-form-field">
          First name
          <input name="name" autoComplete="given-name" value={form.name} onChange={handleChange} />
        </label>
        <label className="space-form-field">
          Surname
          <input name="surname" autoComplete="family-name" value={form.surname} onChange={handleChange} />
        </label>
      </div>
      <div className="space-form-row">
        <label className="space-form-field">
          {/* One span, so "(optional)" stays on the label's line */}
          <span>
            Phone <span className="space-form-optional">(optional)</span>
          </span>
          <input name="phone" type="tel" autoComplete="tel" value={form.phone} onChange={handleChange} />
        </label>
        <label className="space-form-field">
          Email
          <input value={user.email} readOnly aria-describedby="email-note" />
        </label>
      </div>
      <p id="email-note" className="account-note">
        Your email is how you sign in. Ask another admin to change it.
      </p>

      {message.text && <p className={message.isError ? 'space-form-error' : 'account-saved'}>{message.text}</p>}
      <Button type="submit" className="account-submit">
        Save details
      </Button>
    </Card>
  )
}
