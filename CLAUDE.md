# Smash Entertainment – Enquiry & Booking Management System

Laravel 13 + MySQL + Blade + Alpine + Tailwind v4 (database queue + scheduler). Copied from the Funky Monkey Lead Management System and adapted for Smash (family entertainment centre: entry tickets, birthday parties, school/corporate events). Handles everything BEFORE billing (Semnox = gaming, separate POS = billing): enquiries -> follow-up -> booking -> automatic WhatsApp + email confirmation.

Smash build phases (do in order, stop after each for "continue"): 1 rebrand/roles/schema ✅ · 2 enquiry channels ✅ · 3 pipeline/follow-ups ✅ · 4 bookings ✅ · 5 confirmations ✅ · 6 payments (PAYMENTS_ENABLED) ✅ · 7 reports + BookingExportService ✅. All phases done. Extend existing code; no new frameworks.

## Naming (UI vs code)
- UI says **Outlet** / **Enquiry**; code/tables keep `branches`/`branch_id` and `leads` (no churn). New Smash tables use `outlet_id` -> `branches` (trait `Models\Concerns\BelongsToOutlet`: `outlet()`, `visibleTo()`, `active()`); leads keep `BelongsToBranch`.
- `branches.slug` (unique, auto-filled from name in `Branch::booted`) = public form URL `/enquiry/{slug}`.

## Database
- MySQL (local: service `MySQL84`, DB `smash_enquiries`). Tests stay on in-memory SQLite (phpunit.xml). pgsql config/`DB_SCHEMA` left in config/database.php from the Funky Monkey copy — unused.
- Phase 1 migrations `2026_11_23_1000xx_*`: branches.slug; leads enquiry fields (enquiry_type, preferred_date, guest_count, notes, lost_reason, next_follow_up_at, created_by_user_id); legacy role/status remap; tables enquiry_activities, areas, packages, addons, bookings, booking_addons, notification_logs. Models: Area/Package/Addon/Booking/EnquiryActivity/NotificationLog/PaymentLink (table `payment_links`, migration `101100`). Phase 4 migration `2026_11_23_101000` adds bookings.package_price, created_by_user_id, confirmed_at, cancel_reason.
- Deploy files (Dockerfile, docker/start.sh, Render notes) come from Funky Monkey; Dockerfile now defaults to `DB_CONNECTION=mysql` (pdo_mysql installed).

## Roles & statuses
- `App\Enums\Role`: admin | outlet_manager | front_desk (`Role::outletRoles()` need `users.branch_id`). admin = all; outlet_manager = own outlet, may manage catalogue (`User::canManageCatalog()`); front_desk = own outlet enquiries/bookings only. Users + outlets + assigning enquiries to an outlet: `role:admin` routes.
- `LeadStatus`: new | contacted | quote_sent | confirmed | completed | lost, plus legacy `needs_review` (agency parsing only). Use `LeadStatus::options()` / `LeadSource::options()` for filters (hide agency stuff unless `leads.agency_parsing`). `isOpen()` = new/contacted/quote_sent/needs_review.
- `LeadSource` adds phone_call | walk_in | walk_in_qr | website_form (`channel()` null = no chat thread; `isLeadAd()` = FB/IG lead ads only). `EnquiryType`: general | tickets | birthday | school_group | corporate | other.
- `Lead::assignTo()` no longer changes status; `markReplied()` new -> contacted; `awaitingReply()` (new + outlet + no reply) drives reminders, which go to all active outlet staff. "Close" route = mark lost.
- Agency parsing (Funky Monkey) is kept but OFF: `AGENCY_PARSING_ENABLED=false` -> agency numbers are normal WhatsApp customers. Agency tests enable it in setUp.
- `Lead::awaitingReply()` also excludes `LeadSource::isStaffEntered()` (phone_call/walk_in — staff already spoke to them); same filter in `leads:send-reminders`.

