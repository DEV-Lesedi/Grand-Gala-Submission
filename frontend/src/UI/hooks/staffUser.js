import { createContext, useContext } from 'react'

// user: { id, name, surname, email, phone, role } or null. loading checks a saved sign-in.
export const StaffUserContext = createContext({
  user: null,
  loading: false,
  signIn: async () => '',
  signOut: async () => {},
  saveDetails: async () => '',
  changePassword: async () => '',
})

export function useStaffUser() {
  return useContext(StaffUserContext)
}
