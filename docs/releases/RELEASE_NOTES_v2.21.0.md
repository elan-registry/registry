# Elan Registry v2.21.0 Release Notes

**Release Date:** [DATE]
**Type:** Minor Release - UserSpice 6.0.8 Update

## Required Actions After Deployment

1. Run the UserSpice DB updater: navigate to `/users/admin/` and apply new indexes from 6.0.8 (#02296).
2. Log in via production (behind Cloudflare) and verify the `Secure` cookie flag is set in browser devtools.

## User-Facing Changes

No direct user-facing feature changes in this release. All changes are infrastructure and security improvements.

## Admin-Facing Changes

### Improvements

- **Login open redirect and XSS fixed** ([#820](https://github.com/unibrain1/elanregistry/issues/820)): Fixes open redirect, passkey JS XSS, and CSP nonce gaps.
- **Secure session cookies behind Cloudflare** ([#806](https://github.com/unibrain1/elanregistry/issues/806)): Secure and SameSite=Strict flags set correctly.
- **UserSpice 6.0.8 upgrade** ([#805](https://github.com/unibrain1/elanregistry/issues/805)): Closes CVE-2026-30964 (passkey path); applies DB performance indexes.
- **User Manager columns reconciled** ([#808](https://github.com/unibrain1/elanregistry/issues/808)): Override aligned to 6.0.8; custom columns preserved.
- **Customizer template updated to 2.1.0** ([#807](https://github.com/unibrain1/elanregistry/issues/807)): iPad mini layout fix; Lotus green CSS re-saved.

## Issues Resolved

- [#804](https://github.com/unibrain1/elanregistry/issues/804) — chore: pre-upgrade audit — snapshot UserSpice override inventory before 6.0.8 update
- [#805](https://github.com/unibrain1/elanregistry/issues/805) — chore: apply UserSpice 6.0.8 update and run DB updater
- [#806](https://github.com/unibrain1/elanregistry/issues/806) — security: port secure-cookie and trusted-proxy pattern into users/init.php for Cloudflare
- [#807](https://github.com/unibrain1/elanregistry/issues/807) — chore: update Customizer template to 2.1.0 and re-save custom CSS
- [#808](https://github.com/unibrain1/elanregistry/issues/808) — chore: diff and reconcile usersc/includes/user_manager_columns.php against 6.0.8 upstream shape
- [#809](https://github.com/unibrain1/elanregistry/issues/809) — test: smoke-test plan — authentication flows, account page, User Manager after 6.0.8
- [#810](https://github.com/unibrain1/elanregistry/issues/810) — docs: update USERSPICE_FUNCTIONS.md with new/changed function references from 6.0.8
- [#820](https://github.com/unibrain1/elanregistry/issues/820) — security: port 6.0.8 security fixes into usersc/login.php (open redirect, XSS, CSP nonce)
