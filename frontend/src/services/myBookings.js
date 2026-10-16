// The guest's bookings and ticket orders for My bookings.
// The API already picks each card's status; this maps a card to BookingRow props.
import { apiGet } from './api'
import { formatDate, formatDateRange } from '../lib/dates'
import { formatRand } from '../lib/money'

function toRow(card) {
  const facts = []
  if (card.startDate && card.endDate && card.endDate !== card.startDate) facts.push(formatDateRange(card.startDate, card.endDate))
  else if (card.startDate) facts.push(formatDate(card.startDate))
  else facts.push('Date to agree')
  if (card.ticketCount !== null && card.ticketCount !== undefined) {
    facts.push(`${card.ticketCount} ${card.ticketCount === 1 ? 'ticket' : 'tickets'}`)
  } else if (card.guests) {
    facts.push(`${card.guests} ${card.guests === 1 ? 'guest' : 'guests'}`)
  }

  return {
    id: card.reference,
    reference: card.reference,
    kind: card.kind,
    status: card.status,
    title: card.title,
    facts,
    price: card.price === null || card.price === undefined ? 'To be quoted' : formatRand(card.price),
    actionLabel: card.actionLabel,
    actionTo: card.actionTo,
    image: card.image ?? '/photos/villa.jpg',
  }
}

// { upcoming: rows, past: rows }
export async function loadMyBookings() {
  const [upcoming, past] = await Promise.all([apiGet('/me/bookings?tab=upcoming'), apiGet('/me/bookings?tab=past')])
  return { upcoming: upcoming.map(toRow), past: past.map(toRow) }
}
