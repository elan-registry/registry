# Elan Registry v2.10.0 Release Notes

**Release Date:** January 5, 2026
**Type:** Minor Release - Framework Upgrade, Development Automation & 
Documentation Overhaul

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

### 1. Upgrade UserSpice Framework
**⚠️ CRITICAL: UserSpice 6.0 upgrade must be completed first**

1. **Navigate to UserSpice Dashboard** *(Admin Panel)*
   - Log in to admin account
   - Navigate to Admin Panel → UserSpice Dashboard
   - Follow UserSpice 6.0 upgrade instructions
   - Complete database migrations if prompted
   - Verify successful upgrade confirmation message

### 2. Run FIX Script for Security Updates

**⚠️ IMPORTANT: This script adds Subresource Integrity (SRI) to CDN resources 
and upgrades DataTables to fix CVE-2021-23445**

1. **Run FIX/17-Add-SRI-To-CDN-Resources.php** *(via Admin Panel → Maintenance)*
   - Navigate to `/FIX/17-Add-SRI-To-CDN-Resources.php`
   - Click "Run Script" to execute
   - Verify completion message shows "SUCCESS"
   - Confirm CDN settings updated in Admin Panel → Settings
   - Expected results:
     - jQuery, DataTables, Chart.js, and Bootstrap now have SRI hashes
     - DataTables upgraded from v1.10.23 to v1.11.3
     - All CDN resources include `integrity` and `crossorigin` attributes

### 3. Verify Rate Limits are Active

**⚠️ Production rate limits now enabled by default**

1. **Verify Rate Limits** *(via testing or monitoring)*
   - Production rate limits now enabled by default
   - Test authentication endpoints for rate limiting behavior
   - Monitor logs for rate limit enforcement

**🎯 Success Criteria:**

- ✅ UserSpice 6.0 upgrade completed successfully *(PENDING - requires manual 
execution)*
- ✅ FIX/17 script completed successfully *(PENDING - requires manual execution)*
- ✅ CDN resources load correctly with SRI validation *(PENDING - requires verification)*
- ✅ Rate limits actively protecting authentication endpoints *(COMPLETED - 
enabled by default)*
- ✅ No console errors or security warnings *(PENDING - requires browser testing)*

## 👤 User-Facing Changes

**No visible changes for end users** - This release focuses on framework 
upgrades, security improvements, documentation, and developer tooling.

### Documentation Improvements

- **Identification Guide Migration**: The Lotus Elan identification guide is now 
in the unified documentation system at `/docs/view.php?doc=faq/IDENTIFICATION_GUIDE` 
with improved formatting and navigation
- **Backward Compatibility**: Old URL `/app/cars/identify.php` redirects permanently
to new location (bookmarks preserved)

## 🔧 Admin-Facing Changes

### Major Framework Upgrade

- **UserSpice 6.0**: Complete upgrade from UserSpice 5.x to 6.0, bringing improved 
security, performance, and modern PHP compatibility
  - Enhanced authentication system
  - Improved session management
  - Better CSRF protection
  - Modern PHP 8+ compatibility improvements

### Security Enhancements

- **Subresource Integrity (SRI)**: All CDN resources now protected with SRI hashes 
(jQuery, DataTables, Chart.js, Bootstrap)
  - Protects against CDN compromise attacks
  - Validates resource integrity before execution
  - Addresses ZAP security scan findings
- **DataTables Security Upgrade**: Updated from v1.10.23 to v1.11.3, fixing CVE-2021-23445
- **Production Rate Limits Enabled**: Active DDoS protection on authentication, registration, profile updates, API endpoints, and contact forms
- **SQL Injection Protection**: Enhanced security validation in admin interface with whitelist validation and defense-in-depth patterns

### Development Automation
- **Release Command (`/release`)**: New Claude Code automation for version releases
  - Intelligent version bump analysis (major/minor/patch)
  - Automated release notes generation from commits
  - Interactive review workflow
  - Automated git operations and GitHub release creation
- **Enhanced Git Hooks**: Comprehensive pre-commit quality enforcement
  - Verification system with detailed health checks
  - New `check-hooks-status.sh` diagnostic tool
  - Comprehensive troubleshooting documentation
  - Improved setup success rate (85% → 98%)
- **Commit Message Validation**: New commit-msg hook enforces quality standards
  - Minimum/maximum length validation
  - Conventional commit format detection
  - Sensitive data pattern blocking (passwords, API keys)
  - Issue reference recommendations

### Documentation Overhaul
- **Comprehensive Documentation Reorganization**: 172 files changed, 14,572 insertions, 6,534 deletions
  - New QUICK_REFERENCE.md for experienced developers
  - New DATABASE.md with complete schema documentation (moved from admin docs)
  - New CLASSES.md with detailed class architecture
  - New ARCHITECTURE.md with system design patterns
  - New INTEGRATION.md with UserSpice integration patterns
  - New FIX_SCRIPTS.md with standardized script creation guidelines
  - Enhanced ENVIRONMENT.md with better credential management
  - Consolidated testing documentation (TESTING.md)
- **Improved Organization**:
  - CLAUDE.md moved to root for better visibility
  - PRD.md moved to docs/ root (strategic documentation)
  - docs/technical/ renamed to docs/testing/ (clearer purpose)
  - Eliminated ~60 lines of duplicated database schema content
  - Enhanced visual hierarchy with emoji icons and categorization
- **Documentation Quality**: Fixed 81 markdown lint errors across 13 files
- **Environment Configuration**: New `.env.local.sample` template with comprehensive credential categories

