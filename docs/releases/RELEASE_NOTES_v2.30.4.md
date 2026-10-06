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

- Cars show Sold and Verified badges on your account page and the cars list. The car details page shows a Sold stamp
  in its Sold row. A car shows at most two badges. Sold hides Verified. On the cars list, New also hides Verified.
  Every badge has a tooltip that explains it. On the cars list, the New badge now sits below the Details button, not inside it. ([#1900](https://github.com/elan-registry/registry/issues/1900))
- The Vehicle Information card on the car details page, your account page, and the vericode page has a Verified row.
  It shows "Last confirmed" or "Current since" with a date, or "Not specified" when the record is more than 12 months
  old. A help button explains what Verified means. ([#1897](https://github.com/elan-registry/registry/issues/1897))
- The verification email shows a photo of your car with a link to all its photos. If no photo is on file, the email
  asks you to add one. ([#1894](https://github.com/elan-registry/registry/issues/1894))
- The password reset, registration attempt, and email verification emails no longer show a long link as text. If the
  button does not work, the email gives a contact address. ([#2147](https://github.com/elan-registry/registry/issues/2147))

## Admin-Facing Changes

Changes visible only to administrators (admin dashboard, maintenance tools, settings, etc.). One sentence each.

- On the car details page, admins and editors see an Email on file row when the owner's address is marked Bounced or
  Suppressed. Each word has a tooltip that explains it. ([#1897](https://github.com/elan-registry/registry/issues/1897))

## Issues Resolved

- [#1929](https://github.com/elan-registry/registry/issues/1929) — `CLASSES.md` lists which owner actions reset
  `owner_last_updated` (a car's freshness date), each with a covering test.
- [#2189](https://github.com/elan-registry/registry/issues/2189) — Failed-login log no longer stores the submitted
  username verbatim (a password was stored in plain text)
- [#2259](https://github.com/elan-registry/registry/issues/2259) — Workflow commands and agents use the model each step
  needs, and repeated review work is removed (developer workflow only, no site change)
- [#1897](https://github.com/elan-registry/registry/issues/1897) — Verified row and admin-only Email on file row in
  the shared Vehicle Information card. New `CarBadges::verifiedStatus()`, and new
  `CarRepository::isWithinFreshnessWindow()`, `parseTimestamp()` (now public) and `freshnessCutoff()`, so the
  1-year window has one definition. The Verified stamp tooltip now reads "The owner confirmed, added, or updated this
  car's record in the last 12 months." The Suppressed tooltip names Clear Suppression only. The owner's Resume control
  is deferred to [#1895](https://github.com/elan-registry/registry/issues/1895)
- [#1900](https://github.com/elan-registry/registry/issues/1900) — Sold and Verified badges on the account page
  and cars list, and a Sold stamp in the Sold row of the car details page. New
  class `CarBadges` owns which badges a car shows. The cars list API response has
  a `badges` key on each row. NEW moved outside the Details link (WCAG 4.1.2). New
  `--er-badge-*` tokens and `.er-badge` classes. `UI_STANDARDS.md` and `CLASSES.md` describe the system
- [#1898](https://github.com/elan-registry/registry/issues/1898) — Verified vector on the statistics page's
  data-completeness radar chart. `verified_cars` now uses the shared freshness rule, not a count of non-null
  `last_verified`. The chart no longer shows `NaN` when the registry has no cars.
- [#1894](https://github.com/elan-registry/registry/issues/1894) — The verification email's Photos row links the
  primary photo's `-resized-300` file by absolute URL, with escaped alt text that names the car. It has three states:
  thumbnail, plain "N photos on file" when the primary photo has no `-resized-300` file, and a highlighted
  "Not yet provided" when no listed photo is on disk. Only the last state is named in the blank-field callout. A listed
  photo that cannot be shown is logged once under `FileError`. `CarVerificationEmailComposer` takes an optional image root and
  reads legacy comma-separated `cars.image` values. `EMAIL_SYSTEM.md` no longer says the composer is not wired to a send
  path (includes the remaining gap from #1892)
- [#2147](https://github.com/elan-registry/registry/issues/2147) — The password reset, registration attempt, and
  email verification emails no longer print the link as text. Brevo rewrites that link to its tracking domain, so it
  looked like a phishing link. A contact address replaces it. The unused `NO_TRACK_LINK_CLASS` is removed, and
  `EMAIL_SYSTEM.md` states the rule for links in email templates
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
