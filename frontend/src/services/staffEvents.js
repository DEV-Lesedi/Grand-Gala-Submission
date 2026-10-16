// Staff events and tickets. The API already counts sold tickets and revenue.
// Events, tiers, and orders keep the API field names and the database id.
import { apiGet, apiPost, apiPut } from './api'

// Staff event. namesById maps space id to name; spaceNames joins those, or the venue.
// status: confirmed or cancelled. saleStatus: on_sale, sold_out, not_on_sale_yet, sales_closed.
export function toStaffEvent(apiEvent, namesById) {
  const names = apiEvent.spaceIds.map((id) => namesById[id]).filter(Boolean)
  return {
    id: apiEvent.id,
    reference: apiEvent.reference,
    title: apiEvent.title,
    venue: apiEvent.venue,
    spaceIds: apiEvent.spaceIds,
    spaceNames: names.length > 0 ? names.join(' + ') : apiEvent.venue,
    date: apiEvent.date,
    startTime: apiEvent.startTime,
    endTime: apiEvent.endTime,
    image: apiEvent.image ?? '/photos/event.jpg',
    status: apiEvent.status,
    saleStatus: apiEvent.saleStatus,
    ticketsSold: apiEvent.ticketsSold,
    revenue: apiEvent.revenue,
    capacity: apiEvent.capacity,
    tiers: apiEvent.ticketTiers.map((tier) => ({
      id: tier.id,
      name: tier.name,
      description: tier.description ?? '',
      price: tier.price,
      allocation: tier.allocation,
      sold: tier.sold,
      maxPerOrder: tier.maxPerOrder,
      salesOpen: tier.salesOpen,
      salesClose: tier.salesClose,
      active: tier.active,
    })),
  }
}

// The upcoming events, and the spaces a new event can use (switched on and for hire)
export async function loadStaffEvents() {
  const [answer, spaces] = await Promise.all([apiGet('/staff/events'), apiGet('/staff/spaces')])
  const namesById = {}
  for (const space of spaces) namesById[space.id] = space.name
  return {
    events: answer.items.map((event) => toStaffEvent(event, namesById)),
    spaces: spaces.filter((space) => space.isActive && space.isHireable),
  }
}

// Paid orders, newest first. There is no paid date, and the API sends at most 100.
export async function loadEventOrders(eventId) {
  const answer = await apiGet(`/staff/events/${eventId}/orders?pageSize=100`)
  return answer.items.map(toEventOrder)
}

// One API ticket order for the attendee list. items: [{ tierId, name, quantity, unitPrice }]
export function toEventOrder(apiOrder) {
  return {
    reference: apiOrder.reference,
    eventId: apiOrder.eventId,
    status: apiOrder.status,
    buyerName: apiOrder.buyerName,
    buyerSurname: apiOrder.buyerSurname,
    buyerEmail: apiOrder.buyerEmail,
    total: apiOrder.total,
    items: apiOrder.items,
  }
}

// New public event. spaceId must be a space from loadStaffEvents, which has the venue.
export function createEvent(form, spaces) {
  const space = spaces.find((item) => item.id === form.spaceId)
  if (!space) throw new Error('Choose a space for the event.')
  const description = [...(form.about ?? []), ...(form.goodToKnow ?? [])].join('\n\n')
  return apiPost('/staff/events', {
    title: form.title,
    description: description || null,
    venueId: space.venueId,
    date: form.date,
    startTime: form.startTime,
    endTime: form.endTime,
    spaceIds: [space.id],
    capacity: form.tiers.reduce((sum, tier) => sum + tier.allocation, 0),
    image: form.image,
    tiers: form.tiers.map((tier) => ({
      name: tier.name,
      price: tier.price,
      allocation: tier.allocation,
      salesOpen: tier.salesOpen ? `${tier.salesOpen}T00:00` : null,
    })),
  })
}

// Saves the tiers: one with a database id changes that tier, one without is added
export function saveTiers(eventId, tiers) {
  return apiPut(`/staff/events/${eventId}/tiers`, {
    // The API replaces each tier with what's sent, so send it whole: a field left out is wiped
    tiers: tiers.map((tier) => {
      const row = {}
      if (typeof tier.id === 'number') row.id = tier.id
      row.name = tier.name
      row.description = tier.description?.trim() || null
      row.price = tier.price
      row.allocation = tier.allocation
      if (tier.maxPerOrder) row.maxPerOrder = tier.maxPerOrder
      row.active = tier.active !== false
      // 'YYYY-MM-DDTHH:MM', or null: on sale straight away / until the event starts
      row.salesOpen = tier.salesOpen ?? null
      row.salesClose = tier.salesClose ?? null
      return row
    }),
  })
}

export function cancelEvent(eventId, reason) {
  return apiPost(`/staff/events/${eventId}/cancel`, { reason })
}

export function refundOrder(reference, reason) {
  return apiPost(`/staff/ticket-orders/${encodeURIComponent(reference)}/refund`, { reason })
}
