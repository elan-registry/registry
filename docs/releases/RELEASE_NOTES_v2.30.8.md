# Elan Registry v2.30.8 Release Notes

**Release Date:** October 13, 2026 (draft)
**Type:** Patch Release - Delivery Events From All Mail

A bounce or complaint on any email that the registry sends now updates the
owner's profile, and owner-to-owner and admin contact obey it. The registry
keeps delivery records per owner, removes them on a fixed schedule, and
deletes them with the account. Admins see the email state of each owner.

## User-Facing Changes

- A bounce or a spam complaint on any email that the registry sends to you now updates your profile. This includes password-reset email and
  owner-to-owner contact email. Before, only verification email did this.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The privacy page now states that the registry records the delivery status of all its email to your address, how long it keeps these records, and what
  stays after you delete your account.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))

## Admin-Facing Changes

- The registry matches each Brevo delivery event to its owner by the message ID of the email. An event for a message that this environment did not send
  is ignored. This includes mail of the test site on the shared Brevo account. It sets no flag and writes no row.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The verification switch now stops only the automatic send batch. The webhook and both Brevo cron jobs still write delivery events and flags while the
  switch is off.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- To stop all event writes in an incident, do three steps. First, set `BREVO_WEBHOOK_TOKEN` to an empty value. The webhook then refuses every call with
  401, and Brevo retries. Second, set `er_cron_job_runs.enabled = 0` for `brevo_reconciliation` and `brevo_suppression_sync`. This also stops the
  retention prune. Third, do not use Run Now, script 27 or script 28, because these paths read neither the switch nor `enabled`. After the incident, run
  the reconciliation within 48 hours of step 1.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The nightly suppression import reads only the last 48 hours of Brevo's blocklist. A blocklist entry that is older needs the full backfill (script 28).
  For example, an owner changes from a blocked address A to B and later back to A. The full backfill sets `email_blocked` again.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The suppression import matches one user by address, so a blocked address of a user with no car now sets `email_blocked`. Brevo's `adminBlocked` now
  sets the cause `blocklist`. Before, it set `unsubscribed`.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The reconciliation job fetches each event type separately, and it records each event under the webhook's event name. A reconciled hard bounce now sets
  the owner's bounce flag. Before, it did not.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The Status chip of a car ignores a `delivered` event of mail that is not a verification send, such as a password reset. A hard bounce of that mail
  still shows as Bounced.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The webhook accepts eight events: `delivered`, `soft_bounce`, `hard_bounce`, `blocked`, `invalid`, `invalid_email`, `spam` and `unsubscribed`. It
  ignores other events such as `opened`. The webhook registration script no longer subscribes to `opened`. Register the webhook again after the deploy.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The unmatched recipient counter counts only verification-tagged events of the registry's own mail. The suppression import no longer adds to it.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The registry stores each delivery event once for each owner. A car's Status chip on the Verification dashboard shows the events for that car's
  verification email, and a hard bounce or block of any other mail to the owner.
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
- [#2359](https://github.com/elan-registry/registry/issues/2359) — Apply delivery events from all registry mail to the owner profile, matched by
  message ID.
- WIP: [#2360](https://github.com/elan-registry/registry/issues/2360) — Apply the profile send policy to owner-to-owner and admin contact.
- WIP: [#2361](https://github.com/elan-registry/registry/issues/2361) — Show the email state of each owner (Bounced, Blocked, Opted out) to admins.
- WIP: [#2364](https://github.com/elan-registry/registry/issues/2364) — Keep the Status panel in one place on the Verification tab.
- WIP: [#2386](https://github.com/elan-registry/registry/issues/2386) — Correct the EMAIL_SYSTEM.md Status Indicators list for the webhook and
  reconciliation rows.
- WIP: [#2393](https://github.com/elan-registry/registry/issues/2393) — Fix the error handling in the deliverability event path that the #2101 review found.
- WIP: [#2406](https://github.com/elan-registry/registry/issues/2406) — Stop a failed owner resume from causing a 500 error.
- WIP: [#2414](https://github.com/elan-registry/registry/issues/2414) — Show "an administrator blocked this address" to an owner whom a Brevo admin blocked.
