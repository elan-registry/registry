# Elan Registry v2.22.0 Release Notes

**Release Date:** May 6, 2026
**Type:** Minor Release - Google-Free Maps

## Required Actions After Deployment

Run the following FIX scripts in order after deploying:

1. `01-Remove-elan-google-geo-key.php` — removes `elan_google_geo_key` from the `settings` table (#433)
2. `02-Remove-elan-google-maps-key.php` — removes `elan_google_maps_key` from the `settings` table (#724, must run after #01)

## User-Facing Changes

### New Features

- **Google-Free Map Display** ([#724](https://github.com/unibrain1/elanregistry/issues/724)): Statistics and Car Details pages now use self-hosted MapLibre GL JS + VersaTiles Colorful instead of Google Maps — no API key, no Google scripts, no tracking. The world map on the Statistics page includes series-colored teardrop markers and a filter/legend to show or hide cars by series.

### Improvements

- **Updated Privacy Policy** ([#771](https://github.com/unibrain1/elanregistry/issues/771)): Privacy Policy updated to reflect the v2.22.0 Google-free migration — location/mapping section now discloses OpenStreetMap tile servers and Nominatim geocoding; confirms no Google services are in use. Effective date updated to May 6, 2026.
- **Faster Car Saves** ([#796](https://github.com/unibrain1/elanregistry/issues/796)): Fixed v2.19.0 regression where FilePond re-processed all existing images on every form submit, causing slow saves even for simple text-only changes.

## Admin-Facing Changes

### Improvements

- **Removed Google Maps API Key Setting** ([#724](https://github.com/unibrain1/elanregistry/issues/724)): Google Maps API key field and "Test Maps API Key" button removed from Admin Settings; `elan_google_maps_key` column dropped from the `settings` table via FIX script `02`.
- **Fixed Dangling Page Reference in Seed SQL** ([#799](https://github.com/unibrain1/elanregistry/issues/799)): `database/3-configuration.sql` now correctly references `tab-health.php` (page ID 306) instead of the deleted `tab-system.php`.
- **Architecture Wiki Restored to Main** ([#828](https://github.com/unibrain1/elanregistry/pull/828)): v2.20.0 architecture documentation update (admin panel split, scripts restructure, new tabs) was accidentally committed to `master` and is now synced to `main` and the live GitHub wiki.
- **Removed Deprecated Google Geocoding Code** ([#433](https://github.com/unibrain1/elanregistry/issues/433)): Deleted `LocationGeocoder.php` and removed deprecated `geocodeAddress()`/`applyGeocoding()` methods from `ElanRegistryOwner`; cleaned up `elan_google_geo_key` via FIX script.

## Issues Resolved

- [#433](https://github.com/unibrain1/elanregistry/issues/433) — CLEANUP: Remove deprecated Google Geocoding API code
- [#724](https://github.com/unibrain1/elanregistry/issues/724) — feat: replace Google Maps with self-hosted MapLibre GL JS + VersaTiles for all map display
- [#771](https://github.com/unibrain1/elanregistry/issues/771) — Update Privacy Policy for v2.22.0 Google-free migration
- [#796](https://github.com/unibrain1/elanregistry/issues/796) — Bug: saving a car is slow even for simple text-only changes (v2.19.0 regression)
- [#799](https://github.com/unibrain1/elanregistry/issues/799) — bug: database/3-configuration.sql references deleted tab-system.php (page ID 306)
