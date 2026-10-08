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

- One-sentence description of what changed. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Issues Resolved

- WIP: [#1875](https://github.com/elan-registry/registry/issues/1875) — Monitor the verification cron jobs with healthchecks.io.
- WIP: [#2088](https://github.com/elan-registry/registry/issues/2088) — Register the production Brevo webhook and turn on the verification switch.
- WIP: [#2184](https://github.com/elan-registry/registry/issues/2184) — Send all mail through a project-owned, tagged Brevo mailer.
- WIP: [#2253](https://github.com/elan-registry/registry/issues/2253) — Upgrade UserSpice from 6.1.4 to 6.1.7.
- WIP: [#2321](https://github.com/elan-registry/registry/issues/2321) — Close #2208 and retire the cleanup-ledger label.
- WIP: [#2326](https://github.com/elan-registry/registry/issues/2326) — Stop `.htaccess` from writing a web-readable stack-trace file into the docroot.
