// Fixed words for the stay pages. Spaces, prices, and bookings come from the API.

// Home hero
export const HOME_HERO = {
  photo: '/photos/villa.jpg',
  eyebrow: 'Stay · gather · celebrate',
  title: 'Four acres of garden, one place to book it all',
}

// "What are you planning?" on Home. to: where each one goes
export const INTENTS = [
  { title: 'Stay the night', detail: 'Instant book', to: '/spaces?kind=stays' },
  { title: 'Host a private event', detail: 'Get a quote', to: '/enquire' },
]

// Under the intents on Home
export const HOME_SUMMARY = {
  title: 'Four rooms, three venues, four acres',
  detail: 'The Villa — whole or by room — and three venues to hire.',
}

// What the search covers ("Stays & venues" in the search pill). short: for very small phones
export const SEARCH_KINDS = [
  { value: 'all', label: 'Stays & venues', short: 'All' },
  { value: 'stays', label: 'Stays', short: 'Stays' },
  { value: 'venues', label: 'Venues', short: 'Venues' },
]

// Browse filters. id is ?filter=; several selected means match any.
export const BROWSE_FILTERS = [
  { id: 'whole-villa', label: 'Whole villa' },
  { id: 'villa-rooms', label: 'Villa rooms' },
  { id: 'event-hire', label: 'Event hire' },
  { id: 'sleeps-4', label: 'Sleeps 4+' },
  { id: 'under-1000', label: 'Under R1 000' },
]

// Shared by the villa and its rooms.
export const VILLA_FEATURES = [
  'Pool, jacuzzi and braai',
  'Free parking on site',
  'Fibre wi-fi',
  'Full kitchen',
  'Self check-in from 14:00',
]

// One line under the space's name on Space detail
export const SPACE_DESCRIPTIONS = {
  villa: 'Book the whole villa or a single room. Pool, jacuzzi, braai and garden are included.',
  room: 'A room in The Villa. Pool, jacuzzi, braai and garden are shared.',
  venue: 'For private events. Send a brief and we’ll quote.',
}

// Check-in and check-out times, shown as "from 14:00" and "by 10:00".
export const CHECK_IN_TIME = '14:00'
export const CHECK_OUT_TIME = '10:00'

// Reserve and Checkout steps for a stay.
export const STAY_STEPS = ['Your stay', 'Details', 'Pay']
