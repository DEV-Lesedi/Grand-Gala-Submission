import { formatDate } from '../../../../lib/dates'
import { eventText } from '../enquiries/enquiryHelpers'

// "All" (status null) shows everything
export function matchesFilter(quotation, filter) {
  return filter.status === null || quotation.status === filter.status
}

// Drop a typed minus sign
export function withoutMinus(text) {
  return text.replace('-', '')
}

// "v2 · Wedding reception · Open Garden · 80 guests"
export function quotationSummary(quotation, enquiry) {
  return `v${quotation.version} · ${eventText(enquiry)} · ${enquiry.estimatedGuests} guests`
}

// Amber: valid until or awaiting payment. Green: paid. Red: lapsed.
export function quotationStatusLine(quotation) {
  if (quotation.status === 'sent') return { text: `Valid until ${formatDate(quotation.validUntil)}`, tone: 'warning' }
  if (quotation.status === 'accepted') {
    if (quotation.paidDate) return { text: `Paid ${formatDate(quotation.paidDate)}`, tone: 'success' }
    return { text: 'Accepted · awaiting payment', tone: 'warning' }
  }
  if (quotation.status === 'expired') return { text: `Lapsed ${formatDate(quotation.validUntil)}`, tone: 'danger' }
  return { text: '', tone: 'muted' }
}

// Accepted quotations are final (the API refuses a new version too)
export function canRevise(quotation) {
  return quotation.status !== 'accepted'
}
