# Elan Registry v2.30.8 Release Notes

**Release Date:** October 19, 2026
**Type:** Patch Release - Delivery Events From All Mail

A bounce or complaint on any email that the registry sends now updates the
owner's profile, and owner-to-owner and admin contact obey it. The registry
keeps delivery records per owner, removes them on a fixed schedule, and
deletes them with the account. Admins see the email state of each owner.

## User-Facing Changes

- A bounce or spam complaint on any registry email to you, including password-reset and owner-to-owner contact email, now updates your profile, where
  before only verification email did.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The privacy page now states that the registry records the delivery status of all its email to your address, how long it keeps these records, and what
  stays after you delete your account.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- Owner-to-owner contact email is not sent to an owner who has no profile, whose address bounced, who opted out, or whom an admin blocked, and the sender
  sees the same response as for a sent message.
  ([#2360](https://github.com/elan-registry/registry/issues/2360))
- An admin message follows the same rules as owner-to-owner contact email, except that an admin can still write to a blocked owner.
  ([#2360](https://github.com/elan-registry/registry/issues/2360))
- Opting out of registry email now covers all email except account mail (password reset, sign-in, email change), where before it covered only
  verification email.
  ([#2360](https://github.com/elan-registry/registry/issues/2360))
- Resuming delivery of paused email now shows the correct status when logging fails, where before a logging failure caused an unexpected server error.
  ([#2406](https://github.com/elan-registry/registry/issues/2406))
- When our email provider flagged an owner's address, the account settings page now links "contact an admin" to the owner contact page.
  ([#2393](https://github.com/elan-registry/registry/issues/2393))
- An owner whom a Brevo admin blocked now sees "an administrator blocked this address".
  ([#2414](https://github.com/elan-registry/registry/issues/2414))
- The maps use MapLibre GL JS 6.14.0, and your browser loads the new map files after each release instead of cached old copies.
  ([#2454](https://github.com/elan-registry/registry/issues/2454))

## Admin-Facing Changes

- The registry matches each Brevo delivery event to its owner by message ID, and it ignores an event for a message that this environment did not send,
  including test-site mail on the shared Brevo account, with no flag and no row.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The verification switch now stops only the automatic send batch, and the webhook and both Brevo cron jobs still write delivery events and flags while
  the switch is off.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- To stop all event writes in an incident, do these three steps, and run the reconciliation within 48 hours of step 1 after the incident.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
  1. Set `BREVO_WEBHOOK_TOKEN` to an empty value. The webhook then refuses every call with 401, and Brevo retries.
  2. Set `er_cron_job_runs.enabled = 0` for `brevo_reconciliation` and `brevo_suppression_sync`. This also stops the retention prune.
  3. Do not use Run Now, script 27 or script 28, because these paths read neither the switch nor `enabled`.
- The nightly suppression import reads only the last 48 hours of Brevo's blocklist, so an older entry needs the full backfill (script 28), which sets
  `email_blocked` again, for example when an owner changes from a blocked address A to B and later back to A.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The suppression import matches one user by address, so a blocked address of a user with no car now sets `email_blocked`, and Brevo's `adminBlocked` now
  sets the cause `blocklist` instead of `unsubscribed`.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The reconciliation job fetches each event type separately and records each event under the webhook's event name, so a reconciled hard bounce now sets
  the owner's bounce flag.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The Status chip of a car ignores a `delivered` event of mail that is not a verification send, such as a password reset, and a hard bounce of that mail
  still shows as Bounced.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The webhook accepts eight events (`delivered`, `soft_bounce`, `hard_bounce`, `blocked`, `invalid`, `invalid_email`, `spam` and `unsubscribed`) and
  ignores other events such as `opened`, and the webhook registration script no longer subscribes to `opened`.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The unmatched recipient counter counts only verification-tagged events of the registry's own mail, and the suppression import no longer adds to it.
  ([#2359](https://github.com/elan-registry/registry/issues/2359))
- The registry stores each delivery event once for each owner, and a car's Status chip on the Verification dashboard shows the events for that car's
  verification email and a hard bounce or block of any other mail to the owner.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- A deleted car, a merged car, or a transferred car no longer loses or mixes up the owner's delivery events, and account deletion removes them.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- The nightly prune keeps terminal events for 24 months, and `soft_bounce`, `delivered` and `sent` rows for 90 days, except each car's latest `sent` row
  for its current owner, whose address it blanks only when the row is older than 90 days.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- The prune, the reconciliation and the suppression import now run with the verification switch off, and among the cron jobs only the send batch follows
  the switch.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- Maintenance script 27 shows "owners matched", and the new read-only script 29 previews the event re-key.
  ([#2358](https://github.com/elan-registry/registry/issues/2358))
- When an admin contact message is not sent, the admin or editor sees why (the bounce date, the opt-out, or the missing profile), and one blocking flag
  on any user of a shared address stops the message.
  ([#2360](https://github.com/elan-registry/registry/issues/2360))
- Contact log lines now name user IDs and flag names, and they no longer hold an email address.
  ([#2360](https://github.com/elan-registry/registry/issues/2360))
- A stored profile email flag with a value other than 0 or 1 no longer makes Brevo resend the event, because the webhook now returns 400 to tell Brevo
  not to retry.
  ([#2393](https://github.com/elan-registry/registry/issues/2393))
- The suppression sync job counts an owner whose flag step did not run (no profile row, an old address, or an event before a guard date) as skipped, not
  matched.
  ([#2393](https://github.com/elan-registry/registry/issues/2393))
- The Verification tab shows Bounced, Blocked and Opted out as three summary cards, and each card counts owners, so an owner with several cars counts
  once.
  ([#2361](https://github.com/elan-registry/registry/issues/2361))
- The filter pills Blocked (`?status=blocked`) and Opted out (`?status=opted_out`) replace Suppressed, and an old `?status=suppressed` link shows all
  rows.
  ([#2361](https://github.com/elan-registry/registry/issues/2361))
- The Status chip of a car shows "Blocked" for a blocked owner, where before it showed "Brevo complaint".
  ([#2361](https://github.com/elan-registry/registry/issues/2361))
- The Email on file row on the car details page shows "Opted out", "Bounced" and "Blocked" as separate states, each with its date and its own tooltip,
  and only admins and editors see the row.
  ([#2361](https://github.com/elan-registry/registry/issues/2361))
- The System Status & Feature Switch panel is always directly above Automatic Sending, and it opens when a status needs attention.
  ([#2364](https://github.com/elan-registry/registry/issues/2364))
- When the Brevo status check fails, the Brevo row shows "Not ready" and the reason "The Brevo status could not be checked."
  ([#2330](https://github.com/elan-registry/registry/issues/2330))

## Issues Resolved

- [#2165](https://github.com/elan-registry/registry/issues/2165) — Add `IntegrationTestCase::invokeEndpoint()` and HTTP-level tests for the owner-contact
  IDOR check and the admin AJAX CSRF check, and move the `save.php` ownership and `statistics.php` validation tests to
  [#2362](https://github.com/elan-registry/registry/issues/2362).
- [#2330](https://github.com/elan-registry/registry/issues/2330) — Move the Verification tab status and batch cap into classes, with new tests.
- [#2358](https://github.com/elan-registry/registry/issues/2358) — Key email events by user and keep them through car delete, merge, and account deletion.
- [#2359](https://github.com/elan-registry/registry/issues/2359) — Apply delivery events from all registry mail to the owner profile, matched by
  message ID.
- [#2360](https://github.com/elan-registry/registry/issues/2360) — Apply the profile send policy to owner-to-owner and admin contact.
- [#2361](https://github.com/elan-registry/registry/issues/2361) — Show the email state of each owner (Bounced, Blocked, Opted out) to admins and editors.
- [#2364](https://github.com/elan-registry/registry/issues/2364) — Keep the Status panel in one place on the Verification tab.
- [#2386](https://github.com/elan-registry/registry/issues/2386) — Correct the EMAIL_SYSTEM.md Status Indicators list for the webhook and
  reconciliation rows.
- [#2393](https://github.com/elan-registry/registry/issues/2393) — Fix the error handling in the deliverability event path that the #2101 review found.
- [#2406](https://github.com/elan-registry/registry/issues/2406) — Stop a failed owner resume from causing a 500 error.
- [#2414](https://github.com/elan-registry/registry/issues/2414) — Show "an administrator blocked this address" to an owner whom a Brevo admin blocked.
- [#2454](https://github.com/elan-registry/registry/issues/2454) — Upgrade maplibre-gl to 6.14.0, and make browsers load the new map files after each
  release.
