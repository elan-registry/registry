# Email System

> **Maintainer:** Registry maintainer.
> **Repository review:** 2026-09-27.
> **Review triggers:** Email plugin, API, webhook, DNS, or account changes.
> **Claim scope:** Plugin behavior is a repository fact. Brevo plan, tenant,
> sender, DNS, and webhook settings are live settings. No live vendor check is
> recorded here. Record the date and evidence when you check those settings.

The Lotus Elan Registry uses Brevo (formerly Sendinblue) as its transactional email service
for production and staging environments. This document covers account setup, configuration,
troubleshooting, and the developer API.

## Overview

Brevo provides reliable email delivery via HTTP API. We chose Brevo because
A2 Hosting blocks outbound SMTP ports; Brevo uses port 443 (HTTPS) which is
always open.

**Plugin location:** `usersc/plugins/sendinblue/`

**How it works:** The plugin's `override.php` file globally overrides UserSpice's built-in
`email()` function. When the plugin is active, all calls to `email()` throughout the codebase
are routed through Brevo's API. When the plugin is deactivated, UserSpice's native
PHPMailer-based `email()` is used instead.

**Architecture decision:** See
[ADR-012: Adopt Brevo for Transactional Email Delivery](adr/ADR-012-adopt-brevo-for-transactional-email-delivery.md)
for the full rationale and evaluation of alternatives.

## Brevo Account Setup (One-Time)

Follow these steps for a fresh Brevo account:

