// Public events. Each keeps its database id. Sale status, stock, and price come from the API.
// Switched-off tiers are left out.
import { apiGet, orNullOn404 } from './api'

// Guest event: space is the venue, status is the sale state, about is paragraphs or null.
export function toGuestEvent(apiEvent) {
  const tiers = (apiEvent.ticketTiers ?? []).map((tier) => ({
    id: tier.id,
    name: tier.name,
    description: tier.description ?? '',
    price: tier.price,
    left: tier.available,
    maxPerOrder: tier.maxPerOrder,
    salesOpen: tier.salesOpen,
    salesClose: tier.salesClose,
    canBuy: tier.saleStatus === 'on_sale', // the API only says on_sale while tickets are left
    opensLater: tier.saleStatus === 'not_on_sale_yet',
  }))

  return {
    id: apiEvent.id,
    title: apiEvent.title,
    space: apiEvent.venue,
    date: apiEvent.date,
    startTime: apiEvent.startTime,
    endTime: apiEvent.endTime,
    photo: apiEvent.image ?? '/photos/event.jpg',
    about: apiEvent.description ? apiEvent.description.split(/\n+/).filter(Boolean) : null,
    status: apiEvent.saleStatus,
    isCancelled: apiEvent.status === 'cancelled',
    fromPrice: apiEvent.fromPrice,
    capacity: apiEvent.capacity,
    tiers,
  }
}

// Upcoming public events, soonest first (the API leaves out past and cancelled ones)
export async function loadGuestEvents() {
  const answer = await apiGet('/events')
  return answer.items.map(toGuestEvent)
}

// One public event, or null when it isn't on (cancelled, past or a wrong address)
export async function loadGuestEvent(id) {
  const event = await orNullOn404(apiGet('/events/' + encodeURIComponent(id)))
  return event && toGuestEvent(event)
}
