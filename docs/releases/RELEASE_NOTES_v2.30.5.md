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
  Owner Information section to the car edit form and consolidated the
  profile-sync write paths behind it.
  Consolidates [#1880](https://github.com/elan-registry/registry/issues/1880).
- [#1895](https://github.com/elan-registry/registry/issues/1895) — Added a
  Resume Verification Emails control to Account Settings so an owner can
  clear their own `email_suppressed` flag, sharing logic with the existing
  admin action.
- [#1899](https://github.com/elan-registry/registry/issues/1899) — Added an
  email-paused notice to account.php for owners with suppressed or bounced car
  emails, fixed the Uncloak button's CSRF check on the same page, and dropped
  the unused `country` table.
- [#2295](https://github.com/elan-registry/registry/issues/2295) — Fixed car
  edit save failing with "Please select Model" by making Save wait for the
  model list to load instead of a guessed timeout.
  Consolidates [#2297](https://github.com/elan-registry/registry/issues/2297).
- [#2314](https://github.com/elan-registry/registry/issues/2314) — Fixed gaps
  in the developer workflow commands found by a product, architecture and UX
  audit, including command hand-offs, the review fingerprint, the pre-release
  scope check, and a broken hotfix path. Developer-only. No deploy step.
- [#2316](https://github.com/elan-registry/registry/issues/2316) — Closed the
  re-audit findings, moving the cleanup ledger to the committed file
  `docs/development/CLEANUP_LEDGER.md` and giving every command a resume
  path, a working hotfix route through `/start-issue --hotfix`, and a
  confirmation step before `/finish-issue` merges. Developer-only. No deploy
  step.
- [#2324](https://github.com/elan-registry/registry/issues/2324) — Grouped
  the summary index (`scripts/build-summary-index.py`) by series and
  category, added four new series, and added `scripts/project-health.py`.
  Developer-only. No deploy step.
- [#2327](https://github.com/elan-registry/registry/issues/2327) — Cut about
  13,400 lines from `tests/` with no lost coverage by removing duplicate
  tests and replacing text-scan tests with five PHPStan rules in
  `tools/phpstan/Rules/` (the product refactors some removed tests pointed to
  moved to [#2329](https://github.com/elan-registry/registry/issues/2329)
  through [#2334](https://github.com/elan-registry/registry/issues/2334)).
  Developer-only. No deploy step.
