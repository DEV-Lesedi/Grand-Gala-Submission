import { TODAY, formatDate } from '../../../../lib/dates'
import { formatRand } from '../../../../lib/money'
import { PROMOTION_SCOPES } from '../../../../lib/staff/promotionsData'

// Below the minimum the discount is 0. A percentage is capped and never exceeds the amount.
// Checkout applies this and counts the use.
export function discountFor(promo, amount) {
  if (amount < promo.minSpend) return 0
  let discount = promo.type === 'percentage' ? (amount * promo.value) / 100 : promo.value
  if (promo.type === 'percentage' && promo.maxDiscount !== null) discount = Math.min(discount, promo.maxDiscount)
  return Math.round(Math.min(discount, amount))
}

// "stays", "events", or "tickets". Checkout refuses any other kind.
export function worksOn(promo, kind) {
  return promo.appliesTo.includes(kind)
}

// "Stays only" · "Stays and events" · "Stays, events and tickets"
export function appliesToText(promo) {
  const labels = PROMOTION_SCOPES.filter((scope) => promo.appliesTo.includes(scope.value)).map((scope) => scope.label)
  if (labels.length === 1) return `${labels[0]} only`
  const list = labels.map((label, index) => (index === 0 ? label : label.toLowerCase()))
  return `${list.slice(0, -1).join(', ')} and ${list[list.length - 1]}`
}

// Not past its end date (ended ones stay in the DB)
export function isCurrentOrUpcoming(promo) {
  return promo.validTo >= TODAY
}

// Switched off, starts on a date, ended, used up, or active.
// Checkout refuses a code that can't be used.
export function promotionStatus(promo) {
  if (!promo.isActive) return { text: 'Switched off', tone: 'muted' }
  if (promo.validFrom > TODAY) return { text: `Starts ${formatDate(promo.validFrom)}`, tone: 'warning' }
  if (promo.validTo < TODAY) return { text: 'Ended', tone: 'muted' }
  if (promo.usageLimit !== null && promo.usageCount >= promo.usageLimit) return { text: 'Used up', tone: 'muted' }
  return { text: 'Active', tone: 'success' }
}

// "15% off (max R1 500)" or "R500 off"
export function promotionValueText(promo) {
  if (promo.type === 'fixed') return `${formatRand(promo.value)} off`
  return promo.maxDiscount !== null ? `${promo.value}% off (max ${formatRand(promo.maxDiscount)})` : `${promo.value}% off`
}

// "On R3 000+ · 1 per guest · 12 / 50 used"
export function promotionRulesText(promo) {
  return [
    promo.minSpend > 0 && `On ${formatRand(promo.minSpend)}+`,
    promo.limitPerUser && `${promo.limitPerUser} per guest`,
    promo.usageLimit !== null ? `${promo.usageCount} / ${promo.usageLimit} used` : `${promo.usageCount} used`,
  ]
    .filter(Boolean)
    .join(' · ')
}
