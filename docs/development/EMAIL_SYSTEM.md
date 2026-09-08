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

**Rate limiting:** `checkRateLimit('brevo_webhook')` (IP-scoped; see
`usersc/includes/rate_limits.php`), checked only **after** auth passes, so a
rate-limiter failure (which fails open, matching
`app/api/shared/join-failure-report.php`'s pattern) can only ever become a
throughput bypass, never an auth bypass.

**Verification-system gates** (unchanged from the pre-#1887 stub):
`!isEnabled()` → 2xx, silent. `isEnabled() && !brevoReady()` → 2xx, logged
once under `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING`.

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
| Recipient matches no car | 2xx, logged, `er_verification_settings.unmatched_webhook_recipient_count` incremented |
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
rendering of the per-car event history or the unmatched-recipient counter —
that is a follow-up issue.

### Feature Switch Related Documentation

- [DEPLOYMENT.md — Cron Transport](DEPLOYMENT.md#cron-transport-userspice-cron-manager) — the 10-minute interval constant referenced by `cronReady()`
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — `LOG_CATEGORY_VERIFICATION_CONFIG_WARNING` for logging failures, `LOG_CATEGORY_EMAIL_WEBHOOK` for webhook event processing
- [CLASSES.md](CLASSES.md) — `VerificationSettings` and `VerificationConfigException` class reference

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
