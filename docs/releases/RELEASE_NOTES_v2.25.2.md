# Elan Registry v2.25.2 Release Notes

**Release Date:** 2026-06-28
**Type:** Patch Release - Security Hardening & Critical Bug Fixes

## Required Actions After Deployment

None.

## User-Facing Changes

No changes visible to public registry visitors.

## Admin-Facing Changes

### Bug Fixes

- **Owner location update** ([#942](https://github.com/unibrain1/elanregistry/issues/942)): Fixes a crash when saving owner locations caused by a mismatched DB update call signature.
- **Website URL validation** ([#943](https://github.com/unibrain1/elanregistry/issues/943)): Owner website URLs are now validated and stored verbatim without silent character-stripping.
- **Quality score consistency** ([#961](https://github.com/unibrain1/elanregistry/issues/961)): Owner profile quality scores now match between the admin owner management tab and the owner profile page.
- **HTTP status codes** ([#981](https://github.com/unibrain1/elanregistry/issues/981)): Admin AJAX owner-info and owner-profile endpoints now return HTTP 400 for invalid IDs and 404 for not-found owners instead of 200.

### Security

- **Rate limiting** ([#973](https://github.com/unibrain1/elanregistry/issues/973)): Adds rate limits to the owner contact email, feedback submission, and car transfer request endpoints to prevent abuse. A pre-PR review fix ensures rate-limited requests to the owner contact endpoint skip all database work, not just the final email send.
- **Login guard** ([#972](https://github.com/unibrain1/elanregistry/issues/972)): Adds an explicit `isLoggedIn()` check to the `edit.php` AJAX endpoint as defense-in-depth.
- **Schema operations hardening** ([#974](https://github.com/unibrain1/elanregistry/issues/974)): Removes the GET action fallback from `schema-operations.php` and tightens the CSRF guard to reject non-POST requests immediately.

## Issues Resolved

- [#942](https://github.com/unibrain1/elanregistry/issues/942) — Fix inconsistent DB update signature in ElanRegistryOwner::updateLocation()
- [#943](https://github.com/unibrain1/elanregistry/issues/943) — ElanRegistryOwner website validation silently mutates input instead of rejecting it
- [#961](https://github.com/unibrain1/elanregistry/issues/961) — fix: quality score calculation diverges between tab-owner_mgmt.php and ElanRegistryOwner class
- [#972](https://github.com/unibrain1/elanregistry/issues/972) — security: add explicit isLoggedIn() guard to edit.php AJAX endpoint
- [#973](https://github.com/unibrain1/elanregistry/issues/973) — security: add rate limiting to owner contact email, feedback, and transfer request endpoints
- [#974](https://github.com/unibrain1/elanregistry/issues/974) — security: remove GET action fallback from schema-operations.php
- [#981](https://github.com/unibrain1/elanregistry/issues/981) — fix: return correct HTTP status codes from load-owner-info and load-owner-profile on validation errors
