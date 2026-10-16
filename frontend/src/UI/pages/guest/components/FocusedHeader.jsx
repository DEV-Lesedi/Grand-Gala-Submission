import { Link } from 'react-router-dom'
import './FocusedHeader.css'

// Booking header with no site navigation, so nothing pulls the guest away.
export default function FocusedHeader({ title, note }) {
  return (
    <header className="focused-header">
      <Link to="/" className="focused-header-logo">
        gg <span>connect</span>
      </Link>
      <span className="focused-header-title">{title}</span>
      <span className="focused-header-note">
        <span aria-hidden="true">🔒</span> {note}
      </span>
    </header>
  )
}
