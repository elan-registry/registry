# Elan Registry v2.11.0 Release Notes

**Release Date:** January 9, 2026
**Type:** Minor Release - User Experience, Architecture & Security Enhancements

## ⚠️ PRE-DEPLOYMENT WARNING

**This deployment will temporarily break functionality.** The new autoloader requires dependencies to be installed before the site will work correctly.

**Recommended Approach:**

1. Enable maintenance mode before deploying
2. Deploy code changes
3. Run `scripts/install-dependencies.sh --prod` immediately
4. Verify site functionality
5. Disable maintenance mode

Skipping the dependency installation step will result in "Class not found" errors and site failures.

---

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### 1. Install Dependencies & Activate Autoloader (FIRST STEP - CRITICAL)

⚠️ **This step MUST be completed immediately after code deployment, before any other verification steps.**

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
- **Success Criteria:**
  - Script shows "CDN OPTIMIZATION COMPLETED SUCCESSFULLY"
  - Database backup was created
  - Settings table updated with optimized CDN URLs

**FIX Script #20** - Location Data Migration

- Navigate to: `/FIX/20-Backfill-Location-Coordinates.php`
- **What It Does:**
  1. **Forward Geocoding**: Profiles with city/state/country but missing coordinates → adds lat/lon
  2. **Reverse Geocoding**: ALL profiles with coordinates → standardizes location names using OpenStreetMap data
     - Fixes abbreviations: "OR" → "Oregon", "WV" → "West Virginia"
     - Fixes case inconsistencies: "Wv" → "West Virginia"
     - Fixes spelling variations and ensures consistent international location names
  3. **Car Synchronization**: Syncs updated profile location data to all cars owned by each user
- **Execution:**
  - Select batch size: 10 (safe), 20 (default), or 25 (faster)
  - **Keep browser window open** - auto-redirects between batches
  - Estimated time: 8-15 minutes for 500-1000 profiles
  - Monitor real-time progress with emoji indicators and batch counters
- **Success Criteria:**
  - Script shows "LOCATION DATA MIGRATION COMPLETE"
  - Summary shows profiles processed, updated, skipped, errors, and cars synced
  - Database backup was created automatically
- **Important Notes:**
  - Batch processing prevents PHP timeouts (processes 20 profiles per batch by default)
  - Auto-redirects between batches every ~25 seconds (stays within hosting limits)
  - Respects OpenStreetMap Nominatim usage policy (1 request per second)
  - All operations logged to UserSpice logs table for audit trail

### 3. Verify Site Functionality

After completing all steps above, verify everything is working:

**🎯 Success Criteria:**

