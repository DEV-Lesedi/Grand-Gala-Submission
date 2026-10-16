import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useGuestUser } from '../hooks/guestUser'

// Needs a guest account. Signed out goes to Sign in, which returns here via ?next=.
export default function RequireGuest() {
  const { user, loading } = useGuestUser()
  const { pathname, search } = useLocation()

  // Checking a saved sign-in with the API: draw nothing yet, so a reload doesn't bounce to Sign in
  if (loading) return null
  if (!user) return <Navigate to={'/sign-in?next=' + encodeURIComponent(pathname + search)} replace />
  return <Outlet />
}
