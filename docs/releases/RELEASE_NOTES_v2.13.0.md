# Elan Registry v2.13.0 Release Notes

**Release Date:** January 30, 2026
**Type:** Minor Release - Architecture Modernization & Code Quality

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### Apply UserSpice 6.0.3 Update

Download and apply the UserSpice 6.0.3 update to the `users/` directory.
See <https://userspice.com> for update instructions.

### Run FIX/24 - Regenerate Optimized Thumbnails

Updates the thumbnail size setting from 600 to 768 and regenerates all
optimized thumbnails at the new size. Run once after deployment:

```text
https://your-site.com/FIX/24-Regenerate-Optimized-Thumbnails.php
```

The script processes images in batches with timeout management. Safe to
re-run if interrupted — tracks completion in `fix_script_runs` table.

## 👤 User-Facing Changes

### Bug Fixes

- **Fixed Dropzone Validation Error Display** (#533): Resolved issue where
  validation errors on the car edit form were not displayed to users when the
  server returned a 422 response. Users now see proper error messages when
  form validation fails, improving the editing experience.
- **Fixed Registration Page Error**: Resolved TypeError on the join page
  caused by the autoassignun plugin expecting username form elements that
  don't exist in the custom registration view.
- **Fixed Verification Email Link**: Added missing `user_id` parameter to
  the email verification link sent during registration.
- **Fixed Thumbnail Generation Using Wrong Size**: The `uploadImages()`
  function was missing `global $settings`, causing it to always use the
  hardcoded 600px fallback instead of the configured 768px size.
- **Fixed Error Page Paths**: Corrected `__DIR__` relative paths in 403
  and 404 error pages that were looking for includes in the wrong directory.

### Improvements

- **Redirect After Login**: Users who are redirected to the login page from
  a protected page are now returned to their original destination after
  successful login, instead of always landing on the account page.
- **Optimized Thumbnail Size**: Increased mid-range thumbnail from 600px
  to 768px for better display quality on modern screens.

## 🔧 Admin-Facing Changes

### Improved Error Reporting

- **Better Form Validation Feedback**: Car edit form now properly displays
  validation errors to admins, making it easier to understand and correct
  form submission issues
- **Enhanced Logging**: Improved error logging across admin AJAX endpoints
  for better troubleshooting and audit trails

## 📋 Issues Resolved in This Release

- [#462](https://github.com/unibrain1/elanregistry/issues/462) - Add CarException hierarchy and consolidate Car.php constants
- [#463](https://github.com/unibrain1/elanregistry/issues/463) - Decompose Car class into 7 focused service classes
- [#464](https://github.com/unibrain1/elanregistry/issues/464) - Standardize API logging and migrate AJAX calls to ElanRegistryAPI
- [#481](https://github.com/unibrain1/elanregistry/issues/481) - Migrate frontend jQuery.ajax() calls to ElanRegistryAPI client
- [#485](https://github.com/unibrain1/elanregistry/issues/485) - Server Globals: Create server_globals.php initialization module
- [#486](https://github.com/unibrain1/elanregistry/issues/486) - Migrate security files to server globals
- [#487](https://github.com/unibrain1/elanregistry/issues/487) - Migrate AJAX endpoints to use $method server global
- [#488](https://github.com/unibrain1/elanregistry/issues/488) - Migrate error pages and app files to server globals
- [#489](https://github.com/unibrain1/elanregistry/issues/489) - Migrate remaining $_SERVER usage to server globals
- [#490](https://github.com/unibrain1/elanregistry/issues/490) - Document server globals across developer documentation
- [#493](https://github.com/unibrain1/elanregistry/issues/493) - Refactor test infrastructure with dual-bootstrap architecture
- [#497](https://github.com/unibrain1/elanregistry/issues/497) - Bump phpunit/phpunit from 11.5.46 to 11.5.50
- [#498](https://github.com/unibrain1/elanregistry/issues/498) - Refine test suite: consolidate tests, add class coverage, streamline docs
- [#499](https://github.com/unibrain1/elanregistry/issues/499) - Streamline quality gates for cleaner developer experience
- [#500](https://github.com/unibrain1/elanregistry/issues/500) - Fix PHPStan always-false conditions in Car.php
- [#501](https://github.com/unibrain1/elanregistry/issues/501) - Fix PHPStan baseline - remove unused properties and methods
- [#502](https://github.com/unibrain1/elanregistry/issues/502) - Fix PHPStan baseline type errors
- [#503](https://github.com/unibrain1/elanregistry/issues/503) - Streamline documentation: eliminate redundancy
- [#507](https://github.com/unibrain1/elanregistry/issues/507) - Fix cars_hist NOT NULL constraint errors in delete() and merge()
- [#509](https://github.com/unibrain1/elanregistry/issues/509) - Modernize ChassisValidator with strict types and PHPStan annotations
- [#510](https://github.com/unibrain1/elanregistry/issues/510) - Modernize CarErrorMessages with strict types and PHPDoc annotations
- [#519](https://github.com/unibrain1/elanregistry/issues/519) - Fix Playwright auth helper login URL and test path resolution
- [#523](https://github.com/unibrain1/elanregistry/issues/523) - Clean up .gitignore: untrack generated files, preserve backup dirs
- [#533](https://github.com/unibrain1/elanregistry/issues/533) - Dropzone form: validation errors not displayed to user on 422 response
- [#534](https://github.com/unibrain1/elanregistry/issues/534) - Mock DB returns strings for numeric fields, fix TypeErrors
- [#535](https://github.com/unibrain1/elanregistry/issues/535) - Add type-safe helper functions and fix PDO string-to-int mismatches

---

## 📊 Technical Summary

### Server Globals Migration (Issues #485-490)

Migrated all direct `$_SERVER` access to centralized, validated server globals
via `usersc/includes/server_globals.php`. Includes core framework files
`users/init.php` and `z_us_root.php`. Provides 11 validated globals with
security features including CRLF injection prevention and hostname validation.
See [CLAUDE.md](../../CLAUDE.md) and
[PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md) for usage patterns.

### Car Class Decomposition (Issues #462-463)

Decomposed monolithic `Car.php` into 7 focused service classes (CarValidator,
CarImageProcessor, CarRepository, CarVerificationManager,
CarAdministrationService, CarDataTablesService, FactoryDataFormatter) under the
`ElanRegistry\Car` namespace. `Car.php` remains as a thin facade with full
backward compatibility — all existing code works unchanged. See
[CLASSES.md](../development/CLASSES.md) and
[ARCHITECTURE.md](../development/ARCHITECTURE.md).

### Test Infrastructure Refactoring (Issue #493)

Implemented dual-bootstrap architecture (`bootstrap-unit.php` /
`bootstrap-integration.php`) separating unit and integration tests for better
isolation and performance. Fixed all integration test failures. See
[TESTING.md](../testing/TESTING.md).

### jQuery to ElanRegistryAPI Migration (Issues #464, #481)

Migrated 5 jQuery.ajax() calls to the standardized ElanRegistryAPI client
(statistics.js, tab-owner_mgmt.php, load-owner-profile.php, factory.php).
Fixed semantic bug in findCarByChassis endpoint. See
[ERROR_HANDLING.md](../development/ERROR_HANDLING.md) for API client patterns.

### PDO Type Safety Fixes (Issues #533-538)

Resolved TypeErrors caused by PDO returning string IDs in some PHP
configurations. Created `dbInt()`, `dbIntOrNull()`, and `currentUserId()`
helper functions. Updated test mocks to match real PDO behavior. See
[STRICT_TYPE_HANDLING.md](../development/STRICT_TYPE_HANDLING.md) and
[CODING_STANDARDS.md](../development/CODING_STANDARDS.md).

### Code Quality & Static Analysis

- Fixed PHPStan always-false conditions, unused properties/methods, and
  baseline type errors
- Modernized ChassisValidator and CarErrorMessages with
  `declare(strict_types=1)`
- Fixed `cars_hist` NOT NULL constraint errors in `delete()` and `merge()`
- Streamlined documentation, simplified pre-commit hooks, cleaned up .gitignore
- Fixed Playwright auth helper and test path resolution
- PHPUnit updated to 11.5.50
- Migrated `users/init.php` and `z_us_root.php` to `Server::get()` pattern
- Fixed thumbnail size fallback default (600 to 768)
- Fixed missing `global $settings` in `uploadImages()` causing wrong thumbnail size
- Fixed `__DIR__` paths in error/403.php and error/404.php
- Fixed autoassignun plugin compatibility in custom join view
- Fixed missing `user_id` in verification email params
- Migrated `validateChassis.php` AJAX check to `Server::get()` pattern
- Archived FIX scripts 17-23 to `FIX/_ARCHIVE/`
- Added FIX/24 for thumbnail regeneration at new size

---

## 🚀 Deployment Information

All unit and integration tests passing.

### Upgrading from v2.12.0

#### Step 1: Deploy Code Changes

```bash
git pull origin main
git checkout v2.13.0
```

#### Step 2: Verify Deployment

No composer installation required - all changes are code-level improvements.

#### Step 3: Run FIX/24

Navigate to `FIX/24-Regenerate-Optimized-Thumbnails.php` in your browser.
This updates the thumbnail size setting and regenerates optimized images.

#### Step 4: Verify Deployment

```bash
# Verify site loads correctly
# Check error logs for any warnings
# Test car edit form validation
```

#### Step 5: Monitor Logs

- Check UserSpice logs for any errors
- Monitor PHP error logs for TypeErrors
- Verify admin AJAX operations working correctly

### Rollback Plan

If issues are discovered post-deployment:

```bash
git checkout v2.12.0
# Restart web server
```

### Verification Checklist

After deployment, verify:

- [ ] Site loads without errors
- [ ] Login functionality works
- [ ] Car edit form displays validation errors correctly
- [ ] Admin AJAX operations complete successfully
- [ ] Statistics dashboard loads correctly
- [ ] Owner management operations work
- [ ] No TypeErrors in PHP error logs
- [ ] UserSpice logs show proper categorization
- [ ] Redirect after login returns to original page
- [ ] FIX/24 completed successfully (check fix_script_runs table)
- [ ] Thumbnails display at correct sizes

---

## 📝 Breaking Changes

**None** - This release maintains 100% backward compatibility.

All changes are internal architecture improvements that do not affect:

- Public APIs
- Database schema
- User workflows
- Admin functionality
- Existing integrations

The Car class refactoring uses a facade pattern with `class_alias` ensuring
all existing code continues to work without modification.

---

## 📚 Related Documentation

- [CLAUDE.md](../../CLAUDE.md) - Development guidelines with server globals
  patterns
- [ERROR_HANDLING.md](../development/ERROR_HANDLING.md) - Error handling and
  API patterns
- [CODING_STANDARDS.md](../development/CODING_STANDARDS.md) - Code quality
  requirements
- [PAGE_LOADING_FLOW.md](../development/PAGE_LOADING_FLOW.md) - Page
  initialization sequence
- [ARCHITECTURE.md](../development/ARCHITECTURE.md) - System architecture
  overview
- [TESTING.md](../testing/TESTING.md) - Testing guide with dual-bootstrap
  architecture
- [STRICT_TYPE_HANDLING.md](../development/STRICT_TYPE_HANDLING.md) - Type
  safety patterns

---

## 👥 Contributors

- **Jim Boone** - Development, testing, architecture, documentation
- **Claude Opus 4.5** - AI-assisted development, code review, testing,
  technical writing

---

**Summary:** This minor release delivers significant architecture
modernization through server globals migration, Car class decomposition,
and test infrastructure refactoring. Includes critical PDO type safety
fixes, jQuery to ElanRegistryAPI migration, PHPStan improvements, and
documentation streamlining. Zero breaking changes - full backward
compatibility maintained. All tests passing.

**Recommendation:** Deploy to production. Run FIX/24 after deployment to
update thumbnail sizes. Monitor logs for 24-48 hours post-deployment.

**Full Changelog:**
<https://github.com/unibrain1/elanregistry/compare/v2.12.0...v2.13.0>
