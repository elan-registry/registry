# Elan Registry v2.31.1 Release Notes

**Release Date:** November 3, 2026
**Type:** Patch Release - Verification Tab Shows the Real Queue

The Verification tab shows the eligible cars in the order the batch sends them, with a link to each car and owner.
A failed read no longer shows as a default value or a success.

## Admin-Facing Changes

- From November to March, the Verification tab "cron last seen" and next-send times are no longer 1 hour off.
- Some times recorded from November to March before this release, for example log entries, stay 1 hour off. They are not corrected.
- If the switch, the batch size, or the cron state cannot be read, the Verification tab shows "Unavailable" for it.
  An unreadable batch size also hides the batch size Save form.
- If the switch or batch size cannot be read, auto-send sends nothing. A manual send with an unreadable batch size shows an error.
- An unreadable or missing job state row now says what failed and which next step to take.
- The All pill is gone. Eligible (default) and Next Queue use send order. Rows link car and owner.
- Verification cards name their unit, and the Bounced, Blocked and Opted out pills say "cars". Blocked shows a readable cause.
- The Email on file tooltips say which mail stops and which still sends. Help buttons are easier to tap.
- Recent Activity is a sortable table, newest first, with links to the car, owner, and user.

## Issues Resolved

- [#2589](https://github.com/elan-registry/registry/issues/2589) — PHP and MySQL use the same time zone all year.
- [#2572](https://github.com/elan-registry/registry/issues/2572) — The Save form no longer overwrites the batch size with 5 when the settings read fails.
- [#2587](https://github.com/elan-registry/registry/issues/2587) — The auto-send job sends no emails when the batch-size read fails.
- [#2573](https://github.com/elan-registry/registry/issues/2573) — The status panel gives a reason and a next step for an unreadable or missing job state.
- [#2599](https://github.com/elan-registry/registry/issues/2599) — Eligible and Next Queue list cars in send order. Queue rows link to the car and owner.
- [#2458](https://github.com/elan-registry/registry/issues/2458) — Verification tab labels, chip text, tooltips, and accessibility follow-ups.
- [#2496](https://github.com/elan-registry/registry/issues/2496) — Recent Activity shows as a sortable table with car, owner, and user links.
