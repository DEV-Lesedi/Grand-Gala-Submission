// How a quotation's lines add up, for the builder and the guest quotation page.
// Recompute on save and at checkout; never trust totals from the browser.

// Input text → number; blank or negative → 0 (min="0" doesn't stop typing)
export function toNumber(value) {
  return Math.max(0, Number(value) || 0)
}

export function lineAmount(item) {
  return toNumber(item.quantity) * toNumber(item.unitPrice)
}

// Totals, worked out every render. VAT is included: total × 15 / 115
export function quotationTotals(items, discount) {
  const lineItems = items.reduce((sum, item) => sum + lineAmount(item), 0)
  const total = Math.max(0, lineItems - toNumber(discount))
  return { lineItems, discount: toNumber(discount), vat: Math.round((total * 15) / 115), total }
}