- ✅ All FIX scripts completed successfully
- ✅ All custom classes load automatically without explicit requires
- ✅ Existing functionality continues to work without modification
- ✅ No "Class not found" errors in logs
- ✅ Location picker working on registration/settings pages
- ✅ GPS button and autocomplete functional
- ✅ DataTables pages load correctly
- ✅ Story pages accessible at `/docs/stories/` paths
- ✅ Menu items pointing to correct story URLs (handled by FIX #18)
- ✅ Owner locations display correctly on maps

## 👤 User-Facing Changes

### Modern Location Collection with GPS and Autocomplete

**New Location Picker Component** replaces manual city/state/country text entry with intelligent location collection:

**Key Features:**

1. **GPS Location Button**
   - One-click location detection using device GPS (HTML5 Geolocation)
   - Automatically populates city, state, country, and coordinates
   - Works on mobile and desktop devices with location services enabled
   - Shows loading indicator during GPS acquisition

2. **Autocomplete Location Search**
   - Type-ahead suggestions as you enter city or address
   - Powered by free OpenStreetMap services (Photon + Nominatim)
   - Displays formatted results with city, state/region, country
   - Keyboard navigation support (arrow keys, enter, escape)
   - Mobile-responsive with larger tap targets

3. **Privacy-Focused Design**
   - All API requests proxied through backend (your IP not exposed to OSM)
   - Session-based caching reduces redundant API calls
   - No API keys required, no tracking, no data sharing

**Where It's Used:**

- Registration form (new account creation)
- User Settings page (profile updates)
- Admin Panel owner management (admin updates)

**Benefits for Users:**

- Faster, more accurate location entry
- No manual typing of city/state/country
- Automatic coordinate population for map features
- Works on mobile devices with GPS
- Consistent location formatting across all profiles

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

### Modern Location Collection System

**Location Picker in Owner Management:**

- Owner profile editing now uses same location picker as user-facing forms
- GPS button and autocomplete available for quick location updates
- Coordinates automatically populated when location is selected
- Real-time validation of location data
- Improved UX for bulk owner profile updates

**Google Geocoding API Removal:**

- **Settings UI Removed**: "Google Services Integration" section removed from Admin Settings
- **Cost Savings**: Eliminates $60-600+ annual Google Geocoding API costs
- **Zero Ongoing Fees**: Free OpenStreetMap services replace Google API
- **Deprecated Classes**: `LocationGeocoder` class marked @deprecated (scheduled for removal in v3.0.0)
- **Backward Compatibility**: Existing code continues to work during v2.11.x lifecycle

**Important for Administrators:**

- No action required - Google API key no longer needed
- Existing profiles with coordinates remain unchanged
- Run FIX Script #20 to backfill missing coordinates for profiles without lat/lon
- Monitor logs table for any geocoding errors during backfill process

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
  - PSR-4 compatible autoloader supporting both namespaced and legacy classes
  - Automatic class loading from `/usersc/classes/` and `/app/admin/includes/classes/`
  - Foundation for gradual namespace migration
  - Simplified class loading configuration
- **Improved Code Organization**: Moved all exception classes to `usersc/classes/exceptions/` and admin utilities to `usersc/classes/` for better structure
- **Reduced Code Complexity**: Eliminated 10+ explicit class includes across the codebase, replaced with automatic on-demand loading
- **Future-Ready Architecture**: Enables gradual namespace migration (see issue #407) without breaking changes or code modifications
- **LocationGeocoder Class**: Replaced procedural include pattern (`_geolocate.php`) with proper OOP architecture
  - Runtime enforcement prevents direct instantiation outside ElanRegistryOwner
  - Centralized geocoding through `ElanRegistryOwner::geocodeAddress()` static method
  - Supports both forward (address → coordinates) and reverse (coordinates → address) geocoding
  - Updated 3 integration points: `during_user_creation.php`, `user_settings.php`, `ElanRegistryOwner` class
  - Removed legacy `app/views/_geolocate.php` file with no backward compatibility period
- **Autoloader Stability**: Fixed path ambiguity in `users/init.php` preventing "Class 'Input' not found" errors
  - Changed from relative to absolute path for UserSpice autoloader
  - Custom autoloader now loads after UserSpice in correct sequence
  - Moved admin classes (BackupManager, EnhancedSchemaManager) to `usersc/classes/` for consistent architecture

### Performance Optimization

- **DataTables CDN Optimized**: Removed 5 unused extensions from DataTables CDN URLs
  - **What Changed**: Analyzed actual DataTables usage across all pages
  - **Removed**: RowGroup, Scroller, Select, SearchBuilder, SearchPanes (unused extensions)
  - **Kept**: DataTables Core, FixedHeader, Responsive (actively used)
  - **Impact**: 62.5% reduction in loaded extensions (from 8 to 3)
  - **Benefits**:
    - Smaller bundle size → faster page loads
    - Less JavaScript to parse → improved browser performance
    - Reduced bandwidth usage
    - Cleaner, more maintainable configuration

**Optimized Extension Configuration:**

DataTables now loads only essential extensions:

1. DataTables Core (dt-1.10.23) - Base functionality
2. FixedHeader (fh-3.1.8) - Sticky table headers
3. Responsive (r-2.2.7) - Mobile-responsive tables

**Removed (unused):**

- ~~RowGroup (rg-1.1.2)~~ - Row grouping (not used)
- ~~Scroller (sc-2.0.3)~~ - Virtual scrolling (not used)
- ~~Select (sl-1.3.3)~~ - Row selection (not used)
- ~~SearchBuilder (sb-1.0.1)~~ - Query builder (not used, poor UX with server-side)
- ~~SearchPanes (sp-1.2.2)~~ - Faceted search (not used, poor UX with server-side)

### Technical Benefits

- **Performance**: PSR-4 fast path for namespaced classes (< 0.1ms), cached iterator for non-namespaced classes (< 1ms)
- **Maintainability**: Single autoloader replaces fragmented loading logic, easier to understand and maintain
- **Developer Experience**: New classes are automatically discovered, no manual includes needed
- **Testing**: Comprehensive test suite (7 tests, 35 assertions) ensures reliability
- **Onboarding**: New developers can quickly understand the page loading sequence and initialization flow
- **UserSpice Integration**: Added story paths to `z_us_root.php` configuration

## 🔐 Security Enhancements

### Branded Error Pages (#449, #436)

- **403 Forbidden** and **404 Not Found** pages redesigned with consistent site branding
- Improves user experience when accessing restricted or missing content
- Maintains security by not revealing sensitive system information

### CORS Misconfiguration Fixes (#419)

- **Fixed 13 instances** of CORS header misconfiguration
- Prevents unauthorized cross-origin requests that could expose sensitive data
- Ensures proper origin validation for API endpoints

### SQL Timing Attack Vulnerability Fixes (#418)

- **Addressed timing-based vulnerabilities** in database queries
- Prevents attackers from inferring database structure through request timing analysis
- Ensures constant-time operations for security-sensitive comparisons

### Subresource Integrity (SRI) for CDN Resources (#413)

- **Added SRI hashes** to all external resource CDN URLs (FIX Script #17)
- Prevents tampering with resources served from third-party CDNs
- Ensures only cryptographically verified resources are loaded

## 🔧 Error Handling Foundation

### Exception Hierarchy (#455)

- **ElanRegistryException base class** created as foundation for custom exception handling
- Enables type-safe exception catching and propagation
- Foundation for comprehensive error handling standardization (Issue #351 series)

### Standardized API Responses (#454)

- **ApiResponse class** provides consistent structure for AJAX responses
- Improves frontend error handling with predictable response formats
- Supports both success and error response types

### Log Category Constants (#439)

- **Centralized error categories** for consistent logging across application
- Categories include: SystemError, ValidationError, FileError, DatabaseError, CarErrors, CarActions, DatabaseMaintenance
- Enables better log filtering, analysis, and troubleshooting

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

### Step 5: Run FIX Scripts (In Order)

After successful login verification:

1. Run FIX/17 - Add SRI to CDN Resources
2. Run FIX/18 - Update Stories Directory Paths
3. Run FIX/19 - Optimize DataTables CDN
4. Run FIX/20 - Location Data Migration

### Verification Checklist

- [ ] `usersc/vendor/` directory exists
- [ ] `usersc/vendor/johnathanmiller/secure-env-php/` exists
- [ ] Root `vendor/johnathanmiller/` does NOT exist
- [ ] Site loads without errors
- [ ] Can login successfully
- [ ] Database operations work
- [ ] All FIX scripts completed
- [ ] Location picker functional
- [ ] Error pages display correctly

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

### User Experience

[#245](https://github.com/unibrain1/elanregistry/issues/245) - Feature: Modern Location Collection (OpenStreetMap Integration)

- Implemented combined HTML5 GPS + autocomplete location picker
- Replaced manual city/state/country text entry with intelligent location collection
- Eliminated Google Geocoding API dependency (saves $60-600+ annually)
- Added server-side caching and rate limiting for OSM API calls
- Created FIX Script #20 for comprehensive location data migration

[#432](https://github.com/unibrain1/elanregistry/issues/432) - Optimize DataTables CDN configuration by removing unused extensions

- Removed 5 unused extensions: RowGroup, Scroller, Select, SearchBuilder, SearchPanes
- 62.5% reduction in loaded extensions (from 8 to 3)
- Faster page loads, reduced bandwidth usage

### Architecture & Infrastructure

[#426](https://github.com/unibrain1/elanregistry/issues/426) - Architecture: Create unified autoloader for usersc/classes directory

- Unified class autoloading with PSR-4 compatibility
- Supports both namespaced and legacy classes
- Foundation for gradual namespace migration

[#427](https://github.com/unibrain1/elanregistry/issues/427) - Move SecureEnvPHP to usersc/vendor for cleaner dependency management

- Relocated third-party dependency for better project organization
- No functional changes to existing code

[#428](https://github.com/unibrain1/elanregistry/issues/428) - Add installation script for dependency management

- Automated `scripts/install-dependencies.sh` script
- Idempotent and supports both dev and prod modes

[#422](https://github.com/unibrain1/elanregistry/issues/422) - Create LocationGeocoder class to replace procedural geocoding pattern

- Replaced procedural include pattern with proper OOP architecture
- Updated 3 integration points (during_user_creation.php, user_settings.php, ElanRegistryOwner)
- Removed legacy `app/views/_geolocate.php` file

[#429](https://github.com/unibrain1/elanregistry/issues/429) - Architecture: Implement unified namespace-aware autoloader

- PSR-4 compatible autoloader supporting both namespaced and legacy classes
- Automatic class loading from `/usersc/classes/` and `/app/admin/includes/classes/`
- Foundation for gradual namespace migration

[#430](https://github.com/unibrain1/elanregistry/issues/430) - Move SecureEnvPHP to usersc/vendor for cleaner dependency management

- Relocated third-party dependency for better project organization
- No functional changes to existing code

[#360](https://github.com/unibrain1/elanregistry/issues/360) - Move stories/ directory to docs/ for better organization

- Consolidated documentation structure under `/docs/` directory
- Updated all paths and menu items (handled by FIX Script #18)

### Security

[#413](https://github.com/unibrain1/elanregistry/issues/413) - Add Subresource Integrity (SRI) to CDN resources

- Added SRI hashes to all external resource CDN URLs (FIX Script #17)
- Prevents tampering with resources served from third-party CDNs

[#418](https://github.com/unibrain1/elanregistry/issues/418) - Fix SQL timing attack vulnerability

- Addressed timing-based vulnerabilities in database queries
- Ensures constant-time operations for security-sensitive comparisons

[#419](https://github.com/unibrain1/elanregistry/issues/419) - Fix CORS misconfiguration (13 instances)

- Fixed CORS header misconfiguration across application
- Prevents unauthorized cross-origin requests

[#449](https://github.com/unibrain1/elanregistry/issues/449) - Add branded 403/404 error pages matching site design

- Redesigned error pages with consistent site branding
- Improves user experience when accessing restricted or missing content

### Error Handling

[#437](https://github.com/unibrain1/elanregistry/issues/437) - Add ApiResponse class for standardized AJAX responses

- Consistent structure for AJAX responses
- Improves frontend error handling with predictable response formats

[#438](https://github.com/unibrain1/elanregistry/issues/438) - Implementation and integration of ApiResponse class

- Complete implementation with support for success and error responses
- Integration across AJAX endpoints

[#439](https://github.com/unibrain1/elanregistry/issues/439) - Add log category constants for error logging

- Centralized error categories for consistent logging
- Categories: SystemError, ValidationError, FileError, DatabaseError, CarErrors, CarActions, DatabaseMaintenance

[#454](https://github.com/unibrain1/elanregistry/issues/454) - Add ApiResponse class for standardized AJAX responses (#437)

- Standardized API response handling
- Foundation for comprehensive error handling

[#455](https://github.com/unibrain1/elanregistry/issues/455) - Implement Exception Hierarchy: Create ElanRegistryException Base Class

- Base exception class for custom exception handling
- Foundation for comprehensive error handling standardization (Issue #351 series)

### Developer Experience

[#359](https://github.com/unibrain1/elanregistry/issues/359) - Move identification guide documentation to docs/ directory

- Consolidated documentation structure
- Cleaner project organization

[#363](https://github.com/unibrain1/elanregistry/issues/363) - Add git tag-based versioning via deployment hooks

- Automated version management through git tags
- Enables reliable release tracking

[#434](https://github.com/unibrain1/elanregistry/issues/434) - Create PAGE_LOADING_FLOW.md documentation

- Comprehensive developer reference for file loading sequence
- Traces 40-60+ files loaded during page initialization
- Troubleshooting guide and integration points

### Optimizations & Closed Issues

[#127](https://github.com/unibrain1/elanregistry/issues/127) - Lightbox for photos (Closed as Won't Fix)

- Analysis revealed issues with photo lightbox implementation
- Deferred to future release

[#168](https://github.com/unibrain1/elanregistry/issues/168) - Feature: Enhanced search capability (Closed as Won't Fix)

- Analysis revealed 5 unused DataTables extensions (62.5% of loaded extensions)
- Created FIX Script #19 to optimize DataTables CDN instead
- Existing global search functionality sufficient for current user needs

[#265](https://github.com/unibrain1/elanregistry/issues/265) - Data quality query optimizations

- Optimized queries for data quality reporting

[#328](https://github.com/unibrain1/elanregistry/issues/328) - Performance optimizations

- Various performance improvements across application

[#431](https://github.com/unibrain1/elanregistry/issues/431) - Implement LocationGeocoder class (superseded by #245)

- Intermediate refactoring step replaced by LocationService in Issue #245
- Class marked @deprecated, scheduled for removal in v3.0.0

---

## 📝 Development Notes

### Issue #245 Implementation Summary

**Objective**: Replace Google Geocoding API with free OpenStreetMap services and improve location collection UX.

**Architecture Decisions:**

1. **Backend Proxy Pattern**
   - All OSM API calls proxied through PHP backend
   - Benefits: Privacy (user IP not exposed), rate limiting, server-side caching, CSP compliance
   - Implementation: `LocationService` class with Photon + Nominatim integration

2. **Client-Side Session Caching**
   - SessionStorage caching for autocomplete results during current session
   - Benefits: Reduced API calls, improved responsiveness, privacy-friendly

3. **Batch-Processed Migration with Comprehensive Standardization**
   - FIX Script #20 uses **batch processing** to prevent PHP timeouts
   - Configurable batch size: 10/20/25 profiles per batch (20 default)
   - Auto-redirects between batches every ~25 seconds (stays within hosting limits)
   - Tracks cumulative progress across all batches with URL parameters
   - Two-phase processing:
     - Phase 1: Forward geocoding (city/state/country → lat/lon for profiles missing coordinates)
     - Phase 2: Reverse geocoding (ALL profiles with coordinates → standardized location names)
   - Standardization fixes:
     - "OR" → "Oregon", "WV" → "West Virginia" (abbreviations)
     - "Wv" → "West Virginia" (case inconsistencies)
     - Ensures consistent international location naming
     - Only updates profiles where changes are needed (skips already-standardized data)
   - Car synchronization: profile location → all owned cars (ensures data consistency)
   - Respects Nominatim usage policy: 1-second delay between requests
   - Estimated 8-15 minutes for 500-1000 profiles

**Language Preference (English Standardization):**

- All API requests specify English as the preferred language
  (`accept-language=en` for Nominatim, `lang=en` for Photon)
- Prevents multilingual country names like "België / Belgique / Belgien"
  (returns "Belgium" instead)
- Ensures consistent English location names across all profiles
- **Applies universally to:**
  - Location picker autocomplete (registration, user settings, admin panel)
  - GPS reverse geocoding (when users click "Use GPS" button)
  - FIX Script #20 batch standardization (existing data cleanup)
- All future location entries will automatically use English names

**Technical Implementation:**

- **New Classes**: `LocationService` (`/usersc/classes/LocationService.php`)
- **AJAX Endpoints**: `location-search.php`, `location-reverse.php`
- **Frontend Component**: `location-picker.js` with GPS and autocomplete
- **CSS**: `location-picker.css` with Bootstrap theming
- **Modified Files**: `_join.php`, `user_settings.php`, `load-owner-profile.php`, `process-owner-update.php`
- **Deprecated**: `LocationGeocoder` class (scheduled for removal in v3.0.0 - Issue #433)

**API Services Used:**

- **Photon API**: Primary autocomplete service (CompassHub, free, no API key)
- **Nominatim API**: Fallback + reverse geocoding (OpenStreetMap, free, no API key)
- **Rate Limiting**: 1 request per second (Nominatim requirement)

**Cost Savings:**

- Before: Google Geocoding API ($5-50/1000 requests, estimated $60-600+/year)
- After: $0/year (free OSM services with usage policy compliance)

**Security Considerations:**

- CSRF protection on all AJAX endpoints
- Input validation for coordinate ranges (-90 to 90 lat, -180 to 180 lon)
- Rate limiting to prevent abuse (10 requests/minute per user)
- XSS prevention via proper HTML escaping
- Backend proxy eliminates CSP concerns

### Issue #168 Investigation Summary

Prototyped both DataTables SearchPanes and SearchBuilder extensions for enhanced search functionality. Both approaches were found to have **significant UX issues** with our server-side DataTables implementation:

**SearchPanes Issues:**

- Shows empty filter panes because it requires ALL data to build filters
- Incompatible with server-side processing without major backend refactoring
- Depends on Select extension (also unused)

**SearchBuilder Issues:**

- Too complex for average users (requires understanding query logic)
- Not intuitive compared to simple visual filters

**Resolution:**

- Closed issue #168 as "Won't Fix" for current release
- Created FIX #19 to optimize DataTables CDN by removing ALL unused extensions
- Analysis revealed we were loading 5 unused extensions (62.5% of loaded extensions)
- Optimized to load only what we use: Core, FixedHeader, Responsive
- Existing global search functionality is sufficient for current user needs

**Performance Impact:**

- Before: 8 extensions loaded (5 unused)
- After: 3 extensions loaded (0 unused)
- Result: Faster page loads, reduced bandwidth, cleaner configuration

**Alternative Solutions for Future:**

- Custom dropdown filters above tables
- Column-specific search boxes
- Dedicated advanced search page
- Can be revisited if strong user demand emerges

---

**Related Work**: This release establishes the foundation for [#407](https://github.com/unibrain1/elanregistry/issues/407) - a phased namespace migration strategy that will gradually modernize the codebase while maintaining backward compatibility.
