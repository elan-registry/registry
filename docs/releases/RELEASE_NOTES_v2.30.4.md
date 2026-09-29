# Elan Registry v2.30.4 Release Notes

**Release Date:** TBD (started 2026-09-28)
**Type:** Patch Release - Verification System Refresh: Freshness Surfaced

Anyone researching a car can see how fresh its record is: Verified and Sold
badges with dates, a freshness vector on the statistics page, and engagement
touches on the verification email itself.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- To be filled in as issues complete.

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- To be filled in as issues complete.

## Issues Resolved

- WIP: [#1929](https://github.com/elan-registry/registry/issues/1929) — Define which owner actions reset `owner_last_updated` before freshness is surfaced
- WIP: [#2189](https://github.com/elan-registry/registry/issues/2189) — Failed-login log no longer stores the submitted username verbatim (a password was stored in plain text)
- WIP: [#1897](https://github.com/elan-registry/registry/issues/1897) — Verified status row (and admin-only Email on file row) on the car details page
- WIP: [#1900](https://github.com/elan-registry/registry/issues/1900) — Sold and Verified badges on the account page, cars list, and car details page
- WIP: [#1898](https://github.com/elan-registry/registry/issues/1898) — Verified vector on the statistics page's data-completeness radar chart
- WIP: [#1894](https://github.com/elan-registry/registry/issues/1894) — Verification email photo thumbnail at 300px with descriptive alt text, and a highlighted fallback when a car has no photo (includes the remaining gap from #1892)
- WIP: [#2147](https://github.com/elan-registry/registry/issues/2147) — Stop displaying Brevo's rewritten tracking URL in the four auth email templates
- WIP: [#2150](https://github.com/elan-registry/registry/issues/2150) — Close three test-coverage gaps in the verification send pipeline
- WIP: [#2250](https://github.com/elan-registry/registry/issues/2250) — `render-deploy-sheet.sh` no longer reports false migration, trigger and new-page conditions

## Carried from the Dev Environment milestone

The Dev Environment milestone merged to `main` with no release of its own. If
v2.30.4 is the first versioned release after it, list its production-affecting
changes here and add its deploy checks to this release's deploy sheet. See
`docs/releases/RELEASE_NOTES_Dev-Environment.md`, "Production-affecting
changes". If `v2.30.1.1` releases first, it carries them, and this section is
removed.
