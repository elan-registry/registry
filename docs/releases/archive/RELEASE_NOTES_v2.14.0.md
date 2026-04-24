# Elan Registry v2.14.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Data Quality & Validation

## REQUIRED ACTIONS AFTER DEPLOYMENT

Add database index for performance optimization:

```sql
ALTER TABLE elan_factory_info ADD INDEX idx_serial (serial);
```

## User-Facing Changes

### New Features

- **Paint Colors Reference Guide** ([#557](https://github.com/unibrain1/elanregistry/pull/557)):
  Comprehensive factory paint colors page for all Elan and Plus 2 models with 25 paint chip images,
  Lotus color codes (L01–L26), model applicability, Sprint survey data, and modern paint matching
  cross-references.

### Improvements

- **Car History Table Highlighting** ([#322](https://github.com/unibrain1/elanregistry/issues/322)):
  Changed fields are now highlighted for easier comparison between history records.

### Bug Fixes

- **Toast Notifications** ([#536](https://github.com/unibrain1/elanregistry/issues/536)): Fixed positioning and z-index issues.
- **Account Page Timeout** ([#558](https://github.com/unibrain1/elanregistry/pull/558)): Fixed timeout for owners with multiple cars by optimizing ownership queries.
- **Car Constructor TypeError** ([#546](https://github.com/unibrain1/elanregistry/issues/546)): Cast user ID to `(int)` in Car constructor.
- **CSP Policy** ([#553](https://github.com/unibrain1/elanregistry/issues/553)): Removed unused Google Analytics domains and added Gravatar to fix CSP violations.
- **Apple Touch Icons** ([#543](https://github.com/unibrain1/elanregistry/issues/543)): Added missing 120x120 icon files for iOS devices.

## Technical Changes

- **Ownership Queries** ([#558](https://github.com/unibrain1/elanregistry/pull/558)):
  All ownership lookups now use `car_user` junction table instead of denormalized `cars.user_id`.
- **History Table AJAX** ([#322](https://github.com/unibrain1/elanregistry/issues/322)):
  Replaced inline static table with DataTables AJAX endpoint and reusable `highlightDifferences.js` module.
- **Class Consolidation** ([#529](https://github.com/unibrain1/elanregistry/issues/529)): Namespaced exceptions and consolidated duplicate class locations.
- **Toast System** ([#536](https://github.com/unibrain1/elanregistry/issues/536)): Unified BS4-compatible toasts with `pre_footer.php` hook.
- **Image Handling** ([#514](https://github.com/unibrain1/elanregistry/issues/514)): Removed deprecated `imagedestroy()` calls and fixed strict type errors.
- **Markdown Parser** ([#557](https://github.com/unibrain1/elanregistry/pull/557)): Added table parsing support for documentation pages.
- **ESLint Enforcement** ([#549](https://github.com/unibrain1/elanregistry/issues/549),
  [#550](https://github.com/unibrain1/elanregistry/issues/550)):
  Fixed all 67 ESLint findings and enforced in CI to block non-compliant PRs.
- **Test Infrastructure** ([#558](https://github.com/unibrain1/elanregistry/pull/558)):
  Improved `createTestCar()` and `tearDown()` to clean up `car_user` junction table and prevent
  orphaned test data.
- **ImgBot Config** ([#377](https://github.com/unibrain1/elanregistry/issues/377)): Added `.imgbotconfig` to exclude user content and test artifacts.

## Issues Resolved

- [#10](https://github.com/unibrain1/elanregistry/issues/10) — User Add and Account Update - Data Validation
- [#221](https://github.com/unibrain1/elanregistry/issues/221) — Add Production vs Development Configuration Comparison Tool
- [#322](https://github.com/unibrain1/elanregistry/issues/322) — Fix car history table date ordering and highlight changed fields
- [#330](https://github.com/unibrain1/elanregistry/issues/330) — Coding standards cleanup for transfer system files
- [#361](https://github.com/unibrain1/elanregistry/issues/361) — Move database/ directory to docs/development/
- [#369](https://github.com/unibrain1/elanregistry/issues/369) — Add strict types and specific exceptions to edit.php
- [#377](https://github.com/unibrain1/elanregistry/issues/377) — Configure ImgBot to exclude user content and test artifacts
- [#396](https://github.com/unibrain1/elanregistry/issues/396) — Reduce code duplication from 6.0% to below 3%
- [#467](https://github.com/unibrain1/elanregistry/issues/467) — Improve location terminology for international inclusivity
- [#514](https://github.com/unibrain1/elanregistry/issues/514) — Remove deprecated imagedestroy() call in Resize.php
- [#529](https://github.com/unibrain1/elanregistry/issues/529) — Consolidate class file locations and remove duplicates
- [#536](https://github.com/unibrain1/elanregistry/issues/536) — Fix toast notification positioning and z-index
- [#543](https://github.com/unibrain1/elanregistry/issues/543) — Create apple-touch-icon assets
- [#546](https://github.com/unibrain1/elanregistry/issues/546) — Fix getUserWithProfile() TypeError from string user ID
- [#549](https://github.com/unibrain1/elanregistry/issues/549) — Scope ESLint CI check to changed files only
- [#550](https://github.com/unibrain1/elanregistry/issues/550) — Fix all ESLint findings across JavaScript files
- [#553](https://github.com/unibrain1/elanregistry/issues/553) — Clean up CSP: remove unused analytics domains and add Gravatar

## Summary

17 issues resolved across data quality, code quality, and bug fixes. 8 pull requests merged.
Key improvements: ownership data integrity, car history table modernization, CI enforcement,
and test infrastructure.
