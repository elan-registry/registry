# Elan Registry v2.30.3 Release Notes

**Release Date:** September 13, 2026
**Type:** Minor Release - Verification System Refresh - Send Pipeline Live

Real verification email ships, self-monitored, pausable — the send cron with its own guard, EmailTemplate composition, one-click opt-out, and healthchecks.io monitoring on the job it depends on existing.

## User-Facing Changes

- [#1881](https://github.com/elan-registry/registry/issues/1881) — Owners can verify or report a car sold from a public link with no login required.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Verification emails now carry the same branded look as every other system email.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Owners can opt out of verification emails with one click.

## Admin-Facing Changes

- WIP: [#1875](https://github.com/elan-registry/registry/issues/1875) — Verification cron jobs are monitored so a silent stop is caught automatically.
- WIP: [#1884](https://github.com/elan-registry/registry/issues/1884) — The manual send tool previews before sending and no longer deletes car history on send.
- WIP: [#1885](https://github.com/elan-registry/registry/issues/1885) — Verification emails send automatically on a guarded daily cron with a dashboard Pause/Resume control.
- WIP: [#1886](https://github.com/elan-registry/registry/issues/1886) — Superseded admin-only verification pages are removed.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Verification codes are hashed at rest before the first live send batch.
- WIP: [#1930](https://github.com/elan-registry/registry/issues/1930) — Dead owner-timestamp code path is wired up or removed.
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Cars with no live owner are excluded from verification-email eligibility.
- WIP: [#2085](https://github.com/elan-registry/registry/issues/2085) — The unmatched-recipient counter reflects cron reconciliation and suppression-sync, not just the webhook.
- WIP: [#2086](https://github.com/elan-registry/registry/issues/2086) — Minor v2.30.2 cleanup: admin status badge, dead schema column, test coverage gaps.
- WIP: [#2087](https://github.com/elan-registry/registry/issues/2087) — Webhook auth-failure logging no longer grows the logs table unbounded.
- WIP: [#2088](https://github.com/elan-registry/registry/issues/2088) — The production Brevo webhook is registered and real verification sending is turned on.
- WIP: [#2090](https://github.com/elan-registry/registry/issues/2090) — Cron job verification-switch gating is documented for future non-Brevo jobs.

## Issues Resolved

- WIP: [#1875](https://github.com/elan-registry/registry/issues/1875) — Monitor the verification cron jobs with healthchecks.io.
- [#1881](https://github.com/elan-registry/registry/issues/1881) — Build the public verification landing page.
- [#1882](https://github.com/elan-registry/registry/issues/1882) — Compose the verification email via EmailTemplate.
- [#1883](https://github.com/elan-registry/registry/issues/1883) — Add a one-click opt-out link to the verification email.
- [#1884](https://github.com/elan-registry/registry/issues/1884) — Rework send_email.php into a preview/send flow with corrected Mark Bounced semantics.
- WIP: [#1885](https://github.com/elan-registry/registry/issues/1885) — Register send_verification_batch as a guarded cron job with dashboard Pause/Resume.
- WIP: [#1886](https://github.com/elan-registry/registry/issues/1886) — Remove the superseded admin-scoped verification files.
- [#1922](https://github.com/elan-registry/registry/issues/1922) — Investigate and resolve sender-reputation issues ahead of the first live send.
- [#1928](https://github.com/elan-registry/registry/issues/1928) — Hash cars.vericode before the first live verification batch.
- WIP: [#1930](https://github.com/elan-registry/registry/issues/1930) — Wire up or remove CarRepository::updateOwnerLastUpdated().
- [#1991](https://github.com/elan-registry/registry/issues/1991) — Exclude cars with no owner, or owned by the `noowner` system account, from verification-email eligibility.
- WIP: [#2085](https://github.com/elan-registry/registry/issues/2085) — Fix the unmatched-recipient counter to cover all three ingestion paths.
- WIP: [#2086](https://github.com/elan-registry/registry/issues/2086) — v2.30.2 cleanup: status badge, dead schema, test-coverage gaps.
- WIP: [#2087](https://github.com/elan-registry/registry/issues/2087) — Fix unbounded logs-table growth from webhook auth failures.
- WIP: [#2088](https://github.com/elan-registry/registry/issues/2088) — Register the production Brevo webhook and turn on the verification switch.
- WIP: [#2090](https://github.com/elan-registry/registry/issues/2090) — Document AbstractCronJob's verification-switch gating scope.
