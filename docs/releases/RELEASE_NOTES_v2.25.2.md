# Elan Registry v2.25.2 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Security Hardening & Code Quality

## Required Actions After Deployment

None.

## User-Facing Changes

None in this release.

## Admin-Facing Changes

### Improvements

- **Rate limiting for contact and transfer endpoints** ([#973](https://github.com/unibrain1/elanregistry/issues/973)): Owner-contact email, feedback form, and car transfer requests are now rate-limited to prevent abuse.
- **Schema operations endpoint hardened** ([#974](https://github.com/unibrain1/elanregistry/issues/974)): Removed unsafe GET action fallback; CSRF check now unconditional; server paths no longer exposed in backup responses.
- **Car edit endpoint authentication fix** ([#972](https://github.com/unibrain1/elanregistry/issues/972)): Added explicit login guard before CSRF check and replaced legacy token error include with structured API response.
- **DB update call signature fix** ([#942](https://github.com/unibrain1/elanregistry/issues/942)): Fixed inconsistent argument order in `ElanRegistryOwner::updateLocation()` to match UserSpice DB API.
- **Website URL validation cleanup** ([#943](https://github.com/unibrain1/elanregistry/issues/943)): Removed redundant pre-sanitization regex from URL validation, preventing false rejection of valid URLs.
- **Unified quality score calculation** ([#961](https://github.com/unibrain1/elanregistry/issues/961)): Centralized duplicate quality score logic from 5 admin files into `ElanRegistryOwner` class methods.
- **HTTP status codes for owner info endpoints** ([#981](https://github.com/unibrain1/elanregistry/issues/981)): `load-owner-info.php` and `load-owner-profile.php` now return proper 400/404 status codes on error.

## Issues Resolved

- [#942](https://github.com/unibrain1/elanregistry/issues/942) — Fix inconsistent DB update signature in ElanRegistryOwner::updateLocation()
- [#943](https://github.com/unibrain1/elanregistry/issues/943) — Remove preg_replace pre-sanitization from website URL validation
- [#961](https://github.com/unibrain1/elanregistry/issues/961) — Unify quality score calculation across admin files
- [#972](https://github.com/unibrain1/elanregistry/issues/972) — Fix CSRF/auth ordering and structured error response in car edit endpoint
- [#973](https://github.com/unibrain1/elanregistry/issues/973) — Add rate limiting to owner-contact email, feedback form, and transfer request
- [#974](https://github.com/unibrain1/elanregistry/issues/974) — Remove GET action fallback and harden CSRF guard in schema-operations endpoint
- [#981](https://github.com/unibrain1/elanregistry/issues/981) — Return proper HTTP status codes from owner info AJAX endpoints
