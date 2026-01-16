# Elan Registry v2.11.0 Release Notes

**Release Date:** January 9, 2026
**Type:** Minor Release - Architecture, Documentation & Organization

## ⚠️ PRE-DEPLOYMENT WARNING

**This deployment will temporarily break functionality.** The new autoloader
requires dependencies to be installed before the site will work correctly.

**Recommended Approach:**
1. Enable maintenance mode before deploying
2. Deploy code changes
3. Run `scripts/install-dependencies.sh --prod` immediately
4. Verify site functionality
5. Disable maintenance mode

Skipping the dependency installation step will result in "Class not found"
errors and site failures.

---

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### 1. Install Dependencies & Activate Autoloader (FIRST STEP - CRITICAL)

⚠️ **This step MUST be completed immediately after code deployment, before
any other verification steps.**

```bash
cd /path/to/elan_registry
./scripts/install-dependencies.sh --prod
```

The script will:
- Install dependencies in `usersc/vendor/`
- Verify SecureEnvPHP package installation
- Activate the new unified autoloader
- Provide verification output

**Without this step, the site will not function.**

### 2. Run FIX Scripts (In Order)

After installing dependencies, run these FIX scripts in sequence:

**FIX Script #17** - Add SRI to CDN Resources
- Navigate to: `/FIX/17-Add-SRI-To-CDN-Resources.php`
- Adds Subresource Integrity hashes to CDN resources for security
- Follow on-screen instructions

**FIX Script #18** - Update Stories Directory Paths
- Navigate to: `/FIX/18-Update-Stories-Directory-Paths.php`
- Updates menu items from `/stories/` to `/docs/stories/` automatically
- Updates page permissions for new paths
- Handles: SGO_2F, brian_walton, type26register.php

**FIX Script #19** - Optimize DataTables CDN
- Navigate to: `/FIX/19-Add-Select-Extension-DataTables-CDN.php`
- Removes 5 unused DataTables extensions
- Creates database backup automatically

**FIX Script #20** - Location Data Migration
- Navigate to: `/FIX/20-Backfill-Location-Coordinates.php`
- Select batch size: 10 (safe), 20 (default), or 25 (faster)
- **Keep browser window open** - auto-redirects between batches
- Estimated time: 8-15 minutes for 500-1000 profiles
- Standardizes all location names and backfills missing coordinates

### 3. Verify Site Functionality

After completing all steps above, verify everything is working:

**🎯 Success Criteria:**

