// Staff spaces, rates, blackouts, and amenities, already in the page's shape.
// Promotion kinds: the page says stays/events/tickets, the API stay/event/tickets.
import { apiDelete, apiGet, apiPatch, apiPost } from './api'

const TO_API_KIND = { stays: 'stay', events: 'event', tickets: 'tickets' }
const FROM_API_KIND = { stay: 'stays', event: 'events', tickets: 'tickets' }

function fromApiPromotion(promotion) {
  return { ...promotion, appliesTo: (promotion.appliesTo ?? []).map((kind) => FROM_API_KIND[kind] ?? kind) }
}

// Spaces, amenities, and promotions. Staff (not admins) get no promotions.
export async function loadSpacesPage() {
  const [spaces, amenities, promotions] = await Promise.all([
    apiGet('/staff/spaces'),
    apiGet('/staff/amenities'),
    apiGet('/staff/promotions').catch((error) => {
      if (error.status === 403) return { promotions: [] }
      throw error
    }),
  ])
  return { spaces, amenities, promotions: (promotions.promotions ?? []).map(fromApiPromotion) }
}

export function updateSpace(id, changes) {
  return apiPatch('/staff/spaces/' + id, changes)
}

export function addSpace(space) {
  return apiPost('/staff/spaces', {
    name: space.name,
    type: space.type,
    capacity: space.capacity,
    isAccommodation: space.isAccommodation,
    isHireable: space.isHireable,
    venueId: space.venueId,
  })
}

// A new price row. Changing the normal price closes the old one on the API's side.
export function addRate(spaceId, rate) {
  return apiPost(`/staff/spaces/${spaceId}/rates`, {
    basis: rate.basis,
    label: rate.label,
    price: Number(rate.price),
    validFrom: rate.validFrom,
    validTo: rate.validTo ?? null,
  })
}

export function cancelRate(rateId) {
  return apiPost(`/staff/rates/${rateId}/cancel`)
}

export function endRateNow(rateId) {
  return apiPost(`/staff/rates/${rateId}/end-now`)
}

// One blackout for several spaces at once
export function addBlackout(spaceIds, blackout) {
  return apiPost('/staff/blackouts', { spaceIds, start: blackout.start, end: blackout.end, reason: blackout.reason })
}

export function removeBlackout(blackoutId) {
  return apiDelete('/staff/blackouts/' + blackoutId)
}

export function addAmenity(amenity) {
  return apiPost('/staff/amenities', { name: amenity.name, price: amenity.price })
}

export function updateAmenity(id, changes) {
  return apiPatch('/staff/amenities/' + id, changes)
}

// A new promotion (no id yet) or changes to one
export function savePromotion(promotion, isNew) {
  const body = {
    code: promotion.code,
    description: promotion.description,
    type: promotion.type,
    appliesTo: promotion.appliesTo.map((kind) => TO_API_KIND[kind] ?? kind),
    value: promotion.value,
    minSpend: promotion.minSpend,
    maxDiscount: promotion.maxDiscount,
    usageLimit: promotion.usageLimit,
    limitPerUser: promotion.limitPerUser,
    validFrom: promotion.validFrom,
    validTo: promotion.validTo,
    isActive: promotion.isActive,
  }
  if (isNew) return apiPost('/staff/promotions', body)
  delete body.code // the code is fixed once guests may have it
  return apiPatch('/staff/promotions/' + promotion.id, body)
}

export function setPromotionActive(id, isActive) {
  return apiPatch('/staff/promotions/' + id, { isActive })
}
