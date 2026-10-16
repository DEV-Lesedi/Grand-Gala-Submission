// Password rules for guests and staff. Each returns an error, or '' when fine.
// The API checks again, including the current password; these only answer quickly.

// The new one strong enough and typed twice
export function passwordProblem({ current, next, again }) {
  if (!current) return 'Enter your current password.'
  if (weakPassword(next)) return 'Use at least 8 characters, with a letter and a number.'
  if (next === current) return 'Choose a password you haven’t used here yet.'
  if (next !== again) return 'The new passwords don’t match.'
  return ''
}

// Too short, or missing a letter or a number
export function weakPassword(password) {
  return password.length < 8 || !/[a-z]/i.test(password) || !/\d/.test(password)
}
