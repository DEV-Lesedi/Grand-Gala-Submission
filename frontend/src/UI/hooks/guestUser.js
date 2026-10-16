import { createContext, useContext } from 'react'

// user: { id, name, email, phone } or null when signed out. name joins first and surname.
export const GuestUserContext = createContext({
  user: null,
  loading: false,
  signIn: async () => '',
  register: async () => '',
  signOut: async () => {},
  saveProfile: async () => '',
  changePassword: async () => '',
})

export function useGuestUser() {
  return useContext(GuestUserContext)
}
