# Elan Registry v2.30.8 Release Notes

**Release Date:** October 13, 2026 (draft)
**Type:** Patch Release - Delivery Events From All Mail

A bounce or complaint on any email that the registry sends now updates the
owner's profile, and owner-to-owner and admin contact obey it. The registry
keeps delivery records per owner, removes them on a fixed schedule, and
deletes them with the account. Admins see the email state of each owner.

## User-Facing Changes

## Admin-Facing Changes

## Issues Resolved

- [#2165](https://github.com/elan-registry/registry/issues/2165) — Add `IntegrationTestCase::invokeEndpoint()` and HTTP-level tests for the owner-contact
  IDOR check and the admin AJAX CSRF check (the `save.php` ownership and `statistics.php` validation tests move to
  [#2362](https://github.com/elan-registry/registry/issues/2362)).
- WIP: [#2330](https://github.com/elan-registry/registry/issues/2330) — Move the Verification tab status and batch cap into classes, with new tests.
- WIP: [#2358](https://github.com/elan-registry/registry/issues/2358) — Key email events by user and keep them through car delete, merge, and account deletion.
- WIP: [#2359](https://github.com/elan-registry/registry/issues/2359) — Apply delivery events from all registry mail to the owner profile, matched by message ID.
- WIP: [#2360](https://github.com/elan-registry/registry/issues/2360) — Apply the profile send policy to owner-to-owner and admin contact.
- WIP: [#2361](https://github.com/elan-registry/registry/issues/2361) — Show the email state of each owner (Bounced, Blocked, Opted out) to admins.
- WIP: [#2364](https://github.com/elan-registry/registry/issues/2364) — Keep the Status panel in one place on the Verification tab.
- WIP: [#2386](https://github.com/elan-registry/registry/issues/2386) — Correct the EMAIL_SYSTEM.md Status Indicators list for the webhook and reconciliation rows.
- WIP: [#2393](https://github.com/elan-registry/registry/issues/2393) — Fix the error handling in the deliverability event path that the #2101 review found.
- WIP: [#2406](https://github.com/elan-registry/registry/issues/2406) — Stop a failed owner resume from causing a 500 error.
- WIP: [#2414](https://github.com/elan-registry/registry/issues/2414) — Show "an administrator blocked this address" to an owner whom a Brevo admin blocked.
