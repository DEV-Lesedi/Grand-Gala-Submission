// Performance testing Part 6.1: Lighthouse, mobile category, run 3 times, scores + Core Web
// Vitals recorded each time. Uses Playwright's own Chromium (no system Chrome needed).
import { mkdirSync, writeFileSync } from 'node:fs'
import lighthouse from 'lighthouse'
import * as chromeLauncher from 'chrome-launcher'
import { chromium } from '@playwright/test'

// Playwright's own Chromium (npx playwright install chromium), or set CHROME_PATH to another Chrome
const CHROME_PATH = process.env.CHROME_PATH || chromium.executablePath()
const URL = process.argv[2] || 'http://localhost:4173/'
const OUT_DIR = 'e2e/test-results/lighthouse'
const RUNS = 3

mkdirSync(OUT_DIR, { recursive: true })

const results = []

for (let i = 1; i <= RUNS; i++) {
  const chrome = await chromeLauncher.launch({
    chromePath: CHROME_PATH,
    chromeFlags: ['--headless=new', '--no-sandbox'],
  })

  const options = { logLevel: 'error', output: 'html', port: chrome.port, formFactor: 'mobile', screenEmulation: { mobile: true, width: 390, height: 844, deviceScaleFactor: 2, disabled: false } }
  const runnerResult = await lighthouse(URL, options)

  const htmlPath = `${OUT_DIR}/run-${i}.html`
  writeFileSync(htmlPath, runnerResult.report)

  const { categories, audits } = runnerResult.lhr
  const summary = {
    run: i,
    performance: Math.round(categories.performance.score * 100),
    accessibility: Math.round(categories.accessibility.score * 100),
    bestPractices: Math.round(categories['best-practices'].score * 100),
    seo: Math.round(categories.seo.score * 100),
    pwa: categories.pwa ? Math.round(categories.pwa.score * 100) : null,
    lcpMs: audits['largest-contentful-paint'].numericValue,
    clsScore: audits['cumulative-layout-shift'].numericValue,
    tbtMs: audits['total-blocking-time'].numericValue, // proxy for INP in a lab run
    report: htmlPath,
  }
  results.push(summary)
  console.log(`Run ${i}: Performance ${summary.performance}, LCP ${Math.round(summary.lcpMs)}ms, CLS ${summary.clsScore.toFixed(3)}, TBT ${Math.round(summary.tbtMs)}ms`)

  await chrome.kill()
}

writeFileSync(`${OUT_DIR}/summary.json`, JSON.stringify({ url: URL, runs: results }, null, 2))
console.log('\nSaved:', `${OUT_DIR}/summary.json`, 'and run-1.html .. run-' + RUNS + '.html')
