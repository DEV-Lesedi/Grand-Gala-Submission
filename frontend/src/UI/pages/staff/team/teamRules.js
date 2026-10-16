import { detailsProblem, findByEmail } from '../account/accountRules'

// Who may do what on the Team page:
//   super admin → adds and manages admins and staff
//   admin       → adds and manages staff only
//   staff       → no Team page
// Nobody manages themselves (no locking yourself out), and nobody manages the super admin.
// The API checks these again on every change

export const TEAM_ROLES = ['superadmin', 'admin', 'staff'] // customers aren't on the team

export function canSeeTeam(me) {
  return me.role === 'superadmin' || me.role === 'admin'
}

// The roles I can give someone, newest-first in the Role list
export function rolesICanGive(me) {
  if (me.role === 'superadmin') return ['admin', 'staff']
  if (me.role === 'admin') return ['staff']
  return []
}

export function canManage(me, person) {
  return me.id !== person.id && rolesICanGive(me).includes(person.role)
}

// "Add a person": details as on My account, plus a new email and a role I may give.
// No password: the API emails them a link to choose their own.
export function newPersonProblem(accounts, me, form) {
  const problem = detailsProblem(form)
  if (problem) return problem
  const email = form.email.trim().toLowerCase()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return 'Enter an email address like name@grandgala.co.za.'
  if (findByEmail(accounts, email)) return `${email} already has an account.` // app_user.u_email is UNIQUE
  if (!rolesICanGive(me).includes(form.role)) return 'Choose a role.'
  return ''
}
