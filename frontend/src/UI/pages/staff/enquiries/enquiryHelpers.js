import { BOOKING_STATUSES, QUOTATION_STATUSES, statusLabel } from '../../../../lib/statuses'
import { TODAY, formatDate, nightsBetween } from '../../../../lib/dates'
import { MEAL_TYPES } from '../../../../lib/staff/enquiriesData'

// Open: waiting for a quotation, or for the guest
export function isOpenEnquiry(enquiry) {
  return enquiry.status === 'enquiry' || enquiry.status === 'quoted'
}

// "Closed" (status null) is everything that isn't open.
export function matchesFilter(enquiry, filter) {
  if (filter.status) return enquiry.status === filter.status
  return !isOpenEnquiry(enquiry)
}

// Newest first (ISO text sorts by time)
export function sortNewestFirst(enquiries) {
  return [...enquiries].sort((a, b) => (a.received < b.received ? 1 : -1))
}

// "Quoted · v2 sent" while quoted; otherwise the booking status. Colour follows status.
export function enquiryStageText(enquiry) {
  if (enquiry.status === 'quoted') {
    const quoteStatus = statusLabel(QUOTATION_STATUSES, enquiry.quote.status).toLowerCase()
    return `Quoted · v${enquiry.quote.version} ${quoteStatus}`
  }
  return statusLabel(BOOKING_STATUSES, enquiry.status)
}

// "Elopement · Open Garden + The Villa"
export function eventText(enquiry) {
  return `${enquiry.occasion} · ${spacesText(enquiry)}`
}

// ["Open Garden", "The Villa"] → "Open Garden + The Villa"
export function spacesText(enquiry) {
  return enquiry.spaces.join(' + ')
}

export function timesText(enquiry) {
  return `${enquiry.startTime} – ${enquiry.endTime}`
}

// DB flags → short text: "Yes · dinner", "Own caterer", "No"
export function cateringText(enquiry) {
  if (enquiry.wantsCatering) {
    const meal = MEAL_TYPES[enquiry.mealType].toLowerCase()
    return enquiry.cateringRequest ? `Yes · ${meal} · ${enquiry.cateringRequest.toLowerCase()}` : `Yes · ${meal}`
  }
  return enquiry.selfCatering ? 'Own caterer' : 'No'
}

// "Yes · tables, linen and flowers", "Own planner" or "No"
export function decorText(enquiry) {
  if (enquiry.wantsDecor) return enquiry.decorRequest ? `Yes · ${enquiry.decorRequest.toLowerCase()}` : 'Yes'
  return enquiry.selfDecor ? 'Own planner' : 'No'
}

// "Yes · 1 night · The Villa", "3 rooms for 6 guests", named rooms, or "No".
export function accommodationText(enquiry) {
  const stay = enquiry.accommodation
  if (!stay) return 'No'
  const nights = nightsBetween(stay.checkIn, stay.checkOut)
  const nightsText = `${nights} ${nights === 1 ? 'night' : 'nights'}`
  const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`
  if (stay.roomNames && stay.roomNames.length > 0) {
    return `Yes · ${nightsText} · ${stay.roomNames.join(', ')} for ${plural(stay.guests, 'guest')}`
  }
  if (!stay.rooms) return `Yes · ${nightsText} · ${stay.space}`
  return `Yes · ${nightsText} · ${plural(stay.rooms, 'room')} for ${plural(stay.guests, 'guest')}`
}

// → "Today 09:12" or "03 Sep 2026"
export function receivedText(received) {
  const [date, time] = received.split('T')
  return date === TODAY ? `Today ${time}` : formatDate(date)
}

// The brief as submitted
export function briefFacts(enquiry) {
  return [
    { label: 'Occasion', value: enquiry.occasion },
    { label: 'Preferred date', value: formatDate(enquiry.preferredDate) },
    { label: 'Estimated guests', value: enquiry.estimatedGuests },
    { label: 'Accommodation', value: accommodationText(enquiry) },
    { label: 'Catering', value: cateringText(enquiry) },
    { label: 'Décor', value: decorText(enquiry) },
  ]
}

// → "26 Aug 2026 at 09:30"
export function receivedFullText(received) {
  const [date, time] = received.split('T')
  return `${formatDate(date)} at ${time}`
}

// Enquiry fields, grouped. contact is "email · phone".
export function fullEnquirySections(enquiry) {
  const [email, phone] = enquiry.contact.split(' · ')
  return [
    {
      title: 'Guest',
      facts: [
        { label: 'Name', value: enquiry.fullName },
        { label: 'Email', value: email },
        { label: 'Phone', value: phone },
      ],
    },
    {
      title: 'Event',
      facts: [
        { label: 'Occasion', value: enquiry.occasion },
        { label: 'Spaces', value: spacesText(enquiry) },
        { label: 'Preferred date', value: formatDate(enquiry.preferredDate) },
        { label: 'Alternate date', value: enquiry.alternateDate ? formatDate(enquiry.alternateDate) : 'None given' },
        { label: 'Times', value: timesText(enquiry) },
        { label: 'Estimated guests', value: enquiry.estimatedGuests },
      ],
    },
    {
      title: 'Extras',
      facts: [
        { label: 'Catering', value: cateringText(enquiry) },
        { label: 'Décor', value: decorText(enquiry) },
        { label: 'Accommodation', value: accommodationText(enquiry) },
      ],
    },
  ]
}
