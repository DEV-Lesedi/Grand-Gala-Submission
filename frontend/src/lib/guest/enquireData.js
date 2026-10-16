// Fixed text and choices for the event enquiry form. Spaces come from the API.

// Days from today before a guest may enquire (preferred date, other date, stay check-in).
export const ENQUIRE_LEAD_DAYS = 12

// What happens after sending, shown in the side panel.
export const NEXT_STEPS = [
  { title: 'You send this brief', detail: 'No payment, no date held' },
  { title: 'We quote you', detail: 'Within 2 working days' },
  { title: 'You accept or ask for changes', detail: 'Each change is a new version' },
  { title: 'Pay in full to confirm', detail: 'The date is held once paid' },
]

// Catering and décor are yes/no flags, so one question with three answers is mapped to them.
export const CATERING_CHOICES = [
  { value: 'venue', label: 'Please quote catering' },
  { value: 'own', label: 'We’ll bring our own caterer' },
  { value: 'none', label: 'No catering' },
]

export const DECOR_CHOICES = [
  { value: 'venue', label: 'Please quote décor' },
  { value: 'own', label: 'We’ll bring our own planner' },
  { value: 'none', label: 'No décor' },
]
