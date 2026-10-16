import { useState } from 'react'
import Label from '../../../../components/Label'
import Button from '../../../../components/Button'
import Card from '../../../../components/Card'
import { passwordProblem } from '../../../../../lib/passwordRules'
import './ProfileTab.css'

const NO_PASSWORDS = { current: '', next: '', again: '' }

// onSave and onChangePassword return an API error, or ''. Password change signs out other devices.
export default function ProfileTab({ user = { name: '', email: '', phone: '' }, onSave, onChangePassword, onSignOut }) {
  return (
    <div className="profile">
      <div className="profile-cards">
        <DetailsCard user={user} onSave={onSave} />
        {onChangePassword && <PasswordCard onChange={onChangePassword} />}
      </div>

      {onSignOut && (
        <div className="profile-sign-out">
          <p>
            Signed in as <strong>{user.email}</strong>
          </p>
          <Button variant="secondary" onClick={onSignOut}>
            Sign out
          </Button>
        </div>
      )}
    </div>
  )
}

function DetailsCard({ user, onSave }) {
  const saved = { name: user.name, email: user.email, phone: user.phone ?? '' }
  const [form, setForm] = useState(saved)
  const [message, setMessage] = useState({ text: '', isError: false })
  const [saving, setSaving] = useState(false)
  const changed = form.name !== saved.name || form.email !== saved.email || form.phone !== saved.phone

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
    setMessage({ text: '', isError: false })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    const problem = await onSave?.(form)
    setSaving(false)
    setMessage(problem ? { text: problem, isError: true } : { text: 'Details saved.', isError: false })
  }

  return (
    <Card as="form" className="profile-card" onSubmit={handleSubmit} aria-labelledby="profile-details-title">
      <div className="profile-card-head">
        <h2 id="profile-details-title">Your details</h2>
        <p>We use these for your booking confirmations and receipts.</p>
      </div>

      <div className="profile-field">
        <Label htmlFor="profile-name">Full name</Label>
        <input id="profile-name" name="name" type="text" autoComplete="name" value={form.name} onChange={handleChange} />
      </div>
      <div className="profile-field">
        <Label htmlFor="profile-email">Email address</Label>
        <input id="profile-email" name="email" type="email" autoComplete="email" value={form.email} onChange={handleChange} />
      </div>
      <div className="profile-field">
        <Label htmlFor="profile-phone">
          Phone number <span className="profile-optional">optional</span>
        </Label>
        <input id="profile-phone" name="phone" type="tel" autoComplete="tel" placeholder="082 555 0142" value={form.phone} onChange={handleChange} />
      </div>

      <FormFooter message={message}>
        <Button type="submit" disabled={saving || !changed}>
          {saving ? 'Saving…' : 'Save changes'}
        </Button>
      </FormFooter>
    </Card>
  )
}

function PasswordCard({ onChange }) {
  const [form, setForm] = useState(NO_PASSWORDS)
  const [message, setMessage] = useState({ text: '', isError: false })
  const [saving, setSaving] = useState(false)
  const [showPasswords, setShowPasswords] = useState(false)
  const inputType = showPasswords ? 'text' : 'password'

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
    setMessage({ text: '', isError: false })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    const problem = passwordProblem(form) || (await onChange(form))
    setSaving(false)
    if (problem) return setMessage({ text: problem, isError: true })
    setForm(NO_PASSWORDS) // never leave passwords sitting in the boxes
    setMessage({ text: 'Password changed. Your other devices have been signed out.', isError: false })
  }

  return (
    <Card as="form" className="profile-card" onSubmit={handleSubmit} noValidate aria-labelledby="profile-password-title">
      <div className="profile-card-head">
        <h2 id="profile-password-title">Password</h2>
        <p>Changing it signs you out on your other devices.</p>
      </div>

      <div className="profile-field">
        <Label htmlFor="profile-current">Current password</Label>
        <input id="profile-current" name="current" type={inputType} autoComplete="current-password" value={form.current} onChange={handleChange} />
      </div>
      <div className="profile-field">
        <Label htmlFor="profile-next">New password</Label>
        <input
          id="profile-next"
          name="next"
          type={inputType}
          autoComplete="new-password"
          aria-describedby="profile-password-hint"
          value={form.next}
          onChange={handleChange}
        />
        <p id="profile-password-hint" className="profile-hint">
          At least 8 characters, with a letter and a number.
        </p>
      </div>
      <div className="profile-field">
        <Label htmlFor="profile-again">New password again</Label>
        <input id="profile-again" name="again" type={inputType} autoComplete="new-password" value={form.again} onChange={handleChange} />
      </div>
      <label className="profile-show">
        <input type="checkbox" checked={showPasswords} onChange={(event) => setShowPasswords(event.target.checked)} />
        Show passwords
      </label>

      <FormFooter message={message}>
        <Button type="submit" disabled={saving}>
          {saving ? 'Changing…' : 'Change password'}
        </Button>
      </FormFooter>
    </Card>
  )
}

function FormFooter({ message, children }) {
  return (
    <div className="profile-card-foot">
      {message.text && (
        <p className={message.isError ? 'profile-error' : 'profile-saved'} role={message.isError ? 'alert' : 'status'}>
          {message.text}
        </p>
      )}
      {children}
    </div>
  )
}
