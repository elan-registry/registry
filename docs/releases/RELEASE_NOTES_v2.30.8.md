# Elan Registry v2.30.8 Release Notes

**Release Date:** October 13, 2026 (draft)
**Type:** Patch Release - Delivery Events From All Mail

A bounce or complaint on any email that the registry sends now updates the
owner's profile, and owner-to-owner and admin contact obey it. The registry
keeps delivery records per owner, removes them on a fixed schedule, and
deletes them with the account. Admins see the email state of each owner.

## User-Facing Changes

## Admin-Facing Changes

- The registry stores each delivery event once for each owner, and every car of the owner shows it on the Verification dashboard.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- A deleted car, a merged car, or a transferred car no longer loses or mixes up the owner's delivery events, and account deletion removes them.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- The nightly prune keeps terminal events for 24 months, and `soft_bounce`, `delivered` and `sent` rows for 90 days,
  except each car's latest `sent` row for its current owner, whose address it blanks only when the row is older than 90 days.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- The prune, the reconciliation and the suppression import now run with the verification switch off, and among the cron jobs only the send batch honours it.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- Maintenance script 27 shows "owners matched", and the new read-only script 29 previews the event re-key.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))

## Issues Resolved

- [#2165](https://github.com/elan-registry/registry/issues/2165) — Add `IntegrationTestCase::invokeEndpoint()` and HTTP-level tests for the owner-contact
  IDOR check and the admin AJAX CSRF check (the `save.php` ownership and `statistics.php` validation tests move to
  [#2362](https://github.com/elan-registry/registry/issues/2362)).
- WIP: [#2330](https://github.com/elan-registry/registry/issues/2330) — Move the Verification tab status and batch cap into classes, with new tests.
- [#2358](https://github.com/elan-registry/registry/issues/2358) — Key email events by user and keep them through car delete, merge, and account deletion.
- WIP: [#2359](https://github.com/elan-registry/registry/issues/2359) — Apply delivery events from all registry mail to the owner profile, matched by message ID.
- WIP: [#2360](https://github.com/elan-registry/registry/issues/2360) — Apply the profile send policy to owner-to-owner and admin contact.
- WIP: [#2361](https://github.com/elan-registry/registry/issues/2361) — Show the email state of each owner (Bounced, Blocked, Opted out) to admins.
- WIP: [#2364](https://github.com/elan-registry/registry/issues/2364) — Keep the Status panel in one place on the Verification tab.
- WIP: [#2386](https://github.com/elan-registry/registry/issues/2386) — Correct the EMAIL_SYSTEM.md Status Indicators list for the webhook and reconciliation rows.
- WIP: [#2393](https://github.com/elan-registry/registry/issues/2393) — Fix the error handling in the deliverability event path that the #2101 review found.
- WIP: [#2406](https://github.com/elan-registry/registry/issues/2406) — Stop a failed owner resume from causing a 500 error.
- WIP: [#2414](https://github.com/elan-registry/registry/issues/2414) — Show "an administrator blocked this address" to an owner whom a Brevo admin blocked.
