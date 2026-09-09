# Elan Registry v2.30.2 Release Notes

**Release Date:** September 7, 2026
**Type:** Minor Release - The go-live gate — no real email sends until this closes. Automatic bounce/delivery detection, webhook reconciliation, and auto-clearing on confirmed email change.

## Required Actions After Deployment

1. Run pending migrations (`composer migrate`) — adds `cars.email_bounced_address`,
   `cars.email_suppressed`, the new `er_email_events` table, and
   `er_verification_settings.unmatched_webhook_recipient_count`. See #1887.
2. Generate and set `BREVO_WEBHOOK_TOKEN` on **each environment that will
   receive real Brevo webhook calls** (test.elanregistry.org and
   elanregistry.org — not needed on dev, see note below). This is the bearer
   token `app/api/webhooks/brevo.php` requires on every inbound request; an
   empty/missing value rejects all requests (fail-closed), so the receiver is
   inert until this is set.
   - Generate a token with at least 32 bytes of cryptographic randomness, e.g.:
     ```bash
     openssl rand -hex 32
     ```
   - Add it to that environment's `.env` (not `.env.example`, which stays a
     placeholder):
     ```
     BREVO_WEBHOOK_TOKEN=<the generated value>
     ```
     `chmod 600 .env` if not already set.
   - Configure the same value as the bearer token/custom header Brevo sends
     with each webhook call for this domain — see step 3 below, which uses
     this same value to both verify the configuration and register the real
     webhook.
   - Treat this token as a credential: do not commit it, do not log it (the
     endpoint only ever logs a hashed prefix on rejection), and rotate it by
     generating a new value and updating both sides (`.env` and the Brevo
     webhook config) together — a rotation with only one side updated causes
     every webhook call to be rejected until both match again.
