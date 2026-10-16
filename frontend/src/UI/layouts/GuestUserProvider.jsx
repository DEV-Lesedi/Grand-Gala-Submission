import { useEffect, useState } from 'react'
import { GuestUserContext } from '../hooks/guestUser'
import { apiGet, apiPatch, apiPost } from '../../services/api'
import { clearToken, getToken, saveToken } from '../../services/session'

// The API keeps first name and surname apart; the guest pages show one full name
function toGuest(apiUser) {
  return {
    id: apiUser.id,
    name: [apiUser.name, apiUser.surname].filter(Boolean).join(' '),
    email: apiUser.email,
    phone: apiUser.phone ?? '',
  }
}

// Token stays in the browser. On start, GET /auth/me says who it belongs to.
export default function GuestUserProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(getToken('guest')))

  useEffect(() => {
    if (!getToken('guest')) return
    apiGet('/auth/me')
      .then((apiUser) => setUser(toGuest(apiUser)))
      .catch(() => clearToken('guest')) // expired or signed out elsewhere
      .finally(() => setLoading(false))
  }, [])

  // Each returns an error message for the form, or '' once it worked
  async function signIn(email, password) {
    try {
      const answer = await apiPost('/auth/login', { email, password })
      saveToken('guest', answer.token)
      setUser(toGuest(answer.user))
      return ''
    } catch (error) {
      return error.message
    }
  }

  async function register(name, email, password) {
    try {
      const answer = await apiPost('/auth/register', { name, email, password })
      saveToken('guest', answer.token)
      setUser(toGuest(answer.user))
      return ''
    } catch (error) {
      return error.message
    }
  }

  async function signOut() {
    try {
      await apiPost('/auth/logout')
    } catch {
      // Already signed out on the server: forget it here anyway
    }
    clearToken('guest')
    setUser(null)
  }

  // The Profile tab: { name, email, phone }
  async function saveProfile(details) {
    try {
      const apiUser = await apiPatch('/me', details)
      setUser(toGuest(apiUser))
      return ''
    } catch (error) {
      return error.message
    }
  }

  // { current, next, again }. This device stays signed in; the others are signed out.
  async function changePassword(passwords) {
    try {
      await apiPost('/me/password', passwords)
      return ''
    } catch (error) {
      return error.message
    }
  }

  const value = { user, loading, signIn, register, signOut, saveProfile, changePassword }
  return <GuestUserContext.Provider value={value}>{children}</GuestUserContext.Provider>
}
