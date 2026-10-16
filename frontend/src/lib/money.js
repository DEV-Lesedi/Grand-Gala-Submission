// Money is stored as numbers of rands, shown as "R24 500"

// 24500 → "R24 500" (whole rands)
export function formatRand(amount) {
  return `R${Math.round(amount).toLocaleString('en-ZA')}`
}
