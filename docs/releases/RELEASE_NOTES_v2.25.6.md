# Elan Registry v2.25.6 Release Notes

**Release Date:** TBD
**Type:** Patch Release — Performance & Code Quality

## Required Actions After Deployment

- **Run fix script 09** (`app/admin/scripts/fix/09-Fix-Cars-Update-Trigger-Chassis-Override.php`) on each server after deployment to update the `cars_update` trigger to capture `NEW.chassis_override`.

## User-Facing Changes

### Bug Fixes

- **Chassis availability check restored** (hotfix on main, 2026-07-05): Fixed 500 error on the Add Car form where the chassis availability check always failed silently — caused by a missing `use ElanRegistry\Input` import in the endpoint introduced by #1081.

## Admin-Facing Changes

### Improvements

- **Admin AJAX rate limiting** ([#1141](https://github.com/unibrain1/elanregistry/issues/1141)): Rate limiting added to admin AJAX endpoints via the `requireAdminAjax()` helper. WIP: pending #959.

- **Statistics endpoint rate limiting** ([#1142](https://github.com/unibrain1/elanregistry/issues/1142)): Rate limiting or auth added to the public statistics API endpoint. WIP.

### Developer-Facing Changes

- **requireAdminAjax() helper** ([#959](https://github.com/unibrain1/elanregistry/issues/959)): Extracted duplicated 16-line admin auth+CSRF guard from 9 files into a single reusable helper in `custom_functions.php`. Normalizes log categories: auth failures log to `LOG_CATEGORY_ACCESS_DENIED`, CSRF failures to `LOG_CATEGORY_SECURITY`.

- **CarVerificationManager / Car cleanup** ([#939](https://github.com/unibrain1/elanregistry/issues/939)): Removed no-op catch blocks in CarVerificationManager and dead owner-cache block in Car::__construct(); routed remaining direct DB writes in CarVerificationManager, CarImageProcessor, and syncLocationToCars through CarRepository. WIP.

- **CarTransferRepository** ([#1062](https://github.com/unibrain1/elanregistry/issues/1062)): New repository class consolidating car_transfer_requests data access from scattered files. WIP.

- **manage-consolidated.php cleanup** ([#969](https://github.com/unibrain1/elanregistry/issues/969)): Duplicate stat queries consolidated into `getAdminSystemStatus()` helper; car merge path routed through CarRepository. WIP.

- **car_models filter query extraction** ([#1064](https://github.com/unibrain1/elanregistry/issues/1064)): SELECT DISTINCT queries for car listing filter pills moved to CarRepository. WIP.

- **Admin AJAX rate limiting** ([#1141](https://github.com/unibrain1/elanregistry/issues/1141)): Rate limiting added to admin AJAX endpoints. WIP.

### Housekeeping

- **Housekeeping** ([#964](https://github.com/unibrain1/elanregistry/issues/964)): Deleted unused `logging-standard.js` and `custom_totp_policy.php`; removed dead POST-handling block from `contact/owner.php`; archived completed fix script `05-Fix-Website-Scheme.php`. WIP.

- **Remove deprecated backup shims** ([#705](https://github.com/unibrain1/elanregistry/issues/705)): Deprecated backup shims and unused `elan_backup_age` setting removed. WIP.

- **Remove spam/inactive user cleanup system** ([#1066](https://github.com/unibrain1/elanregistry/issues/1066)): Abandoned spam and inactive user cleanup system removed. WIP.

- **Dead code sweep** ([#623](https://github.com/unibrain1/elanregistry/issues/623)): Unused functions, exception classes, and LogCategories constants removed. WIP.

## Issues Resolved

- [#623](https://github.com/unibrain1/elanregistry/issues/623) — chore: remove dead code — unused functions, exception classes, and LogCategories constants
- [#705](https://github.com/unibrain1/elanregistry/issues/705) — chore: remove deprecated backup shims and unused elan_backup_age setting
- [#939](https://github.com/unibrain1/elanregistry/issues/939) — refactor: remove dead code in CarVerificationManager/Car and route remaining direct DB writes through CarRepository
- [#959](https://github.com/unibrain1/elanregistry/issues/959) — refactor: extract requireAdminAjax() helper to eliminate 9-file auth+CSRF guard duplication
- [#964](https://github.com/unibrain1/elanregistry/issues/964) — chore: housekeeping — delete dead assets, remove dead POST block, archive fix script
- [#969](https://github.com/unibrain1/elanregistry/issues/969) — refactor: clean up manage-consolidated.php — getAdminSystemStatus() helper and CarRepository merge path
- [#1062](https://github.com/unibrain1/elanregistry/issues/1062) — refactor: create CarTransferRepository to consolidate car_transfer_requests data access
- [#1064](https://github.com/unibrain1/elanregistry/issues/1064) — refactor: extract car_models filter queries from cars/index.php into CarRepository
- [#1066](https://github.com/unibrain1/elanregistry/issues/1066) — chore: remove abandoned spam/inactive user cleanup system
- [#1141](https://github.com/unibrain1/elanregistry/issues/1141) — Add rate limiting to admin AJAX endpoints
- [#1142](https://github.com/unibrain1/elanregistry/issues/1142) — Add rate limiting or auth to public statistics endpoint
