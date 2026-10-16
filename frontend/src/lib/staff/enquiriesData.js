// Enquiry page title, filter chips, and meal types. Enquiries come from the API.

export const ENQUIRIES_INFO = {
  title: "Enquiries", // subtitle is counted in the page
};

// Chips. status null ("Closed") = accepted, cancelled or expired
export const ENQUIRY_FILTERS = [
  { label: "Enquiry", status: "enquiry" },
  { label: "Quoted", status: "quoted" },
  { label: "Closed", status: null },
];

// c_meal_type: one meal, or "all"
export const MEAL_TYPES = {
  breakfast: "Breakfast",
  lunch: "Lunch",
  dinner: "Dinner",
  all: "All meals",
};
