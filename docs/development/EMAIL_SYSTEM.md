# Email System

The Lotus Elan Registry uses Brevo (formerly Sendinblue) as its transactional email service
for production and staging environments. This document covers account setup, configuration,
troubleshooting, and the developer API.

## Overview

Brevo provides reliable email delivery via HTTP API. We chose Brevo because A2 Hosting blocks outbound SMTP ports; Brevo uses port 443 (HTTPS) which is always open.

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

Use Mailtrap to capture all email for debugging and development:

1. Create a Mailtrap account at [mailtrap.io](https://mailtrap.io)
2. Get your Mailtrap SMTP credentials from the project inbox settings
3. In the registry admin panel, go to Admin → Plugins
4. Deactivate the Brevo plugin
5. Go to Admin → Settings → Email and update the SMTP settings with your Mailtrap credentials
6. All emails sent locally will be captured in Mailtrap's inbox for inspection

To switch back to Brevo for production testing: re-enter the Brevo API key in the plugin configuration and reactivate the override.

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
- It does **not** validate the API key by calling Brevo, since that would add latency to every status check and introduce a hard dependency on external availability
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
roles (read-only for editor, toggle control for admin only).

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

### Preview & Send Flow

**GET request (no side effects):** Renders a table of eligible cars, up to the
configured batch size (`VerificationSettings::batchSize()`, default 5), in the
Verification tab's "Send Verification Emails" card section. Car data shown: ID,
chassis, owner name, email address. Every dynamic value is HTML-escaped at render time.

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
They are owner-level, not car-level.

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
- `sendBatch(array $cars): array` — map `sendOne()` over multiple cars

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
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING` for logging failures, `LOG_CATEGORY_EMAIL_WEBHOOK` for webhook event processing
- [CLASSES.md](CLASSES.md) — `VerificationSettings` and `VerificationConfigException` class reference

## Composing and Sending Verification Emails (#1882, #1883)

The periodic verification email that requests owners confirm their car records are current is built by `CarVerificationEmailComposer`
and includes a one-click opt-out link that lets owners suppress all future verification mail via a single vericode-authenticated
action. The composer is built and unit-tested but not yet wired to any send path — that wiring is future issue #1884's responsibility.

### Verification Email Composer

**Location:** `usersc/classes/Car/CarVerificationEmailComposer.php`

The `CarVerificationEmailComposer` class has one public method, `compose(object $carData, object $owner, string $vericode): array{subject,
html}`, which builds the subject line and full branded HTML body. The class intentionally performs **no database access** — everything
it renders comes from the `$carData` (car row) and `$owner` (owner row) objects supplied by the caller. This design keeps the composer
testable with fixture objects alone, no framework bootstrap or database required.

The composed email includes:

- **Greeting and explanation** — why the owner is receiving this request
- **Verify/Sold side-by-side buttons** — confirm ownership or report the car sold
- **Owner Information box** — ID, name, email, location, join date
- **Car Information box** — ID, year, type, chassis, series, variant, color, purchase/sale dates, photo count, and website
- **Conditional "About the Chassis Number" alert** — appears only when `cars.chassis_override = 1`, explaining that the chassis was
  manually entered and may differ from factory records
- **Conditional blank-field callout** — appears when any of Color, Variant, Purchase Date, or Website is blank, naming every blank
  field and highlighting its row, and encouraging the owner to fill them in
- **Edit button** — links to the full `app/owner/cars/edit.php` form (requires login)
- **Footer block** — opt-out link (see below) and 60-day expiry notice

**Public Constants:**

- `LINK_TTL_DAYS = 60` — Lifetime of the Verify/Sold/Opt-Out links. Must equal `VERIFY_LINK_TTL_DAYS` in `app/verify/verify_car.php`;
  a unit test guards against drift since the composer's expiry notice text promises this window
- `NO_TRACK_LINK_CLASS = 'er-no-track'` — CSS class marking the opt-out (and ideally Verify/Sold) links for click-tracking exclusion

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

### Click-Tracking Exclusion Note

The Opt-Out link carries the CSS class `NO_TRACK_LINK_CLASS = 'er-no-track'`, originally intended to mark it for Brevo
click-tracking exclusion. **No such exclusion is possible** — confirmed during #2147's investigation, Brevo has no
per-link tracking-exclusion mechanism for transactional email (no CSS class, tag, or API parameter accomplishes this).
This is not a "not yet built" gap; it cannot be built against Brevo as-is. The class is currently inert and every link
in a Registry transactional email, including this one, is rewritten through Brevo's tracking redirect domain — see the
broader consequence (a raw tracking URL displayed as body text in several templates) and its resolution in issue #2147.

**Template-variable workaround: tested, does not work.** Some Brevo users report that supplying the URL via a
template variable (`href="{{ params.link }}"` instead of a literal `href="https://..."`) sometimes escapes
rewriting. Tested 2026-09-22 via a direct `POST /v3/smtp/email` send (`params: {link: <url>}`, `htmlContent`
containing both a literal-href control link and an `href="{{ params.link }}"` link) from test.elanregistry.org to a
real Gmail-hosted inbox. Result: **both links were rewritten** to Brevo's click-tracking redirect domain
(`*.r.af.d.sendibt2.com/tr/cl/...`) — no difference in behavior between the literal and template-variable forms on
this account/plan. Confirms the exclusion is not achievable via this route either; do not attempt it again without a
new reason to expect different behavior (e.g. a plan/setting change on Brevo's side).

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

These keys apply when calling `sendinblue()` directly. See [Calling via email()](#calling-via-email) below for the different key names used through the override.

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
