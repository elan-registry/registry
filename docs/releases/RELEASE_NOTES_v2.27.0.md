# Elan Registry v2.27.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - Security Hardening

## Required Actions After Deployment

None.

## User-Facing Changes

### Improvements

- **Contact Owner Privacy** ([#1322](https://github.com/unibrain1/elanregistry/issues/1322)): Contact-owner page now shows only the owner's first name, consistent with the published privacy policy.

## Admin-Facing Changes

### Improvements

- **Owner Privilege Escalation Guard** ([#1232](https://github.com/unibrain1/elanregistry/issues/1232)): Removed `active` and `permissions` from `Owner::extractUserFields()` allowlist; changed the `validateAndSanitizeFields()` default case to drop unknown fields instead of passing them through. Prevents any future endpoint from accidentally writing these privilege-controlling columns via the general profile-update path.
- **Server Hardening** ([#1242](https://github.com/unibrain1/elanregistry/issues/1242)): Blocked web access to PHPUnit config, PHPStan config, and npm manifests; disabled HTTP TRACE; added Permissions-Policy header opting out of geolocation, camera, microphone, and payment APIs.
- **CSP Tightening** ([#1326](https://github.com/unibrain1/elanregistry/issues/1326)): Added `form-action 'self'` to the Content Security Policy (closing a form-hijacking gap that `default-src` doesn't cover); removed `unsafe-eval` from `script-src` after verifying no custom JS uses `eval()` or `new Function()`.
- **Backup Authorization** ([#1308](https://github.com/unibrain1/elanregistry/issues/1308)): Backup and restore operations now require Administrator role; editor accounts are explicitly rejected.
- **Admin Script CSRF Hardening** ([#1308](https://github.com/unibrain1/elanregistry/issues/1308)): Destructive admin maintenance scripts now require POST + CSRF token; the fix-script template has been updated so future scripts inherit this pattern.

## Issues Resolved

- [#1232](https://github.com/unibrain1/elanregistry/issues/1232) — security: latent privilege escalation — extractUserFields() allows active/permissions through validateAndSanitizeFields() default passthrough
- [#1242](https://github.com/unibrain1/elanregistry/issues/1242) — security: block phpunit*.xml from web access and disable HTTP TRACE in .htaccess
- [#1243](https://github.com/unibrain1/elanregistry/issues/1243) — security: verify UserSpice brute-force login protection is active and correctly configured
- [#1304](https://github.com/unibrain1/elanregistry/issues/1304) — security: escape all DataTables text columns in car list and factory table (stored XSS)
- [#1305](https://github.com/unibrain1/elanregistry/issues/1305) — security: require login on public car-data API endpoints (unauthenticated PII exposure)
- [#1306](https://github.com/unibrain1/elanregistry/issues/1306) — security: escape profile and car fields in user_form_hook.php (stored XSS reaching admin)
- [#1307](https://github.com/unibrain1/elanregistry/issues/1307) — security: validate uploaded image filenames against a secure-name allowlist (glob hijack + path-traversal metadata oracle)
- [#1308](https://github.com/unibrain1/elanregistry/issues/1308) — security: require POST + CSRF on destructive admin GET actions and the fix-script template
- [#1311](https://github.com/unibrain1/elanregistry/issues/1311) — fix: prevent lost-update / TOCTOU data loss in image removal and car merge/delete
- [#1312](https://github.com/unibrain1/elanregistry/issues/1312) — fix: close fail-open paths in chassis-availability check and upload path guard
- [#1322](https://github.com/unibrain1/elanregistry/issues/1322) — fix: contact-owner page exposes owner's last name, contradicting the privacy policy (GDPR)
- [#1326](https://github.com/unibrain1/elanregistry/issues/1326) — security: CSP quick wins — add form-action 'self' and remove unsafe-eval
- [#1327](https://github.com/unibrain1/elanregistry/issues/1327) — security: extract inline JS from admin templates to external files
- [#1328](https://github.com/unibrain1/elanregistry/issues/1328) — security: extract inline JS from user-facing pages and remove unsafe-inline from CSP
