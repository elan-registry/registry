# Elan Registry v2.30.6 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Enable Verification System

An owner with a registered car gets a verification email in production that
works, and can use it to confirm their car.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- One-sentence description of what changed and its benefit to users. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- **Deploy step:** update the sendinblue plugin to 1.8.0 or later, run its
  migrations, activate the override, and set "Tag untagged mail" to "Calling
  file name". Below 1.8.0, Brevo reports not ready: verification cannot be
  turned on and sends nothing. If the switch is already on, the Brevo webhook
  drops events and logs a `VerificationConfigWarning`. See
  [DEPLOYMENT.md](../development/DEPLOYMENT.md#brevo-sendinblue-plugin).
  ([#2184](https://github.com/elan-registry/registry/issues/2184))
- The admin Verification tab and the enable toggle now show why Brevo is not ready.
  ([#2184](https://github.com/elan-registry/registry/issues/2184))
- All mail now carries a Brevo tag. Verification mail has `car_verification`.
  Other mail has the name of the calling file, for example `forgot_password`.
  The nightly reconciliation job reports a higher `ignoredByTag` count,
  because it ignores all other tags.
  ([#2184](https://github.com/elan-registry/registry/issues/2184))

## Issues Resolved

- WIP: [#1875](https://github.com/elan-registry/registry/issues/1875) — Monitor the verification cron jobs with healthchecks.io.
- WIP: [#2088](https://github.com/elan-registry/registry/issues/2088) — Register the production Brevo webhook and turn on the verification switch.
- [#2184](https://github.com/elan-registry/registry/issues/2184) — Tag all mail sent through Brevo, and require sendinblue plugin 1.8.0 or later.
- WIP: [#2253](https://github.com/elan-registry/registry/issues/2253) — Upgrade UserSpice from 6.1.4 to 6.1.7.
- [#2321](https://github.com/elan-registry/registry/issues/2321) — Close #2208, move its last items to `CLEANUP_LEDGER.md`, and delete the `cleanup-ledger` label.
- [#2326](https://github.com/elan-registry/registry/issues/2326) — Stop `.htaccess` from writing a web-readable error log into the docroot, and deny that filename.
