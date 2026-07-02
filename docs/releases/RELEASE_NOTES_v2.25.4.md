# Elan Registry v2.25.4 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Data Integrity & Security Bug Fixes

## Required Actions After Deployment

1. Run fix script: `app/admin/scripts/fix/06-Fix-Schema-Integrity.php` (issue #992 — promotes
   UNIQUE KEY to PRIMARY KEY and adds audit table indexes). Backup the database before running.

## User-Facing Changes

### Improvements

- **Safer image saves**
  ([#1084](https://github.com/unibrain1/elanregistry/issues/1084)):
  Cars are no longer saved with broken image references when an image upload or resize fails.
- **Owner email reliability**
  ([#1056](https://github.com/unibrain1/elanregistry/issues/1056)):
  Contact emails that reference a car now fail gracefully if the car record is missing.
- **Consistent website URL validation**
  ([#1055](https://github.com/unibrain1/elanregistry/issues/1055)):
  Website updates on the edit page now use the same URL validation as owner profiles, rejecting malformed URLs consistently.

## Admin-Facing Changes

- **Backup retention enforced**
  ([#1068](https://github.com/unibrain1/elanregistry/issues/1068)):
  BackupManager and backup operations now respect the retention constants defined in `config.php`.
- **Server path removed from backup API**
  ([#977](https://github.com/unibrain1/elanregistry/issues/977)):
  Absolute server filesystem paths are no longer exposed in backup API responses.
- **Admin owner-field display hardened against stored HTML**
  ([#941](https://github.com/unibrain1/elanregistry/issues/941)):
  Owner fields (name, email, city, state, country) in the admin manage-cars duplicate-detection view are now HTML-escaped at render time, closing a stored-XSS path exposed when `sanitizeString()` stopped stripping tags.
- **Statistics dashboard hardened**
  ([#1097](https://github.com/unibrain1/elanregistry/issues/1097)):
  Statistics tab loader no longer injects raw error messages into the DOM; guards against undefined data prevent silent tab failures.
- **Dead schema-maintenance feature removed**
  ([#1112](https://github.com/unibrain1/elanregistry/issues/1112)):
  The unused "Validate Schema" and "Run Maintenance" buttons and their backing code have been removed from the admin Maintenance tab.

## Issues Resolved

- [#934](https://github.com/unibrain1/elanregistry/issues/934)
  — Collapse duplicate if (!) guards in Car::update()
- [#941](https://github.com/unibrain1/elanregistry/issues/941)
  — ElanRegistryOwner::sanitizeString() strips HTML tags, violating v2.23.0 encode-at-output pattern
- [#977](https://github.com/unibrain1/elanregistry/issues/977)
  — security: remove absolute server path from backup API response
- [#992](https://github.com/unibrain1/elanregistry/issues/992)
  — fix: schema integrity — promote UNIQUE KEY to PRIMARY KEY and add missing audit table indexes
- [#995](https://github.com/unibrain1/elanregistry/issues/995)
  — fix: minor error handling consistency issues (serverError method, log category constants)
- [#1055](https://github.com/unibrain1/elanregistry/issues/1055)
  — edit.php updateWebsite() skips FILTER_VALIDATE_URL — inconsistent with ElanRegistryOwner URL validation
- [#1056](https://github.com/unibrain1/elanregistry/issues/1056)
  — send-owner-email.php car details query has no error check — missing car data silently produces incomplete email
- [#1068](https://github.com/unibrain1/elanregistry/issues/1068)
  — bug: BackupManager and backup-operations.php ignore config.php retention constants
- [#1070](https://github.com/unibrain1/elanregistry/issues/1070)
  — bug: LocationService User-Agent contains stale version number
- [#1081](https://github.com/unibrain1/elanregistry/issues/1081)
  — fix: harden transfer-request.php and chassis-availability.php — Input::raw(), length validation, null guards, and info leak
- [#1084](https://github.com/unibrain1/elanregistry/issues/1084)
  — fix: car saved with broken image references — guard mvTmpImages() failures and resize errors in save.php
- [#1097](https://github.com/unibrain1/elanregistry/issues/1097)
  — fix: statistics.js loadTabContent injects error.message into innerHTML
- [#1107](https://github.com/unibrain1/elanregistry/issues/1107)
  — test: add Playwright coverage for length-validation boundary paths in chassis-availability.php and transfer-request.php
- [#1112](https://github.com/unibrain1/elanregistry/issues/1112)
  — refactor: remove dead schema-maintenance feature from admin Maintenance tab
- [#1119](https://github.com/unibrain1/elanregistry/issues/1119)
  — fix(location): add User-Agent header to Photon geocoding requests
