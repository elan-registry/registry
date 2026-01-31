# Elan Registry v2.9.1 Release Notes

**Release Date:** December 16, 2025
**Type:** Minor Release - Admin Interface Refinements & Code Quality

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### Execute Page Permissions Correction Script
**⚠️ CRITICAL: Manual execution required via admin interface**

1. **Run FIX/15 Script** *(via Admin → System Maintenance → FIX Scripts)*
   - Navigate to `/app/admin/fix-scripts/`
   - Execute `15-Page-Permissions-Correction.php`
   - Review and apply suggested page permission corrections
   - Verify all administrative pages have proper permission assignments
   - Confirm successful execution in FIX script logs

2. **Verify Admin Panel Access**
   - Test admin panel access at `/app/admin/manage-consolidated.php`
   - Confirm all tabs load correctly (Car/Owner, Manage Cars, Owner Management, Settings)
   - Verify user permissions for administrative functions

3. **Test Verify Application**
   - Navigate to `/app/admin/verify/`
   - Confirm all verification workflows function correctly
   - Test ownership verification and car approval processes

**🎯 Success Criteria:**
- ✅ FIX/15 script executed successfully *(requires manual execution)*
- ✅ Admin panel tabs display correctly with no access errors
- ✅ Page permissions properly assigned in UserSpice system
- ✅ Verify application accessible at new admin location

## 👤 User-Facing Changes

### Enhanced Documentation & Help System
- **Comprehensive Car Registration Guide**: New step-by-step user guide with screenshots showing how to add cars to the registry
- **Improved Chassis Validation Documentation**: Enhanced documentation with visual examples of valid chassis number formats
- **Better Document Navigation**: Fixed anchor link handling and table of contents navigation in documentation viewer
- **Improved Accessibility**: Enhanced breadcrumb visibility and contrast for easier navigation
- **Image Support in Documentation**: Full markdown image rendering with security validation for screenshots and diagrams

### Visual & UX Improvements
- **Document Formatting**: Fixed bold/italic text rendering in document viewer lists
- **Better Error Messages**: Enhanced error messaging throughout the application
- **Improved Contrast**: Better visibility for navigation elements
- **Image Display Fix**: Resolved missing dot in resized image filenames causing 404 errors (CarView.php)
  - Fixed broken URLs like `img-resized-100jpg` to properly generate `img-resized-100.jpg`
  - Affects all car detail and listing page images

## 🔧 Admin-Facing Changes

### Email Configuration Standardization
- **Updated Admin Email**: Changed default admin contact from `admin@elanregistry.org` to `registrar@elanregistry.org` across:
  - Transfer request notifications and admin alerts
  - Email template contact information
  - System settings and configuration defaults
- **Enhanced Email Debugging**: Added transaction logging to Sendinblue plugin for troubleshooting delivery issues
- **Fixed Email Function Calls**: Corrected missing `to_name` parameter in email notification functions

### Admin Interface Reorganization
- **Verify Application Moved**: Relocated to `/app/admin/verify/` for better organization and security
- **Removed Legacy Data Quality Tab**: Cleaned up deprecated data quality interface (functionality integrated into main admin tabs)
- **Page Permissions Correction**: FIX/15 script ensures all admin pages have proper UserSpice permission assignments

### Code Architecture Improvements
- **ElanRegistryOwner Class**: New dedicated class for owner/user data management following established Car class patterns
  - Standardized data access methods
  - Consistent error handling and logging
  - Integration with existing `getUserWithProfile()` function
- **Improved Error Logging**: Migrated from `error_log()` to UserSpice `logger()` integration for centralized error tracking
- **Code Cleanup**: Removed unreferenced files and improved data-quality tab integration
- **Type Safety Enhancements**: Added explicit type casting in manage-consolidated.php
  - Improved type safety for car ID and user ID parameters
  - Prevents potential type-related issues in database operations
- **Legacy Code Removal**: Removed obsolete `app/cars/manage.php` (1814 lines)
  - Functionality consolidated into manage-consolidated.php
  - Reduces code duplication and maintenance burden

### Testing Infrastructure Enhancements
- **PHPUnit 12 Compatibility**: Upgraded test infrastructure to support latest PHPUnit with PHP 8.2+
- **Resolved Test Errors**: Fixed 12 of 16 test failures (75% improvement) in test suite
- **Test Documentation**: Consolidated testing documentation for better developer experience
- **Playwright Dependencies**: Updated to latest Playwright and test packages
- **Pre-commit Hook Improvements**: Fixed SQL injection checker regex to avoid false positives
  - Updated regex to only match variables inside query strings
  - Prevents flagging proper prepared statements with parameter arrays

### Backup & Maintenance Improvements
- **Enhanced FIX Script UI**: Standardized progress tracking and status updates across administrative scripts
- **Improved Backup System**: Better backup file management with consistent patterns
- **Missing Functions Restored**: Added missing `createStandardBackup()` function for deployment safety

## 📋 What's New Since v2.8.6

