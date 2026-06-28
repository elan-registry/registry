# Elan Registry v2.25.2 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Security & Critical Bugs

## Required Actions After Deployment

None.

## User-Facing Changes

### Improvements

- **Transfer request rate limiting** ([#973](https://github.com/unibrain1/elanregistry/issues/973)): Transfer request and owner contact email endpoints now enforce rate limits to prevent inbox flooding.

## Admin-Facing Changes

### Improvements

- **Quality score consistency** ([#961](https://github.com/unibrain1/elanregistry/issues/961)): Quality score in the owner management tab now matches the score shown on the owner profile page.
- **Correct error status codes** ([#981](https://github.com/unibrain1/elanregistry/issues/981)): Admin owner-info and owner-profile includes now return HTTP 400/404 on validation errors instead of 200.

## Issues Resolved

- [#942](https://github.com/unibrain1/elanregistry/issues/942) — Fix inconsistent DB update signature in ElanRegistryOwner::updateLocation()
- [#943](https://github.com/unibrain1/elanregistry/issues/943) — ElanRegistryOwner website validation silently mutates input instead of rejecting it
- [#961](https://github.com/unibrain1/elanregistry/issues/961) — fix: quality score calculation diverges between tab-owner_mgmt.php and ElanRegistryOwner class
- [#972](https://github.com/unibrain1/elanregistry/issues/972) — security: add explicit isLoggedIn() guard to edit.php AJAX endpoint
- [#973](https://github.com/unibrain1/elanregistry/issues/973) — security: add rate limiting to owner contact email, feedback, and transfer request endpoints
- [#974](https://github.com/unibrain1/elanregistry/issues/974) — security: remove GET action fallback from schema-operations.php
- [#981](https://github.com/unibrain1/elanregistry/issues/981) — fix: return correct HTTP status codes from load-owner-info and load-owner-profile on validation errors
