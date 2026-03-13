# Elan Registry v2.16.3 Release Notes

**Release Date:** TBD
**Type:** Patch Release - Security hardening and jQuery upgrade

## REQUIRED ACTIONS AFTER DEPLOYMENT

Update the jQuery CDN tag in the admin settings page:

1. Go to **Admin Panel → Settings → CDN Settings**
2. In the **jQuery CDN URL** field, replace the existing value with:

   ```html
   <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" integrity="sha384-1H217gwSVyLSIfaLxHbE7dRb3v4mYCKbpQvzx0cegeju1MVsGrX5xXxAvs/HgeFs" crossorigin="anonymous"></script>
   ```

3. Click **Save**

This updates the site-wide jQuery from 3.6.0 to 3.7.1. The error pages
(403/404/500) are updated automatically by the code deploy.

## User-Facing Changes

### Improvements

- **Identification Guide Update**: Expanded and improved the Lotus Elan
  identification guide documentation.

## Technical Changes

- **Security: CSRF-Vulnerable POST Handler Removed**
  ([#602](https://github.com/unibrain1/elanregistry/issues/602)): Removed
  the backup cleanup form POST path that accepted but never validated a CSRF
  token. Also removed duplicate inline JS functions that bypassed the secure
  ElanRegistryAPI client with raw `fetch()` calls lacking CSRF protection.

- **Security: Admin API Error Response Hardening**
  ([#603](https://github.com/unibrain1/elanregistry/issues/603)): Replaced
  exception messages, filenames, and line numbers in admin API error responses
  with generic messages. Sanitized user-supplied inputs (action, reason,
  filename) to prevent log injection and path traversal.

- **Security: Statistics API Input Sanitization**
  ([#604](https://github.com/unibrain1/elanregistry/issues/604)): Replaced
  raw `$_GET` access with `Input::get()` for server globals compliance.
  Removed unsanitized tab parameter from error response data to prevent XSS.
  Replaced hardcoded log category strings with `LogCategories` constants.

- **Security: jQuery Upgrade with SRI Hashes**
  ([#605](https://github.com/unibrain1/elanregistry/issues/605)): Upgraded
  jQuery slim from 3.5.1 to 3.7.1 with SRI integrity hashes on 403, 404,
  and 500 error pages. Updated seed SQL and admin settings placeholder to
  jQuery 3.7.1 with SRI hash for new installations.

## Issues Resolved

- [#602](https://github.com/unibrain1/elanregistry/issues/602) — Fix: add missing CSRF validation on backup cleanup POST handler
- [#603](https://github.com/unibrain1/elanregistry/issues/603) — Fix: remove internal error details from admin API error responses
- [#604](https://github.com/unibrain1/elanregistry/issues/604) — Fix: replace raw $_GET access in statistics API endpoint
- [#605](https://github.com/unibrain1/elanregistry/issues/605) — Security: upgrade jQuery from 3.5.1 to 3.7.x

## Summary

Security-focused patch with 4 fixes: removed a CSRF-vulnerable POST handler
from backup cleanup, hardened admin API error responses to prevent information
leakage and log injection, sanitized statistics API input handling, and upgraded
jQuery to 3.7.1 with SRI integrity hashes across error pages and seed
configuration.