## Enquiry channels (Smash Phase 2)
- Own channels go through `App\Services\EnquiryIntake` (`rules()` shared validation, `submit($data, $source, ?$outlet, ?$by)` → `[$lead, $merged]`). Dedupe = same normalized phone + `LeadStatus::openValues()` + (same outlet or unassigned; any outlet when none given); merge fills blanks, assigns outlet if missing, adds a `note` message "Repeat enquiry via …". New ones get `next_follow_up_at = now + FOLLOW_UP_DEFAULT_HOURS`. Meta channels still use `LeadIngestor`.
- Staff: `EnquiryController` create/store (`enquiries.create|store`; sources phone_call/walk_in; outlet forced to own unless admin) + `qr` (`branches.qr`, bacon/bacon-qr-code SVG, admin or own outlet). Views `enquiries/_fields` (shared), `create`, `qr` (standalone printable).
- Public: `PublicEnquiryController` — `/enquiry/{outlet:slug}` (show/store, PRG with `session('submitted')`, view `public/enquiry`) and `POST /api/enquiries` (`routes/api.php`, JSON 201/422, optional `outlet` slug). Honeypot input `website` → fake success; `throttle:public-enquiry` (5/min + 30/h per IP, AppServiceProvider; keys are hashed by Laravel, tests rely on array cache reset). `PUBLIC_FORM_ENABLED=false` / inactive outlet → 404. CORS: `config/cors.php` paths `api/*`, origins from `WEBSITE_ALLOWED_ORIGINS`.
- Lead Ads: `MetaWebhookProcessor::leadgen` maps `enquiry_type` (`EnquiryType::guess()` on answer or form name), `preferred_date` (`leadDate()`, d/m/Y first), `guest_count` (`leadCount()`).
- Tests: `tests/Feature/SmashPhase2EnquiryChannelsTest.php`.

## Pipeline & follow-ups (Smash Phase 3)
- `EnquiryActivity` (enquiry_activities, no updated_at; `created` bumps lead.last_activity_at) + `ActivityType` call|note|status_change|message. Add via `Lead::logActivity($type, $body, ?$by)` (user defaults to auth user; null = "System").
- Status changes: use `Lead::changeStatus($to, ?$lostReason)` (lost → reason, lost/completed clear next_follow_up_at, contacted sets replied_at). ANY status change (incl. `update()`/`markReplied()`/LeadIngestor reopen) is logged by the `Lead::updated` hook as "Old → New[ · Reason: …]" — don't log status changes manually.
- `EnquiryPipelineController` (LeadPolicy::update): `leads.status` (options() only, lost_reason required_if lost; LOST_REASONS datalist), `leads.activities.store` (call|note; call on new → contacted; `next_follow_up_at` after:now or `no_follow_up`), `leads.follow-up` (PATCH, empty = clear). Old `leads.close` route removed; `mark-replied` logs a `message` activity (not a note message). EnquiryIntake repeat enquiries log a `note` activity.
- Inbox (`InboxController::FILTERS` q, source, status, enquiry_type, branch_id, from/to = received date; blank/invalid dates ignored). `?view=due` = `Lead::followUpsDue()` (next_follow_up_at ≤ end of today incl. overdue, not lost/completed), sorted soonest first. Nav "Follow-ups" item shows the due count.
- Thread: `inbox/_pipeline` (status pills, lost reason form, follow-up editor) under the header; timeline merges messages + activities; footer tabs Reply | Log call | Add note. Edit form also covers enquiry_type, preferred_date, guest_count, notes.
- Tests: `tests/Feature/SmashPhase3PipelineTest.php`.

