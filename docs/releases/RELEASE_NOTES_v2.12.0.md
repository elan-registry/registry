# Elan Registry v2.12.0-rc1 Release Notes

**Release Date:** January 25, 2026
**Type:** Minor Release - Error Handling & Infrastructure Modernization (Release Candidate 1)

**RELEASE CANDIDATE NOTICE:**
This is Release Candidate 1 (RC1) for v2.12.0. Deploy to test server only for
validation testing. Production deployment should wait until final release after
3-7 day testing period.

## REQUIRED ACTIONS AFTER DEPLOYMENT

### Database and System Updates: Run FIX Scripts

FIX scripts must be run after deployment via FIX/index.php admin interface.

1. **FIX/23-Optimize-CDN-Resources.php** (Issue #442 Phase 2 - Performance)
   - Updates CDN resources to minified versions with SRI hashes
   - Optimizes Bootstrap 4.x, jQuery, and Popper.js CDN URLs
   - Expected performance impact:
     - Minified CSS: ~25% smaller
     - Minified JS: ~30% smaller
     - After gzip: ~20-25% total bandwidth savings
   - Confirm successful completion:
     - All CDN resources load correctly
     - Page load time improved (use external performance test tool)
     - Browser console shows no resource loading errors

**Success Criteria:**

- FIX scripts executed successfully via FIX/index.php
- CDN resources loading with SRI integrity verification

### RC1 Testing Focus (3-7 Day Validation Period)

**Critical Test Areas:**

1. **Admin AJAX Operations** (11 endpoints migrated)
   - Owner profile updates and search
   - Car management operations
   - System maintenance operations
   - Verify success/error messages display correctly
   - Check UserSpice logs for proper error logging

2. **Location Services** (Issue #245)
   - Test location autocomplete during registration
   - Verify reverse geocoding functionality
   - Confirm coordinates are saved correctly
   - Test location sync from profiles to cars

3. **Car Management Operations**
   - Car creation, editing, deletion workflows
   - Ownership transfer request/approve/reject
   - Chassis validation
   - Car history retrieval

4. **Error Handling Verification**
   - Trigger validation errors (empty required fields)
   - Test permission denied scenarios (non-admin accessing admin pages)
   - Verify 403/404 error pages display correctly
   - Check that errors are logged to UserSpice logs with correct categories

5. **Frontend Error Handling**
   - Test AJAX error scenarios (network failures, timeouts)
   - Verify error messages display via NotificationHelper
   - Test request cancellation (rapid searches)
   - Confirm CSRF token injection working

6. **Performance Monitoring**
   - Measure page load times before/after FIX/23
   - Check CDN resource loading in browser Network tab
   - Verify SRI integrity checks passing
   - Monitor PHP error logs for any new warnings

**Regression Testing:**

- All existing functionality continues to work
- No new PHP warnings or errors in logs
- All Playwright E2E tests passing
- PHPUnit test suites passing

## User-Facing Changes

### Error Handling Improvements

- **Better Error Messages**: User-friendly error messages now display consistently across all AJAX operations via standardized notification system
- **Improved 403/404 Pages**: Branded error pages matching Lotus Elan Registry Simplex theme replace generic .shtml pages with proper navigation and styling
- **Location Services** (Issue #245): Enhanced location data quality with automatic geocoding and standardized location names

### Visual Enhancements

- **Consistent Notifications**: All AJAX operations now use Bootstrap-styled notification messages with proper success/error indicators
- **Error Page Branding**: HTTP 403 and 404 error pages now match the site theme with dark navbar, logo, and "Return Home" button

## Admin-Facing Changes

### Error Handling & Logging System

- **Centralized Error Logging**: All errors now logged via UserSpice `logger()` function with standardized LogCategories for improved audit trails and debugging
- **124 Log Categories**: Comprehensive LogCategories constants class replaces hardcoded strings (eliminates typos, improves discoverability)
- **Enhanced Error Visibility**: Admin logs now show categorized errors making it easier to identify and troubleshoot issues
- **Typed Exception System**: 23 domain-specific exception classes provide better error classification and handling

### AJAX Endpoint Modernization

- **15 Endpoints Migrated**: Admin AJAX endpoints, location services, car actions, statistics, and transfer operations now use standardized ApiResponse pattern
- **Consistent Response Format**: All AJAX endpoints return `{success, message, ...data}` format with proper HTTP status codes
- **Automatic Error Logging**: ApiResponse class logs errors before sending response for complete audit trail

### Frontend Infrastructure

- **ElanRegistryAPI Client** (587 lines): Standardized JavaScript API client for all AJAX operations with automatic CSRF token injection
- **Request Cancellation**: Built-in support for cancelling pending requests (prevents race conditions in rapid searches)
- **Type-Specific Error Handling**: ApiError, ApiValidationError, and ApiCancelledError classes for granular error handling

### Location Services (Issue #245)

- **OpenStreetMap Integration**: FIX/20 backfills coordinates using OSM Nominatim API for all existing profiles
- **Location Standardization**: Automatic normalization of state/province names (e.g., "WV" → "West Virginia", "OR" → "Oregon")
- **Profile-to-Car Sync**: Updated location data automatically syncs from owner profiles to all owned cars

### Security & Performance

- **Subresource Integrity (SRI)**: FIX/17 adds SRI hashes to all CDN resources protecting against CDN compromises (fixes CVE-2021-23445)
- **Performance Optimization**: FIX/23 switches to minified CDN resources with ~20-25% bandwidth savings
- **DataTables Upgrade**: v1.10.23 → v1.11.3 with Select extension support

### Documentation

- **Comprehensive Error Handling Guide** (838 lines): New
  `docs/development/ERROR_HANDLING.md` documents error handling patterns,
  best practices, and migration guides
- **Updated Coding Standards**: `CODING_STANDARDS.md` and `CLAUDE.md` updated with error handling requirements and LogCategories usage
- **Complete API Documentation**: Frontend API client and backend ApiResponse class fully documented with code examples

## Issues Resolved in This Release

- [#245](https://github.com/unibrain1/elanregistry/issues/245) - ENHANCEMENT: Implement modern location collection methods for registration
- [#356](https://github.com/unibrain1/elanregistry/issues/356) - Refactor generic Exception usage to typed exceptions
- [#436](https://github.com/unibrain1/elanregistry/issues/436) - Add branded 403/404 error pages
- [#440](https://github.com/unibrain1/elanregistry/issues/440) - Error Handling #351.5: Migrate Admin AJAX Endpoints
- [#441](https://github.com/unibrain1/elanregistry/issues/441) - Error Handling #351.6: Migrate Documentation System
- [#442](https://github.com/unibrain1/elanregistry/issues/442) - Error Handling #351.7: Migrate Car Transfer Endpoints
- [#443](https://github.com/unibrain1/elanregistry/issues/443) - Error Handling #351.8: Migrate Car Action Endpoints (Pattern B)
- [#444](https://github.com/unibrain1/elanregistry/issues/444) - Error Handling #351.9: Create Frontend API Client
- [#445](https://github.com/unibrain1/elanregistry/issues/445) - Error Handling #351.10: Migrate Specialized Endpoints
- [#446](https://github.com/unibrain1/elanregistry/issues/446) - Error Handling #351.11: Replace error_log() with logger()
- [#448](https://github.com/unibrain1/elanregistry/issues/448) - Error Handling #351.13: Documentation and Final Polish
- [#450](https://github.com/unibrain1/elanregistry/issues/450) - Refactor Admin Owner Management AJAX Endpoints to Use ApiResponse
- [#451](https://github.com/unibrain1/elanregistry/issues/451) - Refactor Location Services AJAX Endpoints to ApiResponse Pattern
- [#452](https://github.com/unibrain1/elanregistry/issues/452) - Refactor Car Actions AJAX Endpoints to ApiResponse Pattern
- [#453](https://github.com/unibrain1/elanregistry/issues/453) - Refactor Admin System Operations AJAX Endpoints to Use ApiResponse
- [#456](https://github.com/unibrain1/elanregistry/issues/456) - Error Handling #351.5: Migrate Car Management Module to Log Constants
- [#458](https://github.com/unibrain1/elanregistry/issues/458) - Migrate Logger Calls to LogCategories Constants
- [#459](https://github.com/unibrain1/elanregistry/issues/459) - Error Handling #351.8: Migrate Remaining Modules to Log Constants

---

## For Developers

### Breaking Changes

- Generic `Exception` usage replaced with typed exceptions (code catching `Exception` may need updates)
- Hardcoded log category strings must be replaced with LogCategories constants
- Legacy error_log() calls should be migrated to logger()

### New Requirements

- All new AJAX endpoints must use ApiResponse class
- All new error logging must use LogCategories constants
- Frontend AJAX calls should use ElanRegistryAPI client

### Documentation Resources

- `docs/development/ERROR_HANDLING.md` - Complete error handling guide
- `docs/development/CODING_STANDARDS.md` - Updated coding standards
- `CLAUDE.md` - Updated with error handling patterns and LogCategories usage
