// What the guest pays, and how. The page only shows the result and redirects.
// Sign-in is reported as { needsSignIn: true }; this module does not navigate.
import { apiGet, orNullOn404 } from './api'
import { loadGuestEvent } from './events'
import { checkStay, createStay, loadGuestSpace } from './stays'
import { createTicketOrder, checkTicketPromo } from './ticketOrders'
import { PAYFAST_MODE, finishPaymentLocally, sendToPayFast, startPayment } from './payments'
import { TODAY, formatDateRange, formatDayDate } from '../lib/dates'
import { spaceMeta, stayFacts } from '../lib/stays'
import { CHECK_IN_TIME } from '../lib/guest/staysData'
import { isQuotationPaid } from '../lib/statuses'

// Loads what to pay from the API. The address has ids and counts, never a price.
// Returns { order }, { problem, backTo, backLabel }, or { needsSignIn: true }.
export async function orderFrom(params) {
  try {
    if (params.get('event')) return await ticketOrder(params)
    if (params.get('quotation')) return await quotationOrder(params.get('quotation'))
    if (params.get('stay')) return await stayOrder(params)
    if (params.get('booking')) return await bookingOrder(params.get('booking'))
  } catch (error) {
    if (error.status === 401) return { needsSignIn: true }
    throw error
  }
  return { problem: 'Choose your tickets first.', backTo: '/events', backLabel: 'Browse events' }
}

// ---------- Tickets ----------

async function ticketOrder(params) {
  const back = { backTo: '/events', backLabel: 'Browse events' }
  const event = await loadGuestEvent(params.get('event'))
  if (!event || event.isCancelled || event.date < TODAY) return { problem: 'This event isn’t on.', ...back }
  if (event.status !== 'on_sale') return { problem: 'Tickets for this event aren’t on sale.', ...back }

  const lines = []
  const items = []
  for (const tier of event.tiers) {
    const quantity = Number(params.get(tier.id))
    if (!Number.isInteger(quantity) || quantity < 1) continue // not a positive whole number
    if (!tier.canBuy) return { problem: `${tier.name} tickets aren’t on sale.`, ...back }
    if (quantity > tier.left) return { problem: `Only ${tier.left} ${tier.name} tickets are left.`, ...back }
    lines.push({ label: `${tier.name} × ${quantity}`, amount: quantity * tier.price, quantity })
    items.push({ tierId: tier.id, quantity })
  }
  if (lines.length === 0)
    return { problem: 'Choose at least one ticket.', backTo: `/events/${event.id}`, backLabel: 'Choose tickets' }

  const count = lines.reduce((sum, line) => sum + line.quantity, 0)
  return {
    order: {
      kind: 'tickets', // 'tickets' · 'quotation' · 'stay' · 'booking'
      title: event.title,
      subtitle: `${formatDayDate(event.date)} · ${event.startTime}`,
      photo: event.photo,
      lines,
      total: lines.reduce((sum, line) => sum + line.amount, 0),
      notice: 'Tickets are emailed as soon as you’ve paid.',
      needsContact: true, // guests can buy tickets without an account
      needsAccount: false,
      canUsePromo: true, // new stays and tickets only; a saved price can't take a code
      reference: null, // the API gives the ticket order its reference
      facts: [
        { label: 'Date', value: `${formatDayDate(event.date)} · ${event.startTime}` },
        { label: 'Place', value: event.space },
        { label: 'Tickets', value: `${count} ${count === 1 ? 'ticket' : 'tickets'}` },
      ],
      nextStep: 'Show the QR code in your email at the gate. Your tickets are also in My bookings.',
      // pay() creates this first, or pays a reference that already exists
      payment: { type: 'ticket_order', create: { eventId: event.id, items } },
    },
  }
}

// ---------- An accepted quotation ----------

