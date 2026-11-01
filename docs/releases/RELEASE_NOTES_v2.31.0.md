# Elan Registry v2.31.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Verification and Email Follow-ups

Admins can trust what the verification and email tools report, also when a
write, a commit, or a cron run fails. No owner gets mail that a block or
bounce should stop.

## User-Facing Changes

- The paused-email notice on the account page names an admin block that comes back after a Clear Block.
- The car verification page shows the car's photos and location map, as the car details page does.

## Admin-Facing Changes

- The account-deletion privacy log shows the SQLSTATE and the place of the error, and logs a failed commit as "transaction state unknown".
- The verification failed-send log names a plugin precondition failure, or a connection error with its cURL number when known.
- Cron health checks report send outages correctly, and an event with a bad date no longer re-blocks a cleared owner.
- After an admin edits an owner's email, a block or bounce for the new address stays set after verification.
- Clear Block reports a Brevo plugin fault and tells the admin to check the logs, not to try again.
- The Verification tab shows "Unavailable" when it cannot read a setting, and does not show "All healthy" for an unreadable job.
- The transfer request admin alert says when the current owner got no notice of the request.

## Issues Resolved

- [#2551](https://github.com/elan-registry/registry/issues/2551) — Migrate the test suite to PHPUnit 13.
- [#2515](https://github.com/elan-registry/registry/issues/2515) — A failed commit no longer skips the rollback, and the privacy step logs it as unknown state.
- [#2466](https://github.com/elan-registry/registry/issues/2466) — The verification failed-send log names connection errors and plugin precondition failures.
- [#2529](https://github.com/elan-registry/registry/issues/2529) — The paused-email notice names an admin block set after a Clear Block.
- [#2480](https://github.com/elan-registry/registry/issues/2480) — Cron runs report the correct outcome in four failure cases.
- [#2411](https://github.com/elan-registry/registry/issues/2411) — An admin email edit no longer lets a later verification clear the owner's block and bounce.
- [#2488](https://github.com/elan-registry/registry/issues/2488) — Clear Block reports a broken Brevo SDK instead of telling the admin to retry.
- [#2457](https://github.com/elan-registry/registry/issues/2457) — The Verification tab shows fault states as faults, not guessed values.
- [#2476](https://github.com/elan-registry/registry/issues/2476) — The transfer admin alert says when policy skipped the owner's notice.
- [#2561](https://github.com/elan-registry/registry/issues/2561) — The cleanup ledger no longer lists items that only guard core UserSpice and database calls.
- WIP: [#2495](https://github.com/elan-registry/registry/issues/2495) — The verification page shows the car photos and location map, as the details page does.
