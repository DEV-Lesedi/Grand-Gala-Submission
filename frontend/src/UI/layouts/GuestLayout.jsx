import { NavLink, Link, Outlet, useLocation } from 'react-router-dom'
import { NAV_LINKS, PHONE_TABS } from '../../lib/guest/navigation'
import { useGuestUser } from '../hooks/guestUser'
import './GuestLayout.css'

// hidePhoneTabs: no bottom tab bar on phone (e.g. Sign in)
export default function GuestLayout({ hidePhoneTabs = false }) {
  const { user } = useGuestUser()
  const initials = user ? user.name.split(' ').map((n) => n[0]).join('').slice(0, 2) : 'GG'
  const { pathname, search } = useLocation()
  const onProfile = pathname === '/my-bookings' && new URLSearchParams(search).get('tab') === 'profile'

  // Bookings and Account share /my-bookings; ?tab= decides
  function isPhoneTabActive(tab) {
    if (tab.key === 'account') return onProfile || pathname === '/sign-in' || pathname === '/create-account'
    if (tab.key === 'bookings') return pathname === '/my-bookings' && !onProfile
    if (tab.key === 'stays') return pathname === '/' || pathname.startsWith('/spaces')
    return pathname.startsWith(tab.to)
  }

  return (
    <div className="guest-layout">
      <header className="guest-header">
        <Link to="/" className="guest-logo">
          gg <span>connect</span>
        </Link>

        <nav className="guest-nav">
          {NAV_LINKS.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              end={link.to === '/'}
              // Stays is also the current page while browsing spaces (/spaces, /spaces/1)
              className={({ isActive }) =>
                `guest-nav-link ${isActive || (link.to === '/' && pathname.startsWith('/spaces')) ? 'is-active' : ''}`.trim()
              }
            >
              {link.label}
            </NavLink>
          ))}
        </nav>

        <div className="guest-header-actions">
          <NavLink to="/my-bookings" className={({ isActive }) => `guest-my-bookings ${isActive ? 'is-active' : ''}`.trim()}>
            My bookings
          </NavLink>
          <Link to={user ? '/my-bookings' : '/sign-in'} className="guest-account-pill">
            {user ? user.name.split(' ')[0] : 'Sign in'}
            <span className={`guest-avatar ${user ? 'is-signed-in' : ''}`.trim()}>{initials}</span>
          </Link>
        </div>
      </header>

      <main className="guest-main">
        {/* key: a new page fades in (page-enter in index.css) */}
        <div key={pathname} className="page-enter">
          <Outlet />
        </div>
      </main>

      <footer className="guest-footer">
        <p>© {new Date().getFullYear()} GG Connect · Grand Gala Gardens</p>
      </footer>

      {/* Phone only: bottom tab bar */}
      {!hidePhoneTabs && (
        <nav className="guest-tab-bar">
          {PHONE_TABS.map((tab) => (
            <Link
              key={tab.key}
              to={!user && tab.signedOutTo ? tab.signedOutTo : tab.to}
              className={`guest-tab-link ${isPhoneTabActive(tab) ? 'is-active' : ''}`.trim()}
              aria-current={isPhoneTabActive(tab) ? 'page' : undefined}
            >
              {tab.label}
            </Link>
          ))}
        </nav>
      )}
    </div>
  )
}