// The API says "not found" for someone else's quotation or booking too, so both count as not there
async function quotationOrder(reference) {
  const back = { backTo: '/my-bookings?tab=quotations', backLabel: 'My bookings' }
  const quotation = await orNullOn404(apiGet('/me/quotations/' + encodeURIComponent(reference)))
  if (!quotation) return { problem: 'Quotation not found.', ...back }
  if (isQuotationPaid(quotation)) return { problem: 'This quotation is already paid.', ...back }
  if (quotation.status !== 'accepted') {
    return {
      problem: 'Accept the quotation first, then pay.',
      backTo: `/quotations/${quotation.reference}`,
      backLabel: 'Back to the quotation',
    }
  }

  const lines = (quotation.items ?? []).map((item) => ({ label: item.label, amount: item.amount }))
  if (quotation.discount > 0) lines.push({ label: 'Discount', amount: -quotation.discount })
  const spaces = (quotation.spaces ?? []).map((space) => space.name ?? space).join(' + ')

  return {
    order: {
      kind: 'quotation',
      title: `Quotation ${quotation.reference}`,
      subtitle: quotation.eventDate ? formatDayDate(quotation.eventDate) : `Version ${quotation.version}`,
      photo: '/photos/garden.jpg',
      lines,
      total: quotation.total,
      notice: 'Paying in full confirms your date.',
      needsContact: false,
      needsAccount: true,
      canUsePromo: false,
      reference: quotation.bookingReference, // the booking this payment confirms
      facts: [
        ...(quotation.eventDate
          ? [{ label: 'Date and hours', value: `${formatDayDate(quotation.eventDate)} · ${quotation.startTime} – ${quotation.endTime}` }]
          : []),
        ...(spaces ? [{ label: 'Spaces', value: spaces }] : []),
      ],
      nextStep: 'Your date is confirmed. We’ll be in touch about the final details before the day.',
      payment: { type: 'quotation', reference: quotation.reference },
    },
  }
}

// ---------- A stay (from Reserve) ----------

async function stayOrder(params) {
  const back = { backTo: '/spaces?kind=stays', backLabel: 'Browse stays' }
  const space = await loadGuestSpace(params.get('stay'))
  if (!space || !space.isAccommodation) return { problem: 'This place to stay wasn’t found.', ...back }

  const checkIn = params.get('checkin') ?? ''
  const checkOut = params.get('checkout') ?? ''
  const guests = Number(params.get('guests'))
  // Extras: the amenity ids from Reserve (checkStay ignores any the space doesn't offer)
  const extras = (params.get('extras') ?? '').split(',').map(Number)
  const check = await checkStay({ space, checkIn, checkOut, guests, extras })
  if (!check.ok) {
    // checkStay's reasons are short status words; Checkout shows them as a sentence
    const problem = /[.?]$/.test(check.reason) ? check.reason : `${check.reason}.`
    return { problem, backTo: `/spaces/${space.id}`, backLabel: 'Choose other dates' }
  }
  const quote = check.quote

  return {
    order: {
      kind: 'stay',
      title: space.name,
      subtitle: `${formatDateRange(checkIn, checkOut)} · ${guests} ${guests === 1 ? 'guest' : 'guests'}`,
      photo: space.photo,
      lines: quote.lines,
      total: quote.total,
      notice: 'Paying in full confirms your stay.',
      needsContact: false,
      needsAccount: true, // a stay is booked on the guest's account
      canUsePromo: true,
      reference: null, // the API gives the booking its reference
      facts: stayFacts(`${space.name} · ${spaceMeta(space, [space])}`, checkIn, checkOut, guests),
      nextStep: `Self check-in from ${CHECK_IN_TIME} on ${formatDayDate(checkIn)}. Your stay is also in My bookings.`,
      payment: { type: 'stay', create: { space, checkIn, checkOut, guests, extras } },
    },
  }
}

// ---------- A booking waiting for payment (My bookings → Pay now) ----------