1. Create an account at [brevo.com](https://brevo.com)

2. Generate an API key:
   - Log in to Brevo
   - Go to My Profile → Settings → SMTP & API
   - Click API keys & MCP
   - Click Generate a new API key
   - Copy the key to a secure location

3. Add a sender:
   - Go to My Profile → Settings → Senders/Domains/IP
   - Click Senders
   - Click Add Sender
   - Enter the sender name and email address (e.g., `Lotus Elan Registry <noreply@elanregistry.org>`)

4. Add the sending domain:
   - Go to My Profile → Settings → Senders/Domains/IP
   - Click Domains
   - Click Add a Domain
   - Enter your domain (e.g., `elanregistry.org`)

5. Add DNS records:
   - Brevo displays the DNS records you must add to your registrar
   - Copy each record:
     - One TXT record (Brevo domain ownership verification)
     - Two DKIM records (email authentication)
     - One DMARC record (email policy)
   - Add all records to your DNS provider (typically your domain registrar)

6. Verify DNS configuration:
   - Return to Brevo
   - Click Check Configuration
   - Brevo confirms all records are detected and propagated
   - DNS propagation may take several minutes; wait and retry if needed

Once verified, your account is ready for the registry to use.

## Plugin Configuration

After your Brevo account is set up and DNS is verified:

1. Log in to the registry admin panel
2. Go to Admin → Plugins
3. Click Configure Brevo
4. Enter the API key from the account setup above
5. Set the sender name (display name for "From" field)
6. Set the sender email address (must match a verified sender in Brevo)
7. Set the reply-to address (where replies to registry emails should go)
8. Click Save Configuration
9. Click Activate Override to route all `email()` calls through Brevo
10. Click Test Email to send a test message to your email address and verify end-to-end delivery

## Environment Setup

### Production and Staging

Both `elanregistry.org` and `test.elanregistry.org` use the same Brevo account and API key. No environment-specific configuration is needed.

### Local Development

Two options are available, depending on your development need:

#### Option A: Mailtrap (Simpler—Recommended for Most Work)

Use Mailtrap to capture all email without touching the Brevo code path:

1. Create a Mailtrap account at [mailtrap.io](https://mailtrap.io)
2. Get your Mailtrap SMTP credentials from the project inbox settings
3. In the registry admin panel, go to Admin → Plugins
4. Deactivate the Brevo plugin
5. Go to Admin → Settings → Email and update the SMTP settings with your Mailtrap credentials
6. All emails sent locally will be captured in Mailtrap's inbox for inspection

**What this tests:** UserSpice's native PHPMailer email path (the code path
when the Brevo plugin is deactivated). This is sufficient for most UI
development and general testing, but it does not exercise the actual Brevo
HTTP API code in `usersc/plugins/sendinblue/override.php` and
`functions.php`.

To switch back to Brevo: re-enter the Brevo API key in the plugin configuration and reactivate the override.

#### Option B: mock-brevo (Advanced—When Testing the Brevo Integration Itself)

Use mock-brevo (a local Docker Compose service) when you need to test code
that calls the real Brevo HTTP API. It mirrors production behavior locally,
without calls to the real Brevo API. See
`docs/development/ENVIRONMENT.md`'s "Docker Dev Environment" section for the
image and version it runs, and for how to pick up a new release.

**What routes to mock-brevo today:**

Only two clients honor `BREVO_API_HOST` and route to mock-brevo:

- `usersc/classes/Cron/BrevoEventReconciliationClient.php`
- `usersc/classes/Cron/BrevoSuppressionSyncClient.php`

Both call `BrevoDevOverride::hostOverride()` before they build their API
client, and both need `US_ENVIRONMENT=development` in `.env` for the
override to apply.

**The app's send path does not route to mock-brevo yet.** `email()` ignores
`BREVO_API_HOST`. Without the plugin's `override.php` (the local default),
`email()` is UserSpice's core function, which uses the SMTP settings. With
`override.php` active, it calls the real Brevo API, so do not turn it on
locally to test with mock-brevo. In both cases a send from the app (Test
Email, password reset, and so on) does not reach mock-brevo. [Issue #2184](https://github.com/elan-registry/registry/issues/2184)
changes the send path to honor `BREVO_API_HOST`.

Git does not track the plugin (`usersc/plugins/*` is in `.gitignore`), so
this repository cannot show its code. The statement above is true of the
installed plugin checked on 2026-09-28. To check your install, run
`grep -n BrevoDevOverride usersc/plugins/sendinblue/functions.php`. No
output means the send path ignores `BREVO_API_HOST`.

**Prerequisites:**

1. `US_ENVIRONMENT=development` must be set in `.env`. This is already the
   default for local dev, and it is what the reconciliation and
   suppression-sync clients check before they apply `BREVO_API_HOST`.
2. `BREVO_API_HOST=http://mock-brevo:8080/v3` must be set in `.env`.

**Setup:**

The mock-brevo service is always included in the Docker Compose stack
(`docker-compose.yml`). When you run `docker compose up`, it starts
automatically alongside the `app`, `db`, `phpmyadmin` and `landing` services. See
`docs/development/ENVIRONMENT.md`'s "Docker Dev Environment" section for the
full Docker setup.

To send a test email straight to mock-brevo, bypassing the app's send path:

```bash
docker compose exec -T -u www-data app curl -s -X POST http://mock-brevo:8080/v3/smtp/email \
  -H 'api-key: local-test' \
  -H 'Content-Type: application/json' \
  -d '{"sender":{"email":"test@example.com"},"to":[{"email":"owner@example.com"}],"subject":"mock-brevo test","htmlContent":"<p>hello</p>"}'
```

A response with a `messageId` means mock-brevo accepted the message. It then
shows in the web inbox.

To check what the reconciliation or suppression-sync clients sent or
received, read the logs:

- Via logs: `docker compose logs mock-brevo` (shows all HTTP requests and responses)
- Via web UI: open the checkout's `MOCK_BREVO_HOST_PORT` to browse
  received emails. The port table and the landing page link are in
  `ENVIRONMENT.md`'s "Docker Dev Environment" section.

**Important:** mock-brevo is for development only. Its database
(`MOCK_BREVO_DB_PATH`) is on the named volume `brevo-data`, so test emails
stay after `docker compose restart`, `stop`, `down` and `up`. To clear it,
remove the container and the volume. The volume name has the Compose
project name in front of it (`registry_brevo-data` for `Registry/`):

```bash
docker compose rm -s -f mock-brevo
docker volume rm registry_brevo-data
docker compose up -d mock-brevo
```

Do not use `docker compose down -v` for this. It also removes `db_data`,
the local database. Production and staging behavior are completely unaffected by
the `BREVO_API_HOST` override; that environment variable only takes effect
when `US_ENVIRONMENT=development`.

## Verification System Feature Switch

The Lotus Elan Registry uses a feature switch to gate all verification-related
email sends (webhooks, reconciliation, suppression import, and future
verification sends), enabled only once Brevo is confirmed operational. Cron
readiness is not a gate — it is surfaced as an advisory indicator, since a
stalled cron transport delays reminders rather than losing the ability to
send them at all.

### VerificationSettings Class

**Location:** `usersc/classes/Car/VerificationSettings.php`  
**Namespace:** `ElanRegistry\Car`

The `VerificationSettings` class gates the entire verification system via a single-row `er_verification_settings` table (`enabled` column, default off).

**Key Methods:**

- `isEnabled(): bool` — reads the current switch state
- `setEnabled(bool $enabled, int $actingUserId = 0): bool` — writes the
  switch; throws `VerificationConfigException` if attempting to enable while
  `brevoReady()` is false. `$actingUserId` attributes the change in the audit
  log — the class performs no session lookups itself
- `brevoReady(): bool` — computed live; true only if **both** conditions hold:
  1. `plg_sendinblue.key` is non-empty (API key configured)
  2. `usersc/plugins/sendinblue/override.php` exists (plugin override active)
- `cronReady(): bool` — computed live; true if `lastCronRequestAt()` reports a
  timestamp within the last 20 minutes (2× the documented 10-minute cron
  transport interval — see
  [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager)).
- `lastCronRequestAt(): ?DateTimeImmutable` — returns the timestamp of the
  most recent cron transport hit, read from
  `er_verification_settings.last_cron_request_at`, or null if cron has never
  hit this environment. `users/cron/cron.php` writes that column via
  `recordCronRequest()` only on its non-denied path — a hit its own
  `cron_ip` allowlist denies never reaches this column at all, so there is
  no "exclude denied rows" filtering to do (unlike the `logs`-table scan
  this replaced — see #1974).
- `recordCronRequest(): bool` — writes `er_verification_settings.last_cron_request_at = NOW()`;
  called by `users/cron/cron.php` on every non-denied hit. Replaces the
  unconditional `CronRequest` log line removed by #1974 (144 rows/day/environment
  with no diagnostic value); never throws.

### Readiness Checks

Both `brevoReady()` and `cronReady()` are **computed live on every call, never
cached**. No "last checked" timestamp is stored; no periodic background
health check runs. This is an explicit design decision: status displays
always reflect current state.

**Brevo Readiness:**

- `brevoReady()` checks only that the plugin's configuration exists and the override file is active
- It does **not** validate the API key by calling Brevo, since that would
  add latency to every status check and introduce a hard dependency on
  external availability
- The first actual API call (a verification send) will fail and log if the key is stale or invalid; those failures are the true signal

**Cron Readiness:**

- The 20-minute window is 2× the standard documented cron transport interval (10 minutes)
- If cron has never hit this environment (`last_cron_request_at` is `NULL`), `cronReady()` returns false
- If the most recent hit is older than 20 minutes, `cronReady()` returns false
- A hit denied by the `cron_ip` allowlist never writes `last_cron_request_at`
  at all — it proves only that the transport reached the server, not that it
  was recognized and ran jobs

### Asymmetric Enable/Disable Gate

**Critical invariant:** `setEnabled(true)` throws
`VerificationConfigException` (HTTP 422) when `brevoReady()` is false.
**`setEnabled(false)` never throws, for any reason.**

An administrator must always be able to turn verification off, even mid-incident. Disabling the switch when Brevo is broken is the recovery path.

```php
try {
    $settings = new VerificationSettings($db);
    $settings->setEnabled(true, $userId);  // throws if brevoReady() is false
} catch (VerificationConfigException $e) {
    // The exception already logged the refusal internally; surface its
    // user-facing message (e.g. "Brevo is not configured") in the response.
    return ApiResponse::validationError(['enabled' => $e->getUserMessage()], $e->getUserMessage());
}

// Disabling always succeeds, regardless of readiness
$settings->setEnabled(false, $userId);  // never throws
```

### Admin UI

The verification system status appears on the Admin dashboard
(`app/admin/index.php?tab=verification`), visible to both admin and editor
roles (read-only for editor, toggle control for admin only). The status panel
and the feature switch are one collapsible panel, "System Status & Feature
Switch". It is collapsed at the bottom of the tab when the system is healthy,
and expanded at the top when it is not. See
[Dashboard Layout](#dashboard-layout-1896).

**Status Indicators:**

- `brevoReady()` — badge `text-bg-danger` if false while the switch is on; muted text if switch is off
- `cronReady()` — badge `text-bg-warning` if false; muted if true
- Last webhook received — muted "Not yet implemented (#1887)" (placeholder for the real webhook implementation)
- Last reconciliation run — muted "Not yet implemented (#1889)"
- Unmatched recipients — badge `text-bg-warning` if the counter is above 0,
  `text-bg-success` if 0, `text-bg-danger` "Unavailable" if the read itself
  failed; counts Brevo signals (webhook events, reconciliation events,
  suppression-list contacts) whose recipient matched no car (#2085)

**Toggle Control:**

- Located on the Verification System tab
- Disabled (with explanatory text) when current user is not admin, or when admin but attempting to enable while `brevoReady()` is false
- Label always explains which prerequisite failed if the enable direction is blocked
- Disabling is never blocked — the switch can always be turned off

**Dashboard Banner:**

A conditional banner appears below pending-migrations alerts when `isEnabled() && (!brevoReady() || !cronReady())`. Severity:

- `alert-danger` if `!brevoReady()`
- `alert-warning` if `brevoReady()` is true but `!cronReady()`

The banner states which prerequisite failed and links to the Verification System tab for details.

## Admin Verification Email Send Tool (#1884)

**Location:** `app/admin/index.php?tab=verification` (Verification System tab)  
**Implementation:** `app/admin/index.php` (POST command handling) + `app/admin/includes/tab-verification.php` (UI rendering)  
**Access:** Admin only (`securePage()` + `hasPerm([2])`)  
**Independent of Feature Switch:** This manual admin tool runs regardless of
`VerificationSettings::isEnabled()`. A deliberate decision: the admin may want
to send a one-off batch of reminders even while the automatic system is paused
for operational reasons, or to test sending before enabling automation.

### Dashboard Layout (#1896)

`app/admin/includes/tab-verification.php` renders the tab as a dashboard. The
parts appear in this order, from top to bottom:

1. **Summary cards.** Six cards: Eligible, Pending, Verified, Sold, Bounced,
   and Suppressed. `CarRepository::countVerificationSummary()` supplies the
   counts. Verified and Sold follow the queue Window control. The other four
   cards show the current state.
2. **Automatic Sending panel.** Shows the job state, the last-run counts,
   the batch size, **Send Batch Now**, and Pause/Resume. A "Next eligible"
   line shows `last_run_at` plus `SendVerificationBatchJob::GUARD_INTERVAL_HOURS`
   (20 hours). The line shows the earliest time the job can run. The real
   send time is the first cron heartbeat after that time. **Send Batch Now**
   is a link to the Eligible queue view. It does not send. The send happens
   only when the admin presses **Send batch** in that view.
3. **Verification Queue.** One table with seven filter pills and the Window
   and Show controls. See [Verification Queue](#verification-queue).
4. **Recent Activity.** The latest 20 `cars_hist` rows with operation
   `VERIFIED` or `VERIFIED SOLD`, newest first
   (`CarRepository::findRecentVerificationActivity()`). It has its own
   Window control, in the `activity_window` URL parameter. This query reads
   `cars_hist` only, with no join to `cars`, so it can list a row for a car
   that has since been deleted (there is no foreign key from `cars_hist` to
   `cars`; see [DATABASE.md](DATABASE.md#no-enforced-foreign-key-constraints)).
   The Verified and Sold summary cards count differently: their query joins
   to `cars`, so a deleted car's history row does not count there. The two
   can disagree by the number of deleted cars with verification history.
5. **System Status & Feature Switch panel.** The probes and the feature
   switch are described in [Admin UI](#admin-ui). The panel is a collapsible
   `<details>` element. Its place depends on system health:
   - **Healthy** (switch on, Brevo ready, cron ready, unmatched-recipient
     count is 0, cron failure count is 0, and no probe failed): the panel
     is collapsed at the bottom of the tab.
   - **Not healthy** (any other state, including a probe that could not be
     read): the panel is expanded at the top of the tab.

Each part has its own fault domain. If one query fails, that part shows an
error or "unavailable" and the other parts still render.

#### Verification Queue

The queue replaces the earlier separate tables. One table shows all views.

**Filter pills:** `All`, `Eligible`, `Pending`, `Bounced`, `Suppressed`,
`Verified`, `Sold`. Each pill is a plain GET link and shows its count. The
allowed values are in `CarRepository::QUEUE_STATUSES`.

**What `All` counts.** `All` is the distinct count of cars that are
Eligible, Pending, Bounced, or Suppressed. It excludes Verified and Sold:
those two are history, not a current queue state. Bounced and Suppressed
can overlap on one car, so `All` can be less than the sum of the four pills.
The rule lives once, in `CarRepository::countVerificationSummary()`.

**Table columns:** Car, Chassis, Owner, Sent, Days, Status, Actions.

**URL state:** `?tab=verification&status=&window=&show=`. A view has its own
bookmarkable URL. `app/admin/index.php` checks each value against a fixed
allow-list. A value that is not in the list falls back to the default. It
never reaches SQL.

| Parameter | Allowed values | Default |
| --- | --- | --- |
| `status` | `all`, `eligible`, `pending`, `bounced`, `suppressed`, `verified`, `sold` | `all` |
| `window` | `7`, `30`, `90`, `365` (days), `0` (all time) | `30` |
| `show` | `10`, `25`, `50`, `100` (rows) | `25` |

The page also reads `activity_window` for Recent Activity. It uses the same
allowed values and the same default as `window`.

Rules for the controls:

- **Window** applies to the Verified and Sold views and their summary cards
  only. The other views show the current state.
- **Show** limits the number of rows. It does not apply to the Eligible
  view. That view shows one batch (see [Preview & Send Flow](#preview--send-flow)).
- The Pending view lists the longest-waiting car first.
- The Verified and Sold views count each car one time. The pill count and
  the list length match **only when the pill count is at or below the
  current Show limit.** A pill count above the limit is still correct; the
  list is only the top rows the limit allows.

**Pending definition.** A car is Pending when all of these hold, per
`CarRepository`'s `verificationPendingWhereSql()`:

- It has a live verification link: `vericode` is set, and `vericode_sent_at`
  is within `CarVerificationEmailComposer::LINK_TTL_DAYS` (60 days) of now.
- The owner has not responded: `last_verified` is null or earlier than the
  send, and `solddate` is null.
- The car is not bounced and not suppressed (car flag or owner profile
  flag). A bounced or suppressed car shows under its own pill instead.

**Status chip.** The Status column shows the car's latest email event in
its current send cycle. `CarRepository::findLatestEmailEventPerCarWithPrecedence()`
selects the event. The chips are: Sent, Delivered, Soft bounce (with the
reason), Bounced, Brevo complaint, and Opted out. "Bounced" covers
`EmailEventApplier::HARD_BOUNCE_EVENTS` (`hard_bounce`, `blocked`, `invalid`,
`invalid_email`). "Delivered" covers four Brevo event names: `delivered`,
`unique_opened`, `opened`, and `click` — an open or a click proves the
message arrived, so each renders the same chip as a plain delivery. The
reason text is Brevo free text. The page escapes it with
`htmlspecialchars()` at render time.

A Brevo event name this system does not otherwise recognize renders its own
chip: the literal event name, with underscores turned to spaces and the
first letter capitalized (for example `deferred` renders as "Deferred"),
styled as a plain, low-emphasis chip. This keeps a new or unlisted Brevo
event visible instead of showing nothing. The logic is in `vsStatusChip()`'s
`default` case. `invalid_email` is excluded from this example because it is
one of the hard-bounce events above, and so always renders "Bounced"
instead of reaching this `default` case.

**Chip precedence rule.** A terminal event always outranks a later
"Delivered" event in the same send cycle. Terminal events are
`EmailEventApplier::HARD_BOUNCE_EVENTS` (hard bounce, blocked, invalid,
invalid email) plus `EmailEventApplier::SUPPRESSION_EVENTS` (spam,
unsubscribed). For example, Brevo can report a message as delivered and then
hard bounce it. The chip shows Bounced. Rules that select the chip event:

1. The current send cycle starts at the car's latest `sent` event. The cycle
   holds that event and all later events. A car with no `sent` event uses all
   of its events.
2. In the cycle, a terminal event wins over any other event, at any time.
3. If no terminal event exists, the latest event wins (`occurred_at DESC`,
   then `id DESC`).

The send cycle is set by time, not by `brevo_message_id`. The local `sent`
event carries a local id, and Brevo webhook events carry Brevo's own id.
The two ids never match. This differs from the original FRD text. The
project owner approved the difference.

The project owner also approved `unsubscribed` as a terminal event. The FRD
list did not include it.

**Opted out and Brevo complaint.** For a suppressed car, the chip shows
"Brevo complaint" when `EmailNoticeBuilder::resolveSuppressionCause()`
returns `CAUSE_BREVO_COMPLAINT` — a `spam` or `unsubscribed` suppression
event not older than the car's latest `EMAIL SUPPRESSED` `cars_hist` row. In
all other cases it shows "Opted out" (the owner used the opt-out link, which
writes no `er_email_events` row). The chip and the owner-facing "email
paused" account notice share this one rule, so they cannot disagree on which
label a given car gets. The logic is in `vsStatusChip()`.

**Why the filter pill and the status chip can disagree.** The pill is based
on the car's current state. The chip is based on the latest email event.
These two sources do not always match. For example, a car with one soft
bounce:

- The chip shows **Soft bounce**.
- The car is still under the **Pending** pill. A soft bounce is not final.
  Brevo escalates repeated soft bounces. The
  car's `email_bounced` flag stays 0, and the Pending view includes only cars
  that are not bounced and not suppressed.

The car moves to the **Bounced** pill only when the `email_bounced` flag
becomes 1. This is not a fault. Use the pill to find the car. Use the chip
to see what happened to the last email.

A second case: after an admin clicks **Clear Bounced**, the car's Status
chip can still show **Bounced**. Clear Bounced writes `cars.email_bounced =
0` and a `cars_hist` audit row; it does not change or delete the car's
`er_email_events` rows. The hard-bounce event stays the latest terminal
event in the car's open send cycle, so the chip keeps showing Bounced until
a new verification email starts a new send cycle. The pill and the action
button react at once: the car leaves the Bounced pill, and its row offers
**Mark Bounced** again, because both read the `email_bounced` flag, not the
event history.

**Contextual action per row.** Each row has one action button. It replaces
the three buttons per row in the earlier tool. The car's state selects the
button, in this order:

| Car state | Button | POST `command` |
| --- | --- | --- |
| Suppressed (car flag or owner profile flag) | Clear Suppression | `clear_suppression` |
| Not suppressed, bounced | Clear Bounced | `clear_bounced` |
| Not suppressed, not bounced | Mark Bounced | `mark_bounced` |

A suppressed car shows Clear Suppression, even if it is also bounced. Clear
Bounced then becomes available after the suppression is cleared. When the
chip reads Soft bounce, the Mark Bounced button is disabled. One soft bounce
does not confirm a dead address.

The handlers and their owner-level effects are unchanged. See
[Mark Bounced / Clear Bounced / Clear Suppression](#mark-bounced--clear-bounced--clear-suppression-owner-level-actions).
Each button submits a sibling form through the `form=` attribute, because the
Eligible view wraps its table in the batch form and nested forms are not
valid HTML. Editors see a dash in the Actions column (read-only). A row
whose owner could not be loaded, or is the `noowner` system account (the
GDPR-erasure reassignment target), also shows a dash: the three actions all
act on the whole owner, and `noowner` can hold many unrelated cars. The
server independently rejects any of the three commands posted for a
`noowner`-owned car.

### Preview & Send Flow

**GET request (no side effects):** The Eligible view of the Verification
Queue (`?tab=verification&status=eligible`) renders a table of eligible cars,
up to the configured batch size (`VerificationSettings::batchSize()`, default
5), under the heading "Send Verification Emails". Car data shown: ID, chassis,
owner name, sent date, days, status chip, and the row action. Every dynamic
value is HTML-escaped at render time.

**POST `verification_send_batch` action:** Sends verification emails to one batch of cars.
CSRF token validated first (`Token::check()`); missing/invalid token includes the
standard UserSpice token-error page and no writes occur. For each submitted car ID:

1. Look up the car row (`findById()`)
2. Re-evaluate eligibility via `VerificationEligibility::skipReason()` — a car
   can change state between GET and POST (sold, bounced, suppressed, verified
   by owner), and is reported as skipped with the reason rather than sent or
   dropped
3. Cars passing the re-check are sent via `CarVerificationSendService::sendOne()`,
   orchestrated per-batch by `VerificationBatchSender::processBatch()`
4. Result is rendered in the response as a plain HTML report card: four
   sections (Sent N / Unrecorded N with reasons / Skipped N with reasons /
   Failed N with reasons). Unrecorded covers a send that genuinely went out
   but whose follow-up bookkeeping failed (`SendResult::sentUnrecorded()`) —
   distinct from a clean send so the admin isn't shown a false all-clear. No
   PRG pattern — refresh risks double-posting, accepted as within the admin
   tool's manual, low-frequency, single-operator trust model. No silent
   totals (AC13): every car gets a per-row outcome line.

### Mark Bounced / Clear Bounced / Clear Suppression (Owner-level Actions)

**Critical semantics:** These actions fan out to **every car the owner has**.
They are owner-level, not car-level. The queue shows one of the three buttons
for each car row. See [Verification Queue](#verification-queue).

**Mark Bounced** (`mark_bounced` POST):

- Records the owner's **current `users.email`** (not the car's denormalized
  `cars.email`) in `profiles.email_bounced_address` via
  `CarVerificationManager::setBouncedForOwner()`
- Fans out to every car owned by that user, setting `cars.email_bounced = 1`
  and `cars.email_bounced_address` to the owner's `users.email`
- **Never reassigns car ownership** — a defect in the original, deleted tool that
  this rebuild fixes. All cars remain owned by their original owner.
- Writes one `cars_hist` row per affected car (audit trail), operation string
  `'EMAIL BOUNCED'`

**Clear Bounced** (`clear_bounced` POST):

- Clears `profiles.email_bounced` and `profiles.email_bounced_address`
- Fans out to every car owned by that user, clearing `cars.email_bounced` and
  `cars.email_bounced_address` via `CarVerificationManager::clearBouncedForOwner()`
- Idempotent: calling it twice on the same owner has no effect the second time
- Writes one `cars_hist` row per affected car, operation string
  `'EMAIL BOUNCE CLEARED'`

**Clear Suppression** (`clear_suppression` POST):

- Distinct from Clear Bounced; reverses a suppression flag instead
- Fans out to every car owned by that user, clearing `cars.email_suppressed`
  via `CarVerificationManager::clearSuppressedForOwner()`
- Writes one `cars_hist` row per affected car, operation string
  `'EMAIL SUPPRESSION CLEARED'`

All three actions: CSRF validated first. On both success and error, the result is
converted to a UserSpice session flash message (`usError()`/`usSuccess()`) and the
page renders normally (standard POST-then-render pattern, no redirect). On success,
the flash message names the owner and affected car count.

### Resume Verification Emails (Owner Self-Service)

The Account Settings page shows a control (anchor id `resume-emails`) when
`profiles.email_suppressed = 1` OR at least one owned car has
`cars.email_suppressed = 1`. Two paths set these flags differently:

- An owner opt-out sets the profile flag and fans out to every car.
- A Brevo spam or unsubscribe event (`EmailEventApplier::apply()`) sets only
  `cars.email_suppressed` on the one car. The profile flag stays 0.

A control keyed on the profile flag alone would hide from the second owner,
for example an owner whose spam complaint was a mistake.

The control displays "Verification emails are currently paused for {N} of your
cars." With the profile flag at 1, N is every owned car, because the profile
flag blocks all cars, including cars added after the opt-out whose own
`cars.email_suppressed` is still 0. With the profile flag at 0, N is the count
of cars with `cars.email_suppressed = 1`. The `findVerificationEligible()` query
excludes a car when either flag is 1.

The button POSTs with CSRF. The handler clears both `profiles.email_suppressed`
and every owned car's `cars.email_suppressed` in one transaction (mirrors the admin
action), via `CarVerificationManager::clearSuppressedForOwnerByOwner()`. It writes
one `cars_hist` row per car with operation `'SUPPRESSION CLEARED BY OWNER'` and does
not touch bounce state. Resumed cars go back into the normal cron schedule. There
is no immediate send.

The manager reads the profile flag with a locking read, so a double-submit is
safe: the second request waits for the first to commit, reads 0, and succeeds.
If a step after the commit fails (log line, flash message, or redirect), the
owner sees that the emails were resumed, not "Nothing was changed".

### Email-Paused Notice on Account Settings (#1899)

The Account Settings page shows a banner when any car the owner has is
suppressed or bounced. The banner names the affected addresses and links to
the Resume control above and to the owner's email field, so the owner finds
the fix without knowing to look for it first.

**Builder:** `EmailNoticeBuilder::buildForOwner(int $ownerId): ?array`
(`usersc/classes/Car/EmailNoticeBuilder.php`). Returns `null` when no car is
flagged. A flagged car with a `spam` or `unsubscribed` row in
`er_email_events` is a Brevo complaint, but only when that event is not
older than the car's latest `'EMAIL SUPPRESSED'` cars_hist row — otherwise
the event is stale (from before an intervening clear and resuppress) and is
ignored, so a no-longer-current Brevo event can't outrank a newer owner
opt-out. Otherwise the cause is the owner's own opt-out click. The bounced
date is the later of the latest `'EMAIL BOUNCED'` cars_hist row and the
latest hard-bounce event, for the same reason: a webhook bounce writes no
history row, so it can be the more recent signal.

**Repository methods:**
`CarRepository::findLatestEmailEventsByCarIdsAndEvents(array $carIds, array $events): array`
finds each car's latest `er_email_events` row matching one of the given
`event` values (e.g. `EmailEventApplier::SUPPRESSION_EVENTS`,
`HARD_BOUNCE_EVENTS`), keyed by `car_id`, so a later unrelated event
(`opened`, `click`, `delivered`) can't outrank the actual suppression or
bounce event. `CarRepository::findLatestHistoryOperationByCarIds(array $carIds, array $operations): array`
finds each car's latest `cars_hist` row matching one of the given
`operation` values (e.g. `'EMAIL SUPPRESSED'`, `'EMAIL BOUNCED'`), keyed by
`car_id`. Both use the same self-join-on-`MAX()` technique and empty-array
no-op as `findLatestEmailEventsByCarIds()` above. The builder uses them
together to find each address's cause and to date the suppression and
bounce lines in the banner.

**Partial:** `app/views/_email_paused_notice.php`, included from
`usersc/account.php`. Dismissing the banner stores a key in
`sessionStorage` so it stays hidden for that browser tab until the
underlying data changes (a hash of the affected addresses, causes, and
dates) or the session ends.

### The Shared Send Service

**Class:** `CarVerificationSendService` (`usersc/classes/Car/CarVerificationSendService.php`)

The eligibility query, vericode rotation, email composition, and send sequence
are NOT duplicated between the admin tool and the cron job. Instead, both
callers use `CarVerificationSendService`, a stateless service whose public
methods are pure functions of their arguments + current DB state. No
`$_POST`/`$_SERVER`/session lookups live here — anything specific to the admin
environment belongs in `app/admin/index.php` (the Verification tab's POST command handling).

**Key Methods:**

- `findEligible(int $limit, int $offset): array` — delegates to
  `CarRepository::findVerificationEligible()` verbatim; the single query both
  callers use
- `sendOne(object $carData): SendResult` — send one car's email, return sent/failed

**Intended Reuse by #1885:** The cron job that follows this issue will call
`sendOne()` in the same sequence, with the same eligibility rules. This prevents
the admin preview and cron from disagreeing about which cars are due.

### Local Synthetic Email Event ID

For manually-sent verification emails (via the admin tool), there is no real
Brevo `message_id`. Instead, a synthetic id is written to `er_email_events.brevo_message_id`:

```php
'local:' . hash('sha256', $verificationCode)
```

This ensures every row in the `UNIQUE(car_id, brevo_message_id, event)` index
has a distinct key (no NULL collision), so dedup still works if the same email
is sent twice. Brevo webhook events (which have real message ids from Brevo)
are distinguished from admin sends by this `'local:'` prefix.

### Brevo Webhook Receiver (#1887)

**Location:** `app/api/webhooks/brevo.php`
**Not in `$path`, no `securePage()`:** Brevo is an external caller with no
UserSpice session. Authentication is a static bearer token instead (below).
**Method:** POST only; anything else gets a 405, matching every other
`app/api/` endpoint's convention.

**Auth:** `Authorization: Bearer <token>`, compared with `hash_equals()`
against `$_ENV['BREVO_WEBHOOK_TOKEN']` — reads `$_ENV`, not `getenv()`, since
`Dotenv::createImmutable()` (`users/init.php`) never calls `putenv()`, so
`getenv()` would always return `false` here even with a correctly configured
`.env`. Fails **closed**: an empty/missing configured token rejects every
request rather than accepting everything.
Rejections are logged under `LOG_CATEGORY_SECURITY` with only a short hashed
prefix of the provided token, never the raw value. See
[ENVIRONMENT.md](ENVIRONMENT.md) for how to generate and set this token, and
[RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md)-driven release notes
for the per-environment setup step.

**Rate limiting:** Two IP-scoped keys (see `usersc/includes/rate_limits.php`):

- `brevo_webhook` — checked only **after** auth passes; gates the entire
  request via 429 if the limit is exceeded. A rate-limiter failure (which
  fails open, matching `app/api/shared/join-failure-report.php`'s pattern)
  can only ever become a throughput bypass, never an auth bypass.
- `brevo_webhook_auth_failure` — checked **during** the auth-failure branch
  itself in `brevo.php`; gates only whether that failure gets logged (the
  401 response is never affected by rate-limit state). Prevents a spammed
  invalid-token attack from unboundedly growing the `logs` table while
  preserving the security invariant above.

**Verification-system gates** (unchanged from the pre-#1887 stub):
`!isEnabled()` → 2xx, silent. `isEnabled() && !brevoReady()` → 2xx, logged
once under `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING`.

The same `isEnabled()` switch also gates the nightly reconciliation and
suppression-sync cron jobs (and their manual "run now" admin-script paths)
— not just this webhook. See `docs/development/DEPLOYMENT.md`'s cron-author
contract for how `AbstractCronJob` enforces this uniformly across both jobs.

**Tag filter:** the payload's `tags` array must contain
`AppConstants::VERIFICATION_EMAIL_TAG` (`'car_verification'`) — Brevo also
delivers webhook events for other kinds of mail this app may someday send,
and this receiver only ever acts on verification-email events.

**Matching:** the payload's `email` is looked up against `cars.email`
(`CarRepository::findByEmail()`) — every matching car (an email can be shared
by more than one car) gets its own `er_email_events` row and its own
independent escalation.

**Escalation rules** (`BrevoWebhookEventProcessor`):

| Brevo event | Effect |
| --- | --- |
| `hard_bounce`, `blocked`, `invalid` | Immediately flags the car bounced (`cars.email_bounced = 1`, `email_bounced_address` set to the reported address) |
| `soft_bounce` | Event row only, unless this is the 3rd or later distinct send cycle (distinct `brevo_message_id`) since the email's last `delivered` event — then escalates via the same bounced flag |
| `delivered` | Event row only. Implicitly resets the soft-bounce escalation window (the count query only looks at soft bounces after the most recent `delivered` row) |
| `unique_opened` | Event row only, never touches flags or the escalation count |
| `spam` | Flags the car suppressed (`cars.email_suppressed = 1`) — a distinct signal from a bounce |

**HTTP status contract:**

| Situation | Response |
| --- | --- |
| Parsed and durably written | 2xx |
| No recognized tag | 2xx, logged |
| Recipient matches no car | 2xx, logged, `er_verification_settings.unmatched_recipient_count` incremented |
| Malformed/unparseable payload (incl. a top-level JSON list — Brevo's `batched: false` guarantee means a list body is never legitimate) | 4xx, logged |
| Auth token missing/empty/wrong | 4xx, logged (hashed prefix only) |
| Database write failure | 5xx — the only retryable case |

No response body on any status — Brevo parses no body, only the HTTP status.
Never acknowledges (2xx) before the `er_email_events` write commits: Brevo
does not retry a 2xx, so acking early on a write that then fails would
silently lose the event forever.

**Storage:** `er_email_events` (see [DATABASE.md](DATABASE.md)) is the
durable per-car event log; `cars.email_bounced`/`email_bounced_address`/
`email_suppressed` (mirrored on `cars_hist`) are the current-state flags it
drives.

**Out of scope for #1887** (see that issue's non-goals): no outbound Brevo
API calls of any kind (webhook *registration* is #1888, blocked-contacts
*import* is #1923, nightly *reconciliation* is #1889), and no admin UI
rendering of the per-car event history. Admin UI rendering of the
unmatched-recipient counter shipped in #2085 — see the Status Indicators
list above.

### Auto-Clear Bounce Flag on Confirmed Email Change (#1890)

When an owner completes a UserSpice email-verification flow (confirming they
own a new email address by clicking a vericode link), the registry automatically
clears the `cars.email_bounced` flag on any of their cars whose recorded bounced
address is no longer current. This closes the loop: a bounce recorded against
an old address should not permanently suppress verification emails once the
owner has proven a new address is reachable.

**Timing:** Runs on the `verifySuccess` hook, after a successful vericode
confirmation but before the owner-field sync that propagates the new email
address to the cars (see `usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php`).

**Method:** `CarRepository::clearBouncedForUser(int $userId, string $currentEmail): int`
— a single parameterized `UPDATE` scoped by user_id, not a per-car loop.
Clears both `email_bounced` and `email_bounced_address` on every car owned by
the user whose `email_bounced_address` is NULL, empty, or differs from the
just-confirmed email (case-insensitive comparison). Returns the count of rows
affected.

**Data-integrity handling:** Rows with `email_bounced=1` and a NULL/empty
`email_bounced_address` represent a pre-existing data anomaly (the
`updateEmailBounced()` method forbids writing that combination, but legacy
data can still exist). The hook detects this anomaly via
`CarRepository::carIdsWithBouncedFlagButNoAddress(int $userId): array` before
calling `clearBouncedForUser()`, logs it under `LOG_CATEGORY_EMAIL_BOUNCED`,
and clears it anyway — refusing would permanently exclude an owner who just
proved their address is reachable; clearing wrongly self-corrects on the next
real bounce.

**Logging:** The hook logs only when something actually changed:

- If the integrity-check query finds anomalous cars, logs the car IDs and explains the condition
- If `clearBouncedForUser()` clears at least one row, logs the count of cars affected

A no-op confirmation (address never bounced, or a stale re-click) writes no log line.

**Exception safety:** `clearBouncedForUser()` runs in its own `try`/`catch` block,
separate from the owner-field sync. A database failure in the bounce-clear does not
prevent the sync from running, and vice versa; all failures are logged and the
hook continues silently (this is a background repair, not a user-facing operation).

**Related**:

- `UserSpice user email-verification flow` — `users/verify.php`, triggered via
  a clickable vericode link the owner receives via email
- `sync_owner_email_on_verify` hook — runs the bounce-clear operation plus the
  owner-field sync to `cars.email` on every confirmed email change
- `CarRepository::updateEmailBounced()` — the write method that sets the bounce
  flag (forbids writing a null/empty address at the same time)

### Brevo Suppression List Import (#1923)

Brevo maintains an account-level suppression list (`GET /v3/smtp/blockedContacts`)
of addresses it will no longer deliver to — hard bounces, spam complaints, and
unsubscribes — separate from and predating any per-event webhook coverage. A
suppressed address produces no bounce and no delivery event, just silence, so
without importing this list the registry keeps re-sending verification mail to
addresses that can never receive it.

**Job:** `BrevoSuppressionSyncJob` (`usersc/classes/Cron/`), the second job
registered under `users/cron/`, alongside #1889's reconciliation job. Applies
every suppression through the same `EmailEventApplier` the webhook and
reconciliation job use, so an imported suppression flags a car identically to a
live event.

**Two fetch modes:**

- **Nightly incremental** (`execute()`, via the guarded `run()`) — one bounded
  page over a 48-hour lookback window, matching #1889's shape.
- **Manual full backfill** (`runFullBackfill()`, reachable only from the admin
  script's `runNowWithSummary()`, never from the scheduled path) — walks the
  entire suppression list with no date window, bounded by a page-count safety
  cap.

**Reason-code mapping** (Brevo's raw `reason.code` → the event name applied):

| Brevo reason code | Applied event | Effect |
| --- | --- | --- |
| `hardBounce` | `blocked` | flags the car bounced |
| `contactFlaggedAsSpam` | `spam` | flags the car suppressed |
| `unsubscribedViaEmail`, `unsubscribedViaMA`, `unsubscribedViaApi`, `adminBlocked` | `unsubscribed` | flags the car suppressed |
| anything else | (none) | logged, tallied under `'unrecognized'`, skipped |

`adminBlocked` maps to `unsubscribed` rather than `blocked` deliberately: it
means a human suppressed the address at Brevo, a suppression decision rather
than evidence the mailbox is dead. See the job class's own docblock for the
full rationale and the count-accuracy notes behind its `SuppressionSyncSummary`
return value.

**Admin UI:** `app/admin/scripts/maintenance/28-Reconcile-Brevo-Suppressions.php`
— the manual "run now" wrapper, sharing `27-Reconcile-Brevo-Events.php`'s
gate/two-phase-UI pattern. Both scripts render their run's summary inline
(matched/unmatched/skipped counts and a per-event-type or per-reason-code
breakdown, plus warnings when the run was incomplete or something was
skipped) rather than requiring a trip to Admin → Logs — #1923 introduced the
pattern via `SuppressionSyncSummary`/`runNowWithSummary()`, and #2061 applied
the same shape to the reconciliation job via `ReconciliationSummary`.

### Feature Switch Related Documentation

- [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager) — the 10-minute interval constant referenced by `cronReady()`
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) —
  `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING` for logging failures,
  `LOG_CATEGORY_EMAIL_WEBHOOK` for webhook event processing
- [CLASSES.md](CLASSES.md) — `VerificationSettings` and `VerificationConfigException` class reference

## Composing and Sending Verification Emails (#1882, #1883)

The periodic verification email that requests owners confirm their car records are current is built by `CarVerificationEmailComposer`
and includes a one-click opt-out link that lets owners suppress all future verification mail via a single vericode-authenticated
action. `CarVerificationSendService` uses the composer. `app/admin/index.php` and `users/cron/send_verification_batch.php` both build
that service, so the composer is wired to the send path.

### Verification Email Composer

**Location:** `usersc/classes/Car/CarVerificationEmailComposer.php`

The `CarVerificationEmailComposer` class has one main public method, `compose(object $carData, object $owner, string $vericode):
array{subject, html}`, which builds the subject line and full branded HTML body. It also has the public URL builders `verifyUrl()`,
`soldUrl()`, `optOutUrl()`, and `editUrl()`. The class intentionally performs **no database access** — everything it renders comes
from the `$carData` (car row) and `$owner` (owner row) objects supplied by the caller. The one exception is `logger()`, which writes
to the logs table. The constructor is
`__construct(?EmailTemplate $template = null, ?string $imageRoot = null)` where `$imageRoot` defaults to the repo's `userimages/`
directory. Tests inject a temporary directory. `$imageRoot` sets the disk root only. The image URL always uses `ELAN_IMAGE_DIR`, so
an injected root must use the same `{root}/{carId}/` layout. The composer checks the filesystem to see whether photo files exist. This design keeps
the composer testable with fixture objects and an optional temp image directory, no framework bootstrap or database required.

The composed email includes:

- **Greeting and explanation** — why the owner is receiving this request
- **Verify/Sold side-by-side buttons** — confirm ownership or report the car sold
- **Owner Information box** — ID, name, email, location, join date
- **Car Information box** — ID, year, type, chassis, series, variant, color, purchase/sale dates, website, and a Photos row
  (see Photos row states below)
- **Conditional "About the Chassis Number" alert** — appears only when `cars.chassis_override = 1`, explaining that the chassis was
  manually entered and may differ from factory records
- **Conditional blank-field callout** — appears when any of Color, Variant, Purchase Date, Photos (highlighted state only), or
  Website is blank. It names every blank field, highlights its row, and asks the owner to fill them in
- **Edit button** — links to the full `app/owner/cars/edit.php` form (requires login)
- **Footer block** — opt-out link (see below) and an expiry notice for the `LINK_TTL_DAYS` window

**Photos row states:**

- **Thumbnail** — the primary photo is the first listed photo whose base file is on disk. When its `-resized-300` file also
  exists, the row shows that file at 300px (absolute URL). The alt text names the car (year, "Lotus Elan", series, variant, type,
  color). A "View all N photos" link opens `app/owner/cars/details.php`. N counts the photos on disk
- **Plain "N photos on file"** — at least one listed base file is on disk, but the primary photo has no `-resized-300` file. A
  later photo is not used in its place. N counts the safe entries listed. The row is not highlighted
- **Highlighted "Not yet provided"** — no safe photo is listed, or no listed base file is readable on disk. Only this state names
  Photos in the blank-field callout

The composer logs once per email under `FileError` when the photo data has a problem. Examples are a value that is not a list,
an invalid car id, unsafe entries, or listed files missing from disk. The message holds counts and types only, never filenames
or paths. An empty value or an empty list is the normal no-photo state and logs nothing.

**Public Constants:**

- `LINK_TTL_DAYS = 60` — Lifetime of the Verify/Sold/Opt-Out links. Must equal `VERIFY_LINK_TTL_DAYS` in `app/verify/verify_car.php`.
  A unit test guards against drift, because the composer's expiry notice text promises this window

**URL Builders** (public):

- `verifyUrl(string $vericode): string` — Absolute URL to confirm ownership
- `soldUrl(string $vericode): string` — Absolute URL to report the car sold
- `optOutUrl(string $vericode): string` — Absolute URL to opt out of all future verification emails (see below)
- `editUrl(int $carId): string` — Absolute URL to the full car edit form

### One-Click Owner Opt-Out

Every verification email footer includes a "Stop sending me these" link that opens
`app/verify/verify_car.php?vericode=...&action=optout`. The owner needs no login to use it — the vericode is the credential, matching
the Verify/Sold pattern. The flow is entirely GET/POST-driven:

**GET (confirmation page):**

The owner clicks the opt-out link and sees a confirmation page displaying how many cars are registered to them and whether they
are already opted out. The page is read-only — clicking the link again (or revisiting the URL) always shows the confirmation card
with the same information.

**POST (suppression):**

The owner confirms and submits a POST to the same URL. The action is **owner-initiated and scoped to the account**, not individual
cars: `CarVerificationManager::setSuppressedForOwner(int $ownerId)` sets `profiles.email_suppressed = 1` on the owner and fans
`cars.email_suppressed = 1` out to every unsuppressed car they have. Already-suppressed cars are skipped, making the operation
idempotent — a repeat POST or an owner already suppressed via a Brevo complaint webhook commits a no-op and redirects identically.

Each affected car gets one `EMAIL SUPPRESSED` `cars_hist` row via the explicit `insertHistory()` call in `verify_car.php` (in
addition to the generic `UPDATE` row the `cars_update` trigger always writes for any `cars` column change) — the `EMAIL SUPPRESSED`
row is what makes owner-initiated suppression distinguishable from other causes, with comments text `'Owner self-suppression via
verification email opt-out link'`. All cars and their audit rows are written in a single transaction; a database failure rolls back
the entire operation.

The POST redirects via 303 (See Other) to the confirmation page (GET), which then displays the updated suppression state. A repeat
visit always succeeds silently.

**Authentication:** No CSRF token is required or present (see `app/verify/verify_car.php`'s file header for the full rationale).
The vericode is the unguessable credential; session-less CSRF tokens would add no value while breaking legitimate email links.

**Logging:** A successful opt-out writes no `logger()` entry — the `cars_hist` row (`operation = 'EMAIL SUPPRESSED'`) is the audit
record. Failures (a DB error while counting cars, or during the suppression transaction) are logged under
`LogCategories::LOG_CATEGORY_EMAIL_BOUNCED` before `verify_car.php` renders the post-authentication failure page
(`renderActionFailed()`), which states plainly that the write did not complete — deliberately not the generic
"expired or invalid link" copy, since the vericode has already authenticated by this point.

### Brevo Link Rewriting

Brevo rewrites every http(s) link in a transactional email through its click-tracking redirect domain. This applies to
the Verify, Sold, Opt-Out, and edit links in the verification email, and to the http(s) links in the auth emails. It
is not configurable. Brevo has no per-link exclusion for transactional email: no CSS class, tag, or API parameter
turns tracking off for one link (confirmed during #2147).

Rule for templates: **do not print a raw URL as body text.** The reader sees the rewritten tracking URL, which looks
like a phishing link. Give each action link a text label, never the URL. Use an `EmailTemplate::createButton()` or
`createButtonRow()` button for a primary action. A secondary link, such as the verification email's Opt-Out link, can
be a plain `<a>` with a text label. If a template needs a fallback for a button that does not work, give a `mailto:`
contact hint built from `getFeedbackEmail()`. A `mailto:` link is not an http(s) link, so Brevo is not expected to
rewrite it (not yet confirmed in a live send). The auth email templates in `usersc/views/` use this pattern.

**Template-variable workaround: tested, does not work.** Some Brevo users report that supplying the URL via a
template variable (`href="{{ params.link }}"` instead of a literal `href="https://..."`) sometimes escapes
rewriting. Tested 2026-09-22 via a direct `POST /v3/smtp/email` send (`params: {link: <url>}`, `htmlContent`
containing both a literal-href control link and an `href="{{ params.link }}"` link) from test.elanregistry.org to a
real Gmail-hosted inbox. Result: **both links were rewritten** to Brevo's click-tracking redirect domain
(`*.r.af.d.sendibt2.com/tr/cl/...`) — no difference in behavior between the literal and template-variable forms on
this account/plan. Confirms the exclusion is not achievable via this route either; do not attempt it again without a
new reason to expect different behavior (e.g. a plan/setting change on Brevo's side).

**If per-message tracking-off becomes a hard requirement.** Research from #2151 (prices as of 2026-09-23, volume
about 150–250 emails a month). Revisit only if a security reviewer objects to Brevo-rewritten links in
security-sensitive emails, Brevo becomes unsuitable for another reason, or a feature needs a link that Brevo must not
track.

| Provider | Tracking-off | Cost at our volume | Migration size |
| --- | --- | --- | --- |
| Postmark | `TrackLinks: "None"` per message | About $15/month (no free tier) | Small. Flat JSON API and flat webhooks like Brevo's. `Metadata` replaces Brevo tags. |
| Amazon SES | Off by default | About $0.03/month | Large. AWS SDK dependency, SNS for events, sandbox exit, rewrite of `BrevoSuppressionSyncJob` and `BrevoEventReconciliationJob`, new DKIM and SPF records. |
| SendGrid, Mailgun | Per message | No useful free tier | Ruled out. No cost advantage over Postmark or SES. |

A switch touches `usersc/plugins/sendinblue/functions.php` (or its #2186 replacement), `BrevoWebhookEventProcessor`,
`BrevoSuppressionSyncJob` and `BrevoEventReconciliationJob`.

---

## Verifying Email Delivery

### Brevo Dashboard

Log in to Brevo and navigate to Transactional → Email to view:

- **Real Time:** Live feed of all outbound emails with delivery status
- **Statistics:** Delivery rates, bounces, complaints, and trends
- **Logs:** Searchable history of all sent messages with timestamps and recipient details

### Application Logs

In the registry admin panel:

1. Go to Admin → Logs
2. Filter by log category `sendinblue`
3. View all plugin-level send attempts, errors, and exceptions

Logs include timestamps, recipient addresses, and any API error messages returned by Brevo.

## Brevo Webhooks — Verified Behaviour (#1871)

Observed live on 2026-09-03 against `test.elanregistry.org` (A2 Hosting, LiteSpeed,
behind Cloudflare) with the tooling in `scripts/spike-1871/` (see its `README.md` to
re-run; kept until #1887 ships its own fixtures). Every fact below comes from a captured request, not from
Brevo's documentation. The bounce-detection endpoint (#1887) must be designed
against this section; where the docs disagree, this section wins until re-verified.

### Transport

| Question | Observed |
| --- | --- |
| Auth header (UI "Token" / API `auth.type: "bearer"`) | `Authorization: Bearer <token>` |
| `$_SERVER` key on A2/LiteSpeed via Cloudflare | `HTTP_AUTHORIZATION` — present with no `.htaccess` change; also visible in `getallheaders()` |
| `Server::get('HTTP_AUTHORIZATION')` | Returns the value intact (verified via CLI 2026-09-03): `KEY_MAP` is a per-key sanitiser table with a plain-string fall-through, not an allowlist. No project-owned header reader is needed |
| `batched` | `false` — one event per POST; body is always a JSON **object**, never a list |
| `REMOTE_ADDR` | A Brevo egress IP, not a Cloudflare edge IP (the host evidently restores the client IP, mechanism not inspected), so `$remote_addr` is usable for allowlisting |
| Brevo source IPs seen | `172.246.241.0`, `.64`, `.128`, `.192` — evenly spaced, so a larger block; allowlist Brevo's published ranges, not these four |
| User-Agent (live) | `Brevo-webhook/2.0 (+https://developers.brevo.com/docs/how-to-use-webhooks)` |
| Custom headers | `X-Mailin-*` arrive as `HTTP_X_MAILIN_*` (only if configured under "Add object") |
| Latency | `request` + `delivered`/bounce within 2–4 s of the API send; `spam` ~10 s after the recipient reported junk |
| Replay | None — events emitted while no webhook existed are lost |

The webhook UI ("Plugins & Integrations → Webhooks → Outbound") has no header-name
field and no batched toggle; its per-event **Send test request** goes through a
different backend (`User-Agent: Brevo/1.0`, lowercase `bearer`, 2020-era sample
payloads with `template_id`/`X-Mailin-custom`). Use it only to check reachability,
never as a payload reference.

### Payload

Live `event` values are **snake_case** even though the subscription enum is camelCase
(`hardBounce`, `softBounce`, `invalid`, `proxyOpen`, `request`, `click`, …).

| Observed `event` | Trigger | `tags` | `reason` | Extra fields |
| --- | --- | --- | --- | --- |
| `request` | every accepted send ("Sent") | yes | `"sent"` | `mirror_link`, `sending_ip` |
| `delivered` | Gmail, Outlook.com | yes | `"sent"` | `sending_ip`, `uuid` |
| `hard_bounce` | Mailtrap `bounce+550+…` — real SMTP attempt | yes | `"550 no such user 1871b"` (remote SMTP text) | `sending_ip`, `uuid` |
| `soft_bounce` | Mailtrap `bounce+451+…` — real SMTP attempt | yes | `"451 mailbox full"` | `sending_ip`, `uuid` |
| `blocked` | second send to an address Brevo has already hard-bounced | yes | `"blocked : due to blacklist user"` | `uuid` (no `sending_ip`) |
| `spam` | Outlook.com "Report → Junk" (Microsoft JMRP) | yes | **absent** | `uuid` |
| `unique_opened` | recipient's client fetched the pixel | yes | absent | `user_agent`, `device_used`, `link`, `contact_id`; `sending_ip` holds the **opener's** IP |

Fields on every live event: `id` (the webhook id, not a message id), `email`,
`message-id` **including angle brackets** (`<2026…@smtp-relay.mailin.fr>` — exactly the
`messageId` the send API returned), `event`, `date` (local time, no zone), `ts`,
`ts_event`, `ts_epoch` (ms), `subject`, `sender_email`, `tags` (array) **and** `tag`
(the same list JSON-encoded as a string). Match on `message-id` verbatim; filter on
the `tags` array.

Brevo's Transactional → Logs export labels the same events `Sent`, `Delivered`,
`Hard bounce`, `Soft bounce`, `Blocked`, `Complaint` (= `spam`) and `First opening`
(= `unique_opened`); the export confirmed every webhook event above one-for-one,
including the first-send `Hard bounce` → second-send `Blocked` sequence.

Not observed live: `invalid_email`, `deferred`, `error`, `unsubscribed`, `click`,
`proxy_open`, `unique_proxy_open`, `opened`. Their names above come from the test
requests and the docs; treat them as unverified.

### Fixtures

- **Hard bounce:** `bounce+550+<free text>@inbox.mailtrap.io` — `inbox.mailtrap.io`
  publishes a public MX, so Brevo delivers and gets the emulated 550. The local part
  must be lower-case; the text after the code is free and is echoed in `reason`.
- **Soft bounce:** `bounce+451+mailbox+full@inbox.mailtrap.io`.
- **Suppression:** after one hard bounce Brevo suppresses the address; later sends to
  it emit `blocked` immediately, never a second `hard_bounce`. To observe
  `hard_bounce` again, vary the local part (`…+1871b@…`, `…+1871c@…`).
- **Suppression list is queryable and reversible:** `GET /v3/smtp/blockedContacts` lists
  every suppressed address with `blockedAt` and a `reason.code` (`hardBounce`,
  `contactFlaggedAsSpam`, `unsubscribedViaEmail`); `scripts/spike-1871/brevo-send-test.php
  --list-blocked` prints it. On 2026-09-03 the account held 9 entries dating back to
  2025-12 — historical bounce evidence that predates any webhook (see #1922). A spam
  report suppresses the complainant's address the same way a hard bounce does, but scoped
  to the sender identity (`From sender: registrar@elanregistry.org`), whereas a hard bounce
  blocks for **all** senders. The same list is visible in the UI under Transactional →
  Settings → Blocked contacts (exportable as CSV). Brevo
  documents `DELETE /v3/smtp/blockedContacts/{email}` and Transactional → Settings →
  Blocked contacts in the UI for releasing an address; neither was exercised in the spike.
- **Spam:** Mailtrap cannot emulate complaints and iCloud/Gmail have no feedback loop.
  A free Outlook.com mailbox works: move the message to Inbox, then **Report → Junk**;
  Brevo receives the JMRP report within seconds — and immediately suppresses that mailbox
  for all `registrar@` mail, so unblock it in Brevo (Blocked contacts) after the test or it
  stops receiving anything. Outlook.com also auto-junked the
  first message from `registrar@elanregistry.org` on arrival — a sender-reputation
  signal separate from the webhook question.
- **Opens:** Apple Mail on a Mac fetched the tracking pixel immediately on message
  arrival, producing `unique_opened` with the reader's home IP in `sending_ip`. Opens
  therefore measure client behaviour, not human attention; do not use them as a
  liveness signal.
- **Fixture account (test DB only, created 2026-09-03):** user id **93**, owner email
  `bounce+550+no+such+user+here@inbox.mailtrap.io` (already suppressed by Brevo → every send
  yields `blocked`), car id **15**, chassis `TEST-1871-BOUNCE`. Does not exist on prod.

### Design deltas carried to #1887

1. Event names: snake_case (`hard_bounce`, `soft_bounce`, `invalid_email`), not the
   camelCase subscription names.
2. Header: `Server::get('HTTP_AUTHORIZATION')` works as-is (string sanitisation only);
   no `.htaccess` change and no project-owned reader required.
3. Body: single object per request; `batched` is `false` and there is no UI to change it.
4. `blocked` is the steady-state event for a suppressed address; treat it as a
   confirmed bounce, not an error.
5. `spam` carries no `reason`; `tags` is present on every event, so tag filtering is
   safe for all of them.
6. `message-id` arrives with angle brackets — store the send API's `messageId` verbatim.

## Sender Reputation (#1922)

Investigated 2026-09-03 through 2026-09-14 after a transactional test message
from `registrar@elanregistry.org`, sent via Brevo's shared relay, was
auto-classified as Junk on arrival in a fresh Outlook.com mailbox — before any
recipient action. v2.30.x sends verification email to every owner in a
cohort, so a whole slice of Microsoft-domain recipients silently never seeing
the request (and their "no response" being read as evidence of anything)
motivated closing this out before the first live send.

### Authentication (SPF/DKIM/DMARC) — confirmed passing

**Root cause found and fixed:** the SPF record for `elanregistry.org` was
missing `include:spf.brevo.com`, so Brevo's shared sending IPs were not
authorized — the likely cause of the Outlook.com junk classification.

Fix applied directly in Cloudflare's dashboard:

```text
v=spf1 +ip4:106.0.62.78 +include:spf.a2hosting.com include:spf.brevo.com ~all
```

Confirmed live via `dig` against both 1.1.1.1 and 8.8.8.8 (2026-09-13).

**DKIM** was already correctly configured; confirmed working via the
selectors:

- `brevo1._domainkey.elanregistry.org` → `b1.elanregistry-org.dkim.brevo.com`
- `brevo2._domainkey.elanregistry.org` → `b2.elanregistry-org.dkim.brevo.com`

**DMARC** is `p=quarantine`, reporting to Cloudflare and Brevo. Cloudflare's
DMARC Management report (30-day window, 2026-09-13): 100% DMARC pass (12/12),
100% DKIM aligned, 0% SPF aligned. The 0% SPF alignment is expected on
Brevo's shared-IP plan — Brevo's envelope-from/Return-Path stays on their own
domain unless a dedicated IP ($251/yr) or their branded-subdomain option is
purchased — and does not block DMARC, since DKIM alignment alone satisfies
it. Not pursued further; the cost isn't justified by the marginal gain.

**BIMI** was investigated as a side item: the DNS prerequisites (DMARC
quarantine/reject) are already met, but displaying the logo in Gmail/Yahoo
requires a Verified Mark Certificate, which in turn requires a registered
trademark plus roughly $1,300–1,500/yr. Not pursued — there's no branding
goal that changes that cost/benefit call today.

**DNS caveat:** Terraform-managed DNS for this domain
(`~/Developer/Web/Cloudflare`) is abandoned and does not reflect current
records — the SPF fix above was applied directly in Cloudflare's dashboard.
Treat Cloudflare's dashboard as the sole source of truth for this domain's
DNS going forward; do not consult or trust the Terraform state/tfvars here.

### Microsoft SNDS / JMRP enrollment — not independently checkable

Brevo's shared-IP plan does not expose per-customer SNDS (Smart Network Data
Services) or JMRP (Junk Mail Reporting Program) enrollment status to
individual senders — Brevo manages IP reputation and the Microsoft
relationship at the platform level for everyone on the shared pool. There is
no dashboard or API on our side that would show this. The operative signal
for our own sending is Brevo's own domain/IP reputation status (Senders,
Domains & Dedicated IPs) plus the inbox-placement re-test below, not direct
SNDS/JMRP visibility.

### `admin@elanregistry.org` hard-bounce — historical, not a live reference

Brevo's suppression list (`GET /v3/smtp/blockedContacts`) includes a
`hardBounce` entry for `admin@elanregistry.org` dating back to 2025-12 (see
the Fixtures section above). A repo-wide search found no place where
`admin@elanregistry.org` is used as a live From/Reply-To sender identity —
the only occurrences are in unit test fixture data
(`tests/bootstrap-unit.php`, `tests/unit/system/LogCategoriesUsageTest.php`),
and the latter's own test explicitly guards against
`_email_template_verify_new.php` hardcoding this address as a sender, citing
a prior fix in #368. The actual sender identity in use today is
`registrar@elanregistry.org` (see `getAdminEmails()` /
`getFeedbackEmail()` in `usersc/includes/custom_functions.php`, and
`ADMIN_EMAILS`/`FEEDBACK_EMAIL` in `.env.example`). Conclusion: the 2025-12
bounce predates the #368 fix or was a one-off manual send outside the
codebase; there is no current code path to change.

### Verified outcome

A fresh Outlook.com/Hotmail/Live mailbox received a re-sent transactional
test message in the Inbox, not Junk, on 2026-09-14 — confirmed after the SPF
fix above. This was the hard gate for the v2.30.3 send pipeline and has
passed.

## Updating the Plugin

`scripts/check-plugin-updates/` runs weekly and opens a GitHub issue labeled
`plugin-update` when a newer version is published upstream. It only detects
drift — it does not apply the update. Plugin files under `usersc/plugins/sendinblue/`
are gitignored, so there is no git diff to review; the update is a manual
file-replacement performed once per environment (local dev, test, prod).

1. **Back up the current plugin directory** before updating:

   ```bash
   cp -r usersc/plugins/sendinblue usersc/plugins/sendinblue_backup_$(date +%Y%m%d)
   ```

   (`.gitignore` already excludes `sendinblue_backup_*/` directories.)

2. **Apply the update via Spice Shaker:** Admin → Spice Shaker → Installed
   Plugins → Update. Repeat on each environment being updated — updating one
   environment does not affect the others.

3. **Check for dependency changes:** diff `usersc/plugins/sendinblue/composer.json`
   and `composer.lock` against the backup. If they changed, run
   `composer install` inside `usersc/plugins/sendinblue/` to regenerate
   `vendor/`. Watch for a bumped `getbrevo/brevo-php` constraint that could
   conflict with PHP 8.2 or the root project's dependencies.

4. **Re-verify the `email()` override behavior** documented above still
   holds — in particular, that `reply_name` and `attachments` remain
   unsupported via the `email()` override (only available calling
   `sendinblue()` directly). Diff the new `override.php`/`functions.php`
   against the backup if anything seems off.

5. **Smoke test before promoting to the next environment:** Admin → Plugins
   → Brevo Sendinblue → Test Email, and a full password-reset flow (the
   most common `email()` override call path in the app).

## Troubleshooting

### Emails Not Sending

Check the UserSpice logs (category `sendinblue`) for the API error message returned by Brevo.

**Common causes:**

- **Invalid or expired API key:** Generate a new API key in Brevo (My Profile → Settings → SMTP & API) and update the plugin configuration.
- **Domain not verified:** Check Brevo's domain status
  (My Profile → Settings → Senders/Domains/IP → Domains).
  Click Check Configuration again. DNS propagation may take time.
- **Sender email not verified:** Ensure the sender email address matches a verified sender in Brevo (My Profile → Settings → Senders/Domains/IP → Senders).

### Brevo IP Whitelist

Brevo enables an IP whitelist by default. If API calls fail with authorization errors on a new server:

1. Log in to Brevo
2. Go to My Profile → Settings → Security
3. Check IP Security settings
4. Add the server IP to the whitelist or disable the whitelist

This is not required for the current elanregistry.org or test.elanregistry.org deployments.

### "Forgot Password" Link Hidden on Login Page

The plugin configuration page shows a warning and hides the forgot-password link when:

- The override is active (Brevo plugin is enabled), AND
- UserSpice still contains placeholder SMTP values

This is a safety indicator. Email delivery is not affected — the plugin routes all emails
through Brevo regardless. You can safely ignore this warning once Brevo is properly configured.

### Domain Verification Fails

After adding DNS records to your registrar:

1. Wait 5–15 minutes for DNS propagation
2. Return to Brevo and click Check Configuration again
3. If verification still fails, use a DNS lookup tool (e.g., `dig`, `nslookup`) to confirm
   the records are published:

   ```bash
   dig elanregistry.org TXT
   dig _dkim.elanregistry.org TXT
   ```

4. Verify the record values match exactly what Brevo expects

## Developer Reference

### EmailTemplate Class

The `EmailTemplate` class provides a consistent, branded HTML email wrapper for all transactional emails.
Use it to compose structured emails with detail rows, message blocks, action buttons, and responsive
layouts that render correctly in all email clients. The class handles all HTML escaping internally
according to per-method contracts documented below.

**Location:** `usersc/classes/EmailTemplate.php`

**Escaping Contract (per method):**

| Method | Escapes | Notes |
| --- | --- | --- |
| `render()` | Footer text only | `$subject` and `$subtitle` are escaped in the template; `$content` is trusted HTML |
| `createMessageBox()` | Title only | `$title` is escaped; `$content` is trusted HTML (pre-composed by caller) |
| `createDetailRow()` | Both `$label` and `$value` | Always escapes both parameters; `$highlighted` flag affects styling only |
| `createRawDetailRow()` | Label only | `$label` is escaped; `$trustedHtml` is NOT escaped (caller-trusted) |
| `createMessageContent()` | Text | Escapes `$text`; intended for raw user-supplied text inside message boxes |
| `createButton()` | Both `$text` and `$url` | Escapes both parameters before embedding in href and link text |
| `createButtonRow()` | All button labels and URLs | Escapes `label` and `url` in each button entry |

**Security:** Methods with "Raw" in the name (`createRawDetailRow()`) bypass escaping for their value
parameter by design. Use these only for trusted content you control (composed HTML, internal links,
image tags). Never pass raw user input into a `$trustedHtml` or `$content` parameter—the caller is
entirely responsible for escaping user-supplied data before inclusion.

**Example:** A composed transfer notification email.

```php
$et = new EmailTemplate();

// Car details (safe, escaped via createDetailRow)
$carInfo = $et->createDetailRow('Year', $car->year) .
           $et->createDetailRow('Chassis', $car->chassis, true);  // highlighted

// Transfer request comments (safe, escaped via createMessageContent)
$comments = $et->createMessageContent($userInput->comments);

// Action buttons side by side
$actions = $et->createButtonRow([
    ['label' => 'Approve Transfer', 'url' => $approveUrl, 'style' => 'success'],
    ['label' => 'Deny Request', 'url' => $denyUrl, 'style' => 'danger'],
]);

// Composed HTML with message boxes
$content = $et->createMessageBox('Car Information', $carInfo) .
           $et->createMessageBox('Requester Comments', $comments) .
           $et->createRawDetailRow('View Online', '<a href="' . htmlspecialchars($linkUrl) . '">View in Registry</a>') .
           $actions;

// Render complete email
$html = $et->render('Transfer Request', 'New ownership request pending review', $content);
email($adminEmail, 'New Transfer Request', $html);
```

### sendinblue() Function

The plugin provides the `sendinblue()` function, which is called by the overridden `email()` function.

**Signature:**

```php
// Effective signature (implementation in usersc/plugins/sendinblue/functions.php lacks type hints)
sendinblue($to, $subject, $body, $to_name = "", $options = []): bool
```

**Parameters:**

| Parameter | Type | Description |
| --- | --- | --- |
| `$to` | string | Recipient email address (required) |
| `$subject` | string | Email subject line (required) |
| `$body` | string | HTML email body (required) |
| `$to_name` | string | Recipient display name (optional) |
| `$options` | array | Per-send overrides and attachments (optional) |

**Returns:** `true` on success, `false` on any failure (API error, missing required fields, invalid email address, etc.).

### $options Array Keys

These keys apply when calling `sendinblue()` directly. See [Calling via
email()](#calling-via-email) below for the different key names used through
the override.

| Key | Type | Description |
| --- | --- | --- |
| `from` | string | Override sender email address |
| `from_name` | string | Override sender display name |
| `reply` | string | Override reply-to email address |
| `reply_name` | string | Override reply-to display name (only honoured when calling `sendinblue()` directly — not forwarded by the `email()` override) |
| `template` | int | Brevo template ID for templated emails |
| `params` | array | Template variable substitutions (key => value pairs) |
| `attachments` | array | Array of `['content' => base64string, 'name' => 'filename.pdf']` |

### Recommended Calling Pattern

Always check the return value and log failures:

```php
$result = email($to, $subject, $body);
if ($result !== true) {
    $safeLog = preg_replace('/[\r\n\t]/', '', $to);
    logger($user->data()->id, LogCategories::LOG_CATEGORY_EMAIL_ERROR,
        "Email SEND FAILED to {$safeLog}");
}
```

### Calling via email()

Most application code calls `email()` rather than `sendinblue()` directly. The `override.php`
shim translates UserSpice's `email()` option keys to Brevo option keys:

| `email()` option key | Maps to `sendinblue()` key |
| --- | --- |
| `email` | `from` |
| `name` | `from_name` |
| `replyTo` | `reply` |

> **Note:** `reply_name` is not forwarded by the override. Pass it only when calling `sendinblue()` directly.

Example using `email()`:

```php
$opts = [
    'email'   => 'registrar@elanregistry.org',
    'name'    => 'Registry Registrar',
    'replyTo' => 'support@elanregistry.org',
];
email($to, $subject, $body, $opts);
```

### Overriding Sender or Reply-To (sendinblue() directly)

When calling `sendinblue()` directly, use its native keys:

```php
$options = [
    'from'       => 'registrar@elanregistry.org',
    'from_name'  => 'Registry Registrar',
    'reply'      => 'support@elanregistry.org',
    'reply_name' => 'Registry Support',
];
sendinblue($to, $subject, $body, '', $options);
```

### Sending Templated Emails

If you have a Brevo template configured, use the `template` and `params` keys. `sendinblue()`
always requires a non-empty `$body` regardless of whether a template is used (a legacy
unconditional guard) — pass a placeholder string when the template supplies all content:

```php
$options = [
    'template' => 42,  // Brevo template ID
    'params'   => [
        'car_year'   => 1973,
        'car_model'  => 'Lotus Elan S4',
        'owner_name' => 'John Doe',
    ],
];

sendinblue('owner@example.com', 'Your Car Registration', '(template)', '', $options);
```

### Sending Attachments

The `email()` override does not forward the `attachments` key — use `sendinblue()` directly:

```php
$attachmentContent = base64_encode(file_get_contents('/path/to/receipt.pdf'));

$options = [
    'attachments' => [
        [
            'content' => $attachmentContent,
            'name'    => 'receipt.pdf',
        ],
    ],
];

sendinblue($to, 'Your Receipt', $body, '', $options);
```

### Admin Email Recipients

Registry notification emails are sent to one or more admin addresses configured in the database.
Use `getAdminEmails()` from `usersc/includes/custom_functions.php` to retrieve them:

```php
$adminEmails = array_map('trim', explode(',', getAdminEmails()));
foreach ($adminEmails as $adminEmail) {
    $result = email($adminEmail, $subject, $body);
    if ($result !== true) {
        logger(0, LogCategories::LOG_CATEGORY_EMAIL_ERROR, "Admin alert failed to: {$adminEmail}");
    }
}
```

Admin addresses are managed at Admin → Settings → Admin Emails. The default is `registrar@elanregistry.org`.

## Related Documentation

- [CLASSES.md](CLASSES.md) — EmailTemplate class for branded HTML email wrappers
- [Email Colors (design-system.php)](../../app/admin/design-system.php) — Email token → hex mapping and template structure (admin only)
- [ADR-012: Adopt Brevo for Transactional Email Delivery](adr/ADR-012-adopt-brevo-for-transactional-email-delivery.md) — Architecture decision record
- [DATABASE.md](DATABASE.md) — Database schema reference (includes `plg_sendinblue` table)
