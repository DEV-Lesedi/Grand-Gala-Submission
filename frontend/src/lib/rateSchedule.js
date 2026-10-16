import { TODAY, addDays } from './dates'
import { RATE_BASES } from './staff/spacesData'

// How a space's prices read. Guest pages use currentRates from the API instead.
// Rates are never edited: only added or switched off. Changes go through the API.
// A normal price has no end date; a seasonal price sits on top of it until validTo.

// ---------- Questions ----------

// "per_night" → { value, chip: "Per night", unit: "night" }
export function basisInfo(basis) {
  return RATE_BASES.find((item) => item.value === basis)
}

// The prices guests pay today, one per kind (used for the rate card and the list's "Current rate")
export function currentRates(space) {
  return RATE_BASES.map((kind) => rateOn(ratesOfKind(space.rates, kind.value), TODAY)).filter(Boolean)
}

// Per price kind: now, what follows it (backTo or nextPrice), upcoming, and past.
// canEndNow, hasGapAfter, and changeLabel say what the editor may do next.
export function rateSchedule(rates) {
  return RATE_BASES.filter((kind) => rates.some((rate) => rate.basis === kind.value)).map((kind) =>
    scheduleForKind(ratesOfKind(rates, kind.value), kind),
  )
}

function scheduleForKind(rates, kind) {
  const now = rateOn(rates, TODAY)
  const thenFrom = now?.validTo ? addDays(now.validTo, 1) : null
  const then = thenFrom ? rateOn(rates, thenFrom) : undefined
  // The normal price under a seasonal "now" (it keeps covering today)
  const normalUnder = now && isSeasonal(now) ? rates.find((rate) => isNormalOn(rate, TODAY)) : undefined

  const upcoming = rates
    .filter((rate) => rate.isActive && rate.validFrom > TODAY)
    .sort((a, b) => (a.validFrom < b.validFrom ? -1 : 1))
    .map((rate) => {
      // A season covering its start day wins, so this price only applies after that season
      const winner = rateOn(rates, rate.validFrom)
      return { ...rate, waitsFor: winner && winner.id !== rate.id ? winner : null }
    })
  const past = rates
    .filter((rate) => !rate.isActive || (rate.validTo && rate.validTo < TODAY))
    .sort((a, b) => (a.validFrom < b.validFrom ? 1 : -1))
    .map((rate) => ({ ...rate, tag: rate.isActive ? 'Ended' : 'Cancelled', isSeasonal: isSeasonal(rate) }))

  const isBack = Boolean(then && normalUnder && then.id === normalUnder.id)
  return {
    kind,
    now,
    backTo: isBack ? then : undefined,
    nextPrice: then && !isBack ? then : undefined,
    thenFrom,
    upcoming,
    past,
    canEndNow: Boolean(now && isSeasonal(now)),
    hasGapAfter: Boolean(thenFrom && !then),
    changeLabel: (normalUnder ?? now)?.label,
  }
}

// Active seasons of this kind that overlap from–to. The form warns about them.
export function overlappingSeasons(rates, basis, from, to) {
  return rates.filter(
    (rate) => rate.basis === basis && rate.isActive && isSeasonal(rate) && rate.validFrom <= to && from <= rate.validTo,
  )
}

// The season running today for this kind, or undefined. A normal price today ends it.
export function seasonToday(rates, basis) {
  return ratesOfKind(rates, basis).find((rate) => isSeasonal(rate) && covers(rate, TODAY))
}

// ---------- Inside the module (not exported) ----------

function ratesOfKind(rates, basis) {
  return rates.filter((rate) => rate.basis === basis)
}

// Seasonal = created with an end date. A normal price that got an end date only because a
// change was scheduled (closedFor) is still a normal price.
function isSeasonal(rate) {
  return rate.validTo !== null && !rate.closedFor
}

// Switched on and covering the date
function covers(rate, date) {
  return rate.isActive && rate.validFrom <= date && (rate.validTo === null || date <= rate.validTo)
}

function isNormalOn(rate, date) {
  return covers(rate, date) && !isSeasonal(rate)
}

// The price on a date: a season beats a normal price.
// Same type: latest start wins; same start: last added wins.
function rateOn(rates, date) {
  return rates.filter((rate) => covers(rate, date)).reduce(pickWinner, undefined)
}

function pickWinner(winner, rate) {
  if (!winner) return rate
  if (isSeasonal(rate) !== isSeasonal(winner)) return isSeasonal(rate) ? rate : winner
  return rate.validFrom >= winner.validFrom ? rate : winner
}
