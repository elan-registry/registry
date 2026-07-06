# Elan Registry v2.25.6 Release Notes

**Release Date:** TBD
**Type:** Patch Release — Performance & Code Quality

## Required Actions After Deployment

- **Run fix script 09** (`app/admin/scripts/fix/09-Fix-Cars-Update-Trigger-Chassis-Override.php`) on each server after deployment to update the `cars_update` trigger to capture `NEW.chassis_override`.

- **Run fix script 10** (`app/admin/scripts/fix/10-Remove-Spam-Cleanup-Columns.php`) on each server after deployment to drop the 7 `elan_spam_*` columns from the `settings` table and remove the `spam_inactive_cleanup.php` cron row.

## User-Facing Changes

### Bug Fixes

- **Chassis availability check restored** (hotfix on main, 2026-07-05): Fixed 500 error on the Add Car form where the chassis availability check always failed silently — caused by a missing `use ElanRegistry\Input` import in the endpoint introduced by #1081.

- **ElanRegistryOwner location update fixed** ([#1150](https://github.com/unibrain1/elanregistry/issues/1150)): Fixed `TypeError` when saving owner location via the admin sync — `ElanRegistryOwner::find()` was called with a string user ID instead of `int`, causing a fatal error under strict types.

## Admin-Facing Changes

### Improvements

- **Admin AJAX rate limiting** ([#1141](https://github.com/unibrain1/elanregistry/issues/1141)): Rate limiting added inside `requireAdminAjax()`, covering all 9 admin AJAX endpoints automatically. Read/search endpoints (owner search, load profile/info, user/car details) are limited to 30 requests/minute per user; write endpoints (owner update, location sync, transfer approve/deny) to 10 requests/minute per user.

- **Statistics endpoint hardened** ([#1142](https://github.com/unibrain1/elanregistry/issues/1142)): `app/api/shared/statistics.php` now requires a POST request with a valid CSRF token (Option A), blocking cross-origin scraping. IP-based rate limiting (20 requests/minute) added to prevent automated scraping and reduce DoS potential (Option B).

### Developer-Facing Changes

- **requireAdminAjax() helper** ([#959](https://github.com/unibrain1/elanregistry/issues/959)): Extracted duplicated 16-line admin auth+CSRF guard from 9 files into a single reusable helper in `custom_functions.php`. Normalizes log categories: auth failures log to `LOG_CATEGORY_ACCESS_DENIED`, CSRF failures to `LOG_CATEGORY_SECURITY`.

- **CarVerificationManager / Car cleanup** ([#939](https://github.com/unibrain1/elanregistry/issues/939)): Removed no-op catch blocks in CarVerificationManager and CarImageProcessor; removed dead owner-cache block from Car::__construct(); added four named update methods to CarRepository and routed all direct `cars` writes in CarVerificationManager, CarImageProcessor, and ElanRegistryOwner::syncLocationToCars() through them.

- **CarTransferRepository** ([#1062](https://github.com/unibrain1/elanregistry/issues/1062)): New `ElanRegistry\Transfer\CarTransferRepository` class consolidating all SQL access to `car_transfer_requests`. Routes six callers (transfer-request.php, process-transfer-approve.php, process-transfer-deny.php, tab-car_mgmt.php, admin/index.php, TransferEmailService) through named methods (`findById`, `findPendingById`, `findPendingWithCarById`, `hasPendingForCar`, `create`, `updateStatus`, `getPendingWithCarAndUsers`, `getTodayStatusCounts`, `countPending`). Includes 8-test integration suite against the real DB covering SQL round-trips and status-filter correctness.

- **Admin dashboard cleanup** ([#969](https://github.com/unibrain1/elanregistry/issues/969)): Extracted duplicate `SELECT COUNT(*)` header queries from `admin/index.php` and `admin/maintenance.php` into a shared `getAdminSystemStatus()` helper in `custom_functions.php`. Routed the `action=merge` car-merge path in `admin/index.php` through the existing `CarRepository::transferHistory()`, `deleteCarUser()`, `deleteCar()`, and `insertHistory()` methods instead of four raw `$db` calls. Removed the defensive `$systemStatus` fallback from `tab-owner_mgmt.php` — the tab is only included from pages that populate `$systemStatus` upstream.

- **car_models filter query extraction** ([#1064](https://github.com/unibrain1/elanregistry/issues/1064)): Three inline `SELECT DISTINCT` queries for car listing filter pills extracted from `cars/index.php` into `CarRepository::getFilterOptions()`. Page now calls one repository method instead of three raw queries.

### Housekeeping

- **Housekeeping** ([#964](https://github.com/unibrain1/elanregistry/issues/964)): Deleted unused `app/assets/js/logging-standard.js` (documentation stub, not loaded by any page).

- **Remove deprecated backup shims** ([#705](https://github.com/unibrain1/elanregistry/issues/705)): `backup_functions.php` shim deleted; all callers migrated to `BackupManager` directly. `elan_backup_age` setting and its schema definition removed.

- **Remove spam/inactive user cleanup system** ([#1066](https://github.com/unibrain1/elanregistry/issues/1066)): Deleted `users/cron/spam_inactive_cleanup.php` (481 lines) and removed all 7 `elan_spam_*` schema columns. Fix script 10 drops the columns on existing databases.

- **Dead code sweep** ([#623](https://github.com/unibrain1/elanregistry/issues/623)): Deleted 4 unused exception classes (`ForbiddenException`, `UnauthorizedException`, `GeocodingException`, `FIXScriptException`), removed `dbIntOrNull()` and `withStatusCode()`/`toJson()` from `ApiResponse`, and pruned 18 unused `LogCategories` constants.

## Issues Resolved

- [#623](https://github.com/unibrain1/elanregistry/issues/623) — chore: remove dead code — unused functions, exception classes, and LogCategories constants
- [#1150](https://github.com/unibrain1/elanregistry/issues/1150) — fix: cast ElanRegistryOwner::find() userId to int to resolve TypeError in location update
- [#705](https://github.com/unibrain1/elanregistry/issues/705) — chore: remove deprecated backup shims and unused elan_backup_age setting
- [#939](https://github.com/unibrain1/elanregistry/issues/939) — refactor: remove dead code in CarVerificationManager/Car and route remaining direct DB writes through CarRepository
- [#959](https://github.com/unibrain1/elanregistry/issues/959) — refactor: extract requireAdminAjax() helper to eliminate 9-file auth+CSRF guard duplication
- [#964](https://github.com/unibrain1/elanregistry/issues/964) — chore: housekeeping — delete dead assets, remove dead POST block, archive fix script
- [#969](https://github.com/unibrain1/elanregistry/issues/969) — refactor: clean up manage-consolidated.php — getAdminSystemStatus() helper and CarRepository merge path
- [#1062](https://github.com/unibrain1/elanregistry/issues/1062) — refactor: create CarTransferRepository to consolidate car_transfer_requests data access
- [#1064](https://github.com/unibrain1/elanregistry/issues/1064) — refactor: extract car_models filter queries from cars/index.php into CarRepository
- [#1066](https://github.com/unibrain1/elanregistry/issues/1066) — chore: remove abandoned spam/inactive user cleanup system
- [#1141](https://github.com/unibrain1/elanregistry/issues/1141) — security: add per-user rate limiting to admin AJAX endpoints via requireAdminAjax()
- [#1142](https://github.com/unibrain1/elanregistry/issues/1142) — security: require CSRF + add IP rate limiting to public statistics endpoint
