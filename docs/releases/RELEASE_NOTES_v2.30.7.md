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

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- The verification queue shows a "No profile" chip for an owner who has no profile, and sends no email to that owner. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The admin user form shows the owner-level flags (opted out, blocked, bounced) and has no per-car flag columns. ([#2101](https://github.com/elan-registry/registry/issues/2101))
- The soft-bounce count starts again at zero after Clear Bounced. ([#2101](https://github.com/elan-registry/registry/issues/2101), absorbs [#2320](https://github.com/elan-registry/registry/issues/2320))

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
- WIP: [#2339](https://github.com/elan-registry/registry/issues/2339) — Stop rollbacks in four more `CarVerificationManager` methods from deleting log entries.
- WIP: [#2356](https://github.com/elan-registry/registry/issues/2356) — Show each owner's true email state and clear the flags on a confirmed email change.
- WIP: [#2357](https://github.com/elan-registry/registry/issues/2357) — Replace Clear Suppression with Clear Block and Clear Opt-out in the admin tools.
- WIP: [#2374](https://github.com/elan-registry/registry/issues/2374) — Stop the Brevo webhook from dropping verification events when only send-side checks fail.
- WIP: [#2378](https://github.com/elan-registry/registry/issues/2378) — Stop new-account sign-up from setting a remember-me token.
