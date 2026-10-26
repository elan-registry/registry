# Elan Registry v2.30.10 Release Notes

**Release Date:** October 22, 2026 (draft)
**Type:** Patch Release - Post Verification System Cleanup and Bugs

Administrators can now trust the Verification tab and the email-block tools. Account deletion and the email service log no longer keep an owner's address.

## User-Facing Changes

- Pop-up messages show special characters as plain text. ([#2439](https://github.com/elan-registry/registry/issues/2439))
- The email service log keeps a recipient address for 90 days at most. ([#2384](https://github.com/elan-registry/registry/issues/2384))
- The page thanks an owner after a Sold report. ([#2497](https://github.com/elan-registry/registry/issues/2497))
- The join form shows a message when the browser blocks a submit. ([#2043](https://github.com/elan-registry/registry/issues/2043))
- The paused-email notice shows every cause of a block. ([#2499](https://github.com/elan-registry/registry/issues/2499))

## Admin-Facing Changes

- Account deletion also removes plugin mail records for the owner's current and pending addresses. ([#2419](https://github.com/elan-registry/registry/issues/2419))
- The suppression import counts a clear only if it follows the owner's address change. ([#2482](https://github.com/elan-registry/registry/issues/2482))
- The send report, Status chip and owner notice give the same reason for blocked, bounced and opted-out owners. ([#2461](https://github.com/elan-registry/registry/issues/2461))
- The Verification Queue shows the owner's user ID. ([#2498](https://github.com/elan-registry/registry/issues/2498))
- The suppression sync stores the correct block date and adds no duplicate event row. ([#2501](https://github.com/elan-registry/registry/issues/2501))

## Issues Resolved

- [#2439](https://github.com/elan-registry/registry/issues/2439) — Escape decoded text in pop-up messages, so an encoded name or link cannot add HTML.
- [#2419](https://github.com/elan-registry/registry/issues/2419) — Delete plugin mail rows for the current and pending address at account deletion, and add a manual rerun of the privacy step.
- [#2384](https://github.com/elan-registry/registry/issues/2384) — Delete the recipient address from the email service log after 90 days.
- [#2482](https://github.com/elan-registry/registry/issues/2482) — Count only a clear after the address change when the suppression import has no usable date.
- [#2461](https://github.com/elan-registry/registry/issues/2461) — Use one order for opted out, blocked and bounced in the send report, the Status chip and the notice.
- [#2498](https://github.com/elan-registry/registry/issues/2498) — Show the owner's user ID in the Verification Queue.
- [#2501](https://github.com/elan-registry/registry/issues/2501) — Read Brevo's blocked-at dates as Paris time, so the suppression sync stores the real block date.
- [#2502](https://github.com/elan-registry/registry/issues/2502) — Stop the suppression sync from adding a second event row for an owner that is already flagged.
- [#2499](https://github.com/elan-registry/registry/issues/2499) — Show an opt-out as a warning in the paused-email notice.
- [#2500](https://github.com/elan-registry/registry/issues/2500) — Show every block cause in the paused-email notice.
- [#2497](https://github.com/elan-registry/registry/issues/2497) — Thank the owner after a Sold report, not tell them the car is already sold.
- [#2043](https://github.com/elan-registry/registry/issues/2043) — Show a visible error when the location gate blocks the join form.
- [#2474](https://github.com/elan-registry/registry/issues/2474) — Retire the Backlog milestone, so an unscheduled issue has no milestone.
