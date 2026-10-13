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

**Plugin version:** The registry uses the upstream plugin unmodified. It needs
version 1.8.0 or later. Git tracks no file under `usersc/plugins/sendinblue/`
(the whole plugin is gitignored), and the project has no fork of the plugin. Install and
update the plugin with the plugin manager. Deploy steps are in
[DEPLOYMENT.md](DEPLOYMENT.md#brevo-sendinblue-plugin).

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
9. Set Tag untagged mail to "Calling file name". See
   [Mail Tags](#mail-tags).
10. Click Activate Override to route all `email()` calls through Brevo
    (`override.php` equals `override.RENAME.php`)
11. Click Test Email to send a test message to your email address and verify end-to-end delivery

## Mail Tags

Brevo tags let the registry tell verification mail from all other mail. The
webhook receiver and the reconciliation job keep only events that carry the tag
`car_verification` (`AppConstants::VERIFICATION_EMAIL_TAG`).

### The `tags` option

The `email()` override in plugin 1.8.0 and later accepts `$opts['tags']`. The
value is a string or an array of strings. A tag that the caller passes always
wins.

```php
// Explicit tag: used for verification mail
email($to, $subject, $body, ['tags' => AppConstants::VERIFICATION_EMAIL_TAG]);
```

### The "Tag untagged mail" setting

The plugin setting "Tag untagged mail" (`plg_sendinblue.tag_mode`) tags mail
that has no explicit tags.

| Value | Option label | Effect |
| --- | --- | --- |
| `off` | Off (no tag) | Default. Untagged mail has no tag. |
| `fixed` | Fixed tag | Every untagged message gets the text in `tag_fixed`. |
| `caller` | Calling file name | The tag is the base name of the file that called `email()`, without `.php`. |

The registry uses `caller`. The plugin function `sendinblueTagFromFile()`
(`usersc/plugins/sendinblue/functions.php`) builds the tag. It removes every
character outside `A-Za-z0-9_.-` and cuts the result to 64 characters. The
setting is database state. Set it once for each environment. Later deploys keep
it. Without it, all mail except verification mail has no tag.

### Tags in `caller` mode

| Mail | Calling file | Tag |
| --- | --- | --- |
| Car verification | `usersc/classes/Car/CarVerificationSendService.php` | `car_verification` (explicit) |
| Feedback form | `app/api/contact/send-feedback.php` | `send-feedback` |
| Owner contact form | `app/api/contact/send-owner-email.php` | `send-owner-email` |
| Admin contact | `app/admin/includes/process-admin-contact.php` | `process-admin-contact` |
| Registration recovery | `usersc/classes/RegistrationRecoveryNotifier.php` | `RegistrationRecoveryNotifier` |
| Join | `usersc/join.php` | `join` |
| Account settings | `usersc/user_settings.php` | `user_settings` |
| Password reset | `users/forgot_password.php` | `forgot_password` |
| Passwordless login | `users/passwordless.php` | `passwordless` |
| Re-authentication | `users/reauth.php` | `reauth` |
| Resend verification link | `users/verify_resend.php` | `verify_resend` |
| Admin user manager | `users/views/_admin_user.php`, `users/views/_admin_users.php` | `_admin_user`, `_admin_users` |
| Admin test email | `users/views/_admin_email_test.php` | `_admin_email_test` |

Core `users/join.php` and `users/user_settings.php` also call `email()`. Their
tags are `join` and `user_settings`. To find new callers, run
`grep -rn "email(" app usersc users --include='*.php'`.

The webhook and reconciliation tag filters did not change. Every mail now
carries a tag, and only `car_verification` is kept. The nightly reconciliation
job therefore reports a higher `ignoredByTag` count than before.

## Environment Setup

### Production and Staging

Both `elanregistry.org` and `test.elanregistry.org` use the same Brevo account and API key. No environment-specific configuration is needed.

### Local Development

Two options are available, depending on your development need. The plugin
state picks the path. With the Brevo plugin and its override active, `email()`
goes to mock-brevo (Option B). With the plugin deactivated, UserSpice's
PHPMailer `email()` sends the mail (Option A). In Option A, the Verification
tab reports Brevo not ready, so verification cannot be turned on or send.

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

**What routes to mock-brevo:**

With the prerequisites below, all Brevo traffic from the app goes to
mock-brevo:

- The plugin's sends from `email()` (Test Email, password reset, and so on)
- The plugin's delivery-status checks
- `usersc/classes/Cron/BrevoEventReconciliationClient.php`
- `usersc/classes/Cron/BrevoSuppressionSyncClient.php`

The two cron clients call `BrevoDevOverride::hostOverride()` before they build
their API client. The plugin does not know `BREVO_API_HOST`. Instead,
`BrevoDevOverride::applyToSdk()` sets the host on the Brevo SDK's shared default
`Configuration` object. The plugin gets the same object and only sets the API key
on it, so the mock host stays. `usersc/includes/rate_limits_dev_override.php`
makes this call. `usersc/includes/loader.php` loads that file on each request.

`applyToSdk()` does nothing when `US_ENVIRONMENT` is not `development`, when
`BREVO_API_HOST` is empty, or when the plugin's `vendor/` directory is absent.
If it fails, it logs an `EmailError` message.

**Warning:** Without `BREVO_API_HOST` in `.env`, local mail goes to the REAL
Brevo with the API key saved in the plugin. Use a dummy key locally.

**Prerequisites:**

1. `US_ENVIRONMENT=development` must be set in `.env`. `BrevoDevOverride`
   checks it before it applies `BREVO_API_HOST`.
2. `BREVO_API_HOST=http://mock-brevo:8080/v3` must be set in `.env`
   (see `.env.example`).
3. The sendinblue plugin 1.8.0 or later is installed and its override is
   active. Save a dummy API key. See
   [ENVIRONMENT.md](ENVIRONMENT.md#brevo-plugin-local-setup).

**Setup:**

The mock-brevo service is always included in the Docker Compose stack
(`docker-compose.yml`). When you run `docker compose up`, it starts
automatically alongside the `app`, `db`, `phpmyadmin` and `landing` services. See
`docs/development/ENVIRONMENT.md`'s "Docker Dev Environment" section for the
full Docker setup.

To send a test email from the app, use Admin → Plugins → Brevo Sendinblue →
Test Email. The message shows in the mock-brevo inbox.

To send a test email straight to mock-brevo, bypassing the app's send path:

```bash
docker compose exec -T -u www-data app curl -s -X POST http://mock-brevo:8080/v3/smtp/email \
  -H 'api-key: local-test' \
  -H 'Content-Type: application/json' \
  -d '{"sender":{"email":"test@example.com"},"to":[{"email":"owner@example.com"}],"subject":"mock-brevo test","htmlContent":"<p>hello</p>"}'
```

A response with a `messageId` means mock-brevo accepted the message. It then
shows in the web inbox.

To check what the app, the reconciliation client, or the suppression-sync
client sent or received, read the logs:

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
- `brevoNotReadyReason(): ?string` — computed live; returns `null` when Brevo
  is ready, or a message that says why it is not. The admin Verification tab
  and the 422 response of the enable toggle show this message.
- `brevoReady(): bool` — computed live; true only if `brevoNotReadyReason()`
  returns `null`. All of these conditions must hold:
  1. `plg_sendinblue` is readable
  2. `plg_sendinblue.key` is non-empty (API key configured)
  3. `usersc/plugins/plugins.ini.php` has `sendinblue = 1` (plugin active).
     UserSpice loads the override only for an active plugin. Otherwise core
     PHPMailer sends the mail without the tag.
  4. `usersc/plugins/sendinblue/override.php` exists (plugin override active)
  5. `usersc/plugins/sendinblue/info.xml` shows version 1.8.0 or later.
     Older versions drop the `tags` option, so the verification tag never
     reaches Brevo.
  6. `override.php` has the same SHA-256 hash as the bundled
     `override.RENAME.php`. A plugin update does not change `override.php`,
     so an old override can stay in place and drop the tag. After each update,
     open "Show file contents" under "Override Update Available" on the
     plugin page. Then click "Replace my override.php with the bundled
     version".
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

- `brevoReady()` checks only that the plugin's configuration exists, the
  plugin is active, the override file is in place and matches the bundled
  copy, and the plugin version is 1.8.0 or later
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
    // user-facing message in the response, for example "Verification cannot
    // be enabled. No API key is saved in the Brevo plugin."
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

- `brevoReady()` (row "Brevo ready") — badge `text-bg-success` ("Ready") if
  true. If false while the switch is on: badge `text-bg-danger` ("Not ready")
  with the text of `brevoNotReadyReason()`. If the probe itself threw, the text
  is "The Brevo status could not be checked." plus a hint to check the system
  log for `VerificationConfigWarning`. Muted text if the switch is off
- `cronReady()` — badge `text-bg-warning` if false; muted if true
- Last webhook received — muted "Not yet implemented (#1887)" (placeholder for the real webhook implementation)
- Last reconciliation run — muted "Not yet implemented (#1889)"
- Unmatched recipients — badge `text-bg-warning` if the counter is above 0,
  `text-bg-success` if 0, `text-bg-danger` "Unavailable" if the read itself
  failed; counts Brevo signals (webhook events, reconciliation events,
  suppression-list contacts) whose recipient matched no owner's
  `users.email` (#2085, #2356)

**Toggle Control:**

- Located on the Verification System tab
- Disabled (with explanatory text) when current user is not admin, or when admin but attempting to enable while `brevoReady()` is false
- Label always explains which prerequisite failed if the enable direction is blocked (for Brevo, the `brevoNotReadyReason()` text)
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
Eligible, Pending, Bounced, or Suppressed. It also counts the unsold cars of a
live owner (not `noowner`) who has no `profiles` row, so the admin can see
them as "No profile". It excludes Verified and Sold: those two are history,
not a current queue state. Bounced and Suppressed can overlap on one car, so
`All` can be less than the sum of the four pills.

**Where the pills read their state.** The owner's `profiles` row is the
system of record (#2101). Bounced counts cars whose owner has
`email_bounced = 1`. Suppressed counts cars whose owner has `email_blocked = 1`
or `email_opted_out = 1`. Until #2361 separates them, one pill holds both. An
owner with no `profiles` row gets no verification email, so the car is never
Eligible.
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
- The owner's profile has no flag (`email_bounced`, `email_blocked`,
  `email_opted_out`). A car whose owner has a flag shows under its own pill
  instead. A car whose owner has no `profiles` row can still be Pending.

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

**Profile states in the chip.** The owner's profile state comes before the
latest event, because it stops all verification email to the owner. The
checks run in this order, and the first match wins (`vsStatusChip()`):

1. The owner has no `profiles` row: "No profile". The row has no action button.
2. `email_blocked = 1`: "Brevo complaint". The reason text is
   `email_blocked_cause` (`spam`, `unsubscribed`, `blocklist`), or
   "cause unknown" when the cause is NULL. Until #2361 separates the states,
   every cause shows this label.
3. `email_opted_out = 1`: "Opted out".
4. Otherwise the chip shows the latest event, as the rules above select it.

The tab does not read suppression events or `cars_hist` to find the cause.
The cause is the stored `email_blocked_cause`.

**Why the filter pill and the status chip can disagree.** The pill is based
on the car's current state. The chip is based on the latest email event.
These two sources do not always match. For example, a car with one soft
bounce:

- The chip shows **Soft bounce**.
- The car is still under the **Pending** pill. A soft bounce is not final.
  Brevo escalates repeated soft bounces. The
  owner's `email_bounced` flag stays 0, and the Pending view includes only cars
  whose owner has no flag.

The car moves to the **Bounced** pill only when the owner's `email_bounced`
flag becomes 1. This is not a fault. Use the pill to find the car. Use the chip
to see what happened to the last email.

A second case: after an admin clicks **Clear Bounced**, the car's Status
chip can still show **Bounced**. Clear Bounced clears `profiles.email_bounced`
and writes a `logs` audit row. It does not change or delete the car's
`er_email_events` rows. The hard-bounce event stays the latest terminal
event in the car's open send cycle, so the chip keeps showing Bounced until
a new verification email starts a new send cycle. The pill and the action
button react at once: the car leaves the Bounced pill, and its row offers
**Mark Bounced** again, because both read the profile `email_bounced` flag, not the
event history.

**Contextual action per row.** Each row has one action button. It replaces
the three buttons per row in the earlier tool. The car's state selects the
button, in this order:

| Car state | Button | POST `command` |
| --- | --- | --- |
| Suppressed (owner has `email_blocked` or `email_opted_out`) | Clear Suppression | `clear_suppression` |
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
whose owner could not be loaded, has no `profiles` row, or is the `noowner`
system account (the GDPR-erasure reassignment target), also shows a dash: the three actions all
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

**Critical semantics:** These actions are owner-level, not car-level. Each
one writes the owner's one `profiles` row. Nothing is written to `cars`, and no
action fans out to cars (#2101). The queue shows one of the three buttons for
each car row. See [Verification Queue](#verification-queue).

Each action runs in one transaction. It locks the `profiles` row
(`SELECT ... FOR UPDATE`) before it writes. An owner with no `profiles` row
gets an error and nothing is written. Each flag change writes one `logs` row
(see [Flag-change audit rows](#flag-change-audit-rows)).

**Mark Bounced** (`mark_bounced` POST):

- Records the owner's **current `users.email`** (not the car's denormalized
  `cars.email`) in `profiles.email_bounced_address`, with `email_bounced = 1`
  and `email_bounced_at` set to now, via
  `CarVerificationManager::setBouncedForOwner()`. The address is stored in
  lower case.
- A repeat on the same address changes nothing. A bounce on another address
  is rewritten with the new address and time.
- **Never reassigns car ownership** — a defect in the original, deleted tool that
  this rebuild fixes. All cars remain owned by their original owner.
- Writes one `logs` row with source `admin`. The flash message says "marked as
  bounced" when the flag changed and "already marked as bounced" when it did not.

**Clear Bounced** (`clear_bounced` POST):

- Clears `profiles.email_bounced`, `email_bounced_address` and
  `email_bounced_at` via `CarVerificationManager::clearBouncedForOwner()`
- Sets `email_bounce_cleared_at` to now. Soft bounces before this time do not
  count toward a new escalation, so one new soft bounce after a clear does not
  escalate.
- Idempotent: calling it twice on the same owner has no effect the second time
- Writes one `logs` row with source `admin`

**Clear Suppression** (`clear_suppression` POST):

- Distinct from Clear Bounced. Until #2357 adds separate actions, it clears
  both `email_blocked` (with its address, time and cause) and
  `email_opted_out` (with its time) on the owner's profile, via
  `CarVerificationManager::clearSuppressedForOwner()`. The bounce stays.
- Writes one `logs` row for each flag that changed, with source `admin`

These three actions are POST-only, check the CSRF token first, and need the
`admin` role. An editor's attempt is refused and logged. On both success and
error, the result is converted to a UserSpice session flash message
(`usError()`/`usSuccess()`) and the page renders normally (standard
POST-then-render pattern, no redirect). On success, the flash message names the
owner.

#### Flag-change audit rows

`profiles` has no history table. The `cars_hist` operations `EMAIL SUPPRESSED`,
`EMAIL BOUNCED`, `EMAIL BOUNCE CLEARED`, `EMAIL SUPPRESSION CLEARED` and
`SUPPRESSION CLEARED BY OWNER` are no longer written. Old rows remain. The audit
trail for a flag change is one `logs` row, which
`DeliverabilityFlagLog::record()` writes (see [CLASSES.md](CLASSES.md)). The row
has:

- `logtype`: `LogCategories::LOG_CATEGORY_EMAIL_BOUNCED`
- `user_id`: the acting user, or 0 for a delivery event, a cron job or an import
- `logs.metadata` JSON: `owner_id` (integer), `flag` (`email_opted_out`,
  `email_blocked` or `email_bounced`), `old`, `new` and `source`
- `lognote`: the same values as text

`source` is one of `owner`, `admin`, `webhook`, `reconciliation`, `import` or
`email_change`. The row never holds an email address. `record()` writes nothing
when `old` equals `new`. Clearing the UserSpice logs removes this trail.

### Resume Verification Emails (Owner Self-Service)

The Account Settings page shows a section (anchor id `resume-emails`) when
the owner's profile has `email_opted_out = 1` or `email_blocked = 1`. Both flags
pause verification email for every car the owner has, including a car added
after the flag was set.

- `email_opted_out`: the owner chose to stop email. The Resume button clears it.
- `email_blocked`: Brevo flagged the address (spam complaint, unsubscribe or
  blocklist). The owner cannot clear it. Below the button, the section tells the
  owner to contact the registry. With only `email_blocked` set, the button is
  not shown.

The section displays "Verification emails are currently paused for {N} of your
cars." N is the count of the owner's cars. If the count query fails, the section
stays and shows generic text. The `findVerificationEligible()` query excludes a
car when any of the three flags is 1, or when the owner has no `profiles` row.

The button POSTs with CSRF. The handler clears `profiles.email_opted_out` and
`email_opted_out_at` in one transaction, via
`CarVerificationManager::clearSuppressedForOwnerByOwner()`. It writes one `logs`
row with source `owner`. It writes nothing to `cars` and does not touch
`email_blocked` or `email_bounced`. Resumed cars go back into the normal cron
schedule. There is no immediate send.

The owner can clear only an opt-out. The block and the bounce stay, so
verification still excludes an owner who has either flag. The result messages are:

- The owner has no block and no bounce: a success message.
- The opt-out changed, and the owner has a block: a warning (`usWarning()`)
  that says the opt-out is removed, emails stay paused because the provider
  flagged the address, and the owner must contact the registry.
- The opt-out did not change, and the owner has a block: an error that gives
  the same reason and next step.
- The owner has a bounce and no block: the same two messages, with the reason
  "email to your address bounced" and the next step "Update your email address".

A block wins over a bounce, because only the registry can clear a block.
`usWarning()` queues a UserSpice generic message, which the page shows as the
neutral (dark) toast.

The manager locks the profile row, so a double-submit is safe: the second
request waits for the first to commit, reads 0, and succeeds. If a step after
the commit fails (flash message or redirect), the owner sees that the request
was saved, not "Nothing was changed".

### Email-Paused Notice on the Account Page (#1899, #2356)

The account page (`usersc/account.php`) shows one notice when email to the owner is blocked,
bounced or opted out. The notice is about the owner, not about a car. An owner
with no car sees it too (#2319). It links to the fix, so the owner does not
need to know where to look.

**Builder:** `EmailNoticeBuilder::buildForOwner(int $ownerId): ?array`
(`usersc/classes/Car/EmailNoticeBuilder.php`). It calls only
`CarRepository::effectiveEmailFlags()`, so a flag for an old address does not
show (see [Address-Match Rule](#address-match-rule-2356)). It returns `null`
when no flag applies. The array holds `kind`, `address`, `date`, `cause`,
`alsoOptedOut`, `optedOutDate` and `contentHash`.

**States.** One state shows. The builder picks it in this order:

| State | `kind` | When | Address and date |
| --- | --- | --- | --- |
| Blocked | `blocked` | `email_blocked` applies | `email_blocked_address`, `email_blocked_at` |
| Bounced | `bounced` | `email_bounced` applies and no block applies | `email_bounced_address`, `email_bounced_at` |
| Opted out | `opted_out` | `email_opted_out` is set and no block or bounce applies | no address, `email_opted_out_at` |

- A block wins over a bounce, because only the registry can clear a block.
- With a block or a bounce, `alsoOptedOut` is true when the owner also opted out.
- If a block or bounce has no stored address, `address` is the owner's current
  `users.email`. That flag applies to the current address.
- A NULL `_at` column gives a null date. The notice then shows no date.
- `cause` is one of `spam`, `unsubscribed` or `blocklist`. Any other stored
  value, or none, gives no cause (#2318).

**Copy.** The partial `app/views/_email_paused_notice.php` shows this text. It
never shows the word "Brevo".

| State | Message |
| --- | --- |
| Blocked, cause `spam` | "Email to `<address>` is blocked because a message to this address was reported as spam." |
| Blocked, cause `unsubscribed` | "Email to `<address>` is blocked because this address unsubscribed from registry email." |
| Blocked, cause `blocklist` | "Email to `<address>` is blocked because an administrator blocked this address." |
| Blocked, no cause | "Email to `<address>` is blocked." |
| Bounced | "Email to `<address>` bounced on `<date>`." The text "on `<date>`" is absent when the date is null. |
| Opted out | "You opted out of registry email on `<date>`." The text "on `<date>`" is absent when the date is null. |

- A blocked or bounced notice adds "Change your email address or contact an
  admin." It has two links: **Change email** (`usersc/user_settings.php#account-email`)
  and **Contact an admin** (`app/owner/contact/index.php`).
- When `alsoOptedOut` is true, the notice adds "You also opted out of registry
  email." and the **Resume** link.
- The opted-out notice has the **Resume** link (`usersc/user_settings.php#resume-emails`).
- The partial shows the opt-out date only in the opted-out state. In a blocked
  or bounced notice, it shows no opt-out date.
- The partial escapes both addresses with `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.

Dismissing the notice stores a key in `sessionStorage`. The key includes
`contentHash`. The notice stays hidden for that browser tab until the state
changes or the session ends.

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
- `sendOne(object $carData): SendResult` — send one car's email, return sent/failed.
  It mails the owner's `users.email`. `cars.email` is only a copy, and the
  send does not read it. An empty `users.email` fails the send ("Owner has
  no email address."). It writes the mailed address to the `sent` event row
  and to `$carData->email`, so the admin report shows the real recipient.
  It passes `['tags' => AppConstants::VERIFICATION_EMAIL_TAG]` to `email()`.
  It does not send when `VerificationSettings::brevoReady()` is false. It
  returns a failed `SendResult`, and the vericode stays unrotated.

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

**Matching:** the payload's `email` is looked up against the owner's
`users.email` (`CarRepository::findByEmail()`, #2356). The send mails that
address, so the match uses it too. `cars.email` is only a copy, and a stale
copy does not match. Both sides are normalized (trim, then lower case), and
the `noowner` account never matches. Every car of each matching owner gets
its own `er_email_events` row and its own escalation. The reconciliation job
and the suppression sync use the same lookup.

An event for mail sent before this rule, to an old `cars.email` that differs
from the owner's `users.email`, matches no owner. It counts as an unmatched
recipient.

**Escalation rules** (`BrevoWebhookEventProcessor`):

| Brevo event | Effect |
| --- | --- |
| `hard_bounce`, `blocked`, `invalid` | Immediately flags the owner of the car as bounced (`profiles.email_bounced = 1`, with `email_bounced_address` set to the reported address and `email_bounced_at` set to the event time) |
| `soft_bounce` | Event row only, unless this is the 3rd or later distinct send cycle (distinct `brevo_message_id`) since the email's last `delivered` event — then escalates via the same bounced flag. Soft bounces before the owner's `email_bounce_cleared_at` do not count |
| `delivered` | Event row only. Implicitly resets the soft-bounce escalation window (the count query only looks at soft bounces after the most recent `delivered` row) |
| `unique_opened` | Event row only, never touches flags or the escalation count |
| `spam` | Flags the owner of the car as blocked (`profiles.email_blocked = 1`, cause `spam`, with the address and event time) — a distinct signal from a bounce |
| `unsubscribed` | The same as `spam`, with cause `unsubscribed`. This event comes from the suppression import |

The flags belong to the owner, so one event flags the owner for every car. The
applier passes the source (`webhook`, `reconciliation` or `import`) to the
audit row. An event for a car with no owner, a deleted owner, the `noowner`
account, or an owner with no `profiles` row writes the event row and one log
entry, and no flag. The webhook does not throw in this case, because Brevo does
not retry a 2xx.

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
durable per-car event log. The current-state flags it drives are on the
owner's `profiles` row: `email_bounced`, `email_blocked` and their address,
time and cause columns.

**Out of scope for #1887** (see that issue's non-goals): no outbound Brevo
API calls of any kind (webhook *registration* is #1888, blocked-contacts
*import* is #1923, nightly *reconciliation* is #1889), and no admin UI
rendering of the per-car event history. Admin UI rendering of the
unmatched-recipient counter shipped in #2085 — see the Status Indicators
list above.

### Address-Match Rule (#2356)

A block or a bounce is tied to the address that caused it. The stored flag
applies only while that address is the owner's current address. A block or
a bounce with no stored address applies (fail closed):

| Stored `_address` | Result for `email_blocked` and `email_bounced` |
| --- | --- |
| Equals the current `users.email` | The flag applies |
| Differs from the current `users.email` | The flag does not apply |
| NULL or empty | The flag applies (fail closed) |

- The compare normalizes both sides. PHP uses
  `DeliverabilityMigrationQueries::normalizeAddress()` (trim, then lower case).
  SQL uses `LOWER(REGEXP_REPLACE(<column>, '^[[:space:]]+|[[:space:]]+$', ''))`,
  because MySQL `TRIM()` removes spaces only. SQL then compares with
  `COLLATE utf8mb4_bin`, so the compare is byte-exact, as PHP `===` is.
- The two forms agree for an ASCII address with leading or trailing white
  space from the set `[ \t\n\r\x0B]`. The known differences: PHP `trim()`
  removes `\0` and SQL does not. SQL removes `\f`, a no-break space and other
  Unicode spaces, and PHP does not. MySQL `LOWER()` lowers non-ASCII letters,
  and PHP `strtolower()` does not. Address validation rejects these
  characters.
- `email_opted_out` applies whatever the address. A change of address does not
  end an opt-out.
- A write path never clears a flag only because its address does not match. A
  flag for an old address stays in the row. It applies again if the owner
  changes back to that address.

Two methods in `CarRepository` apply this owner-level rule:

- `effectiveEmailFlags(int $userId)` returns an `EffectiveEmailFlags` object.
  PHP code uses it: the send guard, the Brevo suppression import, the admin
  user form, the Email on file row and the account notice.
- `emailFlagActiveSql(string $flag)` returns the SQL form on the aliases `p`
  (`profiles`) and `u` (`users`).

**One address.** A verification send mails `users.email`. `cars.email` is
only a copy, and no deliverability check reads it. The verification
eligibility query, the queue pills (Pending, Bounced, Suppressed) and the
queue columns `email_blocked_active` and `email_bounced_active` use
`emailFlagActiveSql()`. A car that the batch skips because of a flag shows
under the pill of that flag, not as Pending. `CarVerificationSendService::sendOne()`
checks `effectiveEmailFlags()` before it mails.

See [CLASSES.md](CLASSES.md#effectiveemailflags) for the object and the method
signatures.

### Confirmed-Change Rule (#2356, replaces #1890)

Only a confirmed change of email address clears a bounce and a block. A
confirmed change is a successful UserSpice email-verification flow (the owner
clicks the vericode link). The `verifySuccess` hook is the only path that
clears.

**Timing:** The hook runs after a successful vericode confirmation and before
the owner-field sync that copies the new address to the cars (see
`usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php`).

**Method:** The hook makes one call:
`CarVerificationManager::clearFlagsForConfirmedEmailChange(int $actingUserId, int $userId): bool`.
The manager opens a transaction and calls
`CarRepository::clearFlagsForConfirmedEmailChange(int $userId): ?array`. The
repository does these steps:

1. It locks the `profiles` row and the `users` row (`SELECT ... FOR UPDATE OF p, u`),
   and reads `users.email`. It locks `profiles` first, the same order as
   `Owner::update()`. A `STRAIGHT_JOIN` sets this order.
2. It compares the normalized `users.email` with `email_address_seen`.
3. If they are equal, it writes nothing. A re-verify of the same address clears
   nothing.
4. If they differ, it clears `email_bounced` with its address and date, and sets
   `email_bounce_cleared_at`. It clears `email_blocked` with its address, date
   and cause. It sets `email_address_since` to now and `email_address_seen` to
   the normalized address.

`email_opted_out` stays set. The hook does not pass the address, because the
repository reads `users.email` under the lock.

**Logging:** For each flag that changes from 1 to 0, the manager writes one
flag-change `logs` row with source `email_change`. After the commit, it logs a
cleared flag that had no recorded address as a data-integrity anomaly under
`LOG_CATEGORY_EMAIL_BOUNCED`. This applies to `email_bounced` and to
`email_blocked`. The log row holds the user ID and the flag name, and no
address. A no-op confirmation writes no log line.

**Exception safety:** The clear and its audit rows run in one transaction. The
hook calls the manager in its own `try`/`catch` block, separate from the
owner-field sync. A database failure in the clear does not stop the sync, and
the reverse is also true. The hook logs each failure and continues.

**Paths that do not clear:**

- The direct change when email verification is off (`email_act = 0`).
  `usersc/user_settings.php` writes the new `users.email` through
  `Owner::updateProfileAndSync()`, and no `verifySuccess` hook runs. The flags
  stay in the row. The address no longer matches, so the readers ignore a block
  or bounce for the old address. The page does not call the manager.
- `Owner::update()`. It never clears a flag.

### `email_address_since` and `email_address_seen` (#2356)

`profiles.email_address_seen` holds the last normalized address that the
registry saw. `profiles.email_address_since` holds the time of the last change.
Event and import matching (#2359) uses `email_address_since`.

**Writers.** A writer sets both columns when the normalized address differs from
`email_address_seen`. A change of case or white space only is not a change.

| Writer | Notes |
| --- | --- |
| `Owner::update()` | Locks the `profiles` row (`SELECT email_address_seen ... FOR UPDATE`) before any write, then writes `users.email` and both columns in one transaction. The `email_act = 0` path and the admin owner edit use it, and `updateProfileAndSync()` calls it. It writes the columns only if the owner has a `profiles` row. |
| `CarRepository::clearFlagsForConfirmedEmailChange()` | The `verifySuccess` hook. It cannot join the upstream `users.email` write in `users/verify.php`, so an event between the two writes can see the new address with the old `email_address_since`. This risk is accepted. |

**Exception: the upstream admin user form.** The form
(`users/views/_admin_user.php`) changes `users.email`, runs no hook, and sets
neither column. The registry does not edit
upstream files and the form has no hook after the write. After such an edit,
`email_address_seen` still holds the old address. The first `verifySuccess` of
the new address counts as a change: it clears the flags for the old address
and sets both columns. A second `verifySuccess` of the same address clears
nothing. `Owner::create()` is also an exception, but no production code calls
it.

**Related**:

- `UserSpice user email-verification flow` — `users/verify.php`, triggered via
  a clickable vericode link the owner receives via email
- `sync_owner_email_on_verify` hook — runs the flag clear plus the
  owner-field sync to `cars.email` on every confirmed email change
- `CarRepository::setProfileBounced()` — the write method that sets the bounce
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
reconciliation job use, so an imported suppression flags the owner identically
to a live event. The job passes the source `import` to the audit row. The
reconciliation job passes `reconciliation`.

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
| `hardBounce` | `blocked` | sets the owner's `email_bounced` |
| `contactFlaggedAsSpam` | `spam` | sets the owner's `email_blocked`, cause `spam` |
| `unsubscribedViaEmail`, `unsubscribedViaMA`, `unsubscribedViaApi`, `adminBlocked` | `unsubscribed` | sets the owner's `email_blocked`, cause `unsubscribed` |
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
cars: `CarVerificationManager::setSuppressedForOwner(int $ownerId)` sets `profiles.email_opted_out = 1` and `email_opted_out_at` on the
owner's one profile row. Nothing is written to a car. Verification reads the profile, so a car that the owner registers later is also
excluded. The opt-out is set also when the owner has `email_blocked` or `email_bounced`, so it stays after an admin clears those flags.
A repeat POST finds the opt-out already set, writes nothing, and redirects identically.

One flag-change `logs` row (source `owner`) is written when the opt-out changes from 0 to 1. The profile write and the `logs` row commit in one
transaction. A database failure rolls back the whole operation. An owner with no `profiles` row gets the failure page, and nothing is written.

The GET page reads `profiles.email_opted_out` only. A visitor sees the same page whether or not the owner has `email_blocked` or
`email_bounced`, so the page does not reveal the delivery state of the address.

The POST redirects via 303 (See Other) to the confirmation page (GET), which then displays the updated suppression state. A repeat
visit always succeeds silently.

**Authentication:** No CSRF token is required or present (see `app/verify/verify_car.php`'s file header for the full rationale).
The vericode is the unguessable credential; session-less CSRF tokens would add no value while breaking legitimate email links.

**Logging:** A successful opt-out writes the flag-change `logs` row described in
[Flag-change audit rows](#flag-change-audit-rows). Failures (a DB error while counting cars, or during the opt-out transaction) are logged under
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
   holds. In particular, check that `$opts['tags']` still reaches Brevo and
   that "Tag untagged mail" is still "Calling file name". Run the plugin's
   pending migrations on its plugin page. Diff the new
   `override.php`/`functions.php` against the backup if anything seems off.

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
| `reply_name` | string | Override reply-to display name. The `email()` override forwards it. |
| `template` | int | Brevo template ID for templated emails |
| `params` | array | Template variable substitutions (key => value pairs) |
| `attachments` | array | Array of `['content' => base64string, 'name' => 'filename.pdf']` |
| `tags` | string or array | Brevo tags for the message. Explicit tags win over the "Tag untagged mail" setting. See [Mail Tags](#mail-tags). |

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
| `reply_name` | `reply_name` |
| `altBody` | `textContent` |
| `tags` | `tags` |

The fifth argument of `email()` is a file path. The override reads the file and
sends it as an attachment named with the file's base name. It drops an
unreadable file and still sends the message.

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

The `email()` override does not forward the `attachments` key. It accepts a file
path as its fifth argument instead. To send base64 content, use `sendinblue()`
directly:

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
