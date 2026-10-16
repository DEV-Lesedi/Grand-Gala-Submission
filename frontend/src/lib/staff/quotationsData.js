// Quotation list and builder settings. The quotations come from the API.

export const QUOTATIONS_INFO = {
  title: "Quotations", // subtitle is counted in the page
};

// The chips above the list. status null = all of them.
export const QUOTATION_FILTERS = [
  { label: "All", status: null },
  { label: "Sent", status: "sent" },
  { label: "Accepted", status: "accepted" },
  { label: "Expired", status: "expired" },
];

// Default note for a new quotation
export const DEFAULT_GUEST_NOTE = "Prices include VAT. Pay in full to confirm the date. Final numbers 10 days before.";

// How long a new quotation stays valid.
export const DAYS_VALID = 7;
