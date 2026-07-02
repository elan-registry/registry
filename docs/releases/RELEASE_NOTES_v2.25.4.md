# Elan Registry v2.25.4 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Data Integrity & Security Bug Fixes

## Required Actions After Deployment

1. Run fix script: `app/admin/scripts/fix/NN-schema-integrity.php` (issue #992 — promotes
   UNIQUE KEY to PRIMARY KEY and adds audit table indexes). Backup the database before running.

## User-Facing Changes

### Improvements

- **Safer image saves**
  ([#1084](https://github.com/unibrain1/elanregistry/issues/1084)):
  Cars are no longer saved with broken image references when an image upload or resize fails.
- **Owner email reliability**
  ([#1056](https://github.com/unibrain1/elanregistry/issues/1056)):
  Contact emails that reference a car now fail gracefully if the car record is missing.

## Admin-Facing Changes

- **Backup retention enforced**
  ([#1068](https://github.com/unibrain1/elanregistry/issues/1068)):
  BackupManager and backup operations now respect the retention constants defined in `config.php`.
- **Server path removed from backup API**
  ([#977](https://github.com/unibrain1/elanregistry/issues/977)):
  Absolute server filesystem paths are no longer exposed in backup API responses.

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
  — fix: harden transfer-request.php — Input::raw(), length validation, null guards, and info leak
- [#1083](https://github.com/unibrain1/elanregistry/issues/1083)
  — security: exception message leaked to client in chassis-validate.php
- [#1084](https://github.com/unibrain1/elanregistry/issues/1084)
  — fix: car saved with broken image references — guard mvTmpImages() failures and resize errors in save.php
- [#1097](https://github.com/unibrain1/elanregistry/issues/1097)
  — fix: statistics.js loadTabContent injects error.message into innerHTML
