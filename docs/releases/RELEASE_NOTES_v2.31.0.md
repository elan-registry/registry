# Elan Registry v2.31.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Verification and Email Follow-ups

Admins can trust what the verification and email tools report, also when a task fails. Owners no longer get mail that a block or bounce should stop.

## User-Facing Changes

- The paused-email notice on the account page now shows an admin block set after a Clear Block. ([#2529](https://github.com/elan-registry/registry/issues/2529))
- The car verification page shows the car's photos and location map. ([#2495](https://github.com/elan-registry/registry/issues/2495))

## Admin-Facing Changes

- Cron health checks now report send outages correctly. ([#2480](https://github.com/elan-registry/registry/issues/2480))
- The failed-send log names connection errors and plugin faults. ([#2466](https://github.com/elan-registry/registry/issues/2466))
- A block or bounce stays set on a new owner email after verification. ([#2411](https://github.com/elan-registry/registry/issues/2411))
- Clear Block tells the admin to check the logs when the email service fails, not to retry. ([#2488](https://github.com/elan-registry/registry/issues/2488))
- The Verification tab shows "Unavailable" when it cannot read a value, not a guessed status. ([#2457](https://github.com/elan-registry/registry/issues/2457))
- The transfer request alert says when the current owner got no notice of the request. ([#2476](https://github.com/elan-registry/registry/issues/2476))
- The privacy step rerun page says when a failed commit may have landed, not that it rolled back. ([#2515](https://github.com/elan-registry/registry/issues/2515))

## Issues Resolved

- [#2551](https://github.com/elan-registry/registry/issues/2551) — Migrate the test suite to PHPUnit 13.
- [#2515](https://github.com/elan-registry/registry/issues/2515) — A failed commit no longer skips the rollback, and the privacy step logs it as unknown state.
- [#2466](https://github.com/elan-registry/registry/issues/2466) — The verification failed-send log names connection errors and plugin precondition failures.
- [#2529](https://github.com/elan-registry/registry/issues/2529) — The paused-email notice names an admin block set after a Clear Block.
- [#2480](https://github.com/elan-registry/registry/issues/2480) — Cron runs report the correct outcome when Brevo or the send fails.
- [#2411](https://github.com/elan-registry/registry/issues/2411) — An admin email edit no longer lets a later verification clear the owner's block and bounce.
- [#2488](https://github.com/elan-registry/registry/issues/2488) — Clear Block reports a broken Brevo SDK instead of telling the admin to retry.
- [#2457](https://github.com/elan-registry/registry/issues/2457) — The Verification tab shows fault states as faults, not guessed values.
- [#2476](https://github.com/elan-registry/registry/issues/2476) — The transfer admin alert says when policy skipped the owner's notice.
- [#2561](https://github.com/elan-registry/registry/issues/2561) — The cleanup ledger no longer lists items that only guard core UserSpice and database calls.
- [#2495](https://github.com/elan-registry/registry/issues/2495) — The verification page shows the car photos and location map, as the details page does.
