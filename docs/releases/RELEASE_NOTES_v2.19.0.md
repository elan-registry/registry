# Elan Registry v2.19.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Testing Infrastructure

## Required Actions After Deployment

None for most issues. #574 (schema validation) may require:

1. Run database migrations to add missing indexes and FK constraints (see issue for SQL)
2. Verify `EnhancedSchemaManager::validateSchema()` passes on staging before production

## User-Facing Changes

None — this milestone is entirely internal testing infrastructure.

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
- **Schema validation enhancements** ([#574](https://github.com/unibrain1/elanregistry/issues/574)):
  Add 4 missing performance indexes, 4 FK constraints, and expand `EnhancedSchemaManager`
  validation to cover custom tables and column requirements.
- **CI coverage reporting and baseline** ([#611](https://github.com/unibrain1/elanregistry/issues/611)):
  Add coverage reporting to GitHub Actions, establish baseline coverage percentage,
  document coverage gate policy.

## Issues Resolved

- [#574](https://github.com/unibrain1/elanregistry/issues/574) — Enhance schema validation checks for performance and data integrity
- [#606](https://github.com/unibrain1/elanregistry/issues/606) — test: refactor GetDataTables tests from source-inspection to behavior-based testing
- [#611](https://github.com/unibrain1/elanregistry/issues/611) — test: add CI coverage reporting and baseline tracking
- [#629](https://github.com/unibrain1/elanregistry/issues/629) — Upgrade PHPUnit from 11.x to 12.x
- [#689](https://github.com/unibrain1/elanregistry/issues/689) — fix: tighten testFeedbackEmailSettingIsAutoCreated assertion to verify PHP defaults array

## Summary

5 issues resolved across testing infrastructure: PHPUnit upgrade, test quality refactoring,
CI coverage reporting, schema validation enhancements, and a pre-existing test failure fix.
