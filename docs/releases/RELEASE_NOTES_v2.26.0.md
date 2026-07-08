# Elan Registry v2.26.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Namespace Migration & Code Quality

## Required Actions After Deployment

None.

## User-Facing Changes

None — this release contains internal refactoring only. No user-visible behavior changes.

## Technical Changes

- **Rewrite class autoloader for PSR-4 namespace support** ([#608](https://github.com/unibrain1/elanregistry/issues/608)): Replaces the single-prefix PSR-4 handler with a configurable `$namespaceMappings` array mirroring `composer.json`. Adds `ElanRegistry\Exceptions\`, `ElanRegistry\Reference\`, `ElanRegistry\Admin\`, and root `ElanRegistry\` prefix mappings (longest-first). Renames class `UserspiceCustomAutoloader` → `ElanRegistryAutoloader`. Fixes a latent path bug where `ElanRegistry\Reference\CarModel` was resolving via recursive fallback instead of the correct PSR-4 path.
- **Clean up `ElanRegistryOwner` before rename** ([#622](https://github.com/unibrain1/elanregistry/issues/622)): Removes two dead methods (`getOwnerProfile()`, `updateLocation()`), extracts shared `InputSanitizer::normalize()` utility class (fixes byte-level truncation to character-level via `mb_strlen`/`mb_substr`), adds lat/lon coordinate validation, adds PHP 8 typed properties, and makes `searchOwners()` static with a proper `$db->error()` check.
- **Rename `ElanRegistryOwner` → `Owner`** ([#778](https://github.com/unibrain1/elanregistry/issues/778)): The domain class for owner data management has been renamed from `ElanRegistryOwner` to `Owner`, and the file renamed from `ElanRegistryOwner.php` to `Owner.php`. All production PHP, test files, and documentation updated. Behavior-preserving rename only; no logic changes.
- **Route raw SQL car/user lookups through domain classes** ([#962](https://github.com/unibrain1/elanregistry/issues/962)): Three action files (`process-car-details.php`, `send-owner-email.php`, `process-admin-contact.php`) previously issued raw `SELECT` statements against the `cars` and `users` tables. All such lookups now route through the `Car` and `Owner` domain classes. The IDOR ownership check in `send-owner-email.php` retains its raw SQL to preserve the DB-error-vs-access-denied log distinction required by the #1014 security fix. Behavior-preserving; no user-facing changes.
- **PSR-4 namespaces for all custom classes** ([#779](https://github.com/unibrain1/elanregistry/issues/779)): Adds `namespace ElanRegistry;` to 12 root-level custom classes and `namespace ElanRegistry\Admin;` to 2 admin classes. Corrects two files to PSR-4-compliant paths (`Car.php → Car/Car.php`, `DocumentPortalTemplate.php → Documentation/DocumentPortalTemplate.php`). Updates ~160 consumer files with `use ElanRegistry\ClassName;` imports and removes all manual `require_once` class loads. Adds `ElanRegistry\Admin\` to `composer.json` and the custom autoloader with correct lowercase path mapping for Linux case-sensitive filesystems. Behavior-preserving; no logic changes.
- **AutoloaderTest: add `ElanRegistry\Reference\` path assertion** ([#1255](https://github.com/unibrain1/elanregistry/issues/1255)): Adds `SeriesData` as a non-mocked class under `ElanRegistry\Reference\` so `testReferencePrefixResolution()` can verify the prefix maps to the correct directory (`usersc/classes/Reference/`) using `ReflectionClass::getFileName()`. Catches regressions where the Reference prefix mapping points at the wrong directory.

## Issues Resolved

- [#608](https://github.com/unibrain1/elanregistry/issues/608) — refactor: rewrite class autoloader for PSR-4 namespace support
- [#622](https://github.com/unibrain1/elanregistry/issues/622) — refactor: clean up ElanRegistryOwner before rename — remove dead methods, extract coordinate helper, and use syncLocationToCars()
- [#778](https://github.com/unibrain1/elanregistry/issues/778) — refactor: rename ElanRegistryOwner to Owner
- [#779](https://github.com/unibrain1/elanregistry/issues/779) — refactor: introduce PSR-4 namespaces for all custom classes
- [#962](https://github.com/unibrain1/elanregistry/issues/962) — refactor: route raw SQL car/user lookups through Car and Owner classes
- [#1255](https://github.com/unibrain1/elanregistry/issues/1255) — AutoloaderTest: add path assertion for ElanRegistry\Reference\ prefix mapping
