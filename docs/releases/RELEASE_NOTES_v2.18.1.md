# Elan Registry v2.18.1 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Testing Infrastructure

## Required Actions After Deployment

None.

## User-Facing Changes

None — this release is entirely internal testing infrastructure.

## Technical Changes

- **Fix failing test assertion** ([#689](https://github.com/unibrain1/elanregistry/issues/689)):
  Add `elan_feedback_email` to `processSettingsAutoCreation()` so the regression test
  accurately verifies fresh-install seeding.
- **Upgrade PHPUnit 11 → 12** ([#629](https://github.com/unibrain1/elanregistry/issues/629)):
  Resolve Dependabot advisory GHSA-qrr6-mg7r-m243; convert 310 docblock annotations to
  PHP 8 attributes; fix `finfo_close()` and `imagedestroy()` PHP 8.5 deprecations.
- **Refactor GetDataTables tests** ([#606](https://github.com/unibrain1/elanregistry/issues/606)):
  Replace source-inspection anti-pattern assertions with behavior-based tests covering
  valid, malformed, oversized, unauthenticated, and CSRF scenarios.

## Issues Resolved

- [#606](https://github.com/unibrain1/elanregistry/issues/606) — test: refactor GetDataTables tests from source-inspection to behavior-based testing
- [#629](https://github.com/unibrain1/elanregistry/issues/629) — Upgrade PHPUnit from 11.x to 12.x
- [#689](https://github.com/unibrain1/elanregistry/issues/689) — fix: tighten testFeedbackEmailSettingIsAutoCreated assertion to verify PHP defaults array

## Summary

3 issues resolved across testing infrastructure: PHPUnit upgrade, test quality refactoring,
and a pre-existing test failure fix.
