import { defineConfig, devices } from '@playwright/test'

// Real browser tests against the live frontend (+ the local backend on :8000, started separately).
// Run: npx playwright test
export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/global-setup.js',
  fullyParallel: false, // the backend's demo data is shared; keep tests from stepping on each other
  retries: 1, // the Vite dev server under 4 parallel workers occasionally drops one connection
  reporter: [['list'], ['html', { outputFolder: 'e2e/report', open: 'never' }]],
  outputDir: 'e2e/test-results',
  use: {
    baseURL: 'http://localhost:5173',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    // auth-flow registers several accounts and shares the register/login rate limit (per IP) with
    // the chromium project; running it twice in parallel trips the real limiter. The backend logic
    // doesn't differ by viewport, so auth-flow only needs to run once.
    { name: 'mobile', use: { ...devices['Pixel 7'] }, testIgnore: '**/auth-flow.spec.js' },
  ],
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
    timeout: 30_000,
  },
})
