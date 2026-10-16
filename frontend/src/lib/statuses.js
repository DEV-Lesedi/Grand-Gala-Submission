// Status words keyed by what the API sends: label, tone, and guestLabel when guests differ.

// Tones: success green (settled), warning amber (waiting), muted grey, new plum.

// A booking (b_status), on the staff pages. enquiry is plum like on the Enquiries page.
export const BOOKING_STATUSES = {
  enquiry: { label: 'Enquiry', tone: 'new' },
  quoted: { label: 'Quoted', tone: 'warning' },
  pending_payment: { label: 'Pending payment', tone: 'muted' },
  confirmed: { label: 'Confirmed', tone: 'success' },
  completed: { label: 'Completed', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  expired: { label: 'Expired', tone: 'muted' },
  refunded: { label: 'Refunded', tone: 'muted' },
}

// My bookings cards. API sends quote_sent, confirmed, and tickets (not quoted, completed, paid).
export const MY_BOOKING_STATUSES = {
  enquiry: { label: 'Enquiry sent', tone: 'muted' },
  quote_sent: { label: 'Quote sent', tone: 'warning' },
  pending_payment: { label: 'Pending payment', tone: 'muted' },
  confirmed: { label: 'Confirmed', tone: 'success' },
  tickets: { label: 'Tickets', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
  expired: { label: 'Expired', tone: 'muted' },
  refunded: { label: 'Refunded', tone: 'muted' },
}

// A ticket order (to_status), on the guest's Your tickets page
export const TICKET_ORDER_STATUSES = {
  paid: { label: 'Tickets ready', tone: 'success' },
  pending_payment: { label: 'Not paid yet', tone: 'warning' },
  expired: { label: 'Not paid in time', tone: 'muted' },
  refunded: { label: 'Refunded', tone: 'muted' },
  cancelled: { label: 'Cancelled', tone: 'muted' },
}

// Quotation status. superseded means replaced. draft and paid are never stored.
export const QUOTATION_STATUSES = {
  draft: { label: 'Draft', tone: 'muted' },
  sent: { label: 'Sent', guestLabel: 'Awaiting your decision', tone: 'warning' },
  accepted: { label: 'Accepted', guestLabel: 'Accepted · awaiting payment', tone: 'warning' },
  paid: { label: 'Paid', tone: 'success' },
  expired: { label: 'Expired', tone: 'muted' },
  declined: { label: 'Declined', tone: 'muted' },
  superseded: { label: 'Superseded', tone: 'muted' },
}

// Sale status is computed from the sales window and stock; the API sends it.
export const EVENT_SALE_STATUSES = {
  on_sale: { label: 'On sale', tone: 'success' },
  sold_out: { label: 'Sold out', tone: 'muted' },
  not_on_sale_yet: { label: 'Not on sale yet', tone: 'muted' },
  sales_closed: { label: 'Sales closed', tone: 'muted' },
}

// Staff role (user_type.ut_name) on My account and Team
export const STAFF_ROLES = {
  superadmin: { label: 'Super admin', tone: 'new' },
  admin: { label: 'Admin', tone: 'new' },
  staff: { label: 'Staff', tone: 'muted' },
}

// A staff account: switched on or off (u_is_active)
export const ACCOUNT_STATUSES = {
  active: { label: 'Active', tone: 'success' },
  off: { label: 'Switched off', tone: 'muted' },
}

// The words for a status. audience is 'staff' or 'guest'. An unknown status shows as it is.
export function statusLabel(statuses, status, audience = 'staff') {
  const info = statuses[status]
  if (!info) return status
  if (audience === 'guest' && info.guestLabel) return info.guestLabel
  return info.label
}

// The chip colour for a status. Unknown: grey, so it's noticed (green would look settled).
export function statusTone(statuses, status) {
  const info = statuses[status]
  return info ? info.tone : 'muted'
}

// Paid: the API sends paidDate once the quotation's booking is paid (null until then)
export function isQuotationPaid(quotation) {
  return Boolean(quotation.paidDate)
}

// Guest view: sent, accepted, paid, expired, or declined.
// A sent quote past valid-until, or a replaced one, counts as expired.
export function guestQuotationStatus(quotation, today) {
  if (quotation.status === 'sent' && quotation.validUntil < today) return 'expired'
  if (quotation.status === 'sent') return 'sent'
  if (quotation.status === 'accepted') return isQuotationPaid(quotation) ? 'paid' : 'accepted'
  if (quotation.status === 'declined') return 'declined'
  return 'expired'
}
