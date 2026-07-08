# Elan Registry v2.26.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Namespace Migration & Code Quality

## Required Actions After Deployment

None.

## User-Facing Changes

None — this release contains internal refactoring only. No user-visible behavior changes.

## Admin-Facing Changes

### Improvements

- **Admin car management auto-refresh** ([#1144](https://github.com/unibrain1/elanregistry/issues/1144)): The manage-cars tab now uses non-destructive AJAX polling instead of a hard page reload, preserving in-progress form state during data refresh.

## Technical Changes

- **Rename `ElanRegistryOwner` → `Owner`** ([#778](https://github.com/unibrain1/elanregistry/issues/778)): The domain class for owner data management has been renamed from `ElanRegistryOwner` to `Owner`, and the file renamed from `ElanRegistryOwner.php` to `Owner.php`. All production PHP, test files, and documentation updated. Behavior-preserving rename only; no logic changes.
- **Route raw SQL car/user lookups through domain classes** ([#962](https://github.com/unibrain1/elanregistry/issues/962)): Three action files (`process-car-details.php`, `send-owner-email.php`, `process-admin-contact.php`) previously issued raw `SELECT` statements against the `cars` and `users` tables. All such lookups now route through the `Car` and `Owner` domain classes. The IDOR ownership check in `send-owner-email.php` retains its raw SQL to preserve the DB-error-vs-access-denied log distinction required by the #1014 security fix. Behavior-preserving; no user-facing changes.
- **PSR-4 namespaces for all custom classes** ([#779](https://github.com/unibrain1/elanregistry/issues/779)): Adds `namespace ElanRegistry;` to 12 root-level custom classes and `namespace ElanRegistry\Admin;` to 2 admin classes. Corrects two files to PSR-4-compliant paths (`Car.php → Car/Car.php`, `DocumentPortalTemplate.php → Documentation/DocumentPortalTemplate.php`). Updates ~160 consumer files with `use ElanRegistry\ClassName;` imports and removes all manual `require_once` class loads. Also adds `ElanRegistry\Admin\` to `composer.json` and the custom autoloader with correct lowercase path mapping for Linux case-sensitive filesystems. Behavior-preserving; no logic changes.

## Issues Resolved

- [#608](https://github.com/unibrain1/elanregistry/issues/608) — refactor: rewrite class autoloader for PSR-4 namespace support
- [#622](https://github.com/unibrain1/elanregistry/issues/622) — refactor: clean up ElanRegistryOwner before rename — remove dead methods, extract coordinate helper, and use syncLocationToCars()
- [#778](https://github.com/unibrain1/elanregistry/issues/778) — refactor: rename ElanRegistryOwner to Owner
- [#779](https://github.com/unibrain1/elanregistry/issues/779) — refactor: introduce PSR-4 namespaces for all custom classes
- [#867](https://github.com/unibrain1/elanregistry/issues/867) — refactor: Input class improvements — type-safe exists() and clarify raw() signature in docs
- [#962](https://github.com/unibrain1/elanregistry/issues/962) — refactor: route raw SQL car/user lookups through Car and Owner classes
- [#1143](https://github.com/unibrain1/elanregistry/issues/1143) — Refactor save.php: extract CarSaveService and CarImageService from procedural globals
- [#1144](https://github.com/unibrain1/elanregistry/issues/1144) — Split tab-manage_cars.php monolith into service, partial, and JS
- [#1169](https://github.com/unibrain1/elanregistry/issues/1169) — refactor: introduce TransferStatus backed enum for car_transfer_requests status values
