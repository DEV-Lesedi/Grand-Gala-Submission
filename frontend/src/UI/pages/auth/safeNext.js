// ?next= after sign-in. Only paths inside this site, so a link can't send someone elsewhere.
export function safeNext(next) {
  return next && next.startsWith('/') && !next.startsWith('//') ? next : '/my-bookings'
}
