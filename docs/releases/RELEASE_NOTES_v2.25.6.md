# Elan Registry v2.25.6 Release Notes

**Release Date:** TBD
**Type:** Patch Release — Performance & Code Quality

## Required Actions After Deployment

None.

## User-Facing Changes

### Bug Fixes

- **Chassis availability check restored** (hotfix on main, 2026-07-05): Fixed 500 error on the Add Car form where the chassis availability check always failed silently — caused by a missing `use ElanRegistry\Input` import in the endpoint introduced by #1081.

## Admin-Facing Changes

### Improvements

- **Admin AJAX rate limiting** ([#1141](https://github.com/unibrain1/elanregistry/issues/1141)): Rate limiting added to admin AJAX endpoints via the `requireAdminAjax()` helper. WIP: pending #959.

- **Statistics endpoint rate limiting** ([#1142](https://github.com/unibrain1/elanregistry/issues/1142)): Rate limiting or auth added to the public statistics API endpoint. WIP.

### Developer-Facing Changes

- **requireAdminAjax() helper** ([#959](https://github.com/unibrain1/elanregistry/issues/959)): Extracted duplicated 16-line admin auth+CSRF guard from 10 files into a single reusable helper.

- **CarRepository DB write consolidation** ([#939](https://github.com/unibrain1/elanregistry/issues/939)): Remaining direct `$db->update()`/`$db->insert()` calls in CarVerificationManager, CarImageProcessor, and syncLocationToCars routed through CarRepository.

- **CarTransferRepository** ([#1062](https://github.com/unibrain1/elanregistry/issues/1062)): New repository class consolidating car_transfer_requests data access from 5 scattered files.

- **manage-consolidated.php CarRepository** ([#1061](https://github.com/unibrain1/elanregistry/issues/1061)): Car merge path in manage-consolidated.php routes through CarRepository.

- **car_models filter query extraction** ([#1064](https://github.com/unibrain1/elanregistry/issues/1064)): SELECT DISTINCT queries for car listing filter pills moved to CarRepository.

- **getAdminSystemStatus() helper** ([#969](https://github.com/unibrain1/elanregistry/issues/969)): Duplicate stat queries in manage-consolidated.php and manage-maintenance.php consolidated into a single helper.

## Issues Resolved

- [#939](https://github.com/unibrain1/elanregistry/issues/939) — refactor: route remaining direct DB writes through CarRepository (CarVerificationManager, CarImageProcessor, syncLocationToCars)
- [#959](https://github.com/unibrain1/elanregistry/issues/959) — refactor: extract requireAdminAjax() helper to eliminate 10-file auth+CSRF guard duplication
- [#969](https://github.com/unibrain1/elanregistry/issues/969) — refactor: add getAdminSystemStatus() helper to eliminate duplicated stat queries
- [#1061](https://github.com/unibrain1/elanregistry/issues/1061) — refactor: route manage-consolidated.php car merge path through CarRepository
- [#1062](https://github.com/unibrain1/elanregistry/issues/1062) — refactor: create CarTransferRepository to consolidate car_transfer_requests data access
- [#1064](https://github.com/unibrain1/elanregistry/issues/1064) — refactor: extract car_models filter queries from cars/index.php into CarRepository
- [#1141](https://github.com/unibrain1/elanregistry/issues/1141) — Add rate limiting to admin AJAX endpoints
- [#1142](https://github.com/unibrain1/elanregistry/issues/1142) — Add rate limiting or auth to public statistics endpoint
