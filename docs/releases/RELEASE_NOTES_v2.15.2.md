# Elan Registry v2.15.2 Release Notes

**Release Date:** February 8, 2026
**Type:** Major Release - Security Hardening, Admin Tools, Bug Fixes & Improvements

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

### 3. Review Backup Directory Structure

The backup operations have been migrated to a standardized directory structure.
Verify backups are being created in the new location.

## User-Facing Changes

### New Features

- **FIX Script 25: Image Verification and Repair** ([#567](https://github.com/jimboone/elan-registry/pull/567)):
  Comprehensive admin tool for identifying and repairing car images with batch
  processing, automatic thumbnail generation, and orphan recovery.
- **Car History Table Color Coding Legend** ([#562](https://github.com/jimboone/elan-registry/pull/562)):
  Added visual color legend to car history table for better understanding of
  changes.

### Improvements

- **Admin Management Interface**: Fixed CSRF token validation failures affecting
  car and user lookup features in the consolidated management interface.
- **Fixed Identification Guide PDF Link** ([#565](https://github.com/jimboone/elan-registry/issues/565)):
  Users can now access the Super Safety Documentation PDF from the Identification
  Guide.
- **Enhanced Documentation System** ([#569](https://github.com/jimboone/elan-registry/pull/569)):
  Improved privacy policy documentation consistency with centralized markdown
  parser.
- **Error Page Formatting**: Fixed error page relative paths for reliable logo
  and home link rendering.
- **Car Details Display**: Added null-check for owner first name field to prevent
  displaying empty or undefined values.
- **Backup Operations**: Migrated to standardized directory structure for improved
  organization and maintainability.
- **Schema Validation**: Comprehensive schema validation recommendations for database
  integrity checks.
- **Issue Workflow**: Enhanced issue workflow with project-specific security, testing,
  and bug analysis guidelines.

### Bug Fixes

- **Admin Management AJAX Requests**: Fixed CSRF token validation failures that
  caused "JSON Parse error" when using car and user lookup features in the
  consolidated management interface.
- **Car/User Not Found Responses**: Fixed improper HTTP 404 responses when
  searching for non-existent cars or users—now returns proper JSON error
  messages with user-friendly descriptions.
- **Apple Touch Icon Support** ([#543](https://github.com/jimboone/elan-registry/pull/567)):
  Added missing 120x120 Apple touch icon for iOS home screen bookmarks.
- **Error Page Asset Loading**: Fixed error page logo paths in subdirectory deployments.
- **Error Page Error Handling**: Fixed fatal errors in error pages when logging system
  fails to initialize.
- **Backup Operations Security**: Fixed backup operations and migrated to standardized
  directory structure with proper CSRF token validation.

## Technical Changes

- **Image Verification & Repair Tool** ([#567](https://github.com/jimboone/elan-registry/pull/567)):
  New comprehensive admin script with two-phase operation, batch processing,
  automatic 5-size thumbnail generation, orphan recovery, and transaction
  support.
- **Type Safety Improvements** ([#567](https://github.com/jimboone/elan-registry/pull/567)):
  Fixed TypeError in Car constructor with proper integer casting.
- **Anti-Clickjacking Security Headers** ([#420](https://github.com/jimboone/elan-registry/pull/564)):
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
  creation and deletion functionality. Migrated backup storage to standardized
  directory structure.
- **Schema Operations Security**: Added comprehensive CSRF token validation to
  `schema-operations.php` for all POST requests (previously only validated one
  action).
- **Code Quality Enhancements** ([#569](https://github.com/jimboone/elan-registry/pull/569)):
  Added strict type declarations, fixed test failures with exception imports,
  and enhanced MarkdownParser with baseUrl parameter for reliable link
  resolution.
- **Plugin Cleanup** ([#567](https://github.com/jimboone/elan-registry/pull/567)):
  Removed unused hooker plugin hook files to reduce technical debt.
- **Error Page Robustness**: Added `class_exists('LogCategories')` checks in error
  pages to prevent fatal errors when logging system fails to load during error
  handling. Changed error page logo paths from absolute to relative for
  subdirectory deployment support.
- **Null-Safe Data Display**: Added defensive null-checks in car details page for
  optional fields with proper fallback messaging.
- **Schema Validation Recommendations**: Comprehensive documentation of schema
  validation patterns and best practices for database integrity.
- **FIX Directory Cleanup**: Organized and cleaned up FIX scripts directory structure
  after backup migration.

**Files Modified:**

- `error/404.php` — Added LogCategories check, fixed logo path
- `error/403.php` — Added LogCategories check, fixed logo path
- `error/500.php` — Fixed logo path
- `app/cars/details.php` — Added null-check for owner name display
- `app/admin/manage-consolidated.php` — Added CSRF token to document element
- `app/admin/assets/manage-consolidated.js` — Fixed endpoint URL construction
- `app/admin/includes/process-car-details.php` — Standardized error response format
- `app/admin/includes/process-user-details.php` — Standardized error response format
- `backup-operations.php` — Added CSRF token validation, migrated directory structure
- `schema-operations.php` — Added comprehensive CSRF token validation
- `FIX/` directory — Reorganized and cleaned up after backup migration
- Multiple documentation files — See Documentation Changes section

## Issues Resolved

- [#565](https://github.com/jimboone/elan-registry/issues/565) — Broken link in
  Lotus Elan Identification Guide
- [#546](https://github.com/jimboone/elan-registry/issues/546) — TypeError in
  Car constructor with user ID type casting
- [#543](https://github.com/jimboone/elan-registry/issues/543) — Missing Apple
  touch icons for iOS
- [#420](https://github.com/jimboone/elan-registry/issues/420) — Add
  anti-clickjacking security headers

## Documentation Changes

### Major Documentation Reorganization

This release includes a comprehensive documentation restructuring with 9 phases
of optimization:

#### Phase 1: Documentation Optimization - Quick Wins

- Quick-win improvements to documentation accessibility

#### Phase 2: Core Documentation Restructuring

- Phase 2.1: Optimize ERROR_HANDLING.md - Remove legacy patterns and consolidate
- Phase 2.2: Restructure CODING_STANDARDS.md - Separate requirements from patterns
- Phase 2.3: Create development documentation index (README.md)

#### Phase 3: Enhanced Developer Guides

- Phase 3.1: Create Frontend API Integration Guide
- Phase 3.2: Expand QUICK_REFERENCE.md with code patterns
- Phase 3.3: Organize LOG_CATEGORIES.md for improved navigation
- Phase 3.4: Create PAGE_LOADING_FLOW.md quick summary

#### Completed Changes

- Migrated Architecture Guide to GitHub Wiki
- Migrated Integration Guide to GitHub Wiki
- Migrated UserSpice Integration Guide to GitHub Wiki
- Created comprehensive development documentation index
- Added cross-reference links to USERSPICE_FUNCTIONS.md in quick lookup tables
- Created comprehensive schema validation recommendations document
- Enhanced issue workflow with project-specific security, testing, and bug analysis

## Summary

This release encompasses all changes since v2.14.0, consolidating comprehensive
security hardening with modern anti-clickjacking protections, adds
comprehensive image verification and repair tools for administrators, provides
critical CSRF token fixes for the admin management interface, fixes critical
documentation links, improves code quality with type safety enhancements,
enhances error page robustness for subdirectory deployments, restructures and
optimizes all developer documentation, and implements standardized backup
directory structures. Four primary issues resolved with no breaking changes.
Post-deployment execution of FIX Script 25 is recommended to scan and repair any
image integrity issues.

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

### Error Page Robustness

Error pages now gracefully handle initialization failures and work correctly in
subdirectory deployments. When the logging system fails to load, error pages will
still render without fatal errors. Logo and asset paths use relative paths to work
across different deployment configurations.

**Files Modified:**

- `error/404.php` — Added LogCategories class existence check, changed logo path
  from `/usersc/...` to `../usersc/...`
- `error/403.php` — Added LogCategories class existence check, changed logo path
  from `/usersc/...` to `../usersc/...`
- `error/500.php` — Changed logo path from `/usersc/...` to `../usersc/...`

### Data Display Improvements

The car details page now safely handles optional fields that may not have values
set. When the owner's first name is not specified, the page displays "Not specified"
instead of showing empty or undefined values.

**Files Modified:**

- `app/cars/details.php` — Added null-check with fallback display for owner name

### Backup Operations Migration

Backup operations have been migrated to a standardized directory structure with
improved organization and security. All backup creation and deletion operations
now include CSRF token validation.

**Key Changes:**

- Standardized backup directory structure
- Added CSRF token validation to all POST operations
- Improved backup operation security and organization

**Files Modified:**

- `backup-operations.php` — Added CSRF validation, migrated directory structure
- `FIX/` directory — Reorganized after migration

### Documentation Restructuring

Comprehensive reorganization of all developer documentation across 9 phases,
improving navigation, consolidating patterns, and creating new guides for
common tasks and API integration.

**Key Improvements:**

- Centralized documentation index
- Separated requirements from implementation patterns
- Added quick-reference guides for common tasks
- Enhanced navigation with cross-references
- Created comprehensive schema validation guide
- Enhanced issue workflow with security and testing guidelines

**Files Modified:**

- `docs/development/ERROR_HANDLING.md` — Optimized and consolidated
- `docs/development/CODING_STANDARDS.md` — Restructured requirements vs patterns
- `docs/development/README.md` — New comprehensive index
- `docs/development/QUICK_REFERENCE.md` — Expanded with code patterns
- `docs/development/LOG_CATEGORIES.md` — Organized for improved navigation
- `docs/development/PAGE_LOADING_FLOW.md` — New quick summary
- `docs/development/USERSPICE_FUNCTIONS.md` — Added cross-reference links
- `docs/development/SCHEMA_VALIDATION.md` — New comprehensive guide
- `docs/development/ISSUE_WORKFLOW.md` — New guide with security/testing guidelines

---

**Testing Completed:**

- ✅ 652 unit tests passing
- ✅ 188 integration tests passing
- ✅ All pre-commit checks passed
- ✅ PHP coding standards compliance
- ✅ Static analysis (PHPStan) — No errors
- ✅ Security checks passed
