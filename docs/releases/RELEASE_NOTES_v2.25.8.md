# Elan Registry v2.25.8 Release Notes

**Release Date:** July 7, 2026
**Type:** Patch Release - Cloudflare Rocket Loader Map Fix

## Required Actions After Deployment

None.

## User-Facing Changes

### Improvements

- **Map display restored in Safari** ([#1216](https://github.com/unibrain1/elanregistry/issues/1216)): Fixed Cloudflare Rocket Loader deferring MapLibre GL and Chart.js initialization, which caused WebGL context errors and blank maps on the statistics, car details, and account pages in Safari.

## Issues Resolved

- [#1216](https://github.com/unibrain1/elanregistry/issues/1216) — bug: MapLibre WebGL context errors on statistics page