### v2.9.0 - Admin Automation & Documentation (December 9, 2025)
- **Consolidated Admin Interface**: Single unified interface at `/app/admin/manage-consolidated.php` with tabbed organization
- **Unified Documentation System**: Consolidated markdown viewer with security and access control at `/docs/view.php`
- **Transfer System Documentation**: Comprehensive user and admin guides for ownership transfer workflows
- **Database Documentation**: Complete schema and relationship documentation
- **FIX Script Standardization**: Template-based approach for consistent administrative scripts

### v2.9.1 - Interface Refinements & Quality Improvements (This Release)
- **Page Permissions Management**: FIX/14 and FIX/15 scripts for comprehensive permission validation
- **Verify App Reorganization**: Moved to admin directory for better security and organization
- **ElanRegistryOwner Class**: Standardized owner data access architecture
- **Email Configuration**: Unified admin email addresses across all notifications
- **Documentation Enhancements**: User guides with screenshots and improved navigation
- **Testing Improvements**: PHPUnit 12 support and test error resolution
- **Code Quality**: Removed legacy code and improved error logging patterns

## 📋 Issues Resolved in This Release

### Critical Fixes
[#364](https://github.com/unibrain1/elanregistry/issues/364) - Missing create tables and backup function in v2.9.1.rc1
- **Parse Error Fix**: Resolved incomplete string in manage-consolidated.php causing syntax errors
- **Image Display Fix**: Fixed missing dot in resized image filenames (CarView.php)

### Dependency Updates
[#353](https://github.com/unibrain1/elanregistry/issues/353) - Bump playwright and @playwright/test dependencies

### Issues Created for Future Work
[#374](https://github.com/unibrain1/elanregistry/issues/374) - Fix 9 failing unit tests in test suite
[#375](https://github.com/unibrain1/elanregistry/issues/375) - Update hardcoded 600px image size references to 768px

### Code Quality & Architecture
- **ElanRegistryOwner Class Refactoring**: Standardized owner data access following Car class patterns
- **Error Logging Migration**: Replaced `error_log()` with UserSpice `logger()` integration
- **Legacy Code Cleanup**: Removed deprecated Data Quality tab and unreferenced files

### Admin Interface & Permissions
- **FIX/14 Completion**: Admin page permissions update script
- **FIX/15 Implementation**: Page permissions correction script for deployment
- **Verify App Relocation**: Moved to `/app/admin/verify/` for better organization

### Email & Notifications
- **Email Configuration Standardization**: Updated admin email addresses across all templates and settings
- **Email Function Fixes**: Corrected missing `to_name` parameter in notification functions
- **Enhanced Debugging**: Added transaction logging for email troubleshooting

### Testing & Quality
- **PHPUnit 12 Upgrade**: Full compatibility with latest testing framework
- **Test Error Resolution**: Fixed 12 of 16 failing tests (75% improvement)
- **Test Infrastructure**: Consolidated documentation and improved test organization

### Documentation
- **Car Registration Guide**: Comprehensive step-by-step guide with screenshots
- **Chassis Validation Documentation**: Enhanced with visual examples
- **Image Support**: Full markdown image rendering with security validation
- **Navigation Improvements**: Fixed anchor links and table of contents
- **Markdown Formatting**: Improved ADD_CAR_GUIDE.md formatting consistency
  - Updated italic formatting from asterisks to underscores
  - Fixed chassis validation link path

---

## 🔄 Upgrade Path from v2.8.6

1. **Deploy Code**: Push to production via standard deployment process
2. **Execute FIX/15**: Run page permissions correction script via admin interface
3. **Verify Admin Access**: Test all admin panel tabs and functionality
4. **Check Email Configuration**: Verify admin email settings in Admin Panel → Settings tab
5. **Test Documentation**: Confirm documentation viewer renders images and navigation works
6. **Monitor Logs**: Check UserSpice logs for any permission or access issues

**Rollback Plan**: Git-based code rollback to v2.8.6 tag if critical issues encountered

---

## ⚠️ Known Issues

The following issues are known in this release and will be addressed in future updates:

### System Maintenance

[#364](https://github.com/unibrain1/elanregistry/issues/364) - **Run Maintenance Function Issues**

- Missing `createStandardizedBackup()` function - needs relocation from FIX directory to utilities
- View Backup Directory functionality gives error (currently commented out in `tab-system.php`)

[#307](https://github.com/unibrain1/elanregistry/issues/307) - **Database Schema Inconsistency**

- `fix_script_runs` table structure differs between development, test, and production environments

### Owner Management

[#367](https://github.com/unibrain1/elanregistry/issues/367) - **Duplicated Emails Section**

- Edit button does not work in the duplicated emails section on Owner Management tab (`app/admin/manage-consolidated.php?tab=owner-mgmt`)

**Owner Cleanup System** - Interface-only implementation, cleanup functionality not yet implemented

---

**🎉 This release refines the v2.9.0 admin automation features with improved code architecture, standardized email configuration, and enhanced documentation. The ElanRegistryOwner class and testing improvements establish better patterns for future development.**
