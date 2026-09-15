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

- [#1705](https://github.com/elan-registry/registry/issues/1705) — test: UserDeletionReassignmentTest never asserts PII scrub on cars/cars_hist — GDPR guarantee proven only by a mocked unit test
- [#1778](https://github.com/elan-registry/registry/issues/1778) — tech-debt: add dedicated Playwright coverage for car-stories, chassis-validation, and paint-colors pages
- [#2068](https://github.com/elan-registry/registry/issues/2068) — test: no Playwright coverage for requireAdminAjax()'s isRegistryAdmin() branch (non-admin-authenticated case)
- [#2070](https://github.com/elan-registry/registry/issues/2070) — test: add CI/lint check that flags test.skip() calls without a reason string
- [#2071](https://github.com/elan-registry/registry/issues/2071) — bug: chassis/FilePond error-banner Playwright tests fail locally against MAMP

## Retrospective

- **What did we ship that nobody needed?** Nothing — all 5 issues closed real gaps, each tracing to a concrete incident or coverage gap (#1949/#1950 false-pass precedent, a GDPR audit gap, prod fatals in v2.29.x).
- **What did we learn about this theme's audience?** Silent false-passes are the recurring failure mode developers hit with this test suite — a test that reports green without proving what it claims to (#1949/#1950's absorbed guards, #2068's Dev-unauthenticated case) is worse than no test at all, because it's trusted.
- **What signal did we ignore?** None deliberately set aside this milestone.