3. **Configure and verify the Brevo webhook on test.elanregistry.org** (#1888).
   Production registration is explicitly deferred — it's blocked on the
   v2.30.0 privacy-policy disclosure and on the verification-system feature
   switch (#1926) actually being turned on. Requires step 2 above already
   done (`BREVO_WEBHOOK_TOKEN` set in test's `.env`) — not needed in local
   dev at all (see the note below).
   1. On the server, create the capture directory outside the web root and
      lock it down (`mkdir -p ~/spike-1888 && chmod 700 ~/spike-1888`) — it
      will hold recipient email addresses from captured payloads. Edit
      `scripts/spike-1888/brevo-webhook-capture.php`'s `CAPTURE_FILE`
      placeholder to point into that directory before copying it to the
      server (see `scripts/README.md`). Deploy the file itself to
      `~/test.elanregistry.org/scripts/spike-1888/capture.php` — exactly two
      directories below the site root that holds `vendor/` and `.env`
      (`REPO_ROOT` in the script is hardcoded to `../..`); one level too
      shallow makes the autoloader unreachable and every request silently
      404s the same way an auth failure does. Confirm it's reachable.
   2. Register a *throwaway* webhook pointed at the capture script, passing
      the same `BREVO_WEBHOOK_TOKEN` value as its auth token so the capture
      script's inbound auth check has something real to validate against:
      ```bash
      php scripts/spike-1888/brevo-register-webhook.php --create \
        --url='https://test.elanregistry.org/scripts/spike-1888/capture.php' \
        --token=<test's BREVO_WEBHOOK_TOKEN value>
      ```
      Confirm it registered: `php scripts/spike-1888/brevo-register-webhook.php --list-webhooks`.
   3. Send a real test email (Admin → Plugins → Brevo Sendinblue → Test
      Email); confirm `delivered`/`opened` land in the capture log with a
      passing auth check. Send the Mailtrap hard-bounce fixture using the
      existing #1871 send script
      (`scripts/spike-1871/brevo-send-test.php --to='bounce+550+...@inbox.mailtrap.io'`)
      and confirm a `hard_bounce` line lands too.
   4. Delete the throwaway webhook
      (`php scripts/spike-1888/brevo-register-webhook.php --delete --id=<id>`),
      and delete the capture script **and its capture file** (`capture.jsonl`
      — it holds real recipient email addresses) from the server.
      `BREVO_WEBHOOK_TOKEN` in `.env` is left as-is — it's the same value the
      real endpoint needs, not something to rotate here.
   5. Register the **real** webhook against the deployed
      `https://test.elanregistry.org/app/api/webhooks/brevo.php`, using the
      same `BREVO_WEBHOOK_TOKEN`:
      ```bash
      php scripts/spike-1888/brevo-register-webhook.php --create \
        --url='https://test.elanregistry.org/app/api/webhooks/brevo.php' \
        --token=<test's BREVO_WEBHOOK_TOKEN value>
      ```
      No further capture/verification pass is required — the auth mechanism
      and configuration were already proven correct in steps 1–3 above.
   6. Confirm link click-tracking is **off**: Brevo → Transactional →
      Settings → Tracking. (Otherwise Verify/Sold/Review links get rewritten
      through a Brevo redirect domain.)
   7. `spam` end-to-end verification is not self-triggerable (per the #1871
      spike's findings — it requires a real recipient reporting real mail)
      and is already covered instead by
      `testSpamCallsSetSuppressedNotSetBounced` in
      `tests/unit/cars/services/BrevoWebhookEventProcessorTest.php`. No
      manual step needed for it.

**Note:** Development environments do not have access to Brevo and cannot receive real inbound Brevo webhooks (no public URL reaches a dev machine) — `BREVO_WEBHOOK_TOKEN` is not needed there. Webhook-dependent testing (#1887, #1888, #1889, #1890, #1923) will rely on synthetic/captured payloads for local dev and unit tests, with real end-to-end webhook verification happening only on test.elanregistry.org.

## User-Facing Changes

(No user-facing changes yet — content will be added as issues complete.)

## Admin-Facing Changes

- New "Verification System" tab on the admin management page (`app/admin/index.php?tab=verification`), visible to admins and editors. Shows whether Brevo email delivery and the cron transport are configured/healthy, and lets an admin turn the site-wide verification feature switch on or off. The switch defaults **off** and ships with no way to enable real verification sends until a future release — this issue only builds the gate. Enabling the switch is blocked while Brevo isn't configured; disabling it is never blocked, even mid-incident, so an admin can always turn it off. A dashboard banner appears if the switch is left on with a broken prerequisite. (#1926)
- The tab's "Last reconciliation run" row now reads the nightly reconciliation job's actual state (Ran/with timestamp, Never run, Paused, or Status unavailable) instead of a placeholder that had been left behind reading "Not yet implemented" after the job shipped. (#2054)

## Issues Resolved

- [#1887](https://github.com/elan-registry/registry/issues/1887) — feat: automatic bounce & delivery-status detection via Brevo webhooks
- [#1888](https://github.com/elan-registry/registry/issues/1888) — chore: configure & verify the Brevo webhook
- [#1889](https://github.com/elan-registry/registry/issues/1889) — feat: nightly Brevo delivery-event reconciliation job
- [#1890](https://github.com/elan-registry/registry/issues/1890) — feat: auto-clear email_bounced when the owner confirms an email change
- WIP: [#1922](https://github.com/elan-registry/registry/issues/1922) — email: investigate sender reputation — Outlook.com auto-junks registrar@ mail; Brevo suppression list unread
- WIP: [#1923](https://github.com/elan-registry/registry/issues/1923) — feat: import Brevo's suppression list (blockedContacts) into owner email status
- WIP: [#1924](https://github.com/elan-registry/registry/issues/1924) — feat: show email bounce / suppression / verification state on the admin user view hook
- [#1926](https://github.com/elan-registry/registry/issues/1926) — feat: verification-system feature switch with Brevo prerequisite check and admin warning
- [#1968](https://github.com/elan-registry/registry/issues/1968) — chore: raise dev, then test and prod, to PHP 8.4 before 8.2 security EOL
- [#2001](https://github.com/elan-registry/registry/issues/2001) — chore: extract cron transport interval into a shared, discoverable constant (`CRON_TRANSPORT_INTERVAL_MINUTES` in `usersc/includes/config.php`); no behavior change
- [#2027](https://github.com/elan-registry/registry/issues/2027) — feat: extract `CronJobGuard` atomic-claim class from #1885, scoped to v2.30.2 — adds `usersc/classes/Cron/CronJobGuard.php` and (originally) `settings.reconciliation_last_run`; no production caller yet, consumed by #1889. The `settings.reconciliation_last_run` column was superseded by #2034 in this same milestone — see that entry.
- [#2034](https://github.com/elan-registry/registry/issues/2034) — design: generic `cron_job_runs` table vs. per-job settings columns, resolved in favor of a generic table. Adds `er_cron_job_runs` (`job_name` PK, `enabled`, `last_run_at`, `created_at`) and migrates `CronJobGuard::claim()` onto it, dropping `settings.reconciliation_last_run` (#2027) — that column had never been written to in production. Adds an `enabled` flag, checked in `claim()`'s own query, letting an operator pause a single job without touching UserSpice's own `crons` table (add/delete only, no pause). No production caller yet.
- [#2018](https://github.com/elan-registry/registry/issues/2018) — chore: remove rate limiting from `cars_list`, `factory_list`, `car_history`, and `statistics_request` (production log-volume/performance complaint tied to `us_rate_limits` row growth). **Security posture change:** these four public read-only endpoints now carry no app-layer abuse control at all (no CSRF, per ADR-019; no rate limit, as of this issue) — deliberate, user-confirmed tradeoff. See ADR-019's 2026-09-08 update for full rationale; #2015 (cron cleanup of `us_rate_limits`, the alternative that would have preserved the control) remains open and unchosen.
- [#1974](https://github.com/elan-registry/registry/issues/1974) — tech-debt: stop the cron transport logging every request (144 rows/day/environment). `users/cron/cron.php` no longer logs on a successful hit; `VerificationSettings::recordCronRequest()` writes `er_verification_settings.last_cron_request_at` instead, which `lastCronRequestAt()`/`cronReady()` now read for the admin dashboard's cron-health indicator (replacing a `logs`-table scan). A denied hit still logs, unchanged. Manual verification against local MAMP caught and fixed 2 real bugs before merge: a `TypeError` from constructing `VerificationSettings` with the raw `\DB` global instead of `dbi()`, and `recordCronRequest()` being called before (rather than after) the `cron_ip` denial check.
- [#2050](https://github.com/elan-registry/registry/issues/2050) — fix: `.htaccess`'s `Permissions-Policy` header set `geolocation=()`, blocking the Geolocation API everywhere including same-origin — silently breaking the join form's "Use My Current Location" button since #1329 shipped the header (2026-07-13). Changed to `geolocation=(self)`; camera, microphone, and payment remain fully blocked. Also synced `usersc/includes/security_headers.php`'s PHP-level header (previously missing a `geolocation` directive entirely) and corrected ADR-007's Permissions-Policy section, which still described the header as "not adopted."
- [#2054](https://github.com/elan-registry/registry/issues/2054) — fix: Verification tab's "Last reconciliation run" row still read "Not yet implemented (#1889)" after #1889 shipped the reconciliation job — the tab told an operator the opposite of the truth. Adds `usersc/classes/Cron/CronJobRunsReader` (reads `er_cron_job_runs`, never throws) and renders all four possible states distinctly: ran (with timestamp), never run, deliberately paused, or status unavailable (missing row / read failure) — a paused job never renders as a failure, and a read failure never renders as "never run."
