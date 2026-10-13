# Elan Registry v2.30.7 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Owner Profile Is the Record

Each owner's profile is the one record of whether their address can receive
mail. An owner sees the true reason on their page and can clear a block by
changing their address. Admins can clear a block or an opt-out on request,
and the public verify page encodes its codes safely.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- A new car of an owner who opted out, was blocked, or bounced gets no verification email.
  ([#2101](https://github.com/elan-registry/registry/issues/2101), absorbs [#2319](https://github.com/elan-registry/registry/issues/2319))
- An owner who resumes emails while the email provider blocks the address keeps emails paused, and the page says so.
  ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The email notice on your account page now shows if email to your address is blocked, bounced, or opted out.
  ([#2356](https://github.com/elan-registry/registry/issues/2356), absorbs [#2318](https://github.com/elan-registry/registry/issues/2318),
  [#2320](https://github.com/elan-registry/registry/issues/2320), and the notice part of
  [#2319](https://github.com/elan-registry/registry/issues/2319))
- The email notice gives the reason for a block when the reason is known. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- The email notice shows the date of the latest bounce, not an old one. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- You see the email notice even if you have no car yet. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- The email notice does not show a block or bounce for an address you no longer use. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- When you confirm a new email address, the registry clears the bounce and the block for your old address.
  ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Your opt-out stays when you confirm a new email address. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Verification emails now go to the email address on your account. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- The verify page encodes the verification code in its redirects. ([#2205](https://github.com/elan-registry/registry/issues/2205))
- When the registry signs you in right after sign-up, it no longer keeps you signed in after you close the browser.
  ([#2378](https://github.com/elan-registry/registry/issues/2378))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- The verification queue shows a "No profile" chip for an owner who has no profile, and sends no email to that owner.
  ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The admin user form shows the owner-level flags (opted out, blocked, bounced) and has no per-car flag columns.
  ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The soft-bounce count starts again at zero after Clear Bounced. ([#2101](https://github.com/elan-registry/registry/issues/2101), absorbs
  [#2320](https://github.com/elan-registry/registry/issues/2320))
- The owner profile is now the system of record for the opted-out, blocked, and bounced flags. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The `cars` and `cars_hist` columns `email_bounced`, `email_bounced_address`, and `email_suppressed` are removed.
  ([#2101](https://github.com/elan-registry/registry/issues/2101))
- Flag changes no longer write `EMAIL BOUNCED`, `EMAIL SUPPRESSED`, `EMAIL BOUNCE CLEARED`, `EMAIL SUPPRESSION CLEARED`, or `SUPPRESSION CLEARED BY
  OWNER` rows to `cars_hist`. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The audit trail for a flag change is now the `logs` rows with `logtype` `EmailBounced`, which hold no email address and are removed when an admin
  clears the UserSpice logs. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- A restore of a deleted account sets the opt-out only, and does not restore a bounce from soft-bounce escalation or Mark Bounced.
  ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The next `blocked` event from Brevo sets the bounce again after a restore. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The verification queue, the eligible count, the admin user form, and the Email on file row ignore a block or bounce recorded for an address the
  owner no longer uses. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- An opt-out always counts, including for an address the owner no longer uses. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- The Sent report shows the account email address. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Brevo events and suppressed contacts now match the owner's account email, not the email copied onto a car.
  ([#2356](https://github.com/elan-registry/registry/issues/2356))
- A Brevo event for mail sent to an old car email before the deploy shows as an unmatched recipient.
  ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Brevo readiness no longer compares `override.php` with the bundled copy, so on plugin 1.8.0 or later sends and the webhook keep working when
  the two files differ. ([#2374](https://github.com/elan-registry/registry/issues/2374))
- An `override.php` kept from before 1.8.0 still drops the verification tag, and readiness does not detect it. Replace `override.php` with the
  bundled version after each plugin update. ([#2374](https://github.com/elan-registry/registry/issues/2374))
- A sign-up that cannot create the owner's profile row now writes a `DatabaseError` log row with the user id.
  ([#2393](https://github.com/elan-registry/registry/issues/2393), gap 5 only)

## Issues Resolved

- [#2101](https://github.com/elan-registry/registry/issues/2101) — Make the owner profile the system of record for email deliverability.
- [#2167](https://github.com/elan-registry/registry/issues/2167) — Test migration `up()`, `down()`, and idempotency in a throwaway schema, not the shared one.
- [#2205](https://github.com/elan-registry/registry/issues/2205) — Encode the vericode in `verify_car.php` redirects and mark the verify forms' no-CSRF design.
- [#2356](https://github.com/elan-registry/registry/issues/2356) — Show each owner's true email state and clear the flags on a confirmed email change
  (also closes [#2318](https://github.com/elan-registry/registry/issues/2318), [#2320](https://github.com/elan-registry/registry/issues/2320), and the
  notice part of [#2319](https://github.com/elan-registry/registry/issues/2319)).
- [#2374](https://github.com/elan-registry/registry/issues/2374) — Remove the `override.php` hash check from Brevo readiness, so a plugin update no
  longer stops sends or drops webhook events.
- [#2378](https://github.com/elan-registry/registry/issues/2378) — Stop new-account sign-up from setting a remember-me token (also logs a failed
  profile insert, gap 5 of [#2393](https://github.com/elan-registry/registry/issues/2393), which stays open).
- [#2403](https://github.com/elan-registry/registry/issues/2403) — Add a `/groom-tests` command that reviews the test suite for low-value tests.
- [#2416](https://github.com/elan-registry/registry/issues/2416) — Keep settled review items under a `Notes` heading, so a review with no open
  finding no longer stops the release.
