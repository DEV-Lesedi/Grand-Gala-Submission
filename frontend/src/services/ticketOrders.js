// Guest ticket orders. A signed-out buyer gets an access token kept only here.
// This file saves it, sends X-Ticket-Access, and passes that header to payments.
import { apiGet, apiPost } from './api'

// ---------- The access token (one per order reference, kept in this browser) ----------

function tokenKey(reference) {
  return 'gg.ticketAccess.' + reference
}

function savedToken(reference) {
  try {
    return localStorage.getItem(tokenKey(reference))
  } catch {
    return null // private window or storage switched off
  }
}

function saveToken(reference, token) {
  try {
    localStorage.setItem(tokenKey(reference), token)
  } catch {
    // Can't remember it: the email link still works
  }
}

// Proves this browser bought the order, or {} if there is no token.
// A signed-in buyer does not need one.
export function ticketAccessHeaders(reference) {
  const token = savedToken(reference)
  return token ? { 'X-Ticket-Access': token } : {}
}

// ---------- Ticket orders ----------

// Holds tickets until paid. items are { tierId, quantity }; promoCode is optional.
export async function createTicketOrder({ eventId, items }, contact, promoCode) {
  const [name, ...rest] = contact.fullName.trim().split(/\s+/)
  const body = {
    eventId,
    items,
    name,
    surname: rest.join(' '),
    email: contact.email.trim(),
    phone: contact.phone.trim() || null,
  }
  if (promoCode) body.promoCode = promoCode
  const order = await apiPost('/ticket-orders', body)
  // Signed out: the order's own token lets this browser pay for it and open the tickets later
  if (order.accessToken) saveToken(order.reference, order.accessToken)
  return order
}

// Buyer's order via sign-in or token (checkout or email link). null if not theirs.
export async function loadTicketOrder(reference, linkToken) {
  const headers = linkToken ? { 'X-Ticket-Access': linkToken } : ticketAccessHeaders(reference)
  try {
    const order = await apiGet('/ticket-orders/' + encodeURIComponent(reference), { headers })
    // Remember the email token so tickets open here without the link
    if (linkToken) saveToken(reference, linkToken)
    return order
  } catch (error) {
    if (error.status === 404 || error.status === 401) return null
    throw error
  }
}

// Discount for this amount: { discount, total }. Throws the API message if unusable.
// discount is 0 under the code's minimum spend.
export async function checkTicketPromo(code, amount) {
  const answer = await apiPost('/promotions/validate', { code, kind: 'tickets', amount })
  return { discount: answer.discount, total: Math.round((amount - answer.discount) * 100) / 100 }
}