// Pays what the booking already holds (its saved total), not today's rates
async function bookingOrder(reference) {
  const back = { backTo: '/my-bookings', backLabel: 'My bookings' }
  const booking = await orNullOn404(apiGet('/me/bookings/' + encodeURIComponent(reference)))
  if (!booking) return { problem: 'Booking not found.', ...back }
  if (booking.status !== 'pending_payment') return { problem: 'This booking isn’t waiting for payment.', ...back }

  const isStay = booking.type === 'stay'
  // A private event is paid through its accepted quotation
  let payment = { type: 'stay', reference: booking.reference }
  if (!isStay) {
    const list = await apiGet('/me/quotations')
    const accepted = (list.quotations ?? []).find(
      (quotation) => quotation.bookingReference === booking.reference && quotation.status === 'accepted',
    )
    if (!accepted) return { problem: 'We couldn’t find the accepted quotation for this booking.', ...back }
    payment = { type: 'quotation', reference: accepted.reference }
  }

  return {
    order: {
      kind: 'booking',
      title: booking.title,
      subtitle: isStay
        ? `${formatDateRange(booking.startDate, booking.endDate)} · ${booking.guests} ${booking.guests === 1 ? 'guest' : 'guests'}`
        : `${formatDayDate(booking.startDate)} · ${booking.guests ?? ''} guests`,
      // Stand-in photo when the API sends none
      photo: booking.image ?? (isStay ? '/photos/villa.jpg' : '/photos/garden.jpg'),
      lines: [{ label: `Booking ${booking.reference}`, amount: booking.price }],
      total: booking.price,
      notice: isStay ? 'Paying in full confirms your stay.' : 'Paying in full confirms your date.',
      needsContact: false,
      needsAccount: true,
      canUsePromo: false,
      reference: booking.reference,
      facts: isStay
        ? stayFacts(booking.title, booking.startDate, booking.endDate, booking.guests)
        : [{ label: 'Date', value: formatDayDate(booking.startDate) }],
      nextStep: isStay
        ? `Self check-in from ${CHECK_IN_TIME} on ${formatDayDate(booking.startDate)}. Your stay is in My bookings.`
        : 'Your date is confirmed. It’s in My bookings.',
      payment,
    },
  }
}

// ---------- A discount code ----------

// Discount when canUsePromo: { code, discount, total }. Throws if the code can't be used.
// Nothing is held yet; pay() holds the code and the API checks it again.
export async function priceWithCode(order, code) {
  let found
  if (order.payment.type === 'stay') {
    // A stay is priced again with the code, so the total is the real one
    const check = await checkStay({ ...order.payment.create, promoCode: code })
    if (!check.ok) throw new Error(check.reason)
    found = { discount: check.quote.discount, total: check.quote.total }
  } else {
    found = await checkTicketPromo(code, order.total)
  }
  // The API gives 0 off when the order is under the code's minimum spend
  if (!(found.discount > 0)) throw new Error('This code needs a bigger order.')
  return { code, ...found }
}

// ---------- Paying ----------

// Creates the booking or ticket order if needed, then pays (demo now, or PayFast sandbox).
// Never throws. Pass the previous reference so Pay again does not create a second order.
export async function pay(order, { contact, promo = null, reference = null }) {
  let made = reference ?? order.reference
  try {
    if (!made && order.payment.create) made = await createOrder(order, contact, promo)
    // Quotations pay by their reference; other orders pay by theirs
    const checkout = await startPayment(order.payment.type, order.payment.reference ?? made)
    if (PAYFAST_MODE === 'sandbox') {
      rememberPayment({ kind: order.kind, reference: made })
      sendToPayFast(checkout) // leaves the app for PayFast's sandbox
      return { reference: made, leftForPayFast: true }
    }
    await finishPaymentLocally(checkout)
    return { reference: made, paid: true }
  } catch (error) {
    return { reference: made ?? null, error: error.message }
  }
}

// Makes the new stay booking or ticket order and returns its reference
async function createOrder(order, contact, promo) {
  const create = order.payment.create
  if (order.payment.type === 'stay') {
    const booking = await createStay(create.space, { ...create, notes: contact.requests, promoCode: promo?.code })
    return booking.reference
  }
  const ticketOrder = await createTicketOrder(create, contact, promo?.code)
  return ticketOrder.reference
}

// ---------- Coming back from PayFast ----------

// Remembers this tab's payment so /payment/success can link back. Storage may be off.
function rememberPayment(details) {
  try {
    sessionStorage.setItem('gg-checkout-payment', JSON.stringify(details))
  } catch {
    // no storage: the return page just won't link to the booking
  }
}

// What Checkout remembered before going to PayFast (sandbox mode): { kind, reference }, or null
export function rememberedPayment() {
  try {
    return JSON.parse(sessionStorage.getItem('gg-checkout-payment')) ?? null
  } catch {
    return null
  }
}
