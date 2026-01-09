# Implementation Plan: Issue #351 - Standardize Error Handling

## Overview

**LARGE SCOPE - SPLIT INTO SMALLER ISSUES**

Issue #351 is too large to implement in a single PR. This plan breaks it down into 13 smaller, focused issues spread across three milestones (v2.10.0, v2.11.0, v2.12.0). Each issue is 1-2 days of work and can be independently tested and deployed.

**Original Objective:** Standardize error handling across all administrative tools, documentation systems, and user-facing components to improve debugging and user experience. This addresses inconsistent error response formats, limited error context, generic exception handling, and poor user experience.

## Current State Assessment

### Inconsistencies Identified

**Response Format Patterns (3 different formats):**
- Pattern A: `success`/`message` (most common, 7+ endpoints) - PREFERRED
- Pattern B: `status`/`info` (legacy car actions)
- Pattern C: Specialized formats (chassis validation)

**Error Logging Issues:**
- Both `error_log()` and `logger()` used throughout codebase
- 12 files still using `error_log()` (should be migrated to `logger()`)
- CLAUDE.md mandates `logger()` but not consistently followed

**HTTP Status Codes:**
- Some endpoints properly set codes (getDataTables.php, validateChassis.php)
- Many endpoints return 200 even for errors (manage-consolidated.php AJAX)

**Existing Infrastructure:**
- 13 custom exceptions already defined in `/usersc/exceptions/` and `/app/admin/includes/classes/`
- CarErrorMessages class provides excellent centralized error messaging pattern
- process-owner-update.php demonstrates current best practices

### Good Examples to Follow
- `/app/admin/includes/process-owner-update.php` - Typed exceptions, proper HTTP codes, logger() usage
- `/app/cars/actions/validateChassis.php` - Proper HTTP status codes
- `/usersc/classes/CarErrorMessages.php` - Centralized message management

## Issue Breakdown Across Milestones

### Milestone v2.10.0: Foundation (4 issues, ~5 days)

**Foundation work that enables future migrations. No breaking changes to existing functionality.**

#### Issue #351.1: Create Branded Error Pages (403/404)
**Effort:** 1 day
**Priority:** Medium
**Labels:** enhancement, error-handling, ux

**Description:**
Replace generic error pages (403.shtml, 404.shtml) with custom branded PHP error pages that match the Lotus Elan Registry theme and feel integrated into the application.

