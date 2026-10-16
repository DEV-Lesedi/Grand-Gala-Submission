import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import Button from '../../components/Button'
import Label from '../../components/Label'
import { useStaffUser } from '../../hooks/staffUser'
import AuthScreen from './AuthScreen'
import './StaffSignInPage.css'

// Staff sign-in only. No guest continue or create-account; an admin sets up staff.
export default function StaffSignInPage() {
  const { signIn } = useStaffUser()
  const navigate = useNavigate()
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)

  function handleChange(event) {
    setForm({ ...form, [event.target.name]: event.target.value })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSending(true)
    const problem = await signIn(form.email, form.password)
    setSending(false)
    if (problem) return setError(problem)
    navigate('/staff')
  }

  return (
    <div className="staff-sign-in">
      <AuthScreen
        title="Staff sign in"
        subtitle="For the Grand Gala Gardens team."
        brand="gg connect staff"
        headline="Bookings, enquiries and events, all in one console."
        tagline="For the Grand Gala Gardens team."
      >
        <form className="auth-form" onSubmit={handleSubmit} noValidate>
          <div className="auth-field">
            <Label htmlFor="staff-email">Email address</Label>
            <input id="staff-email" name="email" type="email" autoComplete="username" placeholder="you@grandgala.co.za" value={form.email} onChange={handleChange} />
          </div>

          <div className="auth-field">
            <Label htmlFor="staff-password">Password</Label>
            <input id="staff-password" name="password" type="password" autoComplete="current-password" placeholder="••••••••" value={form.password} onChange={handleChange} />
          </div>

          {error && (
            <p className="auth-error" role="alert">
              {error}
            </p>
          )}

          <Button type="submit" className="auth-submit" disabled={sending}>
            {sending ? 'Signing in…' : 'Sign in'}
          </Button>

          <p className="auth-footer">Forgot your password? Ask an admin to reset it.</p>
          <p className="auth-footer">
            Not staff? <Link to="/sign-in">Guest sign in</Link>
          </p>
        </form>
      </AuthScreen>
    </div>
  )
}
