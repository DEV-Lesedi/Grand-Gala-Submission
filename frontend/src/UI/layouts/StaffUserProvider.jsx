import { useEffect, useState } from 'react'
import { StaffUserContext } from '../hooks/staffUser'
import { apiGet, apiPatch, apiPost } from '../../services/api'
import { clearToken, getToken, saveToken } from '../../services/session'

// Token stays in the browser. On start, GET /auth/me says who the staff token belongs to.
export default function StaffUserProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(getToken('staff')))

  useEffect(() => {
    if (!getToken('staff')) return
    apiGet('/auth/me', { as: 'staff' })
      .then((apiUser) => {
        // A guest token saved here by mistake doesn't open the staff console
        if (apiUser.role === 'customer') clearToken('staff')
        else setUser(apiUser)
      })
      .catch(() => clearToken('staff')) // expired, switched off or signed out elsewhere
      .finally(() => setLoading(false))
  }, [])

  // Each returns an error message for the form, or '' once it worked
  async function signIn(email, password) {
    try {
      const answer = await apiPost('/staff/auth/login', { email, password })
      saveToken('staff', answer.token)
      setUser(answer.user)
      return ''
    } catch (error) {
      return error.message
    }
  }

  async function signOut() {
    try {
      await apiPost('/auth/logout', undefined, { as: 'staff' })
    } catch {
      // Already signed out on the server: forget it here anyway
    }
    clearToken('staff')
    setUser(null)
  }

  // "Your details": { name, surname, phone }
  async function saveDetails(details) {
    try {
      setUser(await apiPatch('/staff/me', details))
      return ''
    } catch (error) {
      return error.message
    }
  }

  // { current, next, again }. This device stays signed in; the others are signed out.
  async function changePassword(passwords) {
    try {
      await apiPost('/staff/me/password', passwords)
      return ''
    } catch (error) {
      return error.message
    }
  }

  const value = { user, loading, signIn, signOut, saveDetails, changePassword }
  return <StaffUserContext.Provider value={value}>{children}</StaffUserContext.Provider>
}
