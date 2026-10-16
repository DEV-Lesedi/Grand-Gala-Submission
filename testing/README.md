# Test evidence

Screenshots and reports behind the testing report. Generated on 16 Oct 2026 from this repository: public pages
against the live site (https://ggconnect.up.railway.app), and signed-in flows against a fresh local copy seeded
with `php bin/seed.php`, so no test data was written to the live database.

| Report section | Evidence | What it shows |
|---|---|---|
| 2.1 HTTPS | `evidence/SEC-01-https-valid-certificate.png` | TLS 1.3, valid Let's Encrypt certificate, HTTP/2 200 |
| 2.1 HTTPS | `evidence/SEC-02-http-redirects-to-https.png` | Plain HTTP is redirected to HTTPS |
| 2.2 Login and logout | `evidence/SEC-03-staff-page-needs-sign-in.png` | Opening a staff page signed out goes to staff sign-in |
| 2.2 Login and logout | `evidence/SEC-04-wrong-password-refused.png` | A wrong password is refused |
| 2.3 Other users' data | `evidence/SEC-05-access-to-other-users-data.png` | 401 signed out, 403 guest on a staff route, 404 for another guest's booking |
| 2.4 Unsafe inputs | `evidence/SEC-06-non-numeric-ids-not-found.png` | `abc`, `1abc`, `-1`, `1 OR 1=1` as an ID all return 404 |
| 2.6 Dependency audit | `evidence/SEC-07-npm-audit.png`, `evidence/SEC-08-composer-audit.png`, `reports/npm-audit.*`, `reports/composer-audit.*` | 0 known vulnerabilities |
| 3.2 Workflow | `evidence/INT-01-payment-confirmed.png`, `evidence/INT-02-my-bookings-shows-paid.png` | Stay booked and paid (local PayFast stand-in), shown as confirmed |
| 4 UAT | `evidence/UAT-01-playwright-browser-tests.png`, `evidence/playwright-report/`, `reports/playwright.txt` | 55 browser tests passed |
| 5 Data validation | `evidence/VAL-01-weak-password-refused.png`, `evidence/VAL-02-enquiry-date-12-days-ahead.png`, `evidence/VAL-03-tickets-over-capacity.png` | Weak password, 12-day notice, tickets over capacity |
| 6.1 Lighthouse | `evidence/PERF-01-lighthouse-scores.png`, `evidence/PERF-01-lighthouse-report.html`, `reports/lighthouse-summary.json` | 3 mobile runs on the live site |
| 6.3/6.4 Timing | `evidence/PERF-02-api-response-times.png` | Live API response times from South Africa |
| 7.2 Viewports | `evidence/UI-01-mobile-layout.png`, `evidence/UI-02-tablet-layout.png`, `evidence/UI-03-desktop-layout.png` | Live home page on phone, tablet and desktop |
| 7.4 Offline | `evidence/UI-04-offline-mode.png` | The installed app opening with no connection |
| 8 Database | `evidence/DB-01-tables.png`, `evidence/DB-02-foreign-keys-and-checks.png` | Tables, foreign keys and CHECK constraints |
| 9 Test runs | `evidence/TEST-01-backend-phpunit.png`, `evidence/TEST-02-frontend-vitest.png`, `reports/phpunit.txt`, `reports/vitest.txt` | 242 backend and 54 frontend tests passed |