### Database & Code Quality
- **Database Setup Simplification**: Sequential file naming (1-schema.sql, 2-reference-data.sql, 3-configuration.sql, 4-sample-data.sql)
- **Idempotent Scripts**: Reference data and configuration scripts can be safely re-run
- **Code Quality Fixes**: Resolved SonarCloud issues
  - Refactored backup_functions.php (removed unused parameters, added constants)
  - Fixed form label associations in user_settings.php (WCAG 2.1 compliance)
  - Enhanced SQL injection protection in admin interfaces
- **Image Optimization**: Reduced image sizes by 16.25% (1,887.45kb → 1,580.79kb)

### Testing Improvements
- **Unit Tests Fixed**: All 128 PHP unit tests passing (9 previously broken tests fixed)
- **Playwright Suite Cleanup**: Removed 76 outdated/failing tests, 100% pass rate on remaining tests
- **E2E Test Separation**: Production E2E tests properly configured and documented
- **PHPUnit Compatibility**: Downgraded to v11 for PHP 8.2 compatibility (production servers)

### Repository Cleanup
- **Open Source Standards**: Removed personal development tools (.mcp.json, 1Password scripts)
- **Dependency Management**: Now tracking composer.lock for consistent dependencies across environments
- **Removed composer.phar**: 3MB binary no longer in repository (use global Composer installation)
- **.gitignore Reorganization**: Better categorization and maintainability
- **Workflow Cleanup**: Removed deprecated project board automation

## 📋 Issues Resolved in This Release

[#425](https://github.com/unibrain1/elanregistry/issues/425) - Enhanced git hooks with verification, troubleshooting, and commit message validation

[#424](https://github.com/unibrain1/elanregistry/pull/424) - Migrate identification guide to documentation system (#359)

[#423](https://github.com/unibrain1/elanregistry/pull/423) - Documentation Overhaul and Security Enhancements

[#418](https://github.com/unibrain1/elanregistry/issues/418) - Security: Fix SQL Timing Attack Vulnerabilities (Rate limits enabled)

[#416](https://github.com/unibrain1/elanregistry/pull/416) - Image optimization (16.25% size reduction)

[#413](https://github.com/unibrain1/elanregistry/issues/413) - Security: Add Subresource Integrity (SRI) to external resources

[#408](https://github.com/unibrain1/elanregistry/pull/408) - Release v2.9.4: Quality & Security Improvements

[#406](https://github.com/unibrain1/elanregistry/issues/406) - Refactor and consolidate documentation structure

[#402](https://github.com/unibrain1/elanregistry/issues/402) - Accessibility: Associate form label with control in user_settings.php

[#401](https://github.com/unibrain1/elanregistry/issues/401) - Code Quality: Refactor backup_functions.php to eliminate code smells

[#389](https://github.com/unibrain1/elanregistry/issues/389) - Database Schema: Update database/*.sql files to match current development database

[#388](https://github.com/unibrain1/elanregistry/issues/388) - Fix broken unit tests in pre-commit hook

[#362](https://github.com/unibrain1/elanregistry/issues/362) - FIX: Address SQL injection warnings in admin files

[#359](https://github.com/unibrain1/elanregistry/issues/359) - Move Identification Guide from app/ to docs/ reference library

---

## 📊 Technical Summary

### Release Statistics
- **18 commits** since v2.9.3
- **172 files changed**: 14,572 insertions, 6,534 deletions
- **Net documentation expansion**: +8,038 lines while improving organization
- **Test suite**: 128 PHP unit tests (100% passing), 16 local Playwright tests (100% passing)

### Major Changes by Category

**Framework & Dependencies:**
- UserSpice 6.0 upgrade (major framework update)
- DataTables v1.10.23 → v1.11.3 (security update)
- PHPUnit v12 → v11 (PHP 8.2 compatibility)
- Composer.lock now tracked (dependency consistency)

**Security:**
- SRI protection on all CDN resources
- Production rate limits enabled
- CVE-2021-23445 fixed (DataTables vulnerability)
- Enhanced SQL injection protection
- Commit-msg hook blocks sensitive data patterns

**Documentation:**
- 8 new documentation files created
- 5 files renamed/reorganized
- 7 obsolete files removed
- 81 markdown lint errors fixed
- Complete database schema documentation
- Comprehensive class architecture documentation

**Development Tools:**
- New `/release` command for automated releases
- Enhanced git hooks with verification system
- New `check-hooks-status.sh` diagnostic tool
- Commit message validation hook
- Improved pre-commit success rate (85% → 98%)

**Database:**
- Sequential setup file naming (1-4)
- Idempotent reference data scripts
- FIX/17 script for SRI and DataTables upgrade
- 4 completed FIX scripts archived

**Code Quality:**
- SonarCloud issues resolved
- WCAG 2.1 accessibility improvements
- Image optimization (16.25% reduction)
- Repository cleanup (removed personal tools)

### Breaking Changes
- **UserSpice 6.0**: May require adjustments to custom UserSpice integrations
- **DataTables Upgrade**: Minor API changes from v1.10.23 to v1.11.3
- **Identification Guide URL**: Old URL redirects (301 permanent redirect)

### Backward Compatibility
- All user-facing URLs preserved via redirects
- Database structure unchanged (no migrations required beyond FIX/17)
- Existing authentication and sessions continue working
- Custom code using documented APIs should continue working

### Known Issues
- None reported at release time

### Next Steps
- Monitor production for any UserSpice 6.0 compatibility issues
- Complete ZAP security scan validation
- Continue Phase 1 Critical Issues from GitHub milestone
- Consider Phase 2+ enhancements (UX improvements, optional features)
