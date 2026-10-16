import { useState } from 'react'
import { Link } from 'react-router-dom'
import Button from '../../components/Button'
import Label from '../../components/Label'
import { apiPost } from '../../../services/api'
import AuthScreen from './AuthScreen'

// Asks for a reset email. The answer is the same whether or not the account exists.
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('')
  const [sending, setSending] = useState(false)
  const [sent, setSent] = useState(false)
  const [error, setError] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setSending(true)
    setError('')
    try {
      await apiPost('/auth/forgot-password', { email })
      setSent(true)
    } catch (problem) {
      setError(problem.message)
    }
    setSending(false)
  }

  if (sent) {
    return (
      <AuthScreen title="Check your email" showBack>
        <p className="auth-message">
          If {email} has an account, we've sent it a link to choose a new password. The link works for 60 minutes.
        </p>
        <p className="auth-footer">
          <Link to="/sign-in">Back to sign in</Link>
        </p>
      </AuthScreen>
    )
  }

  return (
    <AuthScreen title="Reset your password" subtitle="We'll email you a link to choose a new one." showBack>
      <form className="auth-form" onSubmit={handleSubmit}>
        <div className="auth-field">
          <Label htmlFor="email">Email address</Label>
          <input
            id="email"
            name="email"
            type="email"
            placeholder="you@email.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </div>

        {error && (
          <p className="auth-error" role="alert">
            {error}
          </p>
        )}

        <Button type="submit" className="auth-submit" disabled={sending}>
          {sending ? 'Sending…' : 'Email me a link'}
        </Button>

        <p className="auth-footer">
          Remembered it? <Link to="/sign-in">Sign in</Link>
        </p>
      </form>
    </AuthScreen>
  )
}
