# Elan Registry v2.25.5 Release Notes

**Release Date:** TBD
**Type:** Patch Release - App Directory Phase 2 & Test Coverage

## Required Actions After Deployment

TBD — will be updated as issues are completed. Expected: UserSpice `pages` table
URL migration script for #1040 (app/owner/ path registration).

## User-Facing Changes

### Improvements

- **Recent Registrations chart ends at today** ([#1128](https://github.com/unibrain1/elanregistry/issues/1128)): The statistics page chart now buckets registrations by calendar day over a 91-day rolling window (previously 13 weekly buckets keyed by each week's Monday). The rightmost x-axis label is always today's date, and label density is capped via Chart.js `maxTicksLimit` so the axis stays readable.

## Admin-Facing Changes

### Security Fixes

- **XSS: Harden admin car management tab against stored XSS** ([#1124](https://github.com/unibrain1/elanregistry/issues/1124)): Replaced `innerHTML` template-literal assignments in `openAdminContactModal()` with DOM API calls; switched all onclick car/owner field encoding from single-quoted JS strings to `json_encode()`; added missing `htmlspecialchars()` to six unescaped DB fields in the duplicate-detection section.
- **XSS: Fix innerHTML injection in statistics tab renderers** ([#1125](https://github.com/unibrain1/elanregistry/issues/1125)): `renderSeriesTable()` and `renderQualityTab()` now use DOM API (`createElement`/`textContent`) instead of template-literal `innerHTML`; `renderGeographicTab()` and `renderColorsTab()` annotated as safe (static HTML only).

### Improvements

- **Internal: Reorganize owner-facing pages under app/owner/** ([#1040](https://github.com/unibrain1/elanregistry/issues/1040)): Moves car listing, details, edit, factory, contact, statistics, and privacy pages to a consistent `app/owner/` directory structure (Phase 2 of app/ reorganization).

## Issues Resolved

- [#907](https://github.com/unibrain1/elanregistry/issues/907) — tests: CarDataTablesService per-column search integration test
- [#987](https://github.com/unibrain1/elanregistry/issues/987) — test: add security regression tests for unauthorized car edits (CarOwnershipSecurityTest) and sender impersonation (OwnerEmailSecurityTest)
- [#989](https://github.com/unibrain1/elanregistry/issues/989) — test: add integration tests for 3 untested admin owner-management endpoints
- [#990](https://github.com/unibrain1/elanregistry/issues/990) — test: add TransferRequestTest for the transfer initiation step
- [#1040](https://github.com/unibrain1/elanregistry/issues/1040) — refactor: create app/owner/ directory and migrate owner-facing pages (Phase 2)
- [#1092](https://github.com/unibrain1/elanregistry/issues/1092) — test: add TransferEmailService success-path and partial-failure unit tests
- [#1093](https://github.com/unibrain1/elanregistry/issues/1093) — test: add process-admin-settings.php field-allowlist behavioral tests
- [#1094](https://github.com/unibrain1/elanregistry/issues/1094) — test: replace tautological StatisticsApiTest assertions with behavioral tests
- [#1124](https://github.com/unibrain1/elanregistry/issues/1124) — security: openAdminContactModal() injects car data via innerHTML in tab-manage_cars.php
- [#1125](https://github.com/unibrain1/elanregistry/issues/1125) — security: statistics.js tab renderers inject server-sourced data via innerHTML
- [#1128](https://github.com/unibrain1/elanregistry/issues/1128) — enhancement: Recent Registrations chart — switch to daily buckets so chart ends at today
