import { test, expect } from '@playwright/test'

// Every public page (BACKEND_FEATURES-era testing Part 7.1): loads, no console errors, no failed
// requests. Pages needing a real id use space 1 / event 1, which the dev database seed creates.
const PUBLIC_PAGES = [
  ['Home', '/'],
  ['Browse spaces', '/spaces'],
  ['Space detail', '/spaces/1'],
  ['Create account', '/create-account'],
  ['Sign in', '/sign-in'],
  ['Forgot password', '/forgot-password'],
  ['Gallery', '/gallery'],
  ['Events', '/events'],
  ['Staff sign-in', '/staff/sign-in'],
]

for (const [name, path] of PUBLIC_PAGES) {
  test(`${name} (${path}) loads without console errors`, async ({ page }) => {
    const consoleErrors = []
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text())
    })
    const failedRequests = []
    page.on('requestfailed', (req) => failedRequests.push(`${req.method()} ${req.url()}: ${req.failure()?.errorText}`))

    const response = await page.goto(path)
    expect(response.ok(), `HTTP status for ${path}`).toBeTruthy()
    await page.waitForLoadState('networkidle')

    expect(consoleErrors, `console errors on ${path}`).toEqual([])
    expect(failedRequests, `failed requests on ${path}`).toEqual([])
  })
}

test('404 page shows for an unknown address', async ({ page }) => {
  await page.goto('/this-page-does-not-exist')
  await expect(page.getByText(/not found/i)).toBeVisible()
})

test('nav links move between Stays, Events and Gallery', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('link', { name: 'Events', exact: true }).first().click()
  await expect(page).toHaveURL(/\/events$/)
  await page.getByRole('link', { name: 'Gallery', exact: true }).first().click()
  await expect(page).toHaveURL(/\/gallery$/)
})
