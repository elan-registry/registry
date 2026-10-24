# Elan Registry v2.30.10 Release Notes

**Release Date:** October 22, 2026 (draft)
**Type:** Patch Release - Post Verification System Cleanup and Bugs

An admin can trust what the Verification tab and email-block tools show, and
an owner's address no longer stays behind after account deletion or in logs.

## User-Facing Changes

- Pop-up messages show special characters in a name or a link as plain
  text. Before, an encoded name or a crafted link could add HTML, such as
  an image or a link, to a message. The site security policy blocked
  scripts in that HTML.

## Admin-Facing Changes

- When an admin deletes an account, the registry now also deletes the
  plugin mail records sent to the owner's current address and to a
  pending new address. Before, these records stayed when the profile
  held an old address.
- The Brevo suppression sync now sets a block or a bounce flag for an
  entry with no usable date when the only admin clear is from before the
  owner's current address. Before, any old clear stopped that entry for
  all later runs.
- A new maintenance script, **Rerun Deletion Privacy Step**, finishes the
  privacy step for one deleted user ID. Run it when the log shows
  "CRITICAL: privacy step failed". See `docs/development/FIX_SCRIPTS.md`.
- Deploy steps for the deletion change, on test and then on production:
  1. Run `composer migrate`. The migration adds the
     `er_deleted_user_addresses` table and the
     `users_delete_capture_addresses` trigger. The database user needs the
     TRIGGER privilege. If binary logging is on, the user also needs
     `log_bin_trust_function_creators=1`, or SUPER or
     SYSTEM_VARIABLES_ADMIN. Without the trigger, the migration fails.
  2. Open `30-Rerun-Deletion-Privacy-Step.php` once as an admin, so
     UserSpice creates its `pages` row.
  3. Run `21-Fix-Page-Permissions.php` from the Maintenance page, so the
     new script page gets its permission rows.

## Issues Resolved

- [#2439](https://github.com/elan-registry/registry/issues/2439) —
  Escape the decoded text in pop-up messages, so an encoded name or `?err=`/`?msg=` value cannot add HTML.
- WIP: [#2487](https://github.com/elan-registry/registry/issues/2487) — Remove the remaining raw exception messages from log rows.
- [#2419](https://github.com/elan-registry/registry/issues/2419) —
  Delete plugin mail rows for the current and pending address at account deletion, and add a manual rerun of the privacy step.
- WIP: [#2384](https://github.com/elan-registry/registry/issues/2384) — Purge `plg_sendinblue_messages` rows older than 90 days.
- [#2482](https://github.com/elan-registry/registry/issues/2482) —
  Count only a clear after `email_address_since` when the suppression import has no usable date.
- WIP: [#2461](https://github.com/elan-registry/registry/issues/2461) —
  Use one order for opted out, blocked and bounced in the send report, the Status chip and the notice.
- WIP: [#2498](https://github.com/elan-registry/registry/issues/2498) — Show the owner's user ID in the Verification Queue.
- WIP: [#2501](https://github.com/elan-registry/registry/issues/2501) — Accept Brevo blocked-at dates that arrive 2 hours ahead in the suppression sync.
- WIP: [#2502](https://github.com/elan-registry/registry/issues/2502) —
  Stop the suppression sync from adding a second event row for an owner that is already flagged.
- WIP: [#2499](https://github.com/elan-registry/registry/issues/2499) — Show an opt-out as a warning in the paused-email notice.
- WIP: [#2500](https://github.com/elan-registry/registry/issues/2500) — Show every block cause in the paused-email notice.
- WIP: [#2497](https://github.com/elan-registry/registry/issues/2497) — Thank the owner after a Sold report, not tell them the car is already sold.
- WIP: [#2043](https://github.com/elan-registry/registry/issues/2043) — Show a visible error when the location gate blocks the join form.
- WIP: [#2474](https://github.com/elan-registry/registry/issues/2474) — Retire the Backlog milestone.
