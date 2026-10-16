// Ticket totals and the Checkout link. Tiers and stock come from the API.
// Stock is re-checked when the order is placed; the page shows what it loaded.

// How many tickets are chosen and what they cost. quantities: { tierId: number }
export function ticketTotal(tiers, quantities) {
  return tiers.reduce(
    (sum, tier) => {
      const quantity = quantities[tier.id] ?? 0
      return { count: sum.count + quantity, total: sum.total + quantity * tier.price }
    },
    { count: 0, total: 0 },
  )
}

// Checkout link: event id and tier counts, never a price. Checkout reprices from the tiers.
// /checkout?event=12&31=2&32=1
export function checkoutLink(event, quantities) {
  const params = new URLSearchParams({ event: event.id })
  for (const tier of event.tiers) {
    if (quantities[tier.id] > 0) params.set(tier.id, String(quantities[tier.id]))
  }
  return `/checkout?${params}`
}
