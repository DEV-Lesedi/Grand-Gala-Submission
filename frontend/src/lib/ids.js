// Temporary ids until the database gives real ones.
// Not crypto.randomUUID(): it only works on https/localhost
let counter = 0

export function newId(prefix = 'new') {
  counter += 1
  return `${prefix}-${Date.now()}-${counter}`
}
