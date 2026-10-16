// Bookings page title, filter chips, and page size. Bookings come from the API.

export const BOOKINGS_INFO = {
  title: "Bookings", // subtitle is counted in the page
};

// Chips above the table. view: "active" or "past" · status: narrows to one status · newestFirst
// Enquiries aren't bookings (they're on the Enquiries page)
export const BOOKING_FILTERS = [
  { label: "Active", view: "active" },
  { label: "Confirmed", view: "active", status: "confirmed" },
  { label: "Pending payment", view: "active", status: "pending_payment" },
  { label: "Quoted", view: "active", status: "quoted" },
  { label: "Past", view: "past", newestFirst: true },
];

// How many rows the desktop table shows per page.
export const BOOKINGS_PER_PAGE = 9;

export const BOOKING_TYPES = [
  { value: "all", label: "All" },
  { value: "stay", label: "Stays" },
  { value: "event", label: "Events" },
];
