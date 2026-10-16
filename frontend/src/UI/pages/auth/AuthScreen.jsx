import { useNavigate } from 'react-router-dom'
import './AuthScreen.css'

// Photo and form card. showBack: phone back button. brand, headline, tagline sit on the photo.
export default function AuthScreen({
  title,
  subtitle,
  showBack = false,
  brand = 'gg connect',
  headline = 'One account for stays, events and tickets.',
  tagline = 'Your stays, quotations and tickets in one place.',
  children,
}) {
  const navigate = useNavigate()

  // Back within the site; if this is the first page, go home instead
  function goBack() {
    const visitNumber = window.history.state?.idx ?? 0
    if (visitNumber > 0) navigate(-1)
    else navigate('/')
  }

  return (
    <div className="auth-screen">
      <div className="auth-photo">
        {showBack && (
          <button type="button" className="auth-back" onClick={goBack} aria-label="Back">
            ←
          </button>
        )}
        <div className="auth-photo-text">
          <p className="auth-brand">{brand}</p>
          <p className="auth-headline">{headline}</p>
          <p className="auth-photo-sub">{tagline}</p>
        </div>
      </div>

      <div className="auth-side">
        <div className="auth-card">
          <h1 className="auth-title">{title}</h1>
          {subtitle && <p className="auth-subtitle">{subtitle}</p>}
          {children}
        </div>
      </div>
    </div>
  )
}
