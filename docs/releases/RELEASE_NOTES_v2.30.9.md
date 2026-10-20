
# Elan Registry v2.30.9 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Enable Verification System

An owner with a registered car gets a verification email in production that
works, and can use it to confirm their car. The cron jobs alert when they
stop, and the mail plugin's message log has a retention limit.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- One-sentence description of what changed and its benefit to users. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- The Verification tab and the send job use a batch size of at most 25, even when the database holds a larger value. ([#2363](https://github.com/elan-registry/registry/issues/2363))
- Elan Registry's own log lines for the Brevo event, suppression and verification-send paths no longer hold email
  addresses, but the sendinblue plugin's log row still can.
  ([#2420](https://github.com/elan-registry/registry/issues/2420), [#2425](https://github.com/elan-registry/registry/issues/2425))
- The Verification tab row has two new actions, Clear Block and Clear Opt-out. They replace Clear Suppression.
  Clear Block removes the address from Brevo's blocklist and clears the registry block.
  Clear Opt-out clears the opt-out only. Each action asks the admin to confirm that the owner asked for the change.
  Clear Block calls Brevo only when the blocked address is still the owner's address and no other account uses it.
  If Brevo fails, the block stays and the admin sees "Brevo did not remove the block. Try again later."
  On a site where Brevo is not set up, the block also stays, and the admin sees that Brevo is not set up.
  ([#2357](https://github.com/elan-registry/registry/issues/2357))
- The suppression import no longer sets a flag again after an admin Clear Block or Clear Bounced
  when Brevo's `blockedAt` is missing or not usable. The import also treats a `blockedAt` more than 5 minutes
  in the future as not usable. With no clear on record, the import still sets the flag.
  ([#2426](https://github.com/elan-registry/registry/issues/2426))
- Clearing the UserSpice logs (admin Logs page) removes the Clear Block times. The import guard for
  `email_blocked` then has no lower bound. The guard for `email_bounced` stays, because it reads a profile column.
  ([#2357](https://github.com/elan-registry/registry/issues/2357), [#2426](https://github.com/elan-registry/registry/issues/2426))

## Issues Resolved

- [#2363](https://github.com/elan-registry/registry/issues/2363) — Limit the verification batch size to the 25 maximum.
- [#2420](https://github.com/elan-registry/registry/issues/2420) — Stop the Brevo event and suppression paths from writing email addresses to the logs table.
- [#2425](https://github.com/elan-registry/registry/issues/2425) — Stop BrevoSuppressionSyncClient from logging the Brevo error message.
- [#2426](https://github.com/elan-registry/registry/issues/2426) — Stop a suppression import with no usable blockedAt from undoing an admin clear.
- [#2357](https://github.com/elan-registry/registry/issues/2357) — Replace Clear Suppression with Clear Block and Clear Opt-out.
- WIP: [#2460](https://github.com/elan-registry/registry/issues/2460) — Stop soft bounces on owner-to-owner contact mail from setting email_bounced.
- WIP: [#2463](https://github.com/elan-registry/registry/issues/2463) — Make the transfer notification mail obey the email opt-out.
- WIP: [#2384](https://github.com/elan-registry/registry/issues/2384) — Purge plg_sendinblue_messages rows older than 90 days.
- WIP: [#1875](https://github.com/elan-registry/registry/issues/1875) — Monitor the verification cron jobs with healthchecks.io.
- WIP: [#2088](https://github.com/elan-registry/registry/issues/2088) — Register the production Brevo webhook and turn on the verification switch.
