# Smash Entertainment – Enquiry & Booking Management System

Enquiry and booking system for Smash Entertainment (family entertainment centre: entry tickets, birthday parties, school and corporate events). It covers everything **before billing**: enquiry → follow-up → booking → automatic WhatsApp and email confirmation. Gaming runs on Semnox and billing on a separate POS; this app does not integrate with either yet.

Enquiries arrive from Meta (Lead Ads, Messenger, Instagram, WhatsApp) or are entered by staff (phone call, walk-in, QR form, website form). Admin assigns Meta enquiries to an outlet, and that outlet's staff follow up.

**Stack:** Laravel 13 · MySQL · Blade · Alpine.js · Tailwind v4 · database queue + scheduler.

## Setup (local)

```bash
composer install
npm install && npm run build
cp .env.example .env              # set DB_PASSWORD; APP_MODE=mock needs no Meta/email/payment keys
php artisan key:generate
# MySQL once:  CREATE DATABASE smash_enquiries CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate --seed
php artisan serve                 # + php artisan queue:work  and  php artisan schedule:work
```

Seeded logins (password `password` — change after first login):

| Role | Email | Access |
|---|---|---|
| Admin | admin@smash.test | Everything: all outlets, users, outlets, assigning enquiries |
| Outlet Manager (Smash Andheri) | manager@smash.test | Own outlet: enquiries, bookings, packages/areas/addons |
| Front Desk (Smash Andheri) | frontdesk@smash.test | Own outlet: create/edit enquiries and bookings only |

The seeders also create 2 outlets (`andheri`, `pune`). Each outlet gets 3 areas, 4 packages and 5 add-ons. In mock mode they add 30 sample enquiries and 8 bookings around today (both outlets, all statuses). Users can't sign up themselves; an admin creates accounts and resets passwords under **Users**.

## Configuration

Everything is set in `.env` and read through `config/leads.php` (see `.env.example`):

- `APP_MODE` — `mock` (no external calls; logs instead) or `live`
- Meta and WhatsApp: `META_*`, `IG_ACCOUNT_ID`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `WA_TEMPLATE_CONFIRMATION|REMINDER|THANK_YOU`, `WA_TEMPLATE_LANG`
- Booking notifications: `REMINDER_HOURS_BEFORE`, `THANK_YOU_HOURS_AFTER`, `MAIL_*`
- Follow-ups and forms: `FOLLOW_UP_DEFAULT_HOURS`, `PUBLIC_FORM_ENABLED`, `OUTLET_DEFAULT_TIMEZONE`
- Payments: `PAYMENTS_ENABLED`, `PAYMENT_ADVANCE_PERCENT`, `PAYMENT_LINK_EXPIRY_HOURS`, `RAZORPAY_KEY_ID`, `RAZORPAY_KEY_SECRET`, `RAZORPAY_WEBHOOK_SECRET`, `WA_TEMPLATE_PAYMENT_LINK`
- POS hand-off: `BOOKING_EXPORT_DRIVER` (`none` | `csv`), `BOOKING_EXPORT_PATH`
- Legacy: `AGENCY_PARSING_ENABLED=false` (Funky Monkey agency parsing; the code is kept but switched off)

## Enquiry channels

All channels create rows in the same enquiry list (Inbox). Phone numbers are normalised to `+91…`. If the same phone already has an **open** enquiry (new, contacted or quote sent) at that outlet, or one with no outlet yet, the new details are merged into it and a "Repeat enquiry" note is added; no duplicate is created.

- **Staff form** (Inbox → **+ New**): phone call or walk-in. Outlet staff always create enquiries for their own outlet; the admin picks one. Follow-up is set to `FOLLOW_UP_DEFAULT_HOURS` from now.
- **Walk-in QR form:** `/enquiry/{outlet-slug}`, mobile-first, no login. Print the poster from **Outlets → QR poster** (staff: "Walk-in QR poster" on the New enquiry page).
- **Website form:** `POST /api/enquiries` (JSON or form data). Responses: `201 {"ok":true,"message":…}`, or `422` with field errors.

  | Field | Rules |
  |---|---|
  | `name` | required |
  | `phone` | required, Indian mobile (10 digits, or with `+91` / `0`) |
  | `email` | optional |
  | `enquiry_type` | optional: `general` `tickets` `birthday` `school_group` `corporate` `other` |
  | `preferred_date` | optional, `YYYY-MM-DD`, today or later |
  | `guest_count` | optional, 1–2000 |
  | `notes` | optional, max 2000 chars |
  | `outlet` | optional outlet slug (e.g. `andheri`); without it, an admin assigns the outlet |
  | `website` | honeypot: render it as a hidden input and leave it empty |

  Browser calls are allowed only from `WEBSITE_ALLOWED_ORIGINS` (CORS).
