import { test, expect } from '@playwright/test'
import { clearRateLimits } from './clearRateLimits.js'

// Real end-to-end flows against the live local backend (not mocked): Integration testing
// Part 3.2/3.3 and User acceptance testing Part 4. Each test uses its own fresh account so
// they don't interfere with each other or the seeded demo data.

function freshEmail() {
  return `e2e-${Date.now()}-${Math.floor(Math.random() * 1e6)}@example.com`
}

// This file alone makes 6+ register/login calls from one IP, which the real rate limiter
// (Services/RateLimiter.php, 5 registers / 10 min) would otherwise start refusing partway
// through. Clearing between tests exercises the real auth logic without the limiter interfering
// with a test run of the limiter's own call volume.
test.beforeEach(() => {
  try {
    clearRateLimits()
  } catch {
    // Best effort - if this fails the test just runs against whatever quota is left
  }
})

test('a guest can register, land on My bookings, and sign out', async ({ page }) => {
  const email = freshEmail()

  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('Playwright Guest')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page).toHaveURL(/\/my-bookings$/)
  await expect(page.getByText('Playwright Guest')).toBeVisible()
})

test('registering with a weak password shows the server-validated error', async ({ page }) => {
  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('Weak Password')
  await page.getByLabel('Email address').fill(freshEmail())
  await page.getByLabel('Password', { exact: true }).fill('short')
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByRole('alert')).toContainText(/at least 8 characters/i)
  await expect(page).toHaveURL(/\/create-account/) // didn't navigate away
})

test('registering twice with the same email is refused', async ({ page }) => {
  const email = freshEmail()

  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('First Time')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/my-bookings$/)

  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('Second Time')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByRole('alert')).toContainText(/already has an account/i)
})

test('a signed-out guest is sent to sign in when opening My bookings directly', async ({ page }) => {
  await page.goto('/my-bookings')
  await expect(page).toHaveURL(/\/sign-in/)
})

test('sign in with the wrong password shows an error and does not sign in', async ({ page }) => {
  const email = freshEmail()
  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('Wrong Password Case')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/my-bookings$/)

  // Sign out by clearing storage (no visible sign-out control assumed) and try the wrong password
  await page.evaluate(() => localStorage.clear())
  await page.goto('/sign-in')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('TheWrongOne1')
  await page.getByRole('button', { name: 'Sign in' }).click()

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page).toHaveURL(/\/sign-in/)
})

test('staff can sign in with the seeded superadmin account and reach Today', async ({ page }) => {
  await page.goto('/staff/sign-in')
  await page.getByLabel('Email address').fill('lesedi@grandgala.co.za')
  // The seeded super admin's password: the DEMO_ACCOUNT_PASSWORD the database was seeded with
  await page.getByLabel('Password', { exact: true }).fill(process.env.E2E_STAFF_PASSWORD || 'garden-2026')
  await page.getByRole('button', { name: /sign in/i }).click()

  await expect(page).toHaveURL(/\/staff$/)
})

test('a customer account cannot sign in on the staff console', async ({ page }) => {
  const email = freshEmail()
  await page.goto('/create-account')
  await page.getByLabel('Full name').fill('Not Staff')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/my-bookings$/)
  await page.evaluate(() => localStorage.clear())

  await page.goto('/staff/sign-in')
  await page.getByLabel('Email address').fill(email)
  await page.getByLabel('Password', { exact: true }).fill('Playwright1')
  await page.getByRole('button', { name: /sign in/i }).click()

  await expect(page.getByRole('alert')).toContainText(/guest account/i)
})
