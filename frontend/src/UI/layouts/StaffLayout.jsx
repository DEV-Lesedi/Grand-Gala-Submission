import { NavLink, Link, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useApiData } from '../hooks/useApiData'
import { apiGet } from '../../services/api'
import { useStaffUser } from '../hooks/staffUser'
import { initials } from '../pages/staff/account/accountRules'
import { canSeeTeam } from '../pages/staff/team/teamRules'
import { SIDEBAR_LINKS, MOBILE_TABS } from '../../lib/staff/navigation'
import './StaffLayout.css'

// Adds "is-active" to the open page's link
const navLinkClass = (baseClass) => ({ isActive }) => `${baseClass} ${isActive ? 'is-active' : ''}`.trim()

export default function StaffLayout() {
  const { user, loading, signOut } = useStaffUser()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  // Sidebar counts, refreshed on each page change so they stay current.
  const counts = useApiData(() => (user ? apiGet('/staff/nav-counts') : Promise.resolve({})), [user, pathname])

  // Checking a saved sign-in with the API: draw nothing yet, so a reload doesn't bounce to sign-in
  if (loading) return null

  // Signed out: staff pages stay closed (the API refuses staff data without a staff sign-in too)
  if (!user) return <Navigate to="/staff/sign-in" replace />

  async function handleSignOut() {
    await signOut()
    navigate('/staff/sign-in')
  }

  return (
    <div className="staff-layout">
      {/* Desktop: dark sidebar */}
      <aside className="staff-sidebar" aria-label="Staff menu">
        <Link to="/staff" className="staff-sidebar-logo">
          gg connect <span>staff</span>
        </Link>

        <nav className="staff-sidebar-nav">
          {SIDEBAR_LINKS.filter((link) => !link.adminOnly || canSeeTeam(user)).map((link) => (
            <NavLink key={link.to} to={link.to} end={link.to === '/staff'} className={navLinkClass('staff-sidebar-link')}>
              {link.label}
              {/* "> 0": React would print a count of 0 */}
              {counts.data?.[link.countKey] > 0 && <span className="staff-sidebar-count">{counts.data[link.countKey]}</span>}
            </NavLink>
          ))}
        </nav>

        {/* Your name opens My account */}
        <div className="staff-sidebar-user">
          <Link to="/staff/account" className="staff-sidebar-me">
            <span className="staff-avatar">{initials(user)}</span>
            {user.name}
          </Link>
          ·
          <button type="button" className="staff-sidebar-sign-out" onClick={handleSignOut}>
            sign out
          </button>
        </div>
      </aside>

      <div className="staff-content">
        {/* The current staff page (e.g. Today) is drawn here */}
        <main className="staff-page">
          {/* key: a new page fades in (page-enter in index.css) */}
          <div key={pathname} className="page-enter">
            <Outlet />
          </div>
        </main>

        {/* iPad and phone: five-tab bar at the bottom */}
        <nav className="staff-mobile-tabs">
          {MOBILE_TABS.map((tab) => (
            <NavLink key={tab.to} to={tab.to} className={navLinkClass('staff-mobile-tab')}>
              {tab.label}
            </NavLink>
          ))}
        </nav>
      </div>
    </div>
  )
}