- **Spam protection** (QR form and API): a honeypot field (bots get a fake success and nothing is stored) and a rate limit of 5 per minute and 30 per hour per IP. `PUBLIC_FORM_ENABLED=false` turns both channels off (404).
- **Meta** channels work as before. Lead Ad answers also fill guests (`number_of_guests`, `number_of_kids`, …), date (`preferred_date`/`event_date`) and enquiry type (from the answer or the form name).

## Working an enquiry

- **Status buttons** at the top of an enquiry: New → Contacted → Quote sent → Confirmed → Completed, or **Lost…**, which needs a reason. Every status change is recorded on the enquiry's timeline with who made it ("System" for automatic changes).
- **Log call / Add note** (bottom of the enquiry): saves to the timeline and sets the **next follow-up**, which defaults to `FOLLOW_UP_DEFAULT_HOURS` from now, or tick "No follow-up". Logging a call on a *New* enquiry moves it to *Contacted*. The follow-up can also be changed or cleared from the status area.
- **Inbox filters:** status, enquiry type, source, outlet (admin) and received date. **Follow-ups** (sidebar, with a count) lists enquiries due today or overdue, soonest first. Lost and completed enquiries drop out of this list.

## Bookings

- **Catalogue** (sidebar, admin and outlet managers): areas (with capacity), packages (flat price, duration, guest range) and add-ons, per outlet. Untick **Active** to hide an item from new bookings; items used by a booking can't be deleted.
- **Create a booking** from an enquiry (**+ Create booking** under the status buttons). The enquiry must have an outlet. Pick area, package, date and start time; the end time follows the package duration. Add-ons have quantities. The form shows the total, balance and the area's existing bookings for that day as you type. Totals are recalculated on the server. Prices are fixed at booking time, so later catalogue price changes don't alter existing bookings.
- **No double booking:** an area can't have two tentative or confirmed bookings that overlap. Back-to-back is fine. The check runs inside a database transaction with a row lock on the area, so two staff saving at the same moment can't both get the slot.
- **Status:** a new booking is *Tentative* (holds the slot) → **Confirm** (also marks the enquiry *Confirmed* and sends the confirmation on WhatsApp and email) → **Mark completed** (marks the enquiry *Completed* once it has no other open booking). **Cancel** needs a reason and frees the slot. Every step is logged on the enquiry's timeline.
- **Advance received:** record cash or UPI taken; the balance is due at the counter. Billing itself stays in the POS.
- **Calendar** (Bookings → Calendar): day view (time grid per area) or week view (areas × days), per outlet. Cancelled bookings are hidden.
- **Counter lookup** (sidebar): search by booking code (`SMB-…`) or the customer's phone. Today's bookings are listed first; you can confirm or complete from there.

## Automatic customer messages

Each message goes out on **WhatsApp** (an approved template) and **email**, sent from the queue:

| Message | When |
|---|---|
| Confirmation | As soon as a booking is confirmed |
| Reminder | `REMINDER_HOURS_BEFORE` (default 24) hours before the start. Skipped if the booking was confirmed inside that window, since the confirmation just went out. |
| Thank-you | `THANK_YOU_HOURS_AFTER` (default 3) hours after the end, for bookings marked **Completed** (up to 7 days back) |

- Reminders and thank-yous are sent by `php artisan bookings:send-notifications`, scheduled every 15 minutes, so the scheduler and queue worker must be running.
- A message is **never sent twice automatically**: every attempt is recorded, including skipped or failed ones.
- **Booking page → Customer messages** lists every attempt with its status (sent, mocked, skipped, failed) and the provider's reply. **Resend** retries one message; **Send …** sends one on demand.
- Editing a confirmed booking doesn't notify the customer. Use Resend on the confirmation if the details changed.
- A message is **skipped** when there is no valid mobile number or email on the enquiry, no template is configured (live mode), or the booking was cancelled before it went out.
- **WhatsApp templates:** create them in Meta Business Manager, then set `WA_TEMPLATE_CONFIRMATION`, `WA_TEMPLATE_REMINDER` and `WA_TEMPLATE_THANK_YOU` (and `WA_TEMPLATE_LANG`). The body variables must be in this order:
  - confirmation: `{{1}}` name · `{{2}}` booking code · `{{3}}` date · `{{4}}` time · `{{5}}` outlet · `{{6}}` area · package · `{{7}}` guests · `{{8}}` balance due
  - reminder: `{{1}}` name · `{{2}}` booking code · `{{3}}` date · `{{4}}` time · `{{5}}` outlet
  - thank_you: `{{1}}` name · `{{2}}` outlet
  - payment_link (`WA_TEMPLATE_PAYMENT_LINK`, payments only): `{{1}}` name · `{{2}}` amount · `{{3}}` booking code · `{{4}}` payment link · `{{5}}` link expiry
