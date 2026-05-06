# Elan Registry v2.22.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Google-Free Maps

## Required Actions After Deployment

Run the following FIX scripts in order after deploying:

1. FIX script from #433: removes `elan_google_geo_key` from the `settings` table
2. FIX script from #724: removes `elan_google_maps_key` from the `settings` table (must run after #433 FIX script)

## User-Facing Changes

### New Features

- **Google-Free Map Display** ([#724](https://github.com/unibrain1/elanregistry/issues/724)): Statistics and Car Details pages now use Leaflet/OpenStreetMap instead of Google Maps — no Google scripts, no tracking, and a smaller page payload (~42KB vs 150KB+).

### Improvements

- **Faster Car Saves** ([#796](https://github.com/unibrain1/elanregistry/issues/796)): Fixed v2.19.0 regression where FilePond re-processed all existing images on every form submit, causing slow saves even for simple text-only changes.

## Admin-Facing Changes

### Improvements

- **Removed Google Maps API Key Setting** ([#724](https://github.com/unibrain1/elanregistry/issues/724)): Google Maps API key field and "Test Maps API Key" button removed from Admin Settings; `elan_google_maps_key` cleaned up via FIX script.
- **Fixed Dangling Page Reference in Seed SQL** ([#799](https://github.com/unibrain1/elanregistry/issues/799)): `database/3-configuration.sql` now correctly references `tab-health.php` (page ID 306) instead of the deleted `tab-system.php`.
- **Architecture Wiki Restored to Main** ([#828](https://github.com/unibrain1/elanregistry/pull/828)): v2.20.0 architecture documentation update (admin panel split, scripts restructure, new tabs) was accidentally committed to `master` and is now synced to `main` and the live GitHub wiki.
- **Removed Deprecated Google Geocoding Code** ([#433](https://github.com/unibrain1/elanregistry/issues/433)): Deleted `LocationGeocoder.php` and removed deprecated `geocodeAddress()`/`applyGeocoding()` methods from `ElanRegistryOwner`; cleaned up `elan_google_geo_key` via FIX script.

## Issues Resolved

- [#433](https://github.com/unibrain1/elanregistry/issues/433) — CLEANUP: Remove deprecated Google Geocoding API code
- [#724](https://github.com/unibrain1/elanregistry/issues/724) — feat: replace Google Maps with Leaflet/OpenStreetMap for all map display
- [#796](https://github.com/unibrain1/elanregistry/issues/796) — Bug: saving a car is slow even for simple text-only changes (v2.19.0 regression)
- [#799](https://github.com/unibrain1/elanregistry/issues/799) — bug: database/3-configuration.sql references deleted tab-system.php (page ID 306)
