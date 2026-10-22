# Elan Registry v2.30.10 Release Notes

**Release Date:** October 22, 2026 (draft)
**Type:** Patch Release - Post Verification System Cleanup and Bugs

An admin can trust what the Verification tab and email-block tools show, and
an owner's address no longer stays behind after account deletion or in logs.

## User-Facing Changes

- (Filled in as issues are completed.)

## Admin-Facing Changes

- (Filled in as issues are completed.)

## Issues Resolved

- WIP: [#2439](https://github.com/elan-registry/registry/issues/2439) — Stop stored XSS through double entity decode in toast flash messages.
- WIP: [#2487](https://github.com/elan-registry/registry/issues/2487) — Remove the remaining raw exception messages from log rows.
- WIP: [#2419](https://github.com/elan-registry/registry/issues/2419) — Delete plugin mail rows for the current and pending address at account deletion.
- WIP: [#2384](https://github.com/elan-registry/registry/issues/2384) — Purge `plg_sendinblue_messages` rows older than 90 days.
- WIP: [#2482](https://github.com/elan-registry/registry/issues/2482) — Count only a clear after `email_address_since` when the suppression import has no usable date.
- WIP: [#2461](https://github.com/elan-registry/registry/issues/2461) — Use one order for opted out, blocked and bounced in the send report, the Status chip and the notice.
- WIP: [#2498](https://github.com/elan-registry/registry/issues/2498) — Show the owner's user ID in the Verification Queue.
- WIP: [#2501](https://github.com/elan-registry/registry/issues/2501) — Accept Brevo blocked-at dates that arrive 2 hours ahead in the suppression sync.
- WIP: [#2502](https://github.com/elan-registry/registry/issues/2502) — Stop the suppression sync from adding a second event row for an owner that is already flagged.
- WIP: [#2499](https://github.com/elan-registry/registry/issues/2499) — Show an opt-out as a warning in the paused-email notice.
- WIP: [#2500](https://github.com/elan-registry/registry/issues/2500) — Show every block cause in the paused-email notice.
- WIP: [#2497](https://github.com/elan-registry/registry/issues/2497) — Thank the owner after a Sold report, not tell them the car is already sold.
- WIP: [#2043](https://github.com/elan-registry/registry/issues/2043) — Show a visible error when the location gate blocks the join form.
- WIP: [#2474](https://github.com/elan-registry/registry/issues/2474) — Retire the Backlog milestone.
