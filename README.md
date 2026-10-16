# GG Connect: Grand Gala Gardens booking system

GG Connect is the booking system for **Grand Gala Gardens**, a venue in Centurion. Guests book overnight stays,
send enquiries for private events (weddings, birthdays), accept quotations, buy tickets for public events and pay
online. Staff manage spaces, prices, bookings, enquiries, quotations, events and the team from a staff console.

| Part | Folder | Built with |
|---|---|---|
| Backend (REST API) | [`backend/`](backend) | Plain PHP 8.4 (no framework), MySQL, PHPUnit |
| Frontend (guest site + staff console) | [`frontend/`](frontend) | React 19 + Vite, plain CSS, Vitest |

## Team

| Person | Backend | Frontend |
|---|---|---|
| **Lesedi Kutumela** (lead) | Project setup and database, spaces and rates, availability and reservations (no double booking), the booking lifecycle, staff bookings and dashboard; brought every module together | Staff console (Today, Bookings, Enquiries, Quotations, Spaces & rates, Events & tickets, Team, My account); connected every page to the API; ported the Events, Enquiry, Stays and Checkout pages into the app |
| **Ntokozo Shezi** | Accounts, sign-in sessions, password reset, team management, gallery, email | Shared components, guest layout, Sign in / Create account, My bookings, Gallery, error pages; installable app (offline use) |
| **Mfundo Ndlovu** | Enquiries, quotations, public events and tickets | Events, event detail and tickets, event enquiry, quotation pages |
| **Thabang Mollo** | Stays, checkout and PayFast payments, promo codes, rate limits, scheduled jobs | Home, browse spaces, space detail, Reserve, Checkout, booking confirmed, PayFast sandbox |

## How it works, briefly

- **Bookings** are stays or private events. A booking only takes its dates once they are confirmed free, inside one
  database transaction with the venue locked, so two guests can never get the same dates (`backend/Services/Availability.php`).
- **Holds:** an unpaid booking keeps its dates for a while (48 hours for a stay, 72 for an accepted quotation, 15
  minutes for a ticket order). The scheduled job (`php bin/cron.php`, every 15 minutes) expires lapsed holds and
  completes finished stays and events.
- **Every status change** goes through one place, `backend/Services/BookingLifecycle.php`.
- **Payments:** PayFast. A booking is only confirmed by PayFast's own notification (signature, merchant and PayFast's
  "VALID" answer are all checked), never by the guest's browser coming back. On a developer's machine a local
  stand-in plays PayFast, so payments can be tested without an account.
- **Prices** are always worked out by the backend; the frontend never sends a price.

## Running it on your machine

You need **PHP 8.4** with `pdo_mysql` and `curl`, **Composer**, **MySQL 8**, and **Node.js 20.19+ or 22.12+**.

### 1. Backend

```bash
cd backend
composer install
cp .env.example .env
```

In `.env`:

- set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` for an empty MySQL database (create it first, e.g.
  `CREATE DATABASE grand_gala_gardens;`)
- set `JWT_SECRET` and `TICKET_ACCESS_SECRET` to any long random text (e.g. the output of `openssl rand -hex 32`)
- set `DEMO_ACCOUNT_PASSWORD` to the password you want the demo accounts to have

Then create the tables, load the demo data and start the API on http://localhost:8000:

```bash
php bin/migrate.php
php bin/seed.php
composer serve
```

Emails go to [Mailpit](https://mailpit.axllent.org/) on `127.0.0.1:1025` if it's running (open http://localhost:8025 to
read them). Without it, emails are skipped and logged; everything else still works.

### 2. Frontend

```bash
cd frontend
npm install
cp .env.example .env      # VITE_API_BASE_URL=http://localhost:8000/api
npm run dev               # http://localhost:5173
```

### 3. Try it

| Who | Sign in at | Email | Password |
|---|---|---|---|
| Super admin | http://localhost:5173/staff/sign-in | `lesedi@grandgala.co.za` | your `DEMO_ACCOUNT_PASSWORD` |
| Admin | http://localhost:5173/staff/sign-in | `naledi@grandgala.co.za` | your `DEMO_ACCOUNT_PASSWORD` |
| Staff | http://localhost:5173/staff/sign-in | `sipho@grandgala.co.za` | your `DEMO_ACCOUNT_PASSWORD` |
| Guest | http://localhost:5173/sign-in | `thandi@email.com` | your `DEMO_ACCOUNT_PASSWORD` |

A good path: as the guest, browse a stay, pick dates and pay (the local PayFast stand-in confirms it); send an event
enquiry; then as staff, open Enquiries, build a quotation and send it; back as the guest, accept and pay it.

## Tests

This repo holds about 200 of the most important tests (152 backend, 54 frontend); the full suites (about 920 backend
and 130 frontend tests) are in the team repositories.

**Backend** (needs its own test database, never the one you use day to day):

```bash
cd backend
cp .env .env.testing      # then set APP_ENV=testing, APP_TODAY=2026-09-04 and a separate DB_NAME
vendor/bin/phpunit
```

The tests cover reservations (including two guests booking the same dates at the same moment), availability, holds,
the booking lifecycle, the scheduled job, stays, enquiries, quotations, events, tickets, payments and PayFast checks,
cancelling, accounts, sign-in and which routes each role may use.

**Frontend:**

```bash
cd frontend
npx vitest run
```

The tests cover stays (availability and pricing), checkout (every kind of order and paying), the booking calendar
and the dropdown.

## Folder guide

```
backend/
  API/          one controller per area (Auth, Spaces, Stays, Enquiries, Quotations, Events, Tickets, Payments…)
  Services/     the business rules (Availability, BookingLifecycle, Holds, Quotations, Tickets, Payments…)
  Models/       database queries, one class per table or view
  Database/     Migrations/ (the schema, in order) and Seeds/ (demo data)
  Routes/       api.php: every route and who may use it
  bin/          migrate.php, seed.php, cron.php
  tests/        Unit/ and Feature/ (Feature tests call the API over HTTP)
frontend/
  src/services/ how pages talk to the API (one module per area)
  src/lib/      shared rules and text (dates, money, statuses, calendar)
  src/UI/       components/, hooks/, layouts/, pages/guest/ and pages/staff/
```
