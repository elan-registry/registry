# Elan Registry v2.30.4 Release Notes

**Release Date:** TBD (started 2026-09-28)
**Type:** Patch Release - Verification System Refresh: Freshness Surfaced

Anyone researching a car can see how fresh its record is: Verified and Sold
badges with dates, a freshness vector on the statistics page, and engagement
touches on the verification email itself.

## User-Facing Changes

Changes visible to public registry visitors (car listings, owner pages, search, etc.). One sentence each.

- Removing a photo from your own car now counts as keeping its record current, the same as editing it or uploading a
  photo. ([#1929](https://github.com/elan-registry/registry/issues/1929))
- The statistics page's Data Completeness chart and Quality Metrics list show a "Verified (12 mo)" figure: the share of
  cars verified or updated by their owner in the last 12 months. ([#1898](https://github.com/elan-registry/registry/issues/1898))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- To be filled in as issues complete.

## Issues Resolved

- [#1929](https://github.com/elan-registry/registry/issues/1929) — `CLASSES.md` lists which owner actions reset
  `owner_last_updated` (a car's freshness date), each with a covering test.
- [#2189](https://github.com/elan-registry/registry/issues/2189) — Failed-login log no longer stores the submitted
  username verbatim (a password was stored in plain text)
- [#2259](https://github.com/elan-registry/registry/issues/2259) — Workflow commands and agents use the model each step
  needs, and repeated review work is removed (developer workflow only, no site change)
- WIP: [#1897](https://github.com/elan-registry/registry/issues/1897) — Verified status row (and admin-only Email on file row) on the car details page
- WIP: [#1900](https://github.com/elan-registry/registry/issues/1900) — Sold and Verified badges on the account page, cars list, and car details page
- [#1898](https://github.com/elan-registry/registry/issues/1898) — Verified vector on the statistics page's
  data-completeness radar chart. `verified_cars` now uses the shared freshness rule, not a count of non-null
  `last_verified`. The chart no longer shows `NaN` when the registry has no cars.
- WIP: [#1894](https://github.com/elan-registry/registry/issues/1894) — Verification email photo thumbnail at 300px with
  descriptive alt text, and a highlighted fallback when a car has no photo (includes the remaining gap from #1892)
- WIP: [#2147](https://github.com/elan-registry/registry/issues/2147) — Stop displaying Brevo's rewritten tracking URL in the four auth email templates
- [#2150](https://github.com/elan-registry/registry/issues/2150) — Integration tests now connect the verification email
  links to the landing page and show that a hard bounce or an opt-out removes the car from the next verification batch
  (tests only, no site change)
- [#2250](https://github.com/elan-registry/registry/issues/2250) — `render-deploy-sheet.sh` no longer reports false
  migration, trigger, new-page and admin-script conditions. It counts only added files for these conditions. It diffs
  against `origin/main` after a fetch and prints the base it used. It reports an edited migration as
  `migration-modified: CHECK` (developer workflow only, no site change)
- [#2271](https://github.com/elan-registry/registry/issues/2271) — Cleanup-ledger items are checked before the PR is
  pushed. `/review-pr` reports open items the plan does not list. `/commit-push-pr` asks which items the PR completes
  and records them in the PR body. `/finish-issue` reads that section with the new `scripts/ledger-pr-body-items.sh`
  and ticks the items with the new `scripts/ledger-tick-items.sh`. The plugin's `commit-commands:commit-push-pr` skill
  is denied (developer workflow only, no site change)

## Carried from the Dev Environment milestone

The Dev Environment milestone merged to `main` with no release of its own. If
v2.30.4 is the first versioned release after it, list its production-affecting
changes here and add its deploy checks to this release's deploy sheet. See
`docs/releases/RELEASE_NOTES_Dev-Environment.md`, "Production-affecting
changes". If `v2.30.1.1` releases first, it carries them, and this section is
removed.
