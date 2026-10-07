# Elan Registry v2.30.5 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Email Delivery Visibility

## User-Facing Changes

- An owner whose car emails are suppressed or bounced now sees a dismissable
  notice on Account Settings naming the affected addresses, the reason, and
  links to fix it. ([#1899](https://github.com/elan-registry/registry/issues/1899))

## Issues Resolved

- [#1899](https://github.com/elan-registry/registry/issues/1899) — Added an
  email-paused notice to account.php for owners with suppressed or bounced car
  emails. The same change fixes the Uncloak button on account.php, which
  always failed its CSRF check. It also drops the unused `country` table. Run
  `composer migrate` on deploy.
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
