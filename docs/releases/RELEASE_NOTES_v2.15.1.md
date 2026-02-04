# Elan Registry v2.15.1 Release Notes

**Release Date:** February 3, 2026
**Type:** Patch Release - Security Hardening & Bug Fixes

## Required Actions After Deployment

None

## User-Facing Changes

### Bug Fixes

- **Admin Management AJAX Requests**: Fixed CSRF token validation failures that
  caused "JSON Parse error" when using car and user lookup features in the
  consolidated management interface.
- **Car/User Not Found Responses**: Fixed improper HTTP 404 responses when
  searching for non-existent cars or users—now returns proper JSON error
  messages with user-friendly descriptions.

## Technical Changes

- **CSRF Token Availability**: Made CSRF token available to ElanRegistryAPI
  client via `data-csrf-token` attribute on document element for reliable AJAX
  request validation.
- **Endpoint URL Construction**: Fixed URL path concatenation in
  manage-consolidated.js to properly handle `$us_url_root` with or without
  trailing slashes across all three AJAX endpoint calls.
- **API Response Format**: Standardized error responses from
  `process-car-details.php` and `process-user-details.php` to return HTTP 200
  with `success: false` instead of HTTP 404 for consistency with Pattern A API
  design.
- **Backup Operations Security**: Added missing CSRF token validation to
  `backup-operations.php` for all POST operations handling critical backup
  creation and deletion functionality.
- **Schema Operations Security**: Added comprehensive CSRF token validation to
  `schema-operations.php` for all POST requests (previously only validated one
  action).

## Issues Resolved

None (bug fixes and security improvements)

## Documentation Changes

- Migrated Architecture Guide to GitHub Wiki
- Migrated Integration Guide to GitHub Wiki
- Migrated UserSpice Integration Guide to GitHub Wiki
- Documentation optimization phases: Reorganized ERROR_HANDLING.md,
  CODING_STANDARDS.md, and development documentation structure
- Created Frontend API Integration Guide
- Expanded QUICK_REFERENCE.md with code patterns
- Organized LOG_CATEGORIES.md for improved navigation
- Created PAGE_LOADING_FLOW.md quick summary
- Added cross-reference links to USERSPICE_FUNCTIONS.md in quick lookup tables

## Summary

This patch release addresses critical CSRF token handling issues in the admin
management interface that prevented AJAX requests from functioning properly,
along with missing CSRF protection in backup and schema operations endpoints.
Additionally, comprehensive documentation has been reorganized with key
architectural content migrated to the GitHub Wiki for improved accessibility and
maintainability.

---

## Technical Details

### CSRF Token Fix

The admin management interface was failing to validate CSRF tokens for AJAX
requests due to the token not being available in the DOM where the ElanRegistryAPI
client could locate it. The fix implements the token via the `data-csrf-token`
document attribute, which the API client checks as a fallback when
`<input name="csrf">` is not found.

**Files Modified:**

- `app/admin/manage-consolidated.php` — Added CSRF token to document element
- `app/admin/assets/manage-consolidated.js` — Fixed endpoint URL construction
  for reliable path generation

### Security Enhancements

Two critical endpoints that were missing CSRF protection have been hardened:

**Files Modified:**

- `app/admin/includes/system/backup-operations.php` — Added `Token::check()`
  validation before all backup operations
- `app/admin/includes/system/schema-operations.php` — Added `Token::check()`
  validation for all POST actions (previously only `perform_maintenance` was
  protected)

These changes prevent Cross-Site Request Forgery attacks against critical
database management operations.

### API Response Consistency

The admin detail lookup endpoints now follow the Pattern A response format
consistently by returning HTTP 200 status with `success: false` and an
appropriate error message when resources are not found, rather than using
HTTP 404 status codes.

**Files Modified:**

- `app/admin/includes/process-car-details.php`
- `app/admin/includes/process-user-details.php`
