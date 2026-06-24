# Elan Registry v2.25.0 Release Notes

**Release Date:** TBD
**Type:** Minor Release - Security & Data Integrity

## Required Actions After Deployment

- **Run fix script `05-Fix-Website-Scheme.php`** ([#851](https://github.com/unibrain1/elanregistry/issues/851)): Migrates existing `cars` rows with scheme-less website URLs (e.g. `example.com`) to `https://example.com`; nulls out invalid values (`javascript:`, relative paths, etc.). Run via the Maintenance tab in the admin panel.

_Additional deployment actions expected: SQL migrations for new `chassis_override` column on `cars`, `cars_hist` trigger updates, and `car_user_hist` triggers/indexes (#915, #592)._

## User-Facing Changes

### Improvements

- **Website Field URL Validation** ([#851](https://github.com/unibrain1/elanregistry/issues/851)): The website field now validates URLs at save time, requiring `http://` or `https://` with a clear error message. A one-time migration script corrects existing scheme-less URLs (prepends `https://` for bare domains; nulls out invalid values).
- **Statistics Page Stability** ([#731](https://github.com/unibrain1/elanregistry/issues/731)): Fixed runtime errors on the statistics page for edge-case DOM states.
- **Registration Error Messages** ([#873](https://github.com/unibrain1/elanregistry/issues/873)): Registration failures now show a clean, generic error message; full exception details are logged for admin review only.

## Admin-Facing Changes

### New Features

- **Car Owner Operations Audit Trail** ([#609](https://github.com/unibrain1/elanregistry/issues/609)): All `car_user` write operations (car creation, user deletion cleanup) are now logged via `logger()` with `LOG_CATEGORY_CAR_ACTIONS`. User deletion cleanup also wrapped in a transaction to prevent partial-migration on failure.

### Improvements

- **Chassis Override Persistence** ([#915](https://github.com/unibrain1/elanregistry/issues/915)): Chassis validation override now persists correctly via a dedicated DB column; includes UI indicator and backfill script for existing records.
- **Date Range Validation** ([#903](https://github.com/unibrain1/elanregistry/issues/903)): Server-side validation added for car purchase/sold date ranges.
- **Website Scheme Whitelist — Owner and Settings** ([#921](https://github.com/unibrain1/elanregistry/issues/921)): Extended the http/https scheme whitelist (added to the car validator in #851) to the owner profile and user settings website fields, blocking `javascript:`, `data:`, `ftp://`, and other non-web protocols.
- **Admin Contact Security** ([#660](https://github.com/unibrain1/elanregistry/issues/660), [#661](https://github.com/unibrain1/elanregistry/issues/661)): Fixed email header injection and unvalidated recipient address in the admin multi-user contact path.
- **Verification Email Escaping** ([#854](https://github.com/unibrain1/elanregistry/issues/854)): All fields in the admin verification email template are now properly escaped.
- **Removed Misleading Denylist Sanitizer** ([#917](https://github.com/unibrain1/elanregistry/issues/917)): The legacy `clean_string()` helper in the admin and owner contact paths was a case-sensitive denylist that did not provide real injection protection (header injection is prevented by CR/LF stripping at the call site; XSS is prevented by template-layer escaping). Removed from both call sites; user messages now reach the email body unmangled.
- **Car Verification via Car Class** ([#624](https://github.com/unibrain1/elanregistry/issues/624)): `verify_car.php` now routes verification and sold-status changes through `Car::markVerified()` and `Car::markSold()` instead of direct DB writes, ensuring validation and audit trail consistency. Fixes a pre-existing gap where the "sold" action never set `cars.solddate`.
- **Audit Trail for Car Deletion** ([#593](https://github.com/unibrain1/elanregistry/issues/593)): Eliminated duplicate history row written on car deletion.
- **car_user_hist Triggers and Indexes** ([#592](https://github.com/unibrain1/elanregistry/issues/592)): Added DB triggers and indexes to `car_user_hist` for full audit coverage.

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
