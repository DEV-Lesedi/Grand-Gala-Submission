import { formatDayDate } from './dates'
import { formatRand } from './money'
import { CHECK_IN_TIME, CHECK_OUT_TIME } from './guest/staysData'

// Stays: how the guest pages show a space (Home, Browse, Space detail, Reserve, Checkout).
// Availability and the price of a stay come from the API (services/stays.js).

// The rooms inside a space (the villa's rooms)
export function roomsOf(space, spaces) {
  return spaces.filter((item) => item.parentId === space.id)
}

// Card price from currentRates (today, seasons included): a night rate, or "From" the hire rate.
export function cardPrice(space) {
  const rates = space.currentRates
  const night = rates.find((rate) => rate.basis === 'per_night')
  if (space.isAccommodation && night) return { amount: night.price, text: `${formatRand(night.price)} night` }
  const hire = rates.find((rate) => rate.basis === 'per_event')
  if (hire) return { amount: hire.price, text: `From ${formatRand(hire.price)}` }
  return { amount: null, text: '' }
}

// "Spaces guests love" on Home: the whole villa, one of its rooms and two venues to hire.
// Picked by kind, not by name, so renaming a space in Spaces & rates doesn't drop it from Home.
export function featuredSpaces(spaces) {
  const villa = spaces.find((space) => space.isAccommodation && !space.parentId)
  const room = villa ? roomsOf(villa, spaces)[0] : undefined
  const venues = spaces.filter((space) => !space.isAccommodation).slice(0, 2)
  return [villa, room, ...venues].filter(Boolean)
}

// The kind of space, as the guest pages say it: Stay (the whole villa) · Villa room · Event hire
export function spaceTag(space) {
  if (space.type === 'room') return 'Villa room'
  if (space.isAccommodation) return 'Stay'
  return 'Event hire'
}

// "Sleeps 10 · 8 rooms" for stays, "Up to 150 guests" for venues
export function spaceMeta(space, spaces) {
  if (!space.isAccommodation) return `Up to ${space.capacity} guests`
  const rooms = roomsOf(space, spaces).length
  if (rooms === 0) return `Sleeps ${space.capacity}`
  return `Sleeps ${space.capacity} · ${rooms} ${rooms === 1 ? 'room' : 'rooms'}`
}

// The facts of a stay, for Checkout, "You're booked" and Booking details:
// Check-in "Fri 12 Dec · from 14:00" · Check-out "Sun 14 Dec · by 10:00" · Space · Guests
export function stayFacts(spaceLabel, checkIn, checkOut, guests) {
  const day = (date) => formatDayDate(date).slice(0, -5) // without the year
  return [
    { label: 'Check-in', value: `${day(checkIn)} · from ${CHECK_IN_TIME}` },
    { label: 'Check-out', value: `${day(checkOut)} · by ${CHECK_OUT_TIME}` },
    { label: 'Space', value: spaceLabel },
    { label: 'Guests', value: `${guests} ${guests === 1 ? 'guest' : 'guests'} · self check-in` },
  ]
}
