// Dates are stored "YYYY-MM-DD" (compare as text) and shown "04 Sep 2026" via formatDate()

// Today at the venue (South Africa), as "YYYY-MM-DD". The API checks dates again.
export const TODAY = new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Africa/Johannesburg',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
}).format(new Date())

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

// "2026-12-01" → "Dec 2026" (the month on a date picker)
export function formatMonth(isoDate) {
  const [year, month] = isoDate.split('-')
  return `${MONTHS[Number(month) - 1]} ${year}`
}

// First day of that month, "2026-12-14" → "2026-12-01"
export function startOfMonth(isoDate) {
  return `${isoDate.slice(0, 7)}-01`
}

// Move by whole months, keeping the day when it exists ("2026-01-31", 1) → "2026-02-28"
export function addMonths(isoDate, months) {
  const [year, month, day] = isoDate.split('-').map(Number)
  const start = new Date(Date.UTC(year, month - 1 + months, 1))
  const lastDay = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0)).getUTCDate()
  start.setUTCDate(Math.min(day, lastDay))
  return start.toISOString().slice(0, 10)
}

// "2026-09-04" → "04 Sep 2026". A missing date stays blank.
export function formatDate(isoDate) {
  if (!isoDate || typeof isoDate !== 'string') return ''
  const [year, month, day] = isoDate.slice(0, 10).split('-')
  const monthName = MONTHS[Number(month) - 1]
  if (!year || !monthName || !day) return ''
  return `${day} ${monthName} ${year}`
}

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

// "2026-09-14" → "Mon 14 Sep 2026" (guest pages, where the day of the week helps)
export function formatDayDate(isoDate) {
  const weekday = WEEKDAYS[new Date(`${isoDate}T00:00:00Z`).getUTCDay()]
  return `${weekday} ${formatDate(isoDate)}`
}

// Short stay dates: "12 – 14 Dec", or "30 Sep – 2 Oct" across months.
export function formatDateRange(from, to) {
  const [, fromMonth, fromDay] = from.split('-')
  const [, toMonth, toDay] = to.split('-')
  const month = (value) => MONTHS[Number(value) - 1]
  if (fromMonth === toMonth) return `${Number(fromDay)} – ${Number(toDay)} ${month(toMonth)}`
  return `${Number(fromDay)} ${month(fromMonth)} – ${Number(toDay)} ${month(toMonth)}`
}

// ("2026-09-04", 7) → "2026-09-11" (UTC)
export function addDays(isoDate, days) {
  const date = new Date(`${isoDate}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

export function nightsBetween(checkIn, checkOut) {
  const oneDay = 24 * 60 * 60 * 1000
  return Math.round((new Date(`${checkOut}T00:00:00Z`) - new Date(`${checkIn}T00:00:00Z`)) / oneDay)
}