- ✅ All FIX scripts completed successfully
- ✅ All custom classes load automatically without explicit requires
- ✅ Existing functionality continues to work without modification
- ✅ No "Class not found" errors in logs
- ✅ Location picker working on registration/settings pages
- ✅ DataTables pages load correctly
- ✅ Story pages accessible at `/docs/stories/` paths
- ✅ Menu items pointing to correct story URLs (handled by FIX #18)

## 👤 User-Facing Changes

### URL Changes
- **Story URLs have moved**: All car stories and Type 26 archive have moved from `/stories/*` to `/docs/stories/*`
- **No automatic redirects**: Old bookmarks will result in 404 errors (low-traffic documentation section)
- **Updated paths**:
  - Car Stories landing page remains at `/docs/car-stories.php`
  - SGO 2F story now at `/docs/stories/SGO_2F/`
  - Brian Walton rally story now at `/docs/stories/brian_walton/`
  - Type 26 archive now at `/docs/stories/type26register.php`

### Internal Improvements
**No other visible changes for end users** - Additional changes focus on internal architecture improvements and developer documentation that enhance code maintainability without affecting user-facing functionality.

## 🔧 Admin-Facing Changes

### Documentation Organization
- **Consolidated documentation structure**: All reference content now under `/docs/` directory
- **Cleaner root directory**: Removed `/stories/` from root level for better project organization
- **Consistent patterns**: Follows same pattern as identification guide move (Issue #359)
- **PAGE_LOADING_FLOW.md**: Comprehensive developer reference documenting the complete file loading sequence
  - Traces all 40-60+ files loaded during page initialization
  - Documents 4 major phases: core init, template prep, page execution, footer
  - Clarifies autoloader scope and class loading mechanisms
  - Provides troubleshooting guide for common initialization issues
  - Shows when global variables become available
  - Includes integration points for custom code

### Architecture Improvements
- **Unified Class Autoloading**: Consolidated all custom class loading into a single hybrid autoloader that supports both current non-namespaced classes and future namespaced classes
- **Improved Code Organization**: Moved all exception classes to `usersc/classes/exceptions/` and admin utilities to `usersc/classes/` for better structure
- **Reduced Code Complexity**: Eliminated 10+ explicit class includes across the codebase, replaced with automatic on-demand loading
- **Future-Ready Architecture**: Enables gradual namespace migration (see issue #407) without breaking changes or code modifications
- **LocationGeocoder Class**: Replaced procedural include pattern (`_geolocate.php`) with proper OOP architecture
  - Runtime enforcement prevents direct instantiation outside ElanRegistryOwner
  - Centralized geocoding through `ElanRegistryOwner::geocodeAddress()` static method
  - Supports both forward (address → coordinates) and reverse (coordinates → address) geocoding
  - Updated 3 integration points: `during_user_creation.php`, `user_settings.php`, `ElanRegistryOwner` class
  - Removed legacy `app/views/_geolocate.php` file with no backward compatibility period

### Technical Benefits
- **Performance**: PSR-4 fast path for namespaced classes (< 0.1ms), cached iterator for non-namespaced classes (< 1ms)
- **Maintainability**: Single autoloader replaces fragmented loading logic, easier to understand and maintain
- **Developer Experience**: New classes are automatically discovered, no manual includes needed
- **Testing**: Comprehensive test suite (7 tests, 35 assertions) ensures reliability
- **Onboarding**: New developers can quickly understand the page loading sequence and initialization flow
- **UserSpice Integration**: Added story paths to `z_us_root.php` configuration
- **Autoloader Stability**: Fixed path ambiguity in `users/init.php` preventing "Class 'Input' not found" errors
  - Changed from relative to absolute path for UserSpice autoloader
  - Custom autoloader now loads after UserSpice in correct sequence
  - Moved admin classes (BackupManager, EnhancedSchemaManager) to `usersc/classes/` for consistent architecture

## 🔧 Infrastructure Changes

### SecureEnvPHP Dependency Reorganization

**Change:** Moved `johnathanmiller/secure-env-php` from root `vendor/` to `usersc/vendor/` for cleaner dependency management.

**Why:** Separates development dependencies (PHPUnit, PHPStan in root) from application runtime dependencies (SecureEnvPHP in usersc).

**Impact:** No user-facing changes. Cleaner code architecture following UserSpice patterns.

### Dependency Lock File Management

**Fixed:** `usersc/composer.lock` now tracked in version control for consistent dependency versions across all environments.

**Why:** Both root and usersc lock files must be committed to ensure identical dependency versions in development, staging, and production. This prevents "works on my machine" issues and provides a complete audit trail of dependency changes.

**Change:** Removed `usersc/composer.lock` from `.gitignore` (line 98).

### Installation Script Added

**New:** `scripts/install-dependencies.sh` - Automated dependency installation script

**Features:**
- Idempotent: Safe to run multiple times
- Validates Composer availability
- Verifies successful installation
- Supports both development (`--dev`) and production (`--prod`) modes
- Comprehensive progress feedback and error handling

**Usage:**
```bash
# Development
./scripts/install-dependencies.sh --dev

# Production
./scripts/install-dependencies.sh --prod
```

## 🚨 DEPLOYMENT SEQUENCE (CRITICAL)

This release requires manual composer installation in the `usersc/` directory. **Follow these steps in exact order:**

### Step 1: Deploy Code Changes
Deploy the updated codebase as normal (git pull, etc.)

### Step 2: Install usersc Dependencies (REQUIRED)

**Option A: Automated Installation (Recommended)**
```bash
cd /path/to/elan_registry
./scripts/install-dependencies.sh --prod
```

The installation script will:
- Install dependencies in usersc/vendor/
- Verify SecureEnvPHP package installation
- Update root dependencies for production
- Provide detailed progress and verification

**Option B: Manual Installation**
```bash
cd /path/to/elan_registry/usersc
composer install --no-dev --optimize-autoloader
cd ..
```

**Verify installation succeeded:**
```bash
ls -la usersc/vendor/johnathanmiller/secure-env-php
```
You should see the SecureEnvPHP package directory.

### Step 3: Update Root Dependencies (Manual Installation Only)

**Note:** If you used the automated installation script (Option A), skip this step - it's already done.

For manual installation (Option B):

```bash
cd /path/to/elan_registry
composer update --no-dev
```

This removes SecureEnvPHP from root vendor/ (no longer needed).

### Step 4: Verify Successful Login
1. Access the site homepage
2. Login with your admin account
3. Verify no errors in error logs
4. Confirm database connectivity works

**This verifies environment variables are loading correctly.**

### Step 5: Run FIX/18 Script
After successful login verification:
1. Navigate to `FIX/` directory via admin panel
2. Run script `18-Update-Stories-Directory-Paths.php`
3. Follow on-screen instructions

### Verification Checklist
- [ ] `usersc/vendor/` directory exists
- [ ] `usersc/vendor/johnathanmiller/secure-env-php/` exists
- [ ] Root `vendor/johnathanmiller/` does NOT exist
- [ ] Site loads without errors
- [ ] Can login successfully
- [ ] Database operations work
- [ ] FIX/18 script completed

### Rollback Procedure (if needed)
If issues occur:
```bash
git checkout composer.json usersc/includes/custom_functions.php users/init.php
composer install
```

Then restart web server and test.

### Support
If you encounter issues during deployment, check:
1. PHP error logs for specific error messages
2. Verify `.env.enc` and `.env.key` files exist in root
3. Check file permissions on `usersc/vendor/` directory
4. Confirm composer installed successfully (no errors in output)

## 📋 Issues Resolved in This Release

[#360](https://github.com/unibrain1/elanregistry/issues/360) - Move stories/
directory to docs/ for better organization

[#422](https://github.com/unibrain1/elanregistry/issues/422) - Create
LocationGeocoder class to replace procedural geocoding pattern

[#426](https://github.com/unibrain1/elanregistry/issues/426) - Architecture:
Create unified autoloader for usersc/classes directory

[#427](https://github.com/unibrain1/elanregistry/issues/427) - Move
SecureEnvPHP to usersc/vendor for cleaner dependency management

---

**Documentation Added**:

- `docs/development/PAGE_LOADING_FLOW.md` - Complete reference for
  understanding file loading sequence and initialization phases
- Comprehensive documentation for all car stories and Type 26 archive content

**Related Work**: This release establishes the foundation for
[#407](https://github.com/unibrain1/elanregistry/issues/407) - a phased
namespace migration strategy that will gradually modernize the codebase while
maintaining backward compatibility.
