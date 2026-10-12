# Elan Registry v2.30.7 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Owner Profile Is the Record

Each owner's profile is the one record of whether their address can receive
mail. An owner sees the true reason on their page and can clear a block by
changing their address. Admins can clear a block or an opt-out on request,
and the public verify page encodes its codes safely.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- One-sentence description of what changed and its benefit to users. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- One-sentence description of what changed. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Issues Resolved

- WIP: [#2101](https://github.com/elan-registry/registry/issues/2101) — Make the owner profile the system of record for email deliverability.
- WIP: [#2167](https://github.com/elan-registry/registry/issues/2167) — Test migration `up()`, `down()`, and idempotency in a throwaway schema, not the shared one.
- WIP: [#2205](https://github.com/elan-registry/registry/issues/2205) — Encode the vericode in `verify_car.php` redirects and mark the verify forms' no-CSRF design.
- WIP: [#2339](https://github.com/elan-registry/registry/issues/2339) — Stop rollbacks in four more `CarVerificationManager` methods from deleting log entries.
- WIP: [#2356](https://github.com/elan-registry/registry/issues/2356) — Show each owner's true email state and clear the flags on a confirmed email change.
- WIP: [#2357](https://github.com/elan-registry/registry/issues/2357) — Replace Clear Suppression with Clear Block and Clear Opt-out in the admin tools.
- WIP: [#2374](https://github.com/elan-registry/registry/issues/2374) — Stop the Brevo webhook from dropping verification events when only send-side checks fail.
- WIP: [#2378](https://github.com/elan-registry/registry/issues/2378) — Stop new-account sign-up from setting a remember-me token.
