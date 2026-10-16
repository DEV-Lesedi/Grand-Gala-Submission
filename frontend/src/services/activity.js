// How many API requests are running right now, for the thin progress bar at the top of the page.
// api.js calls startRequest() and endRequest() around every request; TopProgress listens.

// A request quicker than this never shows the bar, so fast pages don't flicker
const SHOW_AFTER_MS = 150

let running = 0
let startedAt = 0
// What TopProgress reads. A new object on every change (React compares it by reference).
//   busy: a request is running · run: counts each busy spell, so the bar starts fresh
//   finished: the last spell was long enough to show the bar, so it should finish it off
let snapshot = { busy: false, run: 0, finished: false }
const listeners = new Set()

export function startRequest() {
  running += 1
  if (running === 1) {
    startedAt = Date.now()
    snapshot = { busy: true, run: snapshot.run + 1, finished: false }
    tell()
  }
}

export function endRequest() {
  running = Math.max(0, running - 1)
  if (running === 0) {
    snapshot = { busy: false, run: snapshot.run, finished: Date.now() - startedAt >= SHOW_AFTER_MS }
    tell()
  }
}

// For useSyncExternalStore: subscribe(listener) → unsubscribe, and the current snapshot
export function onActivityChange(listener) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function activitySnapshot() {
  return snapshot
}

function tell() {
  for (const listener of listeners) listener()
}
