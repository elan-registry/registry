# Elan Registry v2.15.1 Release Notes

**Release Date:** February 3, 2026
**Type:** Minor Release - Security Hardening, Admin Tools & Bug Fixes

## Required Actions After Deployment

### 1. Run FIX Script 25: Image Verification and Repair

**When:** After deployment to production
**Who:** System administrator with FIX script access
**Steps:**

1. Navigate to the FIX Scripts section in the admin panel
2. Select FIX Script 25 (Image Verification and Repair)
3. Run in **Report Mode** first:
   - Click "Report" to scan database for image issues
   - Review findings (missing files, corrupted metadata, extensionless files,
     orphaned images)
4. After reviewing, run in **Fix Mode**:
   - Click "Fix" to repair identified issues
   - Script will generate missing thumbnails and recover orphaned images
5. Monitor progress bar for completion

**Why:** Identifies and repairs corrupted car images, missing thumbnails, and
orphaned image files. Includes automatic recovery for images matching datestamp
patterns and fuzzy matching.

**Safety:** Two-phase operation with database transaction rollback capability
prevents data loss.

### 2. Verify Security Headers (Optional)

Verify anti-clickjacking headers are present:

```bash
curl -I https://elanregistry.org
# Should see:
# X-Frame-Options: SAMEORIGIN
# Content-Security-Policy: ... frame-ancestors 'self' ...
```

## User-Facing Changes

### New Features

- **FIX Script 25: Image Verification and Repair** ([#567](https://github.com/unibrain1/elanregistry/pull/567)):
  Comprehensive admin tool for identifying and repairing car images with batch
  processing, automatic thumbnail generation, and orphan recovery.
- **Car History Table Color Coding Legend** ([#562](https://github.com/unibrain1/elanregistry/pull/562)):
  Added visual color legend to car history table for better understanding of
  changes.

### Improvements

- **Admin Management Interface**: Fixed CSRF token validation failures affecting
  car and user lookup features in the consolidated management interface.
- **Fixed Identification Guide PDF Link** ([#565](https://github.com/unibrain1/elanregistry/issues/565)):
  Users can now access the Super Safety Documentation PDF from the Identification
  Guide.
- **Enhanced Documentation System** ([#569](https://github.com/unibrain1/elanregistry/pull/569)):
  Improved privacy policy documentation consistency with centralized markdown
  parser.
- **Error Page Formatting**: Fixed error page relative paths for reliable logo
  and home link rendering.

### Bug Fixes

- **Admin Management AJAX Requests**: Fixed CSRF token validation failures that
  caused "JSON Parse error" when using car and user lookup features in the
  consolidated management interface.
- **Car/User Not Found Responses**: Fixed improper HTTP 404 responses when
  searching for non-existent cars or users—now returns proper JSON error
  messages with user-friendly descriptions.
- **Apple Touch Icon Support** ([#543](https://github.com/unibrain1/elanregistry/pull/567)):
  Added missing 120x120 Apple touch icon for iOS home screen bookmarks.

## Technical Changes

- **Image Verification & Repair Tool** ([#567](https://github.com/unibrain1/elanregistry/pull/567)):
  New comprehensive admin script with two-phase operation, batch processing,
  automatic 5-size thumbnail generation, orphan recovery, and transaction
  support.
- **Type Safety Improvements** ([#567](https://github.com/unibrain1/elanregistry/pull/567)):
  Fixed TypeError in Car constructor with proper integer casting.
- **Anti-Clickjacking Security Headers** ([#420](https://github.com/unibrain1/elanregistry/pull/564)):
  Added modern CSP3 `frame-ancestors 'self'` directive and X-Frame-Options
  headers to error pages for defense-in-depth protection.
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
- **Code Quality Enhancements** ([#569](https://github.com/unibrain1/elanregistry/pull/569)):
  Added strict type declarations, fixed test failures with exception imports,
  and enhanced MarkdownParser with baseUrl parameter for reliable link
  resolution.
- **Plugin Cleanup** ([#567](https://github.com/unibrain1/elanregistry/pull/567)):
  Removed unused hooker plugin hook files to reduce technical debt.

## Issues Resolved

- [#565](https://github.com/unibrain1/elanregistry/issues/565) — Broken link in
  Lotus Elan Identification Guide
- [#546](https://github.com/unibrain1/elanregistry/issues/546) — TypeError in
  Car constructor with user ID type casting
- [#543](https://github.com/unibrain1/elanregistry/issues/543) — Missing Apple
  touch icons for iOS
- [#420](https://github.com/unibrain1/elanregistry/issues/420) — Add
  anti-clickjacking security headers

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

This release consolidates all changes since v2.14.0 into a comprehensive update
focusing on security hardening with modern anti-clickjacking protections, adds
comprehensive image verification and repair tools for administrators, provides
critical CSRF token fixes for the admin management interface, fixes critical
documentation links, and improves code quality with type safety enhancements.
Four issues resolved with no breaking changes. Post-deployment execution of FIX
Script 25 is recommended to scan and repair any image integrity issues.

---

## Technical Details

### FIX Script 25: Image Verification and Repair

Comprehensive administrative tool for maintaining car image integrity with:

- Two-phase operation (Report and Fix modes)
- Automatic 5-size thumbnail generation
- Orphaned image recovery with fuzzy matching
- Database transaction support for safe rollback
- Batch processing capabilities

### Security Hardening

Multiple layers of security improvements:

- **CSP3 frame-ancestors directive** - Modern anti-clickjacking protection
- **CSRF token availability** - Reliable AJAX request validation
- **Backup operations protection** - CSRF validation on critical operations
- **Schema operations protection** - Comprehensive CSRF validation on database
  management

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

### API Response Consistency

The admin detail lookup endpoints now follow the Pattern A response format
consistently by returning HTTP 200 status with `success: false` and an
appropriate error message when resources are not found, rather than using
HTTP 404 status codes.

**Files Modified:**

- `app/admin/includes/process-car-details.php`
- `app/admin/includes/process-user-details.php`

---

**Testing Completed:**

- ✅ 656 unit tests passing
- ✅ 188 integration tests passing
- ✅ All pre-commit checks passed
- ✅ PHP coding standards compliance
- ✅ Static analysis (PHPStan) — No errors
- ✅ Security checks passed
