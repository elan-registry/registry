# Elan Registry v2.25.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Security & Data Integrity

## Required Actions After Deployment

- **Run fix script `05-Fix-Website-Scheme.php`** ([#851](https://github.com/unibrain1/elanregistry/issues/851)): Migrates existing `cars` rows with scheme-less website URLs (e.g. `example.com`) to `https://example.com`; nulls out invalid values (`javascript:`, relative paths, etc.). Run via the Maintenance tab in the admin panel.

- **Run fix script `06-Add-Car-User-Hist-Triggers.php`** ([#592](https://github.com/unibrain1/elanregistry/issues/592)): Installs AFTER INSERT/UPDATE/DELETE triggers on `car_user` and adds indexes on `car_user_hist(car_id)` and `car_user_hist(userid)`. Run via the Maintenance tab in the admin panel.

- **Run fix script `07-Chassis-Override-Schema-Backfill.php`** ([#915](https://github.com/unibrain1/elanregistry/issues/915)): Adds `chassis_override` column to `cars` and `cars_hist`, recreates all three `cars_*` triggers to include the column, and backfills `chassis_override = 1` on cars modified on or after 2025-08-31 or whose comments contain the legacy audit phrase "CHASSIS VALIDATION OVERRIDDEN". Run via the Maintenance tab in the admin panel.

## User-Facing Changes

### Improvements

- **Website Field URL Validation** ([#851](https://github.com/unibrain1/elanregistry/issues/851)): The website field now validates URLs at save time, requiring `http://` or `https://` with a clear error message. A one-time migration script corrects existing scheme-less URLs (prepends `https://` for bare domains; nulls out invalid values).
- **Statistics Page Stability** ([#731](https://github.com/unibrain1/elanregistry/issues/731)): Fixed runtime errors on the statistics page for edge-case DOM states.
- **Registration Error Messages** ([#873](https://github.com/unibrain1/elanregistry/issues/873)): Registration failures now show a clean, generic error message; full exception details are logged for admin review only.

## Admin-Facing Changes

### New Features

- **Car Owner Operations Audit Trail** ([#609](https://github.com/unibrain1/elanregistry/issues/609)): All `car_user` write operations (car creation, user deletion cleanup) are now logged via `logger()` with `LOG_CATEGORY_CAR_ACTIONS`. User deletion cleanup also wrapped in a transaction to prevent partial-migration on failure.

### Improvements

- **Chassis Override Persistence** ([#915](https://github.com/unibrain1/elanregistry/issues/915)): Chassis validation override now persists correctly via a dedicated `chassis_override` DB column. Fixes checkbox unchecking bug in client-side JS, adds `chassis_override` to the `Car` class allowlist and validator, adds all three database triggers (`cars_insert`, `cars_update`, `cars_delete`) to capture the column, pre-populates the checkbox on edit form reload, and displays a "Validation Override" badge on the car details page. Includes backfill script for existing records.
- **Date Range Validation** ([#903](https://github.com/unibrain1/elanregistry/issues/903)): Server-side validation added for car purchase/sold date ranges.
- **Website Scheme Whitelist — Owner and Settings** ([#921](https://github.com/unibrain1/elanregistry/issues/921)): Extended the http/https scheme whitelist (added to the car validator in #851) to the owner profile and user settings website fields, blocking `javascript:`, `data:`, `ftp://`, and other non-web protocols.
- **Admin Contact Security** ([#660](https://github.com/unibrain1/elanregistry/issues/660), [#661](https://github.com/unibrain1/elanregistry/issues/661)): Fixed email header injection and unvalidated recipient address in the admin multi-user contact path.
- **Verification Email Escaping** ([#854](https://github.com/unibrain1/elanregistry/issues/854)): All fields in the admin verification email template are now properly escaped.
- **Removed Misleading Denylist Sanitizer** ([#917](https://github.com/unibrain1/elanregistry/issues/917)): The legacy `clean_string()` helper in the admin and owner contact paths was a case-sensitive denylist that did not provide real injection protection (header injection is prevented by CR/LF stripping at the call site; XSS is prevented by template-layer escaping). Removed from both call sites; user messages now reach the email body unmangled.
- **Car Verification via Car Class** ([#624](https://github.com/unibrain1/elanregistry/issues/624)): `verify_car.php` now routes verification and sold-status changes through `Car::markVerified()` and `Car::markSold()` instead of direct DB writes, ensuring validation and audit trail consistency. Fixes a pre-existing gap where the "sold" action never set `cars.solddate`.
- **Overflow Date Rejection in markSold()** ([#935](https://github.com/unibrain1/elanregistry/issues/935)): `CarVerificationManager::markSold()` now rejects calendar-invalid overflow dates (e.g. `2024-02-30`) that PHP's `DateTime::createFromFormat()` previously silently rolled forward, preventing corrupt date values from being persisted to `cars.solddate`.
- **Audit Trail for Car Deletion** ([#593](https://github.com/unibrain1/elanregistry/issues/593)): Eliminated duplicate history row written on car deletion.
- **car_user_hist Triggers and Indexes** ([#592](https://github.com/unibrain1/elanregistry/issues/592)): Added DB triggers and indexes to `car_user_hist` for full audit coverage.

### Bug Fixes

- **Owner Validation Error Messages** ([#927](https://github.com/unibrain1/elanregistry/issues/927)): Admin owner edit now shows the specific validation error (e.g. "Website URL must use http:// or https://") instead of a generic fallback when input is invalid.
- **Contact Recipient Ownership Check** ([#1014](https://github.com/unibrain1/elanregistry/issues/1014)): `send-owner-email.php` now verifies that `to_user_id` matches `cars.user_id` for the supplied `car_id` before sending. Previously, any authenticated user could POST an arbitrary `to_user_id` to send an unsolicited email to any registered user (IDOR). Mismatched recipients are rejected with an error and logged to `LOG_CATEGORY_ACCESS_DENIED`.
- **Timestamp Hour Padding** ([#947](https://github.com/unibrain1/elanregistry/issues/947)): Fixed `AppConstants::DATETIME_FORMAT` using `G` (no leading zero) instead of `H` (zero-padded) for the hour. Morning timestamps `0–9` now correctly produce `09:xx:xx` instead of `9:xx:xx`, ensuring consistent lexicographic sort order.
- **CSRF Audit Logging Gap** ([#958](https://github.com/unibrain1/elanregistry/issues/958)): Four AJAX endpoints were returning HTTP 403 on CSRF failure with no security log entry. All four now log via `LOG_CATEGORY_SECURITY`, consistent with the rest of the codebase.
- **Car Update Ownership Check** ([#970](https://github.com/unibrain1/elanregistry/issues/970)): Any authenticated user could update another owner's car record by POSTing a `car_id` they don't own to the `updateCar` endpoint. Added ownership guard matching the existing `fetchImages`/`removeImages` pattern — non-owners receive HTTP 403; admins (group 2/3) are unaffected.
- **Owner Contact Sender Impersonation** ([#971](https://github.com/unibrain1/elanregistry/issues/971)): Any authenticated user could send a contact email appearing to come from another registered owner by supplying a different `from_user_id` in the POST body. Sender identity is now always derived from the authenticated session — the `from_user_id` POST parameter is ignored and the hidden form field has been removed.

## Technical Changes

- **`Car::delete()` CSRF now mandatory** ([#930](https://github.com/unibrain1/elanregistry/issues/930)): Changed `Car::delete()` from an optional nullable token to a required `string $token`, consistent with `create()` and `update()`. Removes the opt-in bypass that could be silently exploited by future callers.
- **Admin delete path routed through `Car::delete()`** ([#956](https://github.com/unibrain1/elanregistry/issues/956)): The admin car deletion handler in `manage-consolidated.php` previously issued raw SQL, bypassing the class-level CSRF guard added in #930. Refactored to call `Car::delete()`, which enforces CSRF validation, authentication, transaction wrapping, and audit logging. Defense-in-depth: page-level guards remain in place.
- **Integration test car cleanup**: Added `IntegrationTestCase::trackCarId()` so tests that create cars via `Car::create()` directly (rather than through `createTestCar()`) can register the IDs for tearDown deletion. Fixed two methods in `CarDatabaseOperationsTest` (`testCarCreationPersistsToDatabases`, `testMarkSoldUpdatesDatabase`) that were leaving orphaned test cars assigned to the admin account after each test run.

## Issues Resolved

- [#592](https://github.com/unibrain1/elanregistry/issues/592) — Add database triggers and indexes for car_user_hist table
- [#593](https://github.com/unibrain1/elanregistry/issues/593) — Remove duplicate history row on car deletion
- [#609](https://github.com/unibrain1/elanregistry/issues/609) — security: add application-layer audit logging for car owner operations
- [#624](https://github.com/unibrain1/elanregistry/issues/624) — refactor: verify_car.php should use Car class methods instead of direct DB updates
- [#660](https://github.com/unibrain1/elanregistry/issues/660) — bug: email header injection via $qualityIssue in admin contact subject line
- [#661](https://github.com/unibrain1/elanregistry/issues/661) — bug: `target_email` unvalidated in admin contact multiple-user path
- [#731](https://github.com/unibrain1/elanregistry/issues/731) — fix: statistics.js null safety — canvas getContext and href.substring(1)
- [#851](https://github.com/unibrain1/elanregistry/issues/851) — bug: website field silently hidden when URL lacks http/https scheme
- [#854](https://github.com/unibrain1/elanregistry/issues/854) — fix: escape remaining unescaped fields in admin verification email template
- [#873](https://github.com/unibrain1/elanregistry/issues/873) — security: registration failure exposes raw exception message to user (usersc/join.php)
- [#903](https://github.com/unibrain1/elanregistry/issues/903) — harden: server-side validation for car purchase/sold date ranges
- [#915](https://github.com/unibrain1/elanregistry/issues/915) — fix: chassis override — persist to DB column, UI indicator, and admin fix script
- [#917](https://github.com/unibrain1/elanregistry/issues/917) — bug: clean_string() in admin contact paths is a denylist, not an injection defense
- [#921](https://github.com/unibrain1/elanregistry/issues/921) — harden: apply http/https scheme whitelist to ElanRegistryOwner and user_settings website fields
- [#927](https://github.com/unibrain1/elanregistry/issues/927) — bug: OwnerValidationException getUserMessage() returns generic default instead of specific validation text
- [#931](https://github.com/unibrain1/elanregistry/issues/931) — test: add integration test for manage-consolidated.php car deletion audit trail
- [#930](https://github.com/unibrain1/elanregistry/issues/930) — refactor: Car::delete() CSRF token is nullable — bypassable by omitting token
- [#956](https://github.com/unibrain1/elanregistry/issues/956) — refactor: migrate manage-consolidated.php delete path to Car::delete()
- [#935](https://github.com/unibrain1/elanregistry/issues/935) — bug: CarVerificationManager::markSold() accepts overflow dates that PHP rolls forward silently
- [#947](https://github.com/unibrain1/elanregistry/issues/947) — bug: AppConstants::DATETIME_FORMAT uses 'G' (no leading zero) instead of 'H'
- [#958](https://github.com/unibrain1/elanregistry/issues/958) — security: add CSRF failure audit logging to 4 AJAX endpoints
- [#970](https://github.com/unibrain1/elanregistry/issues/970) — security: add car ownership check to updateCar action in edit.php
- [#971](https://github.com/unibrain1/elanregistry/issues/971) — security: fix sender impersonation in owner-to-owner contact email
- [#1014](https://github.com/unibrain1/elanregistry/issues/1014) — security: validate to_user_id matches car owner in send-owner-email.php
