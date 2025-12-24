# Elan Registry v2.9.3 Release Notes

**Release Date:** December 24, 2025
**Type:** Patch Release - Testing Infrastructure and Code Quality
Improvements

## 🚨 REQUIRED ACTIONS AFTER DEPLOYMENT

No manual actions required for this release. All changes are internal
code quality improvements and test fixes.

## 👤 User-Facing Changes

No visible changes for end users. This is an internal release focused on
testing infrastructure and code quality.

## 🔧 Admin-Facing Changes

### User Interface Improvements

- **Statistics Map Interaction**: Map pins now respond to hover instead
  of click for better user experience
- **Transfer Request Popups**: Updated to use Elan Registry-specific
  messaging instead of generic placeholders
- **Image Display**: Corrected image size references from 600px to 768px
  for consistency

## 📋 Issues Resolved in This Release

### Testing & Code Quality

[#394](https://github.com/unibrain1/elanregistry/issues/394) -
Testing: Enable skipped ElanRegistryOwner tests with proper test data

[#393](https://github.com/unibrain1/elanregistry/issues/393) -
Fix: Remove serialize() call from load-owner-profile.php

[#374](https://github.com/unibrain1/elanregistry/issues/374) -
Fix 9 failing unit tests in test suite

[#392](https://github.com/unibrain1/elanregistry/issues/392) -
[Bug]: Using error_log for system errors

### Database & Infrastructure

[#389](https://github.com/unibrain1/elanregistry/issues/389) -
Database Schema: Update database/*.sql files to match current development
database

[#385](https://github.com/unibrain1/elanregistry/issues/385) -
Database Schema: Document and standardize field size variations

[#384](https://github.com/unibrain1/elanregistry/issues/384) -
Database Schema: Resolve parts table incompatibility between dev and test

[#383](https://github.com/unibrain1/elanregistry/issues/383) -
Database Schema: Investigate and standardize storage engines
(MyISAM vs InnoDB)

[#364](https://github.com/unibrain1/elanregistry/issues/364) -
Admin Backup System does not work

[#349](https://github.com/unibrain1/elanregistry/issues/349) -
Optimize Database Queries in FIX Scripts and Admin Tools

### User Experience Enhancements

[#375](https://github.com/unibrain1/elanregistry/issues/375) -
Update hardcoded 600px image size references to 768px

[#373](https://github.com/unibrain1/elanregistry/issues/373) -
Transfer requests use default popups instead of site-specific popups

[#327](https://github.com/unibrain1/elanregistry/issues/327) -
Refactor user_settings.php to use getUserWithProfile() helper

[#188](https://github.com/unibrain1/elanregistry/issues/188) -
Website Field Validation on user_settings.php

[#159](https://github.com/unibrain1/elanregistry/issues/159) -
Add hover over on map pins on stats page

---

**Summary:** This patch release resolves 15 issues focused on testing
infrastructure, code quality, database schema consistency, and minor UX
improvements. All unit tests now pass successfully with improved test
coverage and reliability.
