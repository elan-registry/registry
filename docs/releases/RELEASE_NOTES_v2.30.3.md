# Elan Registry v2.30.3 Release Notes

**Release Date:** September 13, 2026
**Type:** Minor Release - Verification System Refresh - Send Pipeline Live

Real verification email ships, self-monitored, pausable — the send cron with its own guard, EmailTemplate composition, one-click opt-out, and healthchecks.io monitoring on the job it depends on existing.

## User-Facing Changes

- [#1881](https://github.com/elan-registry/registry/issues/1881) — Owners can verify or report a car sold from a public link with no login required.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Verification emails now carry the same branded look as every other system email.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Owners can opt out of verification emails with one click.

## Admin-Facing Changes

- WIP: [#1884](https://github.com/elan-registry/registry/issues/1884) — The manual send tool previews before sending and no longer deletes car history on send.
- [#1885](https://github.com/elan-registry/registry/issues/1885) — Verification emails send automatically on a guarded daily cron with a dashboard Pause/Resume control and editable batch size. Seeded paused in every environment until an admin explicitly resumes it.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Verification codes are hashed at rest before the first live send batch.
- [#1930](https://github.com/elan-registry/registry/issues/1930) — Dead owner-timestamp code path removed; no behavior change.
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Cars with no live owner are excluded from verification-email eligibility.
- [#2085](https://github.com/elan-registry/registry/issues/2085) — The Verification tab now shows an "Unmatched recipients" counter, reflecting the webhook, cron reconciliation, and suppression-sync signals together (this counter had no admin UI at all before this release).
- [#2086](https://github.com/elan-registry/registry/issues/2086) — The Verification tab now shows a suppression-sync status badge alongside the existing reconciliation one.
- [#2087](https://github.com/elan-registry/registry/issues/2087) — Webhook auth-failure logging is now rate-limited per IP, so spammed invalid tokens can no longer grow the logs table unbounded. The 401 rejection itself is always returned regardless of rate-limit state.
- [#2090](https://github.com/elan-registry/registry/issues/2090) — Documentation now states plainly that every `AbstractCronJob` subclass is gated by the site-wide verification switch, not just Brevo-driven jobs — no behavior change.
- [#2122](https://github.com/elan-registry/registry/issues/2122) — The join form's location picker no longer refuses a normal typing session with "Rate limit exceeded" — a real registrant was locked out of registration by a search-rate limit sized for abuse, not ordinary use.

## Issues Resolved

- [#1881](https://github.com/elan-registry/registry/issues/1881) — Build the public verification landing page.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Compose the verification email via EmailTemplate.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Add a one-click opt-out link to the verification email. The same commit also fixes #2105 (excludes `email_suppressed` cars from verification eligibility), needed for this issue's own opt-out logic.
- [#1884](https://github.com/elan-registry/registry/issues/1884) — Rework send_email.php into a preview/send flow with corrected Mark Bounced semantics.
- [#1885](https://github.com/elan-registry/registry/issues/1885) — Register `send_verification_batch` as a guarded cron job (`AbstractCronJob`/`CronJobGuard`, 20-hour claim interval) with dashboard Pause/Resume, editable batch size, and last-run outcome counts. Also removes the `isFresh()` placeholder note (#1970), consolidated into this work.
- [#1922](https://github.com/elan-registry/registry/issues/1922) — Investigate and resolve sender-reputation issues ahead of the first live send.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Hash cars.vericode before the first live verification batch.
- [#1930](https://github.com/elan-registry/registry/issues/1930) — Remove `CarRepository::updateOwnerLastUpdated()`: zero production callers, resolved by deletion.
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Exclude cars with no owner, or owned by the `noowner` system account, from verification-email eligibility.
- [#2085](https://github.com/elan-registry/registry/issues/2085) — Build the previously-missing admin UI for the unmatched-recipient counter and wire all three ingestion paths (webhook, cron reconciliation, suppression sync) into it via a renamed, broadened `er_verification_settings.unmatched_recipient_count` column.
- [#2086](https://github.com/elan-registry/registry/issues/2086) — Add the missing suppression-sync admin status badge; lock the cron-job name allowlist against actual job classes with a test; extract cron.php's request-gating logic into a testable class, replacing a fragile source-grep regression test; add integration coverage proving the unmatched-recipient counter increments in both cron jobs.
- [#2087](https://github.com/elan-registry/registry/issues/2087) — Rate-limit the Brevo webhook's auth-failure logging (a new `brevo_webhook_auth_failure` key, 10 failures per IP per 5 minutes) to stop unbounded `logs` table growth from spammed garbage POSTs, without weakening the auth check itself.
- [#2090](https://github.com/elan-registry/registry/issues/2090) — Correct `AbstractCronJob`'s docblock, `DEPLOYMENT.md`, and `CLASSES.md` to state the verification-switch gate is unconditional across every subclass (not scoped to Brevo-driven jobs), and fix the stale `AbstractCronJob` "Used By" list to include `BrevoSuppressionSyncJob` and `SendVerificationBatchJob`.
- [#2122](https://github.com/elan-registry/registry/issues/2122) — Raise `location_search`'s rate limit from 10/60s to 1000/300s: the old threshold refused a real registrant's typed address (11 debounced autocomplete requests in 50s) via the join form's manual location-picker fallback. Sized above the issue's own "low hundreds" suggestion to account for production's rate-limit buckets currently being shared per-Cloudflare-edge-node rather than per-visitor (#1952, open) — deliberately still an order of magnitude below the `cars_list`-family value #2018 removed after test-suite-driven `us_rate_limits` row growth.
- [#2129](https://github.com/elan-registry/registry/issues/2129) — Rename the `reconciliation` cron job's internal identifier to `brevo_reconciliation`, matching its `brevo_suppression_sync`/`send_verification_batch` siblings' naming — a data migration renames the existing seeded `er_cron_job_runs` row alongside the matching code constants, so the job keeps running under its new name with no behavior change.
