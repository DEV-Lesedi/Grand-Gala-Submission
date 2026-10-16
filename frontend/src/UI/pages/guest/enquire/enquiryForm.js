// The enquiry form: empty values, what is sent, and the checks shown on the page.
// The server checks the same rules again.
import { TODAY, addDays } from '../../../../lib/dates'
import { ENQUIRE_LEAD_DAYS } from '../../../../lib/guest/enquireData'

// POST /enquiries body. Space and room ids must exist; contact comes from the account.
export function toEnquiry(form, spaces, villa) {
  const isKnown = (id) => spaces.some((space) => space.id === id)
  const body = {
    occasion: form.occasion.trim(),
    preferredDate: form.preferredDate,
    startTime: form.startTime,
    endTime: form.endTime,
    estimatedGuests: Number(form.guests),
    requestedSpaces: form.spaces.filter(isKnown),
    wantsCatering: form.catering === 'venue',
    selfCatering: form.catering === 'own',
    mealType: form.catering === 'venue' && form.mealType ? form.mealType : 'all',
    cateringRequest: form.cateringRequest.trim(),
    wantsDecor: form.decor === 'venue',
    selfDecor: form.decor === 'own',
    decorRequest: form.decorRequest.trim(),
    requirements: form.requirements.trim(),
    contactConsent: form.consent,
  }
  if (form.alternateDate) body.alternateDate = form.alternateDate
  if (form.needsRooms && villa) {
    const stay = {
      checkIn: form.checkIn,
      checkOut: addDays(form.checkIn, Number(form.nights)),
      guests: Number(form.guestsStaying),
    }
    if (form.stayChoice === 'rooms') {
      stay.roomIds = form.pickedRooms.filter(isKnown)
    } else {
      const roomCount = spaces.filter((space) => space.parentId === villa.id).length
      stay.spaceId = villa.id
      stay.rooms = Math.max(1, roomCount)
    }
    body.accommodation = stay
  }
  return body
}

export const EARLIEST_ENQUIRY = addDays(TODAY, ENQUIRE_LEAD_DAYS)

export const EMPTY_FORM = {
  occasion: '',
  preferredDate: '',
  alternateDate: '',
  guests: '',
  startTime: '',
  endTime: '',
  spaces: [], // ids of the spaces ticked
  needsRooms: false,
  stayChoice: 'villa', // 'villa' (the whole villa) or 'rooms' (the rooms ticked in pickedRooms)
  pickedRooms: [], // ids of the rooms ticked
  checkIn: '',
  nights: '',
  rooms: '',
  guestsStaying: '',
  catering: '',
  mealType: '',
  cateringRequest: '',
  decor: '',
  decorRequest: '',
  requirements: '',
  fullName: '',
  email: '',
  phone: '',
  consent: false,
}

// { field: message }; empty means ready. The API checks the same rules again.
export function validate(form, eventBlocked = new Set(), stayBlocked = new Set()) {
  const errors = {}
  if (!form.occasion.trim()) errors.occasion = 'What’s the occasion?'
  if (!form.preferredDate) errors.preferredDate = 'Choose a date.'
  else if (form.preferredDate < EARLIEST_ENQUIRY) errors.preferredDate = 'Choose a date at least 12 days from today.'
  else if (eventBlocked.has(form.preferredDate)) errors.preferredDate = 'That date isn’t free. Choose another.'
  if (form.alternateDate && form.alternateDate < EARLIEST_ENQUIRY) errors.alternateDate = 'Choose a date at least 12 days from today.'
  else if (form.alternateDate && eventBlocked.has(form.alternateDate)) errors.alternateDate = 'That date isn’t free. Choose another.'
  if (form.alternateDate && form.alternateDate === form.preferredDate) errors.alternateDate = 'Choose a different date.'
  if (!(Number(form.guests) >= 1)) errors.guests = 'How many guests, roughly?'
  if (!form.startTime) errors.startTime = 'Choose a start time.'
  if (!form.endTime) errors.endTime = 'Choose an end time.'
  else if (form.startTime && form.endTime <= form.startTime) errors.endTime = 'End after the start time.'
  if (form.needsRooms) {
    if (!form.checkIn) errors.checkIn = 'Choose a check-in date.'
    else if (form.checkIn < EARLIEST_ENQUIRY) errors.checkIn = 'Choose a date at least 12 days from today.'
    else if (stayBlocked.has(form.checkIn)) errors.checkIn = 'That date isn’t free. Choose another.'
    if (!(Number(form.nights) >= 1)) errors.nights = 'At least 1 night.'
    else if (form.checkIn && !errors.checkIn) {
      const checkout = addDays(form.checkIn, Number(form.nights))
      let night = form.checkIn
      while (night < checkout) {
        if (stayBlocked.has(night)) {
          errors.nights = 'Those nights aren’t all free. Shorten the stay or pick another check-in.'
          break
        }
        night = addDays(night, 1)
      }
    }
    if (form.stayChoice === 'rooms' && form.pickedRooms.length === 0) errors.pickedRooms = 'Tick at least one room.'
    if (!(Number.isInteger(Number(form.guestsStaying)) && Number(form.guestsStaying) >= 1)) {
      errors.guestsStaying = 'How many guests will stay?'
    }
  }
  if (!form.catering) errors.catering = 'Choose one.'
  if (form.catering === 'venue' && !form.mealType) errors.mealType = 'Which meal?'
  if (!form.decor) errors.decor = 'Choose one.'
  if (!form.spaces.length) errors.spaces = 'Tick at least one space.'
  if (!form.consent) errors.consent = 'Tick this so we can contact you.'
  return errors
}
