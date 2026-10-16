// Guest site navigation (header links and phone tab bar).

// Phone tabs. Account goes to Profile, or Sign in when signed out.
export const PHONE_TABS = [
  { key: 'stays', label: 'Stays', to: '/' },
  { key: 'events', label: 'Events', to: '/events' },
  { key: 'gallery', label: 'Gallery', to: '/gallery' },
  { key: 'bookings', label: 'Bookings', to: '/my-bookings' },
  { key: 'account', label: 'Account', to: '/my-bookings?tab=profile', signedOutTo: '/sign-in' },
]

// Desktop header links
export const NAV_LINKS = [
  { label: 'Stays', to: '/' },
  { label: 'Events', to: '/events' },
  { label: 'Gallery', to: '/gallery' },
]
