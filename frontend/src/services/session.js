// Sign-in tokens kept in the browser so a reload stays signed in.
// Guest and staff tokens are separate. side is 'guest' or 'staff'.

const KEYS = { guest: 'gg.guestToken', staff: 'gg.staffToken' }

export function getToken(side) {
  try {
    return localStorage.getItem(KEYS[side])
  } catch {
    return null // private window or storage switched off: just signed out
  }
}

export function saveToken(side, token) {
  try {
    localStorage.setItem(KEYS[side], token)
  } catch {
    // Can't remember it: the sign-in still works until the page reloads
  }
}

export function clearToken(side) {
  try {
    localStorage.removeItem(KEYS[side])
  } catch {
    // Nothing saved to clear
  }
}
