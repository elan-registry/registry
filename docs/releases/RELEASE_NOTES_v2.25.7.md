# Elan Registry v2.25.7 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - Consistency Fixes

## Required Actions After Deployment

[To be completed as issues are resolved. Check for: new maintenance script availability (#1127).]

## User-Facing Changes

### Improvements

- **Cache-busting on first-party assets** ([#1126](https://github.com/unibrain1/elanregistry/issues/1126)): Static JS and CSS now include a version parameter so browsers and CDN always serve current assets after a deployment without requiring a manual cache purge.
- **Statistics page performance** ([#946](https://github.com/unibrain1/elanregistry/issues/946)): Series count query reduced from 6 separate round-trips to a single conditional-aggregate query, improving statistics page load time.
- **Car history error feedback** ([#1096](https://github.com/unibrain1/elanregistry/issues/1096)): Car history tab now displays a visible warning instead of failing silently when DataTable initialization fails.
- **Contact form message display** ([#1096](https://github.com/unibrain1/elanregistry/issues/1096)): Contact form success and error messages now use the site-wide notification system for consistent placement and styling.

## Admin-Facing Changes

### New Features

- **Unverified account cleanup tool** ([#1127](https://github.com/unibrain1/elanregistry/issues/1127)): New tab on the admin dashboard to review and selectively delete unverified accounts with no car associations — an admin-initiated, GDPR-aligned replacement for the removed automated cleanup cron.

### Improvements

- **Transfer approval is now atomic** ([#1175](https://github.com/unibrain1/elanregistry/issues/1175)): Car ownership transfer and request-status update are wrapped in a single transaction; if the status update fails (including TOCTOU "already processed"), the ownership change rolls back cleanly. As a side effect, the deny flow now also correctly returns an error when attempting to deny an already-processed request.
- **Transfer approval shows correct error messages** ([#1178](https://github.com/unibrain1/elanregistry/issues/1178)): The transfer approval endpoint now catches all car-domain exceptions (`CarValidationException`, `CarDatabaseException`) correctly, ensuring user-friendly messages (e.g. "user not found") reach the admin rather than falling through to the generic "unexpected error" response. The HTTP status code is also now derived from the exception type (422 for validation, 500 for database errors) rather than hardcoded to 400.
- **Correct 404 for missing cars** ([#976](https://github.com/unibrain1/elanregistry/issues/976)): Admin car-details endpoint now returns HTTP 404 for missing cars instead of HTTP 200 with an error payload, improving error visibility.

## Technical Changes

- **Logging consistency** ([#518](https://github.com/unibrain1/elanregistry/issues/518)): All `withLogging()` and `logger()` calls in non-car endpoints, test files, and PHPDoc examples now use `LogCategories::` constants instead of hardcoded string literals, completing the migration started in issue #464.
- **`ASSET_VERSION` constant for cache-busting** ([#1126](https://github.com/unibrain1/elanregistry/issues/1126)): Added `ASSET_VERSION` PHP constant to `usersc/includes/config.php` (loaded on every page via `loader.php`). Reads the `VERSION` file written by post-receive deploy hooks; allow-list validated against `[a-zA-Z0-9.\-]+` (git describe format); falls back to `'dev'` when absent or unreadable. Applied as `?v=<version>` to all 20 first-party `.min.js`/`.min.css` asset URLs across 11 files, replacing the previous one-off `filemtime()` on `statistics.min.js`.
- **Removed deprecated `X-XSS-Protection` header** ([#976](https://github.com/unibrain1/elanregistry/issues/976)): The header is ignored by all modern browsers (Chrome dropped it in v78, Firefox never implemented it) and implied XSS protection was being provided when it wasn't. The Content Security Policy header remains the correct mechanism and is unchanged.
- **Removed ineffective `cleanString()` defense** ([#976](https://github.com/unibrain1/elanregistry/issues/976)): The feedback-form input filter (`str_replace` on `"content-type"`, `"bcc:"`, `"to:"`, `"cc:"`, `"href"`) was trivially bypassable (e.g. `"ccontent-typeontent-type"` passes through) and silently mutated legitimate user text. Email is sent via the Brevo API rather than raw SMTP header concatenation, so the header-injection vector it purported to block was never real.
- **`car_transfer_requests` FK column types** ([#1164](https://github.com/unibrain1/elanregistry/issues/1164)): `requested_by_user_id` and `created_by` corrected from `int(11)` to `INT UNSIGNED` to match `users.id` and the project-wide FK convention. Migration script `11-Fix-Car-Transfer-Requests-Column-Types.php` handles existing databases; `database/1-schema.sql` updated for fresh installs.

## Issues Resolved

- [#518](https://github.com/unibrain1/elanregistry/issues/518) ✓ — Migrate non-car endpoint logging to LogCategories constants
- [#946](https://github.com/unibrain1/elanregistry/issues/946) ✓ — refactor: consolidate getSeriesCounts() into single conditional-aggregate query
- [#960](https://github.com/unibrain1/elanregistry/issues/960) ✓ — refactor: eliminate boilerplate and duplicated field lists in exception classes and ElanRegistryOwner
- [#976](https://github.com/unibrain1/elanregistry/issues/976) ✓ — chore: remove deprecated X-XSS-Protection header and ineffective cleanString() defense
- [#1096](https://github.com/unibrain1/elanregistry/issues/1096) ✓ — fix: correct DataTable catch behavior in car_details.js and resolve npm vulnerability
- [#1126](https://github.com/unibrain1/elanregistry/issues/1126) ✓ — enhancement: add cache-busting version parameter to static asset URLs
- [#1127](https://github.com/unibrain1/elanregistry/issues/1127) — feat: maintenance script — report and delete unverified accounts with no car associations
- [#1151](https://github.com/unibrain1/elanregistry/issues/1151) ✓ — chore: fix PHP 8.5 ReflectionProperty deprecations in test infrastructure and remove unreliable CarShowcaseService tests
- [#1182](https://github.com/unibrain1/elanregistry/issues/1182) ✓ — test: migrate getNewCarIds() floor/tie-breaking tests to CarShowcaseServiceTest
- [#1167](https://github.com/unibrain1/elanregistry/issues/1167) — fix: CarRepository::getHistory() returns null for empty history — should return []
- [#1175](https://github.com/unibrain1/elanregistry/issues/1175) — fix: process-transfer-approve.php — wrap transfer + updateStatus in a shared transaction
- [#1164](https://github.com/unibrain1/elanregistry/issues/1164) ✓ — fix: car_transfer_requests.requested_by_user_id and created_by should be INT UNSIGNED
- [#1178](https://github.com/unibrain1/elanregistry/issues/1178) — fix: process-transfer-approve.php — widen catch to CarException base to preserve user-friendly messages
