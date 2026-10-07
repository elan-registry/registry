# Elan Registry v2.30.5 Release Notes

**Release Date:** TBD
**Type:** Verification System Refresh - Owner & Admin Self-Service

## User-Facing Changes

- An owner whose car emails are suppressed or bounced now sees a dismissable
  notice on Account Settings naming the affected addresses, the reason, and
  links to fix it. ([#1899](https://github.com/elan-registry/registry/issues/1899))
- The car edit form has a new Owner Information section, so the verification
  email's single edit link now covers everything the email asks the owner to
  check. ([#1891](https://github.com/elan-registry/registry/issues/1891))
- An owner can now resume their own suppressed verification emails from
  Account Settings, without needing an admin.
  ([#1895](https://github.com/elan-registry/registry/issues/1895))
- Fixed a car edit save failure ("Please select Model") that could happen
  when the model list took a moment to load.
  ([#2295](https://github.com/elan-registry/registry/issues/2295))

## Admin-Facing Changes

- The Verification tab has a new dashboard: summary counts, a filterable
  queue, a recent-activity feed, and per-row status chips.
  ([#1896](https://github.com/elan-registry/registry/issues/1896))

## Issues Resolved

- [#1896](https://github.com/elan-registry/registry/issues/1896) — Rebuilt
  the admin Verification tab's dashboard, with per-row status chips that
  share suppression-cause logic with the owner-facing notice and a guard
  against applying owner-level actions to the `noowner` system account.
- [#1891](https://github.com/elan-registry/registry/issues/1891) — Added an
  Owner Information section to the car edit form, consolidating profile-sync
  write paths with corrected exception handling, retry logic, and audit
  logging. Consolidates [#1880](https://github.com/elan-registry/registry/issues/1880).
- [#1895](https://github.com/elan-registry/registry/issues/1895) — Added a
  Resume Verification Emails control to Account Settings so an owner can
  clear their own `email_suppressed` flag, sharing logic with the existing
  admin action.
- [#1899](https://github.com/elan-registry/registry/issues/1899) — Added an
  email-paused notice to account.php for owners with suppressed or bounced car
  emails. The same change fixes the Uncloak button on account.php, which
  always failed its CSRF check. It also drops the unused `country` table. Run
  `composer migrate` on deploy.
- [#2295](https://github.com/elan-registry/registry/issues/2295) — Fixed car
  edit save failing with "Please select Model" when the model list was still
  loading; Save now waits for the real load instead of a guessed timeout.
  Consolidates [#2297](https://github.com/elan-registry/registry/issues/2297).
- [#2314](https://github.com/elan-registry/registry/issues/2314) — Fixed gaps
  in the developer workflow commands found by a product, architecture and UX
  audit: hand-offs between commands, the review fingerprint, the scope check
  before release, and a hotfix path that did not work. Developer-only; no
  deploy step.
- [#2316](https://github.com/elan-registry/registry/issues/2316) — Closed the
  re-audit findings: the cleanup ledger is now the committed file
  `docs/development/CLEANUP_LEDGER.md`, the sprint file is gone, hotfixes run
  through `/start-issue --hotfix`, every command can resume after a stop, and
  `/finish-issue` asks for your confirmation before it merges.
  Developer-only; no deploy step.
- [#2324](https://github.com/elan-registry/registry/issues/2324) — The
  summary index (`scripts/build-summary-index.py`) now groups pages by series
  and category and has four new series. Added `scripts/project-health.py`.
  Developer-only; no deploy step.
- [#2327](https://github.com/elan-registry/registry/issues/2327) — Cut about
  13,400 lines from `tests/` with no lost coverage. Removed duplicate tests
  and long test comments. Five PHPStan rules in `tools/phpstan/Rules/` replace
  text-scan tests. `tools/` is excluded from the deploy package and blocked in
  `.htaccess`. The product refactors that some removed tests pointed to moved
  to #2329 to #2334. Developer-only; no deploy step.
