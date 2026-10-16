import { test, expect } from '@playwright/test'

// UI testing Part 7.2: mobile / tablet / desktop viewports. Screenshots saved under
// e2e/test-results for manual review (attach these as UI-01/UI-02 evidence).
const VIEWPORTS = {
  mobile: { width: 390, height: 844 },
  tablet: { width: 768, height: 1024 },
  desktop: { width: 1440, height: 900 },
}

const PAGES = [
  ['home', '/'],
  ['gallery', '/gallery'],
  ['sign-in', '/sign-in'],
]

for (const [viewportName, size] of Object.entries(VIEWPORTS)) {
  test.describe(`${viewportName} (${size.width}x${size.height})`, () => {
    test.use({ viewport: size })

    for (const [pageName, path] of PAGES) {
      test(`${pageName} has no horizontal overflow`, async ({ page }) => {
        await page.goto(path)
        await page.waitForLoadState('networkidle')

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth)
        // A couple of px of slack for scrollbar rounding
        expect(scrollWidth, `${pageName} at ${viewportName}: content wider than the viewport`).toBeLessThanOrEqual(clientWidth + 2)

        await page.screenshot({ path: `e2e/test-results/UI-${viewportName}-${pageName}.png`, fullPage: true })
      })
    }
  })
}