- **Email** uses the `MAIL_*` settings.
- **Mock mode** (`APP_MODE=mock`) sends nothing. Each attempt is recorded as *mocked*, with the WhatsApp payload or email subject it would have sent.

## Online advance payments (Razorpay)

Off by default. Set `PAYMENTS_ENABLED=true` to turn it on.

- **Booking page → Advance payment:** enter an amount (suggested: `PAYMENT_ADVANCE_PERCENT` of the total, default 50%) and click **Create link & send**. The app creates a Razorpay Payment Link and sends it to the customer on WhatsApp (`WA_TEMPLATE_PAYMENT_LINK`) and email. Links expire after `PAYMENT_LINK_EXPIRY_HOURS` (default 48). Creating a new link cancels the open one.
- **When the customer pays,** Razorpay calls the webhook. The app adds the amount to the advance, updates the balance and payment status, and confirms a tentative booking, which also sends the confirmation message.
- **Each payment is credited once,** even when Razorpay retries the webhook.
- **Webhook:** `https://<your-domain>/webhooks/razorpay`. In the Razorpay dashboard, subscribe to `payment_link.paid` and `payment_link.expired`, and set the same secret as `RAZORPAY_WEBHOOK_SECRET`. Requests with an invalid signature are rejected.
- **Keys:** `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` (test keys work with Razorpay test mode).
- **Mock mode:** no Razorpay calls. The link URL is fake; click **Mark as paid (mock)** to simulate the payment through the same code path as the webhook.
- **Cash or UPI at the counter:** still recorded in the booking's *Advance received* field. If an online payment arrives while someone is editing the booking, their save is rejected so the online payment isn't overwritten.

## Reports

**Reports** (sidebar) has two tabs. Both share a date range and, for admins, an outlet filter. Outlet staff see only their outlet. **Export CSV** downloads the current tab as a UTF-8 CSV that is safe to open in Excel.

- **Enquiries** (by received date; default: last 30 days):
  - totals, conversion (confirmed + completed), lost, pending reply and average time to reply
  - tables by source, outlet, enquiry type and status
  - lost reasons and enquiries per day
- **Bookings** (by event date; default: last 30 and next 30 days):
  - number of bookings, booked value, advance collected, balance still to collect at the counter, cancellations
  - tables by outlet, package, area (hours booked) and status
  - cancellation reasons and a per-day list

## POS / Semnox hand-off (stub)

Billing and gaming aren't integrated yet. `BOOKING_EXPORT_DRIVER` controls what the app hands over:

- `none` (default): nothing is exported.
- `csv`: files in `storage/app/private/{BOOKING_EXPORT_PATH}` (default `exports`):
  - `booking-events-YYYY-MM-DD.csv`: one row every time a booking is created, updated, confirmed, completed, cancelled or paid online.
  - `bookings-YYYY-MM-DD.csv`: the day's confirmed bookings, written by `php artisan bookings:export [--date=YYYY-MM-DD] [--outlet=slug]`. The scheduler runs it every morning at 06:00 for today.

Rows use the same columns as the Bookings report CSV, and the booking code is the key for the POS. A real POS connection becomes a new driver implementing `App\Services\BookingExport\BookingExportService`. An export failure is logged and never blocks a booking.

## Meta webhooks

- Callback URL: `https://<your-domain>/webhooks/meta`; the verify token is `META_VERIFY_TOKEN`. Subscribe the Page to `leadgen` + `messages`, Instagram to `messages`, and the WhatsApp Business Account to `messages`.
- Webhooks are processed on the queue, so keep `php artisan queue:work` running.
- Mock mode: `php artisan meta:replay` replays the fixtures in `tests/fixtures/meta/`. `meta:simulate-webhook` and `meta:send-test` are for live testing.

## Background processes (production)

- **Queue worker:** `php artisan queue:work` (keep it alive with Supervisor/systemd).
- **Scheduler:** cron `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

## Tests

```bash
php artisan test      # in-memory SQLite, no MySQL needed
```
