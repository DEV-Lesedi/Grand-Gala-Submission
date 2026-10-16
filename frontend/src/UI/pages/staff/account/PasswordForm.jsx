import { useState } from 'react'
import Button from '../../../components/Button'
import Card from '../../../components/Card'
import { passwordProblem } from '../../../../lib/passwordRules'
import '../spaces/spaceForms.css'

const EMPTY = { current: '', next: '', again: '' }

// onChange returns the API error, or ''. Other devices are signed out.
export default function PasswordForm({ onChange }) {
  const [form, setForm] = useState(EMPTY)
  const [message, setMessage] = useState({ text: '', isError: false })

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
    setMessage({ text: '', isError: false })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    const problem = passwordProblem(form) || (await onChange(form))
    if (problem) return setMessage({ text: problem, isError: true })
    setForm(EMPTY) // never leave passwords sitting in the boxes
    setMessage({ text: 'Password changed.', isError: false })
  }

  return (
    <Card as="form" className="account-card space-form" onSubmit={handleSubmit} noValidate aria-labelledby="password-title">
      <h2 id="password-title" className="account-card-title">
        Password
      </h2>
      <label className="space-form-field">
        Current password
        <input name="current" type="password" autoComplete="current-password" value={form.current} onChange={handleChange} />
      </label>
      <div className="space-form-row">
        <label className="space-form-field">
          New password
          <input name="next" type="password" autoComplete="new-password" value={form.next} onChange={handleChange} />
        </label>
        <label className="space-form-field">
          New password again
          <input name="again" type="password" autoComplete="new-password" value={form.again} onChange={handleChange} />
        </label>
      </div>
      <p className="account-note">At least 8 characters, with a letter and a number.</p>

      {message.text && <p className={message.isError ? 'space-form-error' : 'account-saved'}>{message.text}</p>}
      <Button type="submit" className="account-submit">
        Change password
      </Button>
    </Card>
  )
}
