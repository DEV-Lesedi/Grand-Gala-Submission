import { useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import Button from '../../components/Button'
import Label from '../../components/Label'
import { useGuestUser } from '../../hooks/guestUser'
import AuthScreen from './AuthScreen'
import { safeNext } from './safeNext'

// ?next= is where RequireGuest sends the guest back after sign-in.
export default function SignInPage() {
  const { signIn } = useGuestUser()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const next = safeNext(searchParams.get('next'))
  const [form, setForm] = useState({ email: '', password: '' })
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)

  const handleChange = (e) => {
    setForm((f) => ({ ...f, [e.target.name]: e.target.value }))
  }

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSending(true)
    const problem = await signIn(form.email, form.password)
    setSending(false)
    if (problem) return setError(problem)
    navigate(next)
  }

  return (
    <AuthScreen title="Sign in" subtitle="Your stays, quotations and tickets in one place." showBack>
      <form className="auth-form" onSubmit={handleSubmit}>
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

        <Link to="/forgot-password" className="auth-forgot">
          Forgot password?
        </Link>

        {error && (
          <p className="auth-error" role="alert">
            {error}
          </p>
        )}

        <Button type="submit" className="auth-submit" disabled={sending}>
          {sending ? 'Signing in…' : 'Sign in'}
        </Button>

        <p className="auth-divider">or</p>

        <Button variant="secondary" to="/checkout?mode=guest" className="auth-guest">
          Continue as guest (tickets only)
        </Button>

        <p className="auth-footer">
          New here? <Link to={'/create-account?next=' + encodeURIComponent(next)}>Create an account</Link>
        </p>
      </form>
    </AuthScreen>
  )
}
