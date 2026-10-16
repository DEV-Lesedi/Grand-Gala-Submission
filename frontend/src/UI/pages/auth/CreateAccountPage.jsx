import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import Button from '../../components/Button'
import Label from '../../components/Label'
import { useGuestUser } from '../../hooks/guestUser'
import AuthScreen from './AuthScreen'
import { safeNext } from './safeNext'

// Create a guest account; signs straight in and goes on to ?next= (or My bookings)
export default function CreateAccountPage() {
  const { register } = useGuestUser()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const next = safeNext(searchParams.get('next'))
  const [form, setForm] = useState({ name: '', email: '', password: '' })
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)

  const handleChange = (e) => {
    setForm((f) => ({ ...f, [e.target.name]: e.target.value }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSending(true)
    const problem = await register(form.name, form.email, form.password)
    setSending(false)
    if (problem) return setError(problem)
    navigate(next)
  }

  return (
    <AuthScreen title="Create an account" subtitle="Your stays, quotations and tickets in one place.">
      <form className="auth-form" onSubmit={handleSubmit}>
        <div className="auth-field">
          <Label htmlFor="name">Full name</Label>
          <input id="name" name="name" type="text" placeholder="Your name" value={form.name} onChange={handleChange} required />
        </div>

        <div className="auth-field">
          <Label htmlFor="email">Email address</Label>
          <input
            id="email"
            name="email"
            type="email"
            placeholder="you@email.com"
            value={form.email}
            onChange={handleChange}
            required
          />
        </div>

        <div className="auth-field">
          <Label htmlFor="password">Password</Label>
          <input
            id="password"
            name="password"
            type="password"
            placeholder="••••••••"
            value={form.password}
            onChange={handleChange}
            required
          />
        </div>

        <p className="auth-message">At least 8 characters, with a letter and a number.</p>

        {error && (
          <p className="auth-error" role="alert">
            {error}
          </p>
        )}

        <Button type="submit" className="auth-submit" disabled={sending}>
          {sending ? 'Creating…' : 'Create account'}
        </Button>

        <p className="auth-footer">
          Already have an account? <Link to={'/sign-in?next=' + encodeURIComponent(next)}>Sign in</Link>
        </p>
      </form>
    </AuthScreen>
  )
}
