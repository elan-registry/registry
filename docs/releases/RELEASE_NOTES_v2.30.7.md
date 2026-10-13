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
  ([#2101](https://github.com/elan-registry/registry/issues/2101), absorbs
  [#2319](https://github.com/elan-registry/registry/issues/2319))
- An owner who resumes emails while the email provider blocks the address keeps emails paused, and the page says so. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The email notice on your account page now shows the true state of your email. It says if email to your address is blocked, bounced, or opted out.
  For a block, it gives the reason when it is known. It shows the date of the latest bounce, not an old one. You see it even if you have no car yet. A
  block or bounce for an address you no longer use does not show. ([#2356](https://github.com/elan-registry/registry/issues/2356), absorbs
  [#2318](https://github.com/elan-registry/registry/issues/2318), [#2320](https://github.com/elan-registry/registry/issues/2320) and the notice part
  of [#2319](https://github.com/elan-registry/registry/issues/2319))
- When you confirm a new email address, the registry clears the bounce and the block for your old address. Your opt-out stays.
  ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Verification emails now go to the email address on your account. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- When the registry signs you in right after sign-up, it no longer keeps you signed in after you close the browser.
  ([#2378](https://github.com/elan-registry/registry/issues/2378))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- The verification queue shows a "No profile" chip for an owner who has no profile, and sends no email to that owner. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The admin user form shows the owner-level flags (opted out, blocked, bounced) and has no per-car flag columns. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The soft-bounce count starts again at zero after Clear Bounced. ([#2101](https://github.com/elan-registry/registry/issues/2101), absorbs [#2320](https://github.com/elan-registry/registry/issues/2320))
- The verification queue, the eligible count, the admin user form, and the Email on file row ignore a block or bounce that was recorded for an address
  the owner no longer uses. An opt-out always counts. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Verification emails go to the owner's account email, not the email copied onto the car. The Sent report shows that address.
  ([#2356](https://github.com/elan-registry/registry/issues/2356))
- Brevo events and suppressed contacts now match the owner's account email, not the email copied onto a car. An event for mail sent before
  the deploy to an old car email shows as an unmatched recipient. ([#2356](https://github.com/elan-registry/registry/issues/2356))
- On plugin 1.8.0 or later, verification sends and the Brevo webhook keep working when `override.php` differs from the bundled copy.
  Brevo readiness no longer compares the two files. An `override.php` kept from before 1.8.0 still drops the verification tag, and readiness
  does not detect it. Replace `override.php` with the bundled version after each plugin update.
  ([#2374](https://github.com/elan-registry/registry/issues/2374))
- A sign-up that cannot create the owner's profile row now writes a `DatabaseError` log row with the user id. Before, it failed with no log.
  ([#2393](https://github.com/elan-registry/registry/issues/2393), gap 5 only)

## Data Model, Deploy, and Rollback Notes

The owner profile (`profiles` table) is now the system of record for email
deliverability: opted out, blocked, and bounced. The `cars` columns
`email_bounced`, `email_bounced_address`, and `email_suppressed` are removed.
The matching `cars_hist` columns are removed too.

No v2.30 release has reached production. The migration has no production flag
data to move.

### Deploy

- Take a database backup before you deploy.
- Deploy the migration and the code together. The code reads the profile, and
  the migration drops the `cars` columns.
- Run the migration with the Brevo webhook and the cron jobs off.
- The migration does not copy the flags of a car whose `user_id` has no
  `users` row, because no profile can hold them. Before you deploy, count
  these cars. A result of 0 means that no flag is lost:
  `SELECT COUNT(*) FROM cars c LEFT JOIN users u ON u.id = c.user_id WHERE u.id IS NULL AND (c.email_bounced = 1 OR c.email_suppressed = 1);`
- If the migration fails, do not clear a flag between the failed run and its
  re-run. The re-run repeats the data step and can overwrite the clear.

### Rollback

- A code rollback restores the pre-deploy database backup, then pushes the
  previous release. `composer migrate:rollback` cannot run on the server after
  a deploy, and the `down()` methods do not restore every value.
- A flag or profile change made after the deploy is lost.
- The steps are in `docs/development/DEPLOYMENT.md`, section "Roll Back This
  Migration".

### Audit Trail

- The code no longer writes the `EMAIL BOUNCED`, `EMAIL SUPPRESSED`,
  `EMAIL BOUNCE CLEARED`, `EMAIL SUPPRESSION CLEARED`, and
  `SUPPRESSION CLEARED BY OWNER` rows in `cars_hist`.
- The audit trail for a flag change is now the `logs` rows. Each row has the
  category `EMAIL BOUNCED` and holds no email address. When an admin clears the
  UserSpice logs, the audit trail is removed.

### Account Restore

A restore of a deleted account loses a bounce that came from soft-bounce
escalation or Mark Bounced. The restore sets the opt-out only. Brevo keeps its
own block on the address after deletion. A send to a blocked address returns a
`blocked` event, and that event sets `email_bounced` again.

## Issues Resolved

- [#2101](https://github.com/elan-registry/registry/issues/2101) — Make the owner profile the system of record for email deliverability.
- [#2167](https://github.com/elan-registry/registry/issues/2167) — Test migration `up()`, `down()`, and idempotency in a throwaway schema, not the shared one.
- [#2205](https://github.com/elan-registry/registry/issues/2205) — Encode the vericode in `verify_car.php` redirects and mark the verify forms' no-CSRF design.
- [#2356](https://github.com/elan-registry/registry/issues/2356) — Show each owner's true email state and clear the flags on a confirmed email
  change. Also closes #2318 and #2320, and the notice part of #2319.
- [#2374](https://github.com/elan-registry/registry/issues/2374) — Remove the `override.php` hash check from Brevo readiness,
  so a plugin update no longer stops sends or drops webhook events.
- [#2378](https://github.com/elan-registry/registry/issues/2378) — Stop new-account sign-up from setting a remember-me token.
  Also logs a failed profile insert at sign-up (gap 5 of [#2393](https://github.com/elan-registry/registry/issues/2393), which stays open).
- [#2403](https://github.com/elan-registry/registry/issues/2403) — Add a `/groom-tests` command that reviews the test suite for low-value tests.
