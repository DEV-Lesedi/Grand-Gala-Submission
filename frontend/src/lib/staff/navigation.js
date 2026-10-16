// The staff navigation

// Sidebar. countKey is the GET /staff/nav-counts field beside the link (none: no number).
// adminOnly: hidden from staff
export const SIDEBAR_LINKS = [
  { label: "Today", to: "/staff" },
  { label: "Bookings", to: "/staff/bookings", countKey: "bookings" },
  { label: "Enquiries", to: "/staff/enquiries", countKey: "enquiries" },
  { label: "Quotations", to: "/staff/quotations", countKey: "quotations" },
  { label: "Spaces & rates", to: "/staff/spaces" },
  { label: "Events & tickets", to: "/staff/events", countKey: "events" },
  { label: "Team", to: "/staff/team", adminOnly: true },
];

// iPad/phone bottom tabs. Today is the home button in the top bar
export const MOBILE_TABS = [
  { label: "Bookings", to: "/staff/bookings" },
  { label: "Enquiries", to: "/staff/enquiries" },
  { label: "Quotes", to: "/staff/quotations" },
  { label: "Spaces", to: "/staff/spaces" },
  { label: "Events", to: "/staff/events" },
];
