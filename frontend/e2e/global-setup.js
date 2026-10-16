import { clearRateLimits } from './clearRateLimits.js'

// Before the whole run: start with empty rate-limit counters (see clearRateLimits.js)
export default function globalSetup() {
  try {
    clearRateLimits()
  } catch (error) {
    console.warn('Could not clear api_rate_limit before the E2E run (is the local MySQL reachable?):', error.message)
  }
}
