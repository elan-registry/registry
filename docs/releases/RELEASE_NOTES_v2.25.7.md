# Elan Registry v2.25.7 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Consistency Fixes

## Required Actions After Deployment

[To be completed as issues are resolved. Check for: npm dependency updates (#1096), new maintenance script availability (#1127).]

## User-Facing Changes

### Improvements

- **Cache-busting on first-party assets** ([#1126](https://github.com/unibrain1/elanregistry/issues/1126)): Static JS and CSS now include a version parameter so browsers and CDN always serve current assets after a deployment without requiring a manual cache purge.
- **Statistics page performance** ([#946](https://github.com/unibrain1/elanregistry/issues/946)): Series count query reduced from 6 separate round-trips to a single conditional-aggregate query, improving statistics page load time.
- **Car history error feedback** ([#1096](https://github.com/unibrain1/elanregistry/issues/1096)): Car history tab now displays a visible warning instead of failing silently when DataTable initialization fails.
- **Contact form message display** ([#1096](https://github.com/unibrain1/elanregistry/issues/1096)): Contact form success and error messages now use the site-wide notification system for consistent placement and styling.

## Admin-Facing Changes

### New Features

- **Unverified account cleanup tool** ([#1127](https://github.com/unibrain1/elanregistry/issues/1127)): New maintenance script to review and delete unverified accounts with no car associations — an admin-initiated, GDPR-aligned replacement for the removed automated cleanup cron.

### Improvements

- **Transfer approval is now atomic** ([#1175](https://github.com/unibrain1/elanregistry/issues/1175)): Car ownership transfer and request-status update are wrapped in a single transaction; if the status update fails (including TOCTOU "already processed"), the ownership change rolls back cleanly. As a side effect, the deny flow now also correctly returns an error when attempting to deny an already-processed request.
- **Correct 404 for missing cars** ([#976](https://github.com/unibrain1/elanregistry/issues/976)): Admin car-details endpoint now returns HTTP 404 for missing cars instead of HTTP 200 with an error payload, improving error visibility.

## Issues Resolved

- [#518](https://github.com/unibrain1/elanregistry/issues/518) — Migrate non-car endpoint logging to LogCategories constants
- [#946](https://github.com/unibrain1/elanregistry/issues/946) — refactor: consolidate getSeriesCounts() into single conditional-aggregate query
- [#960](https://github.com/unibrain1/elanregistry/issues/960) — refactor: eliminate boilerplate and duplicated field lists in exception classes and ElanRegistryOwner
- [#976](https://github.com/unibrain1/elanregistry/issues/976) — chore: remove deprecated X-XSS-Protection header and ineffective cleanString() defense
- [#1096](https://github.com/unibrain1/elanregistry/issues/1096) — fix: correct DataTable catch behavior in car_details.js and resolve npm vulnerability
- [#1126](https://github.com/unibrain1/elanregistry/issues/1126) — enhancement: add cache-busting version parameter to static asset URLs
- [#1127](https://github.com/unibrain1/elanregistry/issues/1127) — feat: maintenance script — report and delete unverified accounts with no car associations
- [#1151](https://github.com/unibrain1/elanregistry/issues/1151) ✓ — chore: fix PHP 8.5 ReflectionProperty deprecations in test infrastructure and remove unreliable CarShowcaseService tests
- [#1167](https://github.com/unibrain1/elanregistry/issues/1167) — fix: CarRepository::getHistory() returns null for empty history — should return []
- [#1175](https://github.com/unibrain1/elanregistry/issues/1175) — fix: process-transfer-approve.php — wrap transfer + updateStatus in a shared transaction
