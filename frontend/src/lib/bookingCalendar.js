import { addDays } from './dates'

// Days that are not "free": booked, held, blacked out, or mode-locked.
export function blockedDatesFromCalendars(calendars) {
  const blocked = new Set()
  for (const days of calendars) {
    for (const day of days) {
      if (day.state && day.state !== 'free') blocked.add(day.date)
    }
  }
  return blocked
}

// Checkout pick: nights before this day must be free, so it can be the next arrival.
export function canPickDay(date, { min, max, blocked = new Set(), stayStart = '' } = {}) {
  if (!date) return false
  if (min && date < min) return false
  if (max && date > max) return false
  if (stayStart) {
    if (date <= stayStart) return false
    let night = stayStart
    while (night < date) {
      if (blocked.has(night)) return false
      night = addDays(night, 1)
    }
    return true
  }
  return !blocked.has(date)
}

// The cells of one month, Monday first. null = a blank from the previous month.
export function monthCells(monthStart) {
  const mondayFirst = (new Date(`${monthStart}T00:00:00Z`).getUTCDay() + 6) % 7
  const cells = Array(mondayFirst).fill(null)
  let date = monthStart
  while (date.slice(0, 7) === monthStart.slice(0, 7)) {
    cells.push(date)
    date = addDays(date, 1)
  }
  return cells
}
