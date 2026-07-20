# Elan Registry v2.28.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release — Injection & Dead-Code Foundation

## Required Actions After Deployment

None.

## User-Facing Changes

None — this release contains internal refactoring only.

## Admin-Facing Changes

### Bug Fixes

- **Malformed model string now rejected cleanly** (#1286): A pipe-delimited model string with missing or empty segments (e.g. `||`) now returns a structured validation error instead of silently proceeding with empty series/variant/type values.

## Issues Resolved

- [#1145](https://github.com/elan-registry/registry/issues/1145) — Decouple Car class from global $user dependency
- [#1239](https://github.com/elan-registry/registry/issues/1239) — refactor: fix Owner class coupling — remove CSRF from domain methods, fix static $db bypass in searchOwners()
- [#1244](https://github.com/elan-registry/registry/issues/1244) — refactor: inject CarRepository into CarVerificationManager and CarImageProcessor constructors
- [#1246](https://github.com/elan-registry/registry/issues/1246) — refactor: eliminate CarErrorMessages and FactoryDataFormatter — consolidate dead utility classes
- [#1286](https://github.com/elan-registry/registry/issues/1286) — refactor: add CarValidator::parseModel() to consolidate pipe-split model string parsing
- [#1318](https://github.com/elan-registry/registry/issues/1318) — tech-debt: typed exceptions in CarTransferRepository + dead-code and error-hygiene cleanup
- [#1346](https://github.com/elan-registry/registry/issues/1346) — refactor: replace ob_start/include template rendering in TransferEmailService with EmailTemplate
- [#1366](https://github.com/elan-registry/registry/issues/1366) — fix: update LogCategoriesUsageTest to use admin-core.js after manage-consolidated.js rename
