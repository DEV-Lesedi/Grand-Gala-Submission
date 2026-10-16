import { TODAY, formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { SPACE_TYPES } from '../../../../lib/staff/spacesData'
import { basisInfo, currentRates } from '../../../../lib/rateSchedule'

// Text for the Spaces list, and blackouts. Price rules live in lib/rateSchedule.js

// "room" → "Villa room"
export function typeLabel(type) {
  return SPACE_TYPES.find((item) => item.value === type)?.label ?? type
}

// "R2 800 / night · R15 000 / event"
export function currentRateText(space) {
  const rates = currentRates(space)
  if (rates.length === 0) return 'No current rate'
  return rates.map((rate) => `${formatRand(rate.price)} / ${basisInfo(rate.basis).unit}`).join(' · ')
}

// "STAY · HIRE" (not "useText": "use…" names are for hooks)
export function usageText(space) {
  return [space.isAccommodation && 'Stay', space.isHireable && 'Hire'].filter(Boolean).join(' · ')
}

// "01 Oct 2026 – 31 Dec 2026" (a period with both ends)
export function dateRangeText(from, to) {
  return `${formatDate(from)} – ${formatDate(to)}`
}

// The booking transaction does the real overlap check.
export function coversDate(blackout, date = TODAY) {
  return blackout.start <= date && date <= blackout.end
}

// Own blackouts + the parent's (a villa blackout blocks its rooms). Inherited ones have `from`
// Availability includes the parent and its rooms.
export function blackoutsFor(space, spaces) {
  const parent = spaces.find((item) => item.id === space.parentId)
  const inherited = parent ? parent.blackouts.map((blackout) => ({ ...blackout, from: parent })) : []
  return [...space.blackouts, ...inherited].sort((a, b) => (a.start < b.start ? -1 : 1))
}

export function blackoutToday(space, spaces) {
  return blackoutsFor(space, spaces).find((blackout) => coversDate(blackout))
}

// "24 Dec 2026 – 26 Dec 2026" (one date if it's a single day)
export function blackoutText(blackout) {
  if (blackout.start === blackout.end) return formatDate(blackout.start)
  return `${formatDate(blackout.start)} – ${formatDate(blackout.end)}`
}
