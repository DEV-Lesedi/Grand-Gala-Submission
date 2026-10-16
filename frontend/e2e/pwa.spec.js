import { test, expect } from '@playwright/test'

// UI testing Part 7.4: PWA installability and offline behaviour, against vite.config.js's
// VitePWA setup (devOptions.enabled: true registers the real service worker in dev too).

test('the page links a web app manifest with the right name and icons', async ({ page }) => {
  await page.goto('/')
  const manifestHref = await page.locator('link[rel="manifest"]').getAttribute('href')
  expect(manifestHref).toBeTruthy()

  const manifestResponse = await page.request.get(manifestHref)
  expect(manifestResponse.ok()).toBeTruthy()
  const manifest = await manifestResponse.json()

  expect(manifest.name).toContain('GG Connect')
  expect(manifest.display).toBe('standalone')
  expect(manifest.icons.length).toBeGreaterThanOrEqual(2)
  for (const icon of manifest.icons) {
    const iconResponse = await page.request.get(icon.src)
    expect(iconResponse.ok(), `icon ${icon.src} should be reachable`).toBeTruthy()
  }
})

test('a service worker registers and takes control', async ({ page }) => {
  await page.goto('/')
  const registered = await page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return false
    const registration = await navigator.serviceWorker.ready
    return !!registration.active
  })
  expect(registered).toBe(true)
})

test('theme-color meta and apple touch icon are present (iOS home screen)', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute('content', '#2d5a3d')
  await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', '/icons/apple-touch-icon.png')
})

test('a previously visited page still renders once offline', async ({ page, context }) => {
  // Visit once online so the service worker can cache it and its data
  await page.goto('/')
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => navigator.serviceWorker.ready)
  // Give the runtime caching strategy (NetworkFirst) a moment to finish writing the response
  await page.waitForTimeout(1000)

  await context.setOffline(true)
  const response = await page.reload()

  // The precached app shell should still come back, served by the service worker
  expect(response).not.toBeNull()
  await expect(page.locator('body')).not.toBeEmpty()
  await page.screenshot({ path: 'e2e/test-results/UI-03-offline-test.png', fullPage: true })

  await context.setOffline(false)
})
