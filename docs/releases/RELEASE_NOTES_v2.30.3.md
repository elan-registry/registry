# Elan Registry v2.30.3 Release Notes

**Release Date:** September 22, 2026
**Type:** Minor Release - Verification System Refresh - Send Pipeline Live

Real verification email ships, self-monitored, pausable — the send cron with its own guard, EmailTemplate composition, one-click opt-out, and admin-visible run-outcome counts on the job it depends on existing.

## User-Facing Changes

- [#1881](https://github.com/elan-registry/registry/issues/1881) — Owners can verify or report a car sold from a public link with no login required.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Verification emails now carry the same branded look as every other system email.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Owners can opt out of verification emails with one click. Opting out now also excludes cars added to the account afterward, not just the ones the owner held at the time.
- [#2105](https://github.com/elan-registry/registry/issues/2105) — Owners who complained to their mail provider about a verification email no longer receive another one.

## Admin-Facing Changes

- [#1884](https://github.com/elan-registry/registry/issues/1884) — The manual send tool previews before sending and no longer deletes car history on send.
- [#1885](https://github.com/elan-registry/registry/issues/1885) — Verification emails send automatically on a guarded daily cron with a dashboard Pause/Resume control and editable batch size. Seeded paused in every environment until an admin explicitly resumes it.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Verification codes are hashed at rest before the first live send batch.
- [#1930](https://github.com/elan-registry/registry/issues/1930) — Dead owner-timestamp code path removed; no behavior change.
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Cars with no live owner are excluded from verification-email eligibility.
- [#2085](https://github.com/elan-registry/registry/issues/2085) — The Verification tab now shows an "Unmatched recipients" counter, reflecting the webhook, cron reconciliation, and suppression-sync signals together (this counter had no admin UI at all before this release).
- [#2086](https://github.com/elan-registry/registry/issues/2086) — The Verification tab now shows a suppression-sync status badge alongside the existing reconciliation one.
- [#2087](https://github.com/elan-registry/registry/issues/2087) — Webhook auth-failure logging is now rate-limited per IP, so spammed invalid tokens can no longer grow the logs table unbounded. The 401 rejection itself is always returned regardless of rate-limit state.
- [#2090](https://github.com/elan-registry/registry/issues/2090) — Documentation now states plainly that every `AbstractCronJob` subclass is gated by the site-wide verification switch, not just Brevo-driven jobs — no behavior change.
- [#2122](https://github.com/elan-registry/registry/issues/2122) — The join form's location picker no longer refuses a normal typing session with "Rate limit exceeded" — a real registrant was locked out of registration by a search-rate limit sized for abuse, not ordinary use.
- [#2148](https://github.com/elan-registry/registry/issues/2148) — The Verification tab now shows a distinct "Last run failed" badge and a cron-failure log summary, so a crashed nightly send no longer looks identical to a healthy one.
- All rate limits were raised 50% to stop production false-positive throttling on ordinary browsing traffic. Two limits added earlier in this same milestone (`verification_code_attempt`, the vericode brute-force ceiling; `brevo_webhook_auth_failure`, deliberately tight by design) were excluded from that raise and kept at their original values.
- Security fix: cron job files under `users/cron/` are no longer directly reachable over HTTP — an unauthenticated direct request could previously trigger a real verification-email batch send outside the normal cron dispatch path.
- Verification emails are no longer resent to the same car within 60 days of the last send, so consecutive nightly runs can't repeatedly re-pick the same batch before an earlier send has had a chance to be acted on.

## Issues Resolved

- [#1881](https://github.com/elan-registry/registry/issues/1881) — Build the public verification landing page.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Compose the verification email via EmailTemplate.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Add a one-click opt-out link to the verification email.
- [#1884](https://github.com/elan-registry/registry/issues/1884) — Rework send_email.php into a preview/send flow with corrected Mark Bounced semantics.
- [#1885](https://github.com/elan-registry/registry/issues/1885) — Register `send_verification_batch` as a guarded cron job with dashboard Pause/Resume and editable batch size.
- [#1922](https://github.com/elan-registry/registry/issues/1922) — Investigate and resolve sender-reputation issues ahead of the first live send.
- [#1970](https://github.com/elan-registry/registry/issues/1970) — Remove the `isFresh()` placeholder note now that the send pipeline calls it.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Hash cars.vericode before the first live verification batch.
- [#1930](https://github.com/elan-registry/registry/issues/1930) — Remove `CarRepository::updateOwnerLastUpdated()` (zero production callers).
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Exclude cars with no owner or owned by the `noowner` system account from verification eligibility.
- [#2085](https://github.com/elan-registry/registry/issues/2085) — Build the previously-missing admin UI for the unmatched-recipient counter.
- [#2086](https://github.com/elan-registry/registry/issues/2086) — Add the missing suppression-sync admin status badge and refactor cron-job testing.
- [#2087](https://github.com/elan-registry/registry/issues/2087) — Rate-limit Brevo webhook auth-failure logging to stop unbounded log-table growth.
- [#2090](https://github.com/elan-registry/registry/issues/2090) — Correct `AbstractCronJob` documentation to state the verification-switch gate applies universally (no behavior change).
- [#2105](https://github.com/elan-registry/registry/issues/2105) — Exclude `email_suppressed` cars from `findVerificationEligible()`, closing a gap that would have sent verification email to spam complainants.
- [#2122](https://github.com/elan-registry/registry/issues/2122) — Raise `location_search` rate limit from 10/60s to 1000/300s (later 1500/300s) to allow normal registration form usage.
- [#2129](https://github.com/elan-registry/registry/issues/2129) — Rename the `reconciliation` cron job identifier to `brevo_reconciliation` (data migration included).
- [#2148](https://github.com/elan-registry/registry/issues/2148) — Add `er_cron_job_runs.last_failure_at` and a failure-log summary so a crashed cron job is distinguishable from a healthy one on the admin dashboard.
