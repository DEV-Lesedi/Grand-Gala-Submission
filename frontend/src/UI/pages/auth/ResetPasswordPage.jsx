import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import Button from '../../components/Button'
import Label from '../../components/Label'
import { apiPost } from '../../../services/api'
import AuthScreen from './AuthScreen'

// Opened from the email link /reset-password?token=…, for a reset or a new staff invite.
export default function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const [form, setForm] = useState({ password: '', again: '' })
  const [saving, setSaving] = useState(false)
  const [done, setDone] = useState(false)
  const [error, setError] = useState('')

  function handleChange(e) {
    setForm((f) => ({ ...f, [e.target.name]: e.target.value }))
  }

  async function handleSubmit(e) {
    e.preventDefault()
    // Local check so a typo doesn't wait on the server. The API checks password rules too.
    if (form.password !== form.again) {
      setError("The two passwords don't match.")
      return
    }

    setSaving(true)
    setError('')
    try {
      await apiPost('/auth/reset-password', { token, password: form.password })
      setDone(true)
    } catch (problem) {
      setError(problem.message)
    }
    setSaving(false)
  }

  if (token === '') {
    return (
      <AuthScreen title="This link isn't complete" showBack>
        <p className="auth-message">Open the link from your email again, or ask for a new one.</p>
        <p className="auth-footer">
          <Link to="/forgot-password">Email me a new link</Link>
        </p>
      </AuthScreen>
    )
  }

  if (done) {
    return (
      <AuthScreen title="Password saved">
        <p className="auth-message">You can sign in with your new password now.</p>
        <Button to="/sign-in" className="auth-submit">
          Sign in
        </Button>
        <p className="auth-footer">
          On the team? <Link to="/staff/sign-in">Staff sign in</Link>
        </p>
      </AuthScreen>
    )
  }

  return (
    <AuthScreen title="Choose a new password" subtitle="At least 8 characters, with a letter and a number.">
      <form className="auth-form" onSubmit={handleSubmit}>
        <div className="auth-field">
          <Label htmlFor="password">New password</Label>
          <input
            id="password"
            name="password"
            type="password"
            autoComplete="new-password"
            value={form.password}
            onChange={handleChange}
            required
          />
        </div>

        <div className="auth-field">
          <Label htmlFor="again">Type it again</Label>
          <input
            id="again"
            name="again"
            type="password"
            autoComplete="new-password"
            value={form.again}
            onChange={handleChange}
            required
          />
        </div>

        {error && (
          <p className="auth-error" role="alert">
            {error}
          </p>
        )}

        <Button type="submit" className="auth-submit" disabled={saving}>
          {saving ? 'Saving…' : 'Save password'}
        </Button>

        <p className="auth-footer">
          Link expired? <Link to="/forgot-password">Email me a new one</Link>
        </p>
      </form>
    </AuthScreen>
  )
}
