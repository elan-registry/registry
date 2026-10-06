# Elan Registry v2.30.5 Release Notes

**Release Date:** October 6, 2026
**Type:** Patch Release - Verification System Refresh: Owner & Admin Self-Service

An owner can correct their own details and turn verification email back on,
and an admin can see every car's verification state and act on it, with no
email between them. This release also upgrades UserSpice from 6.1.4 to 6.1.7.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- One-sentence description of what changed and its benefit to users. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- One-sentence description of what changed. ([#NNN](https://github.com/elan-registry/registry/issues/NNN))
- Editing a car no longer fails with "Please select Model" when the model list takes a moment to load — Save now waits for it. ([#2295](https://github.com/elan-registry/registry/issues/2295))

## Issues Resolved

- WIP: [#1891](https://github.com/elan-registry/registry/issues/1891) — Add an Owner Information section to the car edit form, and fix the form's tablet gutter and missing location-picker assets.
- WIP: [#1895](https://github.com/elan-registry/registry/issues/1895) — Let an owner clear their own email suppression from Account Settings, with a notice when verification email is paused.
- WIP: [#1896](https://github.com/elan-registry/registry/issues/1896) — Rebuild the admin Verification tab to the approved dashboard with summary cards, a filterable queue, and an activity log.
- WIP: [#2253](https://github.com/elan-registry/registry/issues/2253) — Upgrade UserSpice from 6.1.4 to 6.1.7.
- [#2295](https://github.com/elan-registry/registry/issues/2295) — Fix the car edit save failure "Please select Model" and the edit.php Playwright failures under Docker. Closes #2045.
