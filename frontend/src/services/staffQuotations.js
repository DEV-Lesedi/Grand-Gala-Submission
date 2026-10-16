// Staff quotations. API lines use position and label; the builder uses id and description.
import { allPages, apiGet, apiPost, orNullOn404 } from './api'
import { toNumber } from '../lib/pricing'

function toStaffQuotation(apiQuotation) {
  return {
    ...apiQuotation,
    items: (apiQuotation.items ?? []).map((item) => ({
      id: `line-${item.position}`,
      description: item.label,
      note: '',
      quantity: item.quantity,
      unitPrice: item.unitPrice,
    })),
    previousVersions: (apiQuotation.previousVersions ?? []).map((old) => ({
      version: old.version,
      status: old.status,
      date: old.date,
    })),
  }
}

// Every quotation (each page of the list), newest first
export async function loadAllQuotations() {
  const quotations = await allPages('/staff/quotations', 'quotations')
  return quotations.map(toStaffQuotation)
}

// One quotation, or null if there's no such reference
export async function loadQuotation(reference) {
  const quotation = await orNullOn404(apiGet('/staff/quotations/' + encodeURIComponent(reference)))
  return quotation && toStaffQuotation(quotation)
}

// One enquiry (the guest and event the quotation is for), or null
export async function loadEnquiry(reference) {
  return orNullOn404(apiGet('/staff/enquiries/' + encodeURIComponent(reference)))
}

// Today's prices from Spaces & rates, for "Add from rate card"
export async function loadRateCard() {
  const rates = await apiGet('/staff/rate-card')
  return rates.map((rate, index) => ({ ...rate, id: `rate-${index}` }))
}

// Sends a new version. The API numbers it, replaces the last, and copies the enquiry plan.
export async function sendQuotation({ bookingReference, items, discount, notes, validUntil }) {
  const lines = items
    .filter((item) => item.description.trim() !== '')
    .map((item) => ({ label: item.description.trim(), quantity: toNumber(item.quantity), unitPrice: toNumber(item.unitPrice) }))
  const saved = await apiPost('/staff/quotations', {
    bookingReference,
    items: lines,
    discount: toNumber(discount),
    notes,
    validUntil,
  })
  return toStaffQuotation(saved)
}