**Current State:**
- Generic 403.shtml and 404.shtml in root directory
- Purple gradient background (#667eea to #764ba2)
- Minimal branding, no logo
- External Bootstrap CDN
- No themed imagery
- Static HTML (no user context awareness)

**Best Practices Applied:**
- **Location:** Root directory (/) - industry standard, simplest configuration
- **Format:** PHP files for UserSpice integration and dynamic content
- **Scope:** 403/404 only (defer 400/500 to future issue)
- **Dependencies:** Minimal (error pages must work even when site is broken)

**Tasks:**
- Create `/403.php` to replace `/403.shtml` - Forbidden/Access Denied
- Create `/404.php` to replace `/404.shtml` - Page Not Found
- Match registry theme and color scheme (not purple gradient)
- Include Lotus Elan Registry logo in header
- Add "missing elan" image for visual appeal and branding
- Integrate with UserSpice session detection (show different nav for logged-in users)
- Use local Bootstrap (not external CDN if possible)
- Include helpful messages and navigation links (home, browse cars, contact, search)
- Keep dependencies minimal (inline critical CSS, no database queries)
- Update `.htaccess` to serve `.php` files: `ErrorDocument 403 /403.php` and `ErrorDocument 404 /404.php`
- Test error pages for both logged-in and anonymous users
- Verify pages load quickly even when site has issues

**Acceptance Criteria:**
- [ ] 403.php and 404.php created with registry branding
- [ ] Pages match existing app theme and color scheme
- [ ] Lotus Elan Registry logo displayed
- [ ] "Missing elan" image included
- [ ] UserSpice session detection working (contextual navigation)
- [ ] Helpful error messages and navigation links
- [ ] Consistent styling with main application pages
- [ ] Minimal dependencies (works even when site is broken)
- [ ] .htaccess configured for PHP error pages
- [ ] Pages tested for logged-in and anonymous users
- [ ] Fast loading time verified
- [ ] No sensitive information revealed in error messages
- [ ] Old .shtml files can be removed after verification

**Future Work:**
- Create 500.php (Internal Server Error) with even more minimal dependencies
- Create 400.php (Bad Request) if needed
- Consider 503.php for maintenance mode

**Dependencies:** None

**Files:**
- NEW: `/403.php` (replaces 403.shtml)
- NEW: `/404.php` (replaces 404.shtml)
- UPDATE: `/.htaccess` (ErrorDocument directives)
- REFERENCE: Review `/app/` pages for theme consistency
- TODO: Identify or create "missing elan" image for branding

---

#### Issue #351.2: Create ApiResponse Class
**Effort:** 1 day
**Priority:** High
**Labels:** enhancement, error-handling, foundation

**Description:**
Create standardized `ApiResponse` class for consistent JSON responses across all AJAX endpoints.

**Tasks:**
- Create `/usersc/classes/ApiResponse.php` with static factory methods
- Implement: `success()`, `error()`, `validationError()`, `unauthorized()`, `forbidden()`, `notFound()`, `serverError()`
- Add fluent interface: `withData()`, `withLogging()`, `withStatusCode()`, `send()`
- Integrate with UserSpice `logger()` function
- Use `declare(strict_types=1)` and proper PHPDoc
- Create `/tests/Unit/ApiResponseTest.php` with 100% coverage

**Acceptance Criteria:**
- [ ] ApiResponse class created with all factory methods
- [ ] Proper HTTP status codes set for each response type
- [ ] Integration with logger() function working
- [ ] Unit tests achieving 100% code coverage
- [ ] PHPDoc complete and accurate
- [ ] No breaking changes to existing code

**Dependencies:** None

**Files:**
- NEW: `/usersc/classes/ApiResponse.php`
- NEW: `/tests/Unit/ApiResponseTest.php`

---

#### Issue #351.3: Create Exception Hierarchy
**Effort:** 1-2 days
**Priority:** High
**Labels:** enhancement, error-handling, foundation

**Description:**
Create base `ElanRegistryException` and update all existing custom exceptions to use it. Add new exception types for common error scenarios.

**Tasks:**
- Create `/usersc/exceptions/ElanRegistryException.php` base class
- Create `ValidationException`, `UnauthorizedException`, `ForbiddenException`, `DocumentationException`
- Update all 13 existing exceptions to extend `ElanRegistryException`
- Fix BackupException/SchemaException to use `?Throwable` instead of `?Exception`
- Add user-friendly message support, log categories, HTTP status codes to base
- Create `/tests/Unit/ElanRegistryExceptionTest.php`

**Acceptance Criteria:**
- [ ] Base ElanRegistryException class created
- [ ] 4 new exception types created
- [ ] All 13 existing exceptions updated to extend base
- [ ] All exceptions use `?Throwable` consistently
- [ ] Unit tests for exception hierarchy
- [ ] No breaking changes to code using existing exceptions

**Dependencies:** None

**Files:**
- NEW: `/usersc/exceptions/ElanRegistryException.php`
- NEW: `/usersc/exceptions/ValidationException.php`
- NEW: `/usersc/exceptions/UnauthorizedException.php`
- NEW: `/usersc/exceptions/ForbiddenException.php`
- NEW: `/usersc/exceptions/DocumentationException.php`
- UPDATE: All 13 existing exception files
- NEW: `/tests/Unit/ElanRegistryExceptionTest.php`

---

#### Issue #351.4: Create Log Category Constants
**Effort:** 1 day
**Priority:** Medium
**Labels:** enhancement, error-handling, logging

**Description:**
Create centralized log category constants to ensure consistent error tracking across the application.

**Tasks:**
- Create `/usersc/includes/log_categories.php` with constants
- Define categories: SYSTEM_ERROR, DATABASE_ERROR, VALIDATION_ERROR, SECURITY_ERROR, etc.
- Add PHPDoc explaining each category's usage
- Update ElanRegistryException to use constants
- Update CLAUDE.md with logging standards

**Acceptance Criteria:**
- [ ] Log category constants file created
- [ ] All categories properly documented
- [ ] Integration with ElanRegistryException base class
- [ ] CLAUDE.md updated with logging standards
- [ ] No breaking changes to existing logger() calls

**Dependencies:** Issue #351.3 (ElanRegistryException)

**Files:**
- NEW: `/usersc/includes/log_categories.php`
- UPDATE: `/usersc/exceptions/ElanRegistryException.php`
- UPDATE: `CLAUDE.md`

---

### Milestone v2.11.0: Endpoint Migration (6 issues, ~10 days)

**Migrate existing endpoints to use new error handling infrastructure. Maintains backward compatibility.**

#### Issue #351.5: Migrate Admin AJAX Endpoints
**Effort:** 2 days
**Priority:** High
**Labels:** enhancement, error-handling, admin

**Description:**
Migrate admin panel AJAX endpoints in `manage-consolidated.php` to use ApiResponse pattern.

**Tasks:**
- Update owner management AJAX endpoints
- Update car management AJAX endpoints (reassign, details)
- Add proper HTTP status codes
- Replace generic Exception catching with typed exceptions
- Update frontend JavaScript to handle responses
- Integration tests for all endpoints

**Acceptance Criteria:**
- [ ] All admin AJAX endpoints use ApiResponse
- [ ] Proper HTTP status codes set
- [ ] Typed exception handling implemented
- [ ] Frontend JavaScript updated and tested
- [ ] Integration tests passing
- [ ] No regression in admin functionality

**Dependencies:** Issue #351.2 (ApiResponse), Issue #351.3 (Exceptions)

**Files:**
- UPDATE: `/app/admin/manage-consolidated.php`
- UPDATE: `/app/admin/assets/manage-consolidated.js`
- NEW: `/tests/Integration/AdminAjaxTest.php`

---

#### Issue #351.6: Migrate Documentation System
**Effort:** 1 day
**Priority:** High
**Labels:** enhancement, error-handling, documentation

**Description:**
Update documentation viewer (`docs/view.php`) to use new exception handling and error pages.

**Tasks:**
- Replace `error_log()` with `logger()`
- Use DocumentationException for errors
- Include custom error pages instead of die() statements
- Test documentation viewer error scenarios
- Update error handling flow

**Acceptance Criteria:**
- [ ] No error_log() calls remaining
- [ ] DocumentationException used appropriately
- [ ] Custom error pages included on errors
- [ ] All error scenarios tested
- [ ] No regression in doc viewer functionality

**Dependencies:** Issue #351.3 (DocumentationException), Issue #351.1 (Error Pages)

**Files:**
- UPDATE: `/docs/view.php`
- NEW: `/tests/Integration/DocumentationViewerTest.php`

---

#### Issue #351.7: Migrate Car Action Endpoints - Phase 1
**Effort:** 2 days
**Priority:** High
**Labels:** enhancement, error-handling, cars

**Description:**
Migrate car transfer action endpoints to ApiResponse pattern.

**Tasks:**
- Migrate `/app/cars/actions/request-transfer.php`
- Migrate `/app/cars/actions/approve-transfer.php`
- Migrate `/app/cars/actions/reject-transfer.php`
- Migrate `/app/cars/actions/cancel-transfer.php`
- Update frontend JavaScript
- Integration tests for transfer workflow

**Acceptance Criteria:**
- [ ] All transfer endpoints use ApiResponse
- [ ] Proper HTTP status codes
- [ ] Typed exception handling
- [ ] Frontend updated and tested
- [ ] Full transfer workflow tested end-to-end
- [ ] No regression

**Dependencies:** Issue #351.2 (ApiResponse), Issue #351.3 (Exceptions)

**Files:**
- UPDATE: `/app/cars/actions/request-transfer.php`
- UPDATE: `/app/cars/actions/approve-transfer.php`
- UPDATE: `/app/cars/actions/reject-transfer.php`
- UPDATE: `/app/cars/actions/cancel-transfer.php`
- NEW: `/tests/Integration/CarTransferTest.php`

---

#### Issue #351.8: Migrate Car Action Endpoints - Phase 2
**Effort:** 2 days
**Priority:** High
**Labels:** enhancement, error-handling, cars

**Description:**
Migrate remaining car action endpoints from Pattern B (status/info) to Pattern A (success/message).

**Tasks:**
- Migrate `/app/cars/actions/edit.php` (create, update, delete operations)
- Migrate `/app/cars/actions/deleteAction.php`
- Migrate `/app/cars/actions/updateAction.php`
- Update frontend JavaScript with compatibility shim
- Maintain backward compatibility during transition
- Integration tests

**Acceptance Criteria:**
- [ ] All car action endpoints use ApiResponse
- [ ] Pattern B → Pattern A migration complete
- [ ] Frontend compatibility shim working
- [ ] All car operations tested (create, edit, delete)
- [ ] Image upload functionality verified
- [ ] No regression

**Dependencies:** Issue #351.2 (ApiResponse), Issue #351.3 (Exceptions)

**Files:**
- UPDATE: `/app/cars/actions/edit.php`
- UPDATE: `/app/cars/actions/deleteAction.php`
- UPDATE: `/app/cars/actions/updateAction.php`
- UPDATE: `/app/cars/edit.php` (frontend)
- NEW: `/tests/Integration/CarActionsTest.php`

---

#### Issue #351.9: Create Frontend API Client
**Effort:** 1-2 days
**Priority:** Medium
**Labels:** enhancement, error-handling, frontend

**Description:**
Create standardized JavaScript API client for consistent AJAX error handling across the application.

**Tasks:**
- Create `/app/js/api-client.js`
- Implement `ApiClient.request()` method
- Implement `normalizeResponse()` for backward compatibility
- Implement message display helpers
- Update existing AJAX calls to use new client
- Test with both Pattern A and Pattern B responses

**Acceptance Criteria:**
- [ ] ApiClient class created
- [ ] Response normalization working
- [ ] Message display helpers functional
- [ ] Backward compatibility with Pattern B
- [ ] Existing AJAX calls updated
- [ ] All AJAX operations tested

**Dependencies:** Issue #351.5, #351.7, #351.8 (Endpoint migrations)

**Files:**
- NEW: `/app/js/api-client.js`
- UPDATE: `/app/admin/assets/manage-consolidated.js`
- UPDATE: `/app/assets/js/statistics.js`
- UPDATE: Various page-specific JavaScript files

---

#### Issue #351.10: Migrate Specialized Endpoints
**Effort:** 1-2 days
**Priority:** Medium
**Labels:** enhancement, error-handling

**Description:**
Evaluate and migrate specialized format endpoints (chassis validation, statistics API).

**Tasks:**
- Evaluate `/app/cars/actions/validateChassis.php` - keep specialized format or standardize?
- Migrate `/app/reports/api/statistics-data.php` to ApiResponse
- Update statistics chart loading JavaScript
- Test real-time chassis validation
- Test all statistics chart tabs
- Performance testing

**Acceptance Criteria:**
- [ ] Decision made on chassis validation format
- [ ] Statistics API migrated to ApiResponse
- [ ] All chart tabs loading correctly
- [ ] Chassis validation feedback working
- [ ] No performance degradation
- [ ] All specialized endpoints tested

**Dependencies:** Issue #351.2 (ApiResponse), Issue #351.9 (API Client)

**Files:**
- UPDATE/EVALUATE: `/app/cars/actions/validateChassis.php`
- UPDATE: `/app/reports/api/statistics-data.php`
- UPDATE: `/app/assets/js/statistics.js`
- NEW: `/tests/Integration/SpecializedEndpointsTest.php`

---

### Milestone v2.12.0: Cleanup & Deprecation (3 issues, ~4 days)

**Remove backward compatibility, complete migration, deprecate legacy patterns.**

#### Issue #351.11: Replace error_log() with logger()
**Effort:** 1-2 days
**Priority:** High
**Labels:** enhancement, error-handling, logging

**Description:**
Complete migration from `error_log()` to UserSpice `logger()` function across all 12 remaining files.

**Tasks:**
- Identify all remaining `error_log()` calls (12 files total)
- Replace with appropriate `logger()` calls using proper categories
- Use log category constants from Issue #351.4
- Test error logging in UserSpice admin panel
- Verify no error_log() calls remain

**Acceptance Criteria:**
- [ ] All error_log() calls replaced
- [ ] Proper log categories used
- [ ] Errors appear in UserSpice logs
- [ ] No error_log() found in codebase (grep verification)
- [ ] No regression in error logging

**Dependencies:** Issue #351.4 (Log categories)

**Files:**
- UPDATE: 12 files currently using error_log() (identified in exploration)
- UPDATE: `docs/development/CODING_STANDARDS.md`

---

#### Issue #351.12: Remove Pattern B Compatibility
**Effort:** 1 day
**Priority:** Low
**Labels:** cleanup, breaking-change

**Description:**
Remove backward compatibility support for Pattern B (status/info format) response format.

**Tasks:**
- Remove `ApiResponse::legacyFormat()` method
- Remove `ApiClient.normalizeResponse()` compatibility shim
- Update all remaining Pattern B responses
- Update CLAUDE.md to reflect Pattern A as standard
- Add migration notes to release notes

**Acceptance Criteria:**
- [ ] Pattern B compatibility code removed
- [ ] All responses use Pattern A format
- [ ] No breaking changes for current code
- [ ] CLAUDE.md updated
- [ ] Release notes document breaking change

**Dependencies:** Issue #351.8 (Car actions migrated), Issue #351.9 (API Client created)

**Files:**
- UPDATE: `/usersc/classes/ApiResponse.php`
- UPDATE: `/app/js/api-client.js`
- UPDATE: `CLAUDE.md`
- UPDATE: Release notes

---

#### Issue #351.13: Documentation & Final Polish
**Effort:** 1 day
**Priority:** Medium
**Labels:** documentation

**Description:**
Complete all documentation updates and create comprehensive error handling guide.

**Tasks:**
- Create `/docs/development/ERROR_HANDLING.md`
- Update `CLAUDE.md` with error handling patterns
- Update `docs/development/CODING_STANDARDS.md`
- Update `docs/development/QUICK_REFERENCE.md`
- Add error handling examples
- Document migration patterns
- Final review of all changes

**Acceptance Criteria:**
- [ ] ERROR_HANDLING.md created and comprehensive
- [ ] CLAUDE.md error handling section complete
- [ ] CODING_STANDARDS.md updated
- [ ] QUICK_REFERENCE.md updated
- [ ] All code examples tested and accurate
- [ ] Migration guide clear and helpful

**Dependencies:** All previous issues

**Files:**
- NEW: `/docs/development/ERROR_HANDLING.md`
- UPDATE: `CLAUDE.md`
- UPDATE: `docs/development/CODING_STANDARDS.md`
- UPDATE: `docs/development/QUICK_REFERENCE.md`

---

## Issue Summary Table

| Issue | Title | Milestone | Effort | Priority | Dependencies |
|-------|-------|-----------|--------|----------|--------------|
| #351.1 | Create Branded Error Pages (403/404) | v2.10.0 | 1 day | Medium | None |
| #351.2 | Create ApiResponse Class | v2.10.0 | 1 day | High | None |
| #351.3 | Create Exception Hierarchy | v2.10.0 | 1-2 days | High | None |
| #351.4 | Create Log Category Constants | v2.10.0 | 1 day | Medium | #351.3 |
| #351.5 | Migrate Admin AJAX Endpoints | v2.11.0 | 2 days | High | #351.2, #351.3 |
| #351.6 | Migrate Documentation System | v2.11.0 | 1 day | High | #351.3, #351.1 |
| #351.7 | Migrate Car Actions - Phase 1 | v2.11.0 | 2 days | High | #351.2, #351.3 |
| #351.8 | Migrate Car Actions - Phase 2 | v2.11.0 | 2 days | High | #351.2, #351.3 |
| #351.9 | Create Frontend API Client | v2.11.0 | 1-2 days | Medium | #351.5, #351.7, #351.8 |
| #351.10 | Migrate Specialized Endpoints | v2.11.0 | 1-2 days | Medium | #351.2, #351.9 |
| #351.11 | Replace error_log() with logger() | v2.12.0 | 1-2 days | High | #351.4 |
| #351.12 | Remove Pattern B Compatibility | v2.12.0 | 1 day | Low | #351.8, #351.9 |
| #351.13 | Documentation & Final Polish | v2.12.0 | 1 day | Medium | All previous |

**Total Effort:** ~19 days across 13 issues
**v2.10.0:** ~5 days (foundation)
**v2.11.0:** ~10 days (migration)
**v2.12.0:** ~4 days (cleanup)

---

## Proposed Solution

### 1. ApiResponse Class

**Create:** `/usersc/classes/ApiResponse.php`

Static utility class with fluent interface providing:
- `success()` - Successful operations
- `error()` - General errors with custom status codes
- `validationError()` - Validation failures (422 status)
- `unauthorized()` - Authentication failures (401 status)
- `forbidden()` - Permission denied (403 status)
- `notFound()` - Resource not found (404 status)
- `serverError()` - Internal errors (500 status)
- `withData()` - Add custom response fields
- `withLogging()` - Auto-log with logger() function
- `send()` - Send JSON response and exit

**Key Features:**
- Integrates with existing `logger()` function
- Supports HTTP status code management
- Returns `never` type for proper type safety (PHP 8.1+)
- Backward compatibility method for Pattern B migration

### 2. Exception Hierarchy

**Create:** `/usersc/exceptions/ElanRegistryException.php`

Base exception class providing:
- User-friendly messages (safe for display)
- Technical messages (for logging)
- Log category mapping
- HTTP status code mapping

**New Exceptions to Create:**
- `ValidationException` - Input validation errors (422)
- `UnauthorizedException` - Authentication failures (401)
- `ForbiddenException` - Permission denied (403)
- `DocumentationException` - Documentation system errors (500)

**Update Existing Exceptions:**
- Extend `ElanRegistryException` instead of `Exception`
- Use `?Throwable` instead of `?Exception` for consistency
- Fix BackupException/SchemaException to use `?Throwable`

### 3. Error Logging Standardization

**Create:** `/usersc/includes/log_categories.php`

Constants for standardized log categories:
- `LOG_CATEGORY_SYSTEM_ERROR`
- `LOG_CATEGORY_DATABASE_ERROR`
- `LOG_CATEGORY_VALIDATION_ERROR`
- `LOG_CATEGORY_SECURITY_ERROR`
- `LOG_CATEGORY_CAR_ERRORS`
- `LOG_CATEGORY_OWNER_ERRORS`
- `LOG_CATEGORY_DOCUMENTATION_ERROR`
- Plus others for consistent categorization

**Migration:** Replace all `error_log()` calls with `logger()`

### 4. Custom Error Pages

**Create branded error pages in root directory (/):**
- `/403.php` - Forbidden/Access Denied (replaces 403.shtml)
- `/404.php` - Page Not Found (replaces 404.shtml)
- Match registry theme with logo and "missing elan" image
- Integrate with UserSpice session detection for contextual navigation
- Use local Bootstrap, minimal dependencies
- Configure via .htaccess: `ErrorDocument 403 /403.php`

**Future additions:**
- `/500.php` - Internal Server Error (minimal dependencies, no DB)
- `/400.php` - Bad Request (if needed)
- `/503.php` - Service Unavailable (maintenance mode)

### 5. Frontend Compatibility

**Create:** `/app/js/api-client.js`

Standardized API client providing:
- Response normalization (Pattern A ↔ Pattern B compatibility)
- Consistent error handling
- Message display helpers
- Backward compatibility during transition

## Implementation Phases

### Phase 1: Foundation (Days 1-2)

**Create Branded Error Pages (Day 1):**
1. Create `/403.php` - Branded forbidden/access denied page
2. Create `/404.php` - Branded page not found page
3. Update `.htaccess` for error document configuration
4. Test with logged-in and anonymous users

**Create Core Classes (Day 2):**
1. Create `/usersc/classes/ApiResponse.php`
2. Create `/usersc/exceptions/ElanRegistryException.php`
3. Create new exception classes (ValidationException, UnauthorizedException, ForbiddenException, DocumentationException)
4. Create `/usersc/includes/log_categories.php`
5. Update existing exceptions to extend ElanRegistryException
6. Fix BackupException/SchemaException (use Throwable, proper namespace)

**Testing:**
- Test error pages display correctly
- Write `/tests/Unit/ApiResponseTest.php`
- Write `/tests/Unit/ElanRegistryExceptionTest.php`
- Ensure 100% code coverage for new classes

**Gate:** Error pages deployed, all unit tests passing, code review completed

### Phase 2: High-Priority Endpoints (Days 3-5)

**Migrate Critical Files:**

1. **manage-consolidated.php** (Day 3)
   - Update owner management AJAX endpoints
   - Update car management AJAX endpoints
   - Add HTTP status codes
   - Replace with ApiResponse pattern
   - Update frontend JavaScript

2. **docs/view.php** (Day 4)
   - Replace `error_log()` with `logger()`
   - Use DocumentationException
   - Include custom error pages instead of die()
   - Proper error handling flow

3. **getDataTables.php** (Day 5)
   - Standardize JSON error responses
   - Ensure consistent HTTP status codes
   - Update exception handling

**Testing:**
- Integration tests for each migrated endpoint
- Playwright UI tests for affected functionality
- Verify no regression in existing features

**Gate:** All tests passing, no error_log usage in migrated files

### Phase 3: Car Actions Migration (Days 6-7)

**Migrate Pattern B Endpoints:**
1. `/app/cars/actions/edit.php`
2. `/app/cars/actions/deleteAction.php`
3. `/app/cars/actions/updateAction.php`

**Frontend Updates:**
- Implement ApiClient.normalizeResponse()
- Update car edit forms to use new client
- Maintain backward compatibility

**Testing:**
- Test all car operations (create, edit, delete)
- Verify image uploads still work
- Test validation error display

**Gate:** Car operations fully functional, no regressions

### Phase 4: Specialized Formats (Days 8-9)

**Evaluate and Migrate:**
1. **validateChassis.php** - Consider keeping specialized format or standardizing
2. **statistics-data.php** - Migrate to standard format
3. **backup-operations.php** - Standardize error responses

**Frontend Updates:**
- Update Chart.js data loading
- Update chassis validation feedback
- Test statistics tab loading

**Testing:**
- Test chassis validation real-time feedback
- Test all statistics chart tabs
- Performance testing for API endpoints

**Gate:** All specialized endpoints working, no performance degradation

### Phase 5: Final Polish & Cleanup (Days 10-11)

**Complete Migration:**
1. Replace remaining `error_log()` calls (12 files)
2. Update CLAUDE.md with new patterns
3. Create `/docs/development/ERROR_HANDLING.md`
4. Update CODING_STANDARDS.md
5. Consider adding 500.php and 400.php error pages (future enhancement)

**Final Testing:**
- Full regression test suite
- User acceptance testing
- Error page testing (simulate 403/404 errors)
- Log category verification

**Gate:** All acceptance criteria met, documentation complete

## Critical Files to Modify

### New Files (Create)
- `/403.php` ⭐ Branded error page (replaces 403.shtml)
- `/404.php` ⭐ Branded error page (replaces 404.shtml)
- `/usersc/classes/ApiResponse.php` ⭐ Core response handler
- `/usersc/exceptions/ElanRegistryException.php` ⭐ Base exception
- `/usersc/exceptions/ValidationException.php`
- `/usersc/exceptions/UnauthorizedException.php`
- `/usersc/exceptions/ForbiddenException.php`
- `/usersc/exceptions/DocumentationException.php`
- `/usersc/includes/log_categories.php`
- `/app/js/api-client.js` - Frontend helper
- `/tests/Unit/ApiResponseTest.php`
- `/tests/Unit/ElanRegistryExceptionTest.php`
- `/docs/development/ERROR_HANDLING.md`

### Files to Update (Tier 1 - High Priority)
- `/app/admin/manage-consolidated.php` ⭐ Mixed patterns, high traffic
- `/docs/view.php` ⭐ Uses error_log, needs DocumentationException
- `/app/action/getDataTables.php` ⭐ Core data loading
- `/app/admin/includes/process-owner-update.php` - Already good, just needs ApiResponse
- `/app/admin/includes/system/backup-operations.php` - Standardize responses

### Files to Update (Tier 2 - Important)
- `/app/cars/actions/request-transfer.php`
- `/app/cars/actions/cancel-transfer.php`
- `/app/cars/actions/approve-transfer.php`
- `/app/cars/actions/reject-transfer.php`
- `/app/cars/edit.php`

### Files to Update (Tier 3 - Standard)
- `/app/cars/actions/deleteAction.php` - Pattern B format
- `/app/cars/actions/updateAction.php` - Pattern B format
- `/app/admin/includes/sync-location.php`
- `/app/admin/includes/delete-account.php`
- `/app/reports/api/statistics-data.php`

### Files to Update (Tier 4 - Specialized)
- `/app/cars/actions/validateChassis.php` - Evaluate if specialized format should remain
- `/app/admin/assets/manage-consolidated.js` - Frontend updates
- `/app/assets/js/statistics.js` - Frontend updates

### Documentation Updates
- `CLAUDE.md` - Add error handling section
- `docs/development/CODING_STANDARDS.md` - Add ApiResponse requirements
- `docs/development/QUICK_REFERENCE.md` - Add error handling patterns

### Existing Exceptions to Update
All 13 existing exceptions in `/usersc/exceptions/` and `/app/admin/includes/classes/`:
- Extend `ElanRegistryException` instead of `Exception`
- Use `?Throwable` for previous parameter
- Pass appropriate log category and HTTP status to parent

## Migration Pattern Examples

### AJAX Endpoint Pattern

```php
// BEFORE
try {
    // ... operation ...
    echo json_encode(['success' => true, 'message' => 'Success']);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed']);
}

// AFTER
try {
    // ... operation ...
    ApiResponse::success('Operation completed successfully')
        ->withData('result', $data)
        ->withLogging($userId, LOG_CATEGORY_CAR_ACTIONS, 'Car updated')
        ->send();
} catch (ValidationException $e) {
    ApiResponse::validationError($e->getUserMessage(), [])
        ->send();
} catch (ElanRegistryException $e) {
    ApiResponse::error($e->getUserMessage(), $e->getHttpStatusCode())
        ->withLogging($userId, $e->getLogCategory(), $e->getMessage())
        ->send();
}
```

### Documentation Page Pattern

```php
// BEFORE
try {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Failed to read');
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    die('Error reading document file.');
}

// AFTER
try {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new DocumentationException('Failed to read document');
    }
} catch (DocumentationException $e) {
    logger($user->data()->id ?? 0, $e->getLogCategory(), $e->getMessage());
    include(__DIR__ . '/errors/404.php');
    exit;
}
```

## Backward Compatibility Strategy

### Timeline
- **v2.10.0:** Introduce ApiResponse, maintain Pattern B support
- **v2.11.0:** Add deprecation warnings to Pattern B endpoints
- **v2.12.0:** Remove Pattern B support (6+ months later)

### Frontend Compatibility
Use `ApiClient.normalizeResponse()` to handle both formats during transition period.

## Testing Strategy

### Unit Tests
- ApiResponse class (all methods, edge cases)
- Exception classes (messages, categories, HTTP codes)
- 100% code coverage target

### Integration Tests
- Each migrated endpoint
- Database operations
- Error logging verification
- HTTP status codes

### Playwright Tests
- Owner update workflow
- Car operations (create, edit, delete, transfer)
- Chassis validation feedback
- Statistics chart loading
- Error page display
- Unauthorized access handling

### Manual Testing Checklist
- [ ] Admin owner search and update
- [ ] Car transfer workflow (request, approve, reject, cancel)
- [ ] Car edit form validation
- [ ] Chassis validation real-time feedback
- [ ] Statistics charts loading
- [ ] Documentation viewer error handling
- [ ] Unauthorized access redirects
- [ ] Database backup operations
- [ ] Error logging in UserSpice logs

## Success Criteria

### Quantitative
- [ ] 100% of error_log calls replaced with logger()
- [ ] 100% of AJAX endpoints use ApiResponse
- [ ] 0 PHP errors or warnings introduced
- [ ] 0 Playwright test failures
- [ ] <5% performance degradation
- [ ] 100% test coverage for ApiResponse
- [ ] 100% test coverage for exception classes

### Qualitative
- [ ] Consistent error format across all endpoints
- [ ] Clear separation of user-facing vs technical errors
- [ ] Improved debugging with structured logs
- [ ] Easier frontend error handling
- [ ] Better UX with friendly error pages
- [ ] Improved code maintainability
- [ ] Complete documentation

### Acceptance
- [ ] All Tier 1 files migrated and tested
- [ ] All Tier 2 files migrated and tested
- [ ] Backward compatibility maintained
- [ ] CLAUDE.md updated
- [ ] Release notes completed
- [ ] PR approved
- [ ] Test environment deployment successful
- [ ] User acceptance testing passed

## Risk Mitigation

### Breaking Frontend JavaScript
- **Risk:** Frontend expects specific response formats
- **Mitigation:** ApiClient.normalizeResponse() compatibility shim
- **Detection:** Integration and Playwright tests

### Performance Impact
- **Risk:** Additional logging overhead
- **Mitigation:** Conditional logging (errors only auto-log)
- **Detection:** Performance benchmarks

### Missing Edge Cases
- **Risk:** Unexpected exception scenarios
- **Mitigation:** Catch-all Throwable handlers
- **Detection:** Error log monitoring

### CSRF Token Handling
- **Risk:** Token consumed before exception thrown
- **Mitigation:** Validate tokens early in flow
- **Detection:** Security test suite

## Documentation Updates

### CLAUDE.md
Add comprehensive error handling section with:
- ApiResponse usage examples
- Exception handling patterns
- Error logging standards
- Migration patterns

### New Documentation
Create `/docs/development/ERROR_HANDLING.md` covering:
- Complete ApiResponse API reference
- Exception hierarchy and usage
- Frontend error handling patterns
- Custom error pages
- Debugging techniques
- Migration guide from legacy patterns

### Update Existing
- `CODING_STANDARDS.md` - Add ApiResponse and exception requirements
- `QUICK_REFERENCE.md` - Add error handling quick reference

## Implementation Timeline

**11 days total:**
- Days 1-2: Foundation (classes, exceptions, tests)
- Days 3-5: High-priority endpoints
- Days 6-7: Car actions migration
- Days 8-9: Specialized formats
- Days 10-11: Error pages, final migration, documentation

---

## Recommended Implementation Order

### Phase 1: v2.10.0 Foundation (Issues #351.1-4)
**Can be done in parallel or any order:**
1. **Start with #351.1** (Branded Error Pages) - Quick UX win, no dependencies, needed by #351.6
2. **Then #351.2** (ApiResponse) - Core class needed by all endpoint migrations
3. **Then #351.3** (Exception Hierarchy) - Needed for proper error handling
4. **Then #351.4** (Log Categories) - Depends on #351.3

**Milestone Goal:** Foundation infrastructure in place, immediate UX improvement with error pages, no changes to existing functionality.

### Phase 2: v2.11.0 Migration (Issues #351.5-10)
**Sequential order recommended:**
1. **#351.5** (Admin AJAX) - High-traffic, high-value endpoints first
2. **#351.6** (Documentation) - Quick win, improves error handling immediately
3. **#351.7** (Car Actions Phase 1) - Transfer workflow is critical
4. **#351.8** (Car Actions Phase 2) - Complete car action migration
5. **#351.9** (Frontend API Client) - After endpoints migrated, standardize frontend
6. **#351.10** (Specialized Endpoints) - Final endpoint migrations

**Milestone Goal:** All endpoints using standardized error handling, backward compatibility maintained.

### Phase 3: v2.12.0 Cleanup (Issues #351.11-13)
**Order matters:**
1. **#351.11** (Replace error_log) - Complete logging migration
2. **#351.12** (Remove Pattern B) - After all endpoints migrated (can wait 6+ months)
3. **#351.13** (Documentation) - Final polish, comprehensive docs

**Milestone Goal:** Complete standardization, legacy patterns removed, full documentation.

---

## How to Proceed

### Option 1: Manual Issue Creation (Recommended)
1. Review the 13 issue descriptions above
2. Create GitHub issues manually using the provided templates
3. Assign to appropriate milestones (v2.10.0, v2.11.0, v2.12.0)
4. Add labels as specified
5. Reference dependencies in issue descriptions
6. Link all to parent issue #351

### Option 2: Batch Issue Creation
If you'd like me to create all issues via `gh` CLI, I can generate the commands or create them directly.

### Starting Point
**Begin with Issue #351.1 (Create Branded Error Pages)** - Quick UX improvement with immediate user-facing benefits. No dependencies, can be implemented and tested independently. Provides branded 403/404 pages to replace generic .shtml files.

---

## Technical Details (From Original Plan)

The sections below contain detailed technical specifications for reference during implementation. These should be consulted when working on each specific issue.

---

## Next Steps for Issue #351

1. **Close Issue #351** - Mark as "split into smaller issues"
2. **Create 13 new issues** using the breakdown above
3. **Update milestones** - Ensure v2.10.0, v2.11.0, v2.12.0 exist
4. **Assign to milestones** - Distribute issues as specified
5. **Add dependencies** - Link issues with "depends on" relationships
6. **Start with #351.1** - Begin with branded error pages for immediate UX improvement

---

## Implementation Notes

- Each issue is independently testable and deployable
- Foundation issues (#351.1-4) have no dependencies on each other (except #351.3 depends on #351.2)
- Migration issues (#351.5-10) depend on foundation being complete
- Cleanup issues (#351.11-13) depend on migrations being complete
- Follow existing CarErrorMessages pattern for centralized messaging
- Integrate seamlessly with UserSpice logger() function
- Maintain PHP 8+ requirements (strict types, type hints, PHPDoc)
- Prioritize backward compatibility during transition
- Focus on user experience improvements
- Ensure security best practices (sanitize errors, proper HTTP codes)
