import { Link, NavLink } from 'react-router-dom'
import { useStaffUser } from '../../../hooks/staffUser'
import { initials } from '../account/accountRules'
import HomeIcon from './HomeIcon'
import './StaffPageHeader.css'

// mobileSubtitle={null} hides the phone subtitle.
export default function StaffPageHeader({ title, subtitle, actions, mobileTitle = title, mobileSubtitle = subtitle }) {
  const { user } = useStaffUser()

  return (
    <>
      <header className="page-header">
        <div className="page-header-text">
          <h1 className="page-header-title">{title}</h1>
          <p className="page-header-subtitle">{subtitle}</p>
        </div>
        {actions && <div className="page-header-actions">{actions}</div>}
      </header>

      <header className="mobile-header">
        <div>
          <p className="mobile-header-title">{mobileTitle}</p>
          {mobileSubtitle && <p className="mobile-header-subtitle">{mobileSubtitle}</p>}
        </div>
        <div className="mobile-header-buttons">
          {/* Home = Today. "end": active only on /staff */}
          <NavLink
            to="/staff"
            end
            className={({ isActive }) => `mobile-header-home ${isActive ? 'is-active' : ''}`.trim()}
            aria-label="Today"
          >
            <HomeIcon size={20} />
          </NavLink>
          {/* Avatar opens My account */}
          <Link to="/staff/account" className="staff-avatar" aria-label="My account">
            {initials(user)}
          </Link>
        </div>
      </header>
    </>
  )
}
