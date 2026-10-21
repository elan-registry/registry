
# Elan Registry v2.30.9 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Enable Verification System

An owner with a registered car gets a verification email in production that
works, and can use it to confirm their car. The cron jobs alert when they
stop, and the privacy policy describes the email and monitoring services.

## User-Facing Changes

- An owner who opted out of registry email, or whose address bounced or is blocked, no longer gets car transfer notices, and the transfer request still completes.
  ([#2463](https://github.com/elan-registry/registry/issues/2463))
- An owner with a registered car gets a car-verification request email in production, with links to verify the car, report it sold, or opt out.
  ([#2088](https://github.com/elan-registry/registry/issues/2088))
- The privacy policy now says that car-verification request emails go through Brevo and
  that the email service log keeps a recipient address for no more than 90 days.
  ([#2088](https://github.com/elan-registry/registry/issues/2088))
- The privacy policy names healthchecks.io as a processor that receives the scheduled task name and run status, and no personal data.
  ([#1875](https://github.com/elan-registry/registry/issues/1875))

## Admin-Facing Changes

- The Verification tab and the send job use a batch size of at most 25, even when the database holds a larger value.
  ([#2363](https://github.com/elan-registry/registry/issues/2363))
- Elan Registry's own log lines for the Brevo event, suppression and verification-send paths no longer hold email addresses.
  ([#2420](https://github.com/elan-registry/registry/issues/2420), [#2425](https://github.com/elan-registry/registry/issues/2425))
- The Verification tab row has two new actions, Clear Block and Clear Opt-out, that replace Clear Suppression.
  ([#2357](https://github.com/elan-registry/registry/issues/2357))
- Clear Block removes the address from Brevo's blocklist, and the block stays if Brevo fails or is not set up.
  ([#2357](https://github.com/elan-registry/registry/issues/2357))
- The suppression import no longer sets a flag again after an admin Clear Block or Clear Bounced
  when Brevo's `blockedAt` is missing, unusable, or more than 5 minutes in the future.
  ([#2426](https://github.com/elan-registry/registry/issues/2426))
- Clearing the UserSpice logs removes the Clear Block times, so the import guard for `email_blocked` then has no lower bound.
  ([#2357](https://github.com/elan-registry/registry/issues/2357), [#2426](https://github.com/elan-registry/registry/issues/2426))
- Soft bounces on owner-to-owner contact mail no longer set `email_bounced` on the recipient, but hard bounces, blocks and spam complaints still set their flags.
  ([#2460](https://github.com/elan-registry/registry/issues/2460))
- The transfer email service no longer writes email addresses to its log lines. ([#2463](https://github.com/elan-registry/registry/issues/2463))
- The verification send job and the Brevo event reconciliation job ping healthchecks.io after each scheduled run, and a missed or failed run sends an alert to Discord.
  ([#1875](https://github.com/elan-registry/registry/issues/1875))
- A "Run now" click does not ping healthchecks.io. ([#1875](https://github.com/elan-registry/registry/issues/1875))
- Production has its own Brevo webhook that records delivery, bounce, block and spam events for real member email.
  ([#2088](https://github.com/elan-registry/registry/issues/2088))
- The registration script registers the production receiver only with the new `--allow-prod` flag.
  ([#2088](https://github.com/elan-registry/registry/issues/2088))

## Issues Resolved

- [#2363](https://github.com/elan-registry/registry/issues/2363) — Limit the verification batch size to the 25 maximum.
- [#2420](https://github.com/elan-registry/registry/issues/2420) — Stop the Brevo event and suppression paths from writing email addresses to the logs table.
- [#2425](https://github.com/elan-registry/registry/issues/2425) — Stop BrevoSuppressionSyncClient from logging the Brevo error message.
- [#2426](https://github.com/elan-registry/registry/issues/2426) — Stop a suppression import with no usable blockedAt from undoing an admin clear.
- [#2357](https://github.com/elan-registry/registry/issues/2357) — Replace Clear Suppression with Clear Block and Clear Opt-out.
- [#2460](https://github.com/elan-registry/registry/issues/2460) — Stop soft bounces on owner-to-owner contact mail from setting email_bounced.
- [#2463](https://github.com/elan-registry/registry/issues/2463) — Make the transfer notification mail obey the email opt-out.
- [#1875](https://github.com/elan-registry/registry/issues/1875) — Monitor the verification cron jobs with healthchecks.io.
- [#2088](https://github.com/elan-registry/registry/issues/2088) — Register the production Brevo webhook and turn on the verification switch.
