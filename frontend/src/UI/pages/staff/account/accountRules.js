// Staff detail checks (passwords live in lib/passwordRules.js). Each returns an error, or ''.
// The API checks all of these again; these just answer quickly before anything is sent.

export function findByEmail(accounts, email) {
  return accounts.find((account) => account.email.toLowerCase() === email.trim().toLowerCase())
}

// "Your details": names needed, a phone number is optional but must look like one
export function detailsProblem({ name, surname, phone }) {
  if (!name.trim()) return 'Enter your first name.'
  if (!surname.trim()) return 'Enter your surname.'
  const digits = phone.replace(/\D/g, '')
  if (phone.trim() && (!/^\+?[\d\s()-]+$/.test(phone.trim()) || digits.length < 9)) return 'Enter a phone number like +27 82 555 0142.'
  return ''
}

// "Lesedi Kutumela" → "LK"
export function initials(account) {
  return `${account.name.trim()[0] ?? ''}${account.surname.trim()[0] ?? ''}`.toUpperCase()
}
