# Elan Registry Test Coverage Hardening Release Notes

**Release Date:** TBD
**Type:** Patch Release - Test Coverage Hardening

## Required Actions After Deployment

None.

## User-Facing Changes

None — this milestone is developer-facing test infrastructure only.

## Admin-Facing Changes

None.

## Developer-Facing Changes

A developer can trust the test suite to catch real regressions — closing coverage gaps and adding enforcement so silent test-suite gaps stop shipping undetected.

## Issues Resolved

- WIP: [#1705](https://github.com/elan-registry/registry/issues/1705) — test: UserDeletionReassignmentTest never asserts PII scrub on cars/cars_hist — GDPR guarantee proven only by a mocked unit test
- [#1778](https://github.com/elan-registry/registry/issues/1778) — Added dedicated Playwright console-error coverage for `docs/car-stories.php`, `docs/reference/chassis-validation.php`, and `docs/reference/paint-colors.php`, which previously had only incidental page-title-loop coverage. Assertions include per-page row/card counts (not just presence of the last item) so a mid-render PHP fatal or a silently truncated loop — like the two `chassis-validation.php` fatals in v2.29.x (`d8cb618d`, `8b81ab57`) — fails the test instead of passing silently. Also removed a stale `Google Maps` console-error filter (codebase migrated to MapLibre GL) from the shared assertion helper.
- WIP: [#1789](https://github.com/elan-registry/registry/issues/1789) — test: process-transfer-approve.php has no real Playwright success-path coverage (needs disposable non-admin-owned car fixture)
- WIP: [#2068](https://github.com/elan-registry/registry/issues/2068) — test: no Playwright coverage for requireAdminAjax()'s isRegistryAdmin() branch (non-admin-authenticated case)
- [#2070](https://github.com/elan-registry/registry/issues/2070) — Added a local ESLint rule (`localRules/require-skip-reason`) that flags any `test.skip(...)` call with fewer than 2 arguments in Playwright spec files. Makes the two-argument `test.skip(condition, reason)` convention (established in #1950, after #1949's false-`passed`-instead-of-`skipped` defect) self-enforcing instead of prose-only, so a regression can't silently reach CI again.
- WIP: [#2071](https://github.com/elan-registry/registry/issues/2071) — bug: chassis/FilePond error-banner Playwright tests fail locally against MAMP
