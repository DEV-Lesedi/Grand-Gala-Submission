// Spaces and rates: page title, space types, and rate kinds. Spaces come from the API.

export const SPACES_INFO = {
  title: "Spaces & rates", // subtitle is counted in the page
};

// sp_type ("room" still needs adding to the database)
export const SPACE_TYPES = [
  { value: "villa", label: "Villa" },
  { value: "room", label: "Villa room" },
  { value: "garden", label: "Garden" },
  { value: "marquee", label: "Marquee" },
  { value: "pangola", label: "Pangola" },
  { value: "pool", label: "Pool" },
  { value: "hall", label: "Hall" },
  { value: "other", label: "Other" },
];

// sr_basis. chip: grey label · unit: "/ night"
export const RATE_BASES = [
  { value: "per_night", chip: "Per night", unit: "night" },
  { value: "per_event", chip: "Per event", unit: "event" },
  { value: "per_hour", chip: "Per hour", unit: "hour" },
  { value: "per_head", chip: "Per head", unit: "head" },
];