## Bookings (Smash Phase 4)
- ALL booking writes go through `App\Services\BookingService`: `rules($outlet, ?$booking)` (catalogue ids must be the outlet's + active, except ones already on the booking; date ≥ today unless unchanged), `save($lead, $data, ?$booking, ?$by)`, `transition($booking, BookingStatus, ?$reason, ?$by)`, `clashes($areaId, $date, $start, $end, ?$ignoreId)`.
- Double booking: in one transaction `Area::lockForUpdate()` on the area row, then overlap check (same area + date, tentative/confirmed, `start < other.end && end > other.start`; back-to-back OK) → ValidationException on `start_time`.
- Totals: `package_price` (flat, snapshot) + Σ addon pivot `price × qty` (`booking_addons`, price snapshot). Editing keeps agreed prices; new items use catalogue price. `advance_paid` is entered manually (≤ total) → `balance_due`, `payment_status` (`PaymentStatus::fromAmounts`). End time = start + package duration when blank; must end the same day. Guests ≤ area capacity (0 = no limit) and ≤ package max_guests.
- `BookingStatus`: tentative → confirmed → completed, or cancelled (reason required) from tentative/confirmed; `holdsSlot()` = tentative/confirmed = editable. Confirm → lead `changeStatus(Confirmed)`; complete → lead Completed only if no other tentative/confirmed booking; cancel leaves the lead. Each step logs an `ActivityType::Booking` activity (pill in the timeline).
- `booking_code` = `SMB-{created ymd}-{id 4+ digits}` (set in `Booking::booted` created hook; `TMP-…` placeholder before the id exists). Times stored `H:i:s`; `event_date` is a DATE → always `whereDate()` (SQLite stores `Y-m-d 00:00:00`).
- Controllers: `BookingController` (create/store under `leads/{lead}/bookings` with LeadPolicy::update and the lead's outlet required; index/show/edit/update/status under BookingPolicy = admin or own outlet), `BookingCalendarController` (`bookings.calendar` day/week per outlet, cancelled hidden; `bookings.lookup` counter search by code/phone/name via `Booking::search()`; `bookings.availability` JSON for the form). `CatalogController` handles areas/packages/addons via `catalog/{type}` (`TYPES` const drives fields), gate `manage-catalog` (= `User::canManageCatalog()`), outlet fixed after create, delete blocked when used by a booking.
- Views `bookings/*` (`_summary`, `_actions` shared by show + lookup; `form` has an inline Alpine `bookingForm()` for live totals/availability), `catalog/index|form`; enquiry `_pipeline` shows the lead's bookings + "Create booking". `Format::money()` = ₹ with Indian grouping.
- Tests: `tests/Feature/SmashPhase4BookingsTest.php`.

## Booking confirmations (Smash Phase 5)
- `App\Services\BookingNotifier`: `notify($booking, NotificationType, ?$channels, $force)` writes a `notification_logs` row per channel (`whatsapp|email`, status `queued`) THEN queues `Jobs\SendBookingNotification` → `deliver($log)` (never throws; sets sent|mocked|skipped|failed + provider_response; sent/mocked log an `ActivityType::Message` on the lead). Never twice: automatic sends skip a (booking, type, channel) that has ANY row; `$force` (staff Resend / Send) always adds a row.
- Triggers: `BookingService::transition(→Confirmed)` → Confirmation. `bookings:send-notifications` (every 15 min, `routes/console.php`): `dueReminders()` = confirmed, starts within REMINDER_HOURS_BEFORE, `confirmed_at` before that window opened (no reminder right after confirming); `dueThankYous()` = status completed, ended ≥ THANK_YOU_HOURS_AFTER ago, ≤ 7 days ago (no-shows not marked completed get none). Cancelled at delivery time → skipped.
- WhatsApp: `MetaClient::sendTemplate($to, $name, $lang, $params)` (body params; retries connection/5xx only). Params order in `BookingNotifier::templateParams()` (documented in .env.example). Template from `NotificationType::template()` (WA_TEMPLATE_*); blank in live → skipped. Recipient = `Phone::normalize(lead.phone)` digits.
- Email: `Mail\BookingNotificationMail` (markdown `resources/views/mail/booking.blade.php`, subject per type) to `lead.email`; mock mode renders it but doesn't send.
- APP_MODE=mock: nothing leaves the app; provider_response holds the would-be payload (WA: JSON to/template/params; email: "To x: subject").
- UI: `bookings/_notifications` on the booking page (log table, per-row Resend, "Send {type}" for types allowed by `NotificationType::allowedFor($status)`); route `bookings.notify` (BookingPolicy::update, throttle 20/min).
- Tests: `tests/Feature/SmashPhase5NotificationsTest.php` (Queue/Mail/Http fakes; live-mode payload asserted).

## Payments (Smash Phase 6, `PAYMENTS_ENABLED`)
- Off → payment card hidden, `payment-links.*` routes and `POST /webhooks/razorpay` return 404, `NotificationType::PaymentLink` not allowed.
- `App\Services\PaymentService`: `createLink($booking, $amount, ?$by)` (tentative/confirmed, ₹1…balance_due; Razorpay `POST /v1/payment_links` via `Payments\RazorpayClient`, paise, `reference_id` = `{booking_code}-{n}`, `expire_by` = now + PAYMENT_LINK_EXPIRY_HOURS, Razorpay's own SMS/email off; older open links → cancelled locally + best-effort API cancel; then `BookingNotifier::notify(PaymentLink, force)` = WhatsApp template + email with the link). `markPaid($link, $paymentId, ?$amount, ?$by)` = idempotent (locks the link row; skips if already paid or payment id seen; `provider_payment_id` unique) → adds to `advance_paid`, recomputes balance/payment_status, logs a Booking activity, then a tentative booking is confirmed via `BookingService::transition` (→ confirmation message). `handleWebhook()` handles `payment_link.paid|expired|cancelled`; anything else → "ignored", unknown link → "unknown link" (still 200).
- `RazorpayClient`: Basic auth key_id:key_secret, no SDK; mock returns `plink_mock_…` + fake rzp.io URL; `validWebhookSignature()` = hex HMAC-SHA256(raw body, RAZORPAY_WEBHOOK_SECRET) vs `X-Razorpay-Signature` (skipped only in mock with no secret).
- Suggested advance `Booking::suggestedAdvance()` = PAYMENT_ADVANCE_PERCENT of total − advance_paid (else full balance). `Booking::openPaymentLink()` = newest created & unexpired.
- `PaymentLinkController`: `payment-links.store` (BookingPolicy::update), `payment-links.mock-paid` (APP_MODE=mock only, same `markPaid` path as the webhook). View `bookings/_payments`.
- Edit-form race guard: the booking form posts `advance_paid_was`; `BookingService::save` locks the booking row and rejects the save if the advance changed meanwhile (online payment landed).
- Tests: `tests/Feature/SmashPhase6PaymentsTest.php`.

## Reports & POS export (Smash Phase 7)
- `ReportController` (`reports`, `reports.export`): `?tab=enquiries` (default; lead `created_at`, default last 30 days; source/outlet filters; `summarize()` adds converted = confirmed+completed and lost; by source/outlet/status/type, lost reasons, by day) or `?tab=bookings` (event_date, default last 30 + next 30 days; value/advance exclude cancelled; `balance_open` = tentative+confirmed; by outlet (admin) / package / area with hours / status / event day, cancel reasons). Totals in PHP, tables + `reports/_bar` CSS bars (no chart library). Views `reports/index` (tabs + shared filter) + `_enquiries` / `_bookings`. Existing view keys (`totals`, `bySource`, …) and the leads CSV leading columns are kept (Funky Monkey tests); new lead columns are appended.
- CSV: `App\Support\Csv` (BOM, `safe()` formula-injection guard, `put()`); booking rows via `Services\BookingExport\BookingCsv` (`headers()`, `row()`, `WITH` eager loads) — same layout for the report download and the export driver; amounts as plain `0.00`.
- `Services\BookingExport\BookingExportService` interface (`push($booking, $event)`, `exportMany($bookings, $name): ?string`), bound in AppServiceProvider from `BOOKING_EXPORT_DRIVER`: `none` → `NullBookingExport`, `csv` → `CsvBookingExport` (local disk `storage/app/private/{BOOKING_EXPORT_PATH}`: `booking-events-Y-m-d.csv` appended with flock, `{name}.csv` overwritten). Pushed via `BookingService::pushExport()` (never throws) on created/updated (save), confirmed/completed/cancelled (transition) and `payment` (PaymentService::markPaid). `bookings:export [--date=] [--outlet=slug]` = confirmed+completed for the day, scheduled daily 06:00.
- Tests: `tests/Feature/SmashPhase7ReportsExportTest.php`.

## Seeds
- Outlets `andheri`, `pune`; `admin@smash.test`, `manager@smash.test`, `frontdesk@smash.test` (password `password`); `CatalogSeeder` = 3 areas, 4 packages, 5 addons per outlet; `LeadSeeder` (mock only) 30 enquiries; `BookingSeeder` (mock only, bookings table empty) 8 bookings alternating outlets, via BookingService (moves those enquiries to Confirmed/Completed).

## Local toolchain (Windows)
- PHP 8.3 (winget, not on PATH): `/c/Users/DELL2/AppData/Local/Microsoft/WinGet/Packages/PHP.PHP.NTS.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe/php.exe`
- Composer: `~/bin/composer` (wrapper around `~/bin/composer.phar`)
- In Bash: `export PATH="<php dir>:$HOME/bin:$PATH"` then `php artisan ...`, `composer ...`
- Tests: `php artisan test` (SQLite in-memory, Vite disabled in `tests/TestCase.php`)

## Conventions
- Prefer Laravel built-ins; add packages only if essential. No Laravel Boost.
- All app settings live in `config/leads.php` (APP_MODE, Meta/WA keys + template names, DEDUPE_DAYS, follow-up/reminder hours, public_form, payments.razorpay, agency_parsing, outlet_default_timezone). Never call `env()` outside config.
- Everything must work with `APP_MODE=mock` (no real Meta/WA/FCM/AI/email/payment calls — log instead).
- Middleware aliases: `role:admin`, `active`.
- Outlet scoping: `branch_id` models use `BelongsToBranch`, `outlet_id` models use `BelongsToOutlet` → `Model::visibleTo($user)`.
- Layout: `<x-layouts.app title="...">` with optional `<x-slot:actions>`; CSS helpers `.btn .btn-primary .btn-secondary .btn-danger .input .label .card .badge .table` in `resources/css/app.css`.
- Inline `$request->validate()` in controllers (no FormRequest classes).
- Blade gotcha: never mix inline `@php($x = …)` with `@php … @endphp` blocks in one file (the compiler pairs them wrongly). Use block form.

## Leads (Funky Monkey base)
- Enums `LeadSource` (label/short/color/channel/isLeadAd) and `LeadStatus` (label/color). Lead Ads have `channel() === null` → no chat thread.
- `Lead.phone` mutator normalizes via `App\Support\Phone::normalize()` (+91…); unrecognised input kept raw for review.
- `Lead.contact_id` = chat sender id (PSID / IG id / wa_id) — used for replies and to dedupe chats without a phone.
- `Lead.last_activity_at` = inbox sort key; `Message` `created` event bumps it. `Message` has no `updated_at`.
- Authorization: `LeadPolicy::view`/`update` (admin or same outlet); lists use `Lead::visibleTo($user)->filter($request->only(...))`.
- Inbox: `InboxController` index/show share one view (`inbox/index` + `inbox/_thread`); layout `:flush="true"` for full-height panes.
- `LeadSeeder` runs only when APP_MODE=mock and the leads table is empty.

## Meta ingestion (Funky Monkey base)
- `routes/webhooks.php` is loaded outside the web group (no session/CSRF). `MetaWebhookController` verifies `X-Hub-Signature-256` (skipped only in mock with no secret) and queues `ProcessMetaWebhook` → `MetaWebhookProcessor`.
- ALL lead/message creation goes through `App\Services\LeadIngestor` (`findDuplicate`, `ingestChat`, `ingestLeadAd`, `store`). Idempotent via `external_message_id` / `(source, external_id)`; dedupe = same phone (or same source+contact_id) with `last_activity_at` within DEDUPE_DAYS. `store()` only fills blank fields and reopens closed leads.
- Lead-ad duplicates become a message with `channel = 'lead_ad'`, `external_message_id = 'leadgen:{id}'`. Agency messages use `channel = 'agency'` (they come from the agency, not the customer, so never open the 24h window).
- `App\Services\Meta\MetaClient`: Graph GETs + WhatsApp media download. Mock mode reads `tests/fixtures/meta/graph/{id}.json` and `tests/fixtures/meta/media/{filename}`. Attachments: private disk path (`media/whatsapp/{media_id}/{file}`) or Meta CDN URL; served via `attachments.show` (policy-checked).
- WhatsApp phones: always `'+'.$wa_id` before normalizing. Agency = sender in `config('leads.agency_numbers')` → `App\Services\Agency\AgencyParser::handle()`.
- Fixtures: `tests/fixtures/meta/*.json` with `__NOW__` / `__NOW_MS__` tokens; `php artisan meta:replay [names] [--queue]` (mock only).
- Live-test tools: `meta:simulate-webhook {fixture} [--url]` (signed HTTP POST), `meta:send-test {to} [message] [--template=]` (always real Cloud API). Log channel `meta` (`LOG_META_PATH`, default storage/logs/meta.log): ids/object only, never content.

## Agency parsing (Funky Monkey base)
- `AgencyParser` → `SpreadsheetLeadReader` (xlsx/xls/csv/ods via phpoffice/phpspreadsheet; header row found in first 10 rows) or `TextLeadExtractor` (regex `PHONE`, labels Name:/City:). One lead + message copy per candidate, ids `{wamid}#n`; nothing usable → one needs_review lead with id `{wamid}`. Whole message in one DB transaction.
- Status: phone present and unambiguous → new; else needs_review. Candidates with a phone are deduped via `LeadIngestor::findDuplicate`.
- AI: `Services\Agency\Ai\AiLeadExtractor` interface, bound in `AppServiceProvider` (only `NullAiLeadExtractor` exists; AI_PROVIDER=none). Called only for text when rules found nothing or something ambiguous.
- `PATCH leads/{lead}` (`LeadController@update`, LeadPolicy::update — admin or outlet staff); saving a needs_review lead with a valid phone → new.
- Excel fixture `tests/fixtures/meta/media/agency-leads.xlsx` is binary; regenerate with PhpSpreadsheet if rows need changing.

## Assign / reply (Funky Monkey base)
- Message channels: `whatsapp|messenger|instagram` (customer chat), `lead_ad` (dup form summary), `agency` (agency forward), `note` (system note e.g. "Marked reply sent", rendered as a pill). Only `Lead::CHAT_CHANNELS` inbound messages open the 24h window.
- `Lead::replyWindow()` → channel/recipient (WA = phone digits, Messenger/IG = contact_id)/open/closes_at. `Lead::assignTo()` (resets `replied_at`), `Lead::markReplied()` (keeps first `replied_at`).
- `leads.replied_at` = first reply since latest assignment → Phase 6 "time to reply" = replied_at − assigned_at.
- `MetaClient::sendText()` (mock returns `mock.out.{uuid}`). `LeadPolicy::reply` = can view AND lead has a branch. Needs-review leads can't be assigned (bulk skips them).

## Reminders / reports (Funky Monkey base)
- `ReminderService::remind()` → one `reminders` row per active outlet staff user + `LeadReminder` notification (queued; `database` always, `FcmChannel` when `FcmClient::configured()` and the user has device tokens). Only when `Lead::awaitingReply()`.
- `leads:send-reminders` scheduled every 30 min in `routes/console.php` (status new + outlet + no reply, assigned > REMINDER_AFTER_MINUTES ago, no reminder in the last hour).
- `FcmClient`: HTTP v1, JWT signed with openssl (no SDK), access token cached as `fcm.access_token`; mock mode only logs. Unregistered tokens are deleted.
- Bell in the layout (`notifications.*` routes, polls unread count every 60s). Device tokens: `POST/DELETE /device-tokens` (session + CSRF).
- `ReportController`: filters from/to (lead created_at, default last 30 days), source, branch (admin); totals/by source/branch/status/day computed in PHP; CSV via `streamDownload` with BOM + formula-injection guard.
- Windows PHP can't `openssl_pkey_new()` without a config file — tests pass a minimal temp cnf.
