import { TODAY } from '../../../../lib/dates'

// Search stays in the URL. Missing or invalid parts are left out.

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

// { kind, checkIn, checkOut, guests }. Dates are a pair, from today, check-out after check-in.
export function searchFromParams(searchParams) {
  const kind = ['stays', 'venues'].includes(searchParams.get('kind')) ? searchParams.get('kind') : 'all'
  let checkIn = searchParams.get('checkin') ?? ''
  let checkOut = searchParams.get('checkout') ?? ''
  const datesOk = ISO_DATE.test(checkIn) && ISO_DATE.test(checkOut) && checkIn >= TODAY && checkOut > checkIn
  if (!datesOk) [checkIn, checkOut] = ['', '']
  const guests = Number(searchParams.get('guests'))
  return { kind, checkIn, checkOut, guests: Number.isInteger(guests) && guests >= 1 ? guests : null }
}

// { kind, checkIn, checkOut, guests } → "kind=stays&checkin=…", leaving out what isn't set
export function searchToParams({ kind, checkIn, checkOut, guests }) {
  const params = new URLSearchParams()
  if (kind && kind !== 'all') params.set('kind', kind)
  if (checkIn && checkOut) {
    params.set('checkin', checkIn)
    params.set('checkout', checkOut)
  }
  if (guests) params.set('guests', String(guests))
  return params.toString()
}
