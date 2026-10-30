# Elan Registry v2.31.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Verification and Email Follow-ups

Admins can trust what the verification and email tools report, also when a
write, a commit, or a cron run fails. No owner gets mail that a block or
bounce should stop.

## User-Facing Changes

- [Filled in as issues complete.]

## Admin-Facing Changes

- [Filled in as issues complete.]

## Issues Resolved

- WIP: [#2551](https://github.com/elan-registry/registry/issues/2551) — Migrate the test suite to PHPUnit 13.
- WIP: [#2515](https://github.com/elan-registry/registry/issues/2515) — A failed car commit is logged as a commit failure, not as a rollback.
- WIP: [#2449](https://github.com/elan-registry/registry/issues/2449) — Rollback and logger calls no longer hide the first failure.
- WIP: [#2466](https://github.com/elan-registry/registry/issues/2466) — The verification failed-send log names connection errors and plugin precondition failures.
- WIP: [#2529](https://github.com/elan-registry/registry/issues/2529) — The paused-email notice names an admin block set after a Clear Block.
- WIP: [#2480](https://github.com/elan-registry/registry/issues/2480) — Cron runs report the correct outcome in four failure cases.
- WIP: [#2411](https://github.com/elan-registry/registry/issues/2411) — An admin email edit no longer lets a later verification clear the owner's block and bounce.
- WIP: [#2488](https://github.com/elan-registry/registry/issues/2488) — Clear Block reports a broken Brevo SDK instead of telling the admin to retry.
- WIP: [#2457](https://github.com/elan-registry/registry/issues/2457) — The Verification tab shows fault states as faults, not guessed values.
- WIP: [#2476](https://github.com/elan-registry/registry/issues/2476) — The transfer admin alert says when policy skipped the owner's notice.
- WIP: [#2495](https://github.com/elan-registry/registry/issues/2495) — The verification landing page shows the car as the details page does.
