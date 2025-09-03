# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 📖 Table of Contents

- [🏗️ Architecture Overview](#️-architecture-overview)
- [⚙️ Development Setup](#️-development-setup)
- [🔧 Development Guidelines](#-development-guidelines)
- [🚀 Deployment & Production](#-deployment--production)
- [🛡️ Security & CSP Management](#️-security--csp-management)
- [📊 Current Development Status](#-current-development-status)

---

## 🏗️ Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at https://elanregistry.org. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

### Core Application Structure
- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system 
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (elanregistry/, development/, technical/)
- `/tests/` - PHPUnit and Playwright test files

### Database Architecture
- MySQL database with comprehensive car registry schema
- `cars` table for vehicle records with full audit trail via `cars_hist`
- `car_user` junction table for car sharing between users
- Views: `usersview`, `users_carsview` for complex queries
- Database triggers automatically maintain audit trails

### Key Application Files
- `app/cars/index.php` - Searchable car listing with DataTables
- `app/cars/details.php` - Individual car detail pages
- `app/cars/edit.php` - Car editing forms
- `app/reports/statistics.php` - Registry statistics with Google Charts
- `app/contact/send-owner-email.php` - Owner contact functionality

---

## ⚙️ Development Setup

### System Requirements
- PHP 7.4+ required
- MySQL 8.0+ 
- Uses `johnathanmiller/secure-env-php` for encrypted environment variable handling

### Quick Start Commands
```bash
# Install PHP dependencies
composer install

# Install Node dependencies (for testing)
npm install

# Run PHPUnit tests
vendor/bin/phpunit tests/

# Run Playwright browser tests (requires test credentials)
npm test
```

### Database Access
- **Configuration**: Use credentials from `.env.local` file (see DEV_DB_* variables)
- **Connection**: MAMP MySQL server on port 8889
- **MAMP MySQL Path**: `/Applications/MAMP/Library/bin/mysql`
- **Direct Command**: `/Applications/MAMP/Library/bin/mysql -h localhost -P 8889 -u claude -p"claude" elanregi_spice`

### Testing
```bash
# Run specific test suites
npm run test:security      # Security-focused tests
npm run test:ui           # UI consistency tests
npm run test:navigation   # Navigation and redirects
npm run test:functionality # Core functionality
npm run test:maps         # Maps and charts
npm run test:csp          # CSP validation tests
```

#### Test Environment Setup
For Playwright browser tests that require authentication:
1. Copy `.env.local.sample` to `.env.local`
2. Set `TEST_USERNAME` and `TEST_PASSWORD` with valid test account credentials
3. Ensure `.env.local` is never committed to git (it's in `.gitignore`)

### Environment Variables
See comprehensive documentation in `docs/development/ENVIRONMENT.md`:
- **Database credentials** (`DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`)
- **Google API keys** - **Production**: Stored in database settings table; **Testing only**: Environment variables (`MAPS_KEY`, `GEO_ENCODE_KEY`)
- All variables encrypted at rest using SecureEnvPHP

### UserSpice Plugins
**Active Plugins:**
- `Auto Assign Usernames` - Hides username field and auto-assigns usernames on registration
- `getSettings Function` - Provides global settings access via getSettings() function
- `hooker` - Custom hooks system for code injection points
- `reCAPTCHA` - Google reCAPTCHA v2/v3 integration for spam protection
- `Brevo Sendinblue` - API-based email delivery replacing phpmailer (300 emails/day free)

**Inactive Plugins:**
- `CMS and Blog Plugin` - Content management system and blog platform (currently inactive)

---

## 🔧 Development Guidelines

### Security Requirements
- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Input validation and sanitization required for all user inputs
- Password hashing uses bcrypt
- Secure session handling implemented
- **CRITICAL**: Never commit credentials, API keys, or sensitive data to git
- Use environment variables for all sensitive configuration
- Test credentials must be in `.env.local` (git-ignored) or environment variables

### File Organization
- Car-related logic in `/app/cars/`
- Contact forms and email handling in `/app/contact/`
- Statistics and reporting in `/app/reports/`
- Authentication handled by UserSpice in `/users/`
- Custom UserSpice modifications in `/usersc/`
- User uploads organized by car ID in `/userimages/`
- **Database fixes**: All database fix scripts must be placed in `/FIX/` directory using the established PHP format with progress reporting and error handling

### Data Flow: User Registration to Car Management

The system maintains location synchronization between user profiles and car records through the following flow:

#### 1. New User Registration (`usersc/scripts/during_user_creation.php`)
- User provides location information (city, state, country) during registration
- Location is automatically geocoded using Google Maps API via `app/views/_geolocate.php`  
- Coordinates are stored in the `profiles` table linked to the user account

#### 2. Car Creation (`app/cars/actions/edit.php`)
- When a user creates a new car record, owner profile data is copied to the car
- Location fields copied: `city`, `state`, `country`, `lat`, `lon` (lines 167-171)
- This ensures the car initially has the same location as its owner

#### 3. Car Record History (`usersc/classes/Car.php`)
- Any changes to car records trigger automatic history tracking
- Changes are recorded in the `cars_hist` table with timestamps and operation types
- History preserves audit trail of all car modifications

#### 4. Owner Location Updates (`usersc/user_settings.php`) **[FIXED in #193]**
- When an owner changes their location in user settings:
  - Profile location is updated and re-geocoded (lines 235-245)
  - **NEW**: All cars owned by the user are automatically synchronized (lines 244-277)
  - Car records are updated with new location coordinates  
  - History entries are created with `LOCATION_SYNC` operation type
  - Users receive confirmation of how many cars were synchronized

### PHP 8+ Compatibility & Code Quality

#### Car Class Modernization Roadmap
**GitHub Issues tracking comprehensive improvements:**

**Phase 1: Foundation & Security (Release 1)**
1. **Issue #239** ✅ **COMPLETED** - Type declarations and input validation
   - ✅ Add missing return type declarations to all methods
   - ✅ Implement comprehensive input validation for create/update operations
   - ✅ Extract magic numbers to named constants
   - ✅ Add proper error handling for image processing
   - ✅ Lotus Elan year range validation (1963-1974)

**Phase 2: Database Consistency & Security Fixes (Release 2)**
2. **Issue #158** (Medium Priority) - Standardize column names across car-related tables
   - Create database migration to rename `carid` → `car_id` in car_user and car_user_hist tables
   - Update Car class methods to use consistent `car_id` format
   - Update all affected queries for consistency
   - Ensure clean, consistent schema for Car class operations

3. **Issue #238** (Low Priority) - Remove deprecated username field from cars table
   - Remove `username` from Car class `allowedColumns` array
   - Create database migration to drop deprecated `username` column
   - Verify all relationships use proper `car_user` table patterns
   - Update schema documentation

4. **Issue #247** (High Priority) - Fix removeImage() direct database access  
   - Replace direct DB calls with Car class methods
   - Add removeImage() method to Car class
   - Implement proper JSON image format handling
   - Add comprehensive error handling and validation

5. **Issue #248** (Critical Priority) - Replace direct DB access in car management
   - Add Car class methods: delete(), transfer(), merge()
   - Replace all direct database operations in manage.php
   - Implement proper audit trails for admin operations
   - Add comprehensive input validation for management operations

6. **Issue #249** (High Priority) - Fix car verification system bypasses
   - Add Car class methods: setVerificationCode(), markVerified(), markSold()
   - Add static findByVerificationCode() method
   - Replace direct database access in verification scripts
   - Implement proper verification audit trails

**Phase 3: Performance & Architecture (Release 3)**
7. **Issues #240 & #241** (Medium Priority) - Performance and advanced security
   - Database query optimization (single query vs. multiple queries)
   - Implement lazy loading for images and factory data
   - Custom exception classes for better error handling
   - File path security improvements

8. **Issue #242** (Low Priority - Future) - Architecture refactoring
   - Split Car class into focused responsibilities
   - Remove global variable dependencies  
   - Modern PHP features (enums, readonly properties)
   - Comprehensive testing infrastructure

**Dependencies:**
- **Phase 2** requires **Phase 1** completion ✅
- **Phase 3** requires **Phase 2** completion
- All issues build upon Issue #239 foundation

#### Recommended PHP Practices
- **Always validate inputs**: Check for null/empty before string operations
- **Use type declarations**: Add return types to all methods for better debugging
- **Handle edge cases**: Graceful degradation when data is missing
- **Log errors appropriately**: Use proper error handling instead of silent failures

### Templates & Styling
- Uses Bootstrap 4/5 for responsive layout
- Custom CSS in `usersc/templates/ElanRegistry/assets/css/`
- Custom branding assets in `usersc/templates/ElanRegistry/assets/images/`
  - Lotus-logo-3000x3000.png (main logo)
  - logo-72x72.png (small logo)  
  - favicon.ico (browser tab icon)
- Template system via UserSpice with custom overrides
- Card-based layout for consistent UI

### Administrative Tools

#### SPAM and Inactive User Cleanup System
**Issue #232** - Comprehensive automated cleanup system for maintaining database quality:

**Features:**
- **Automated SPAM Detection**: Identifies legacy data anomalies (1969 dates) and suspicious registration patterns
- **Inactive User Management**: Grace period notifications and cleanup for users with no cars after 30+ days
- **Safety Mechanisms**: Multiple percentage limits, maximum deletion counts, and dry-run testing
- **Email Integration**: Grace period notifications via UserSpice email system (supports Mailtrap.io for dev testing)
- **Comprehensive Logging**: All actions tracked via UserSpice logging system with searchable categories

#### FIX Directory Scripts
The `/FIX/` directory contains administrative cleanup scripts with the following features:
- **Run Status Tracking**: Scripts automatically record completion in the `fix_script_runs` table
- **Status Indicators**: Index page shows ✅ for completed scripts, ➖ for unrun scripts
- **Last Run Times**: Displays when each script was last executed
- **Outline Buttons**: Red outline buttons for safe script execution access
- **Progress Reporting**: All scripts use consistent progress messaging with timestamps

##### Creating New FIX Scripts
**IMPORTANT**: Always use the template when creating new FIX scripts:

1. **Copy Template**: Start with `/FIX/_TEMPLATE_Fix-Script.php`
2. **Replace Placeholders**: Update all bracketed placeholders with appropriate values
3. **Standard Features Included**: Two-column layout, progress tracking, error handling, authentication

### Code Quality Requirements

**ALWAYS run the following commands before completing any task:**

- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

This is a CRITICAL step that must NEVER be skipped when working on any code-related task.

---

## 🚀 Deployment & Production

### Production Environment
- **Hosting**: A2 Hosting with git deployment hooks
- **Remote**: `prod` remote configured for direct deployment to production server
- **Auto-deployment**: Master branch deploys automatically when pushed to prod remote
- **Version Display**: Uses VERSION file modification time for deployment timestamp

### 🚨 CRITICAL: Production Deployment Commands

**⚠️ IMPORTANT:** When someone says "push to prod", always use the `prod` remote, NOT `origin`!

**Live Production Server:**
```bash
# Push code to PRODUCTION SERVER (live site)
git push prod main

# Push version tags to PRODUCTION SERVER  
git push prod --tags
```

**GitHub Repository (backup/development):**
```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags
```

### Remote Configuration Reference
```bash
origin	git@github.com:unibrain1/elanregistry.git    # GitHub repository
prod	a2hosting:/home/unibrain/elanregistry.project  # LIVE PRODUCTION SERVER
```

**🔄 Deployment Rule:** 
- `origin` = GitHub (development/backup)
- `prod` = **LIVE WEBSITE** (elanregistry.org)

### Complete Production Deployment Process
1. **Update VERSION file and create matching git tag** (tag must exactly match VERSION content)
2. **Commit changes** with version bump and tag
3. **Push to GitHub** for repository backup: `git push origin main && git push origin --tags`
4. **🎯 DEPLOY TO PRODUCTION** (the important step): `git push prod main && git push prod --tags`
5. **Verify deployment** by checking version display matches git tag on production site
6. **Complete post-deployment verification** (see checklist below)

### Git & Version Control

#### Branch Management Strategy
- `main` branch always contains production-ready code
- All development work happens on feature/phase branches
- Direct commits to main are discouraged

#### Branch Naming Convention
- Feature branches: `feature/issue-{number}-brief-description`
- Phase branches: `phase-{number}-{name}`
- Hotfix branches: `hotfix/issue-{number}-brief-description`

#### Version Management & Automated Release Process

**Version File Structure:**
- Version information stored in `/VERSION` file in project root
- `ApplicationVersion::get()` reads from this file (no git dependencies)
- Production deployment timestamp shows file modification time
- Format: `vX.Y.Z` (semantic versioning, e.g., `v2.3.4`)

**Automated Version Enforcement:**
- **Git Pre-Commit Hook**: Automatically enforces version updates on main branch
- **Location**: `.git/hooks/pre-commit` (installed automatically)
- **Rules**: VERSION file must be updated when committing code changes to main

**Version Bump Helper Script:**
- **Location**: `scripts/bump-version.sh`
- **Usage**: `./scripts/bump-version.sh [patch|minor|major] [--tag] [--dry-run]`
- **Features**: Automatic semantic version incrementing, optional git tag creation

### Post-Deployment Configuration Requirements

**CRITICAL:** After deploying code changes to production, always verify and update:

#### Google Maps API Configuration
- **Problem:** File reorganization affects API referrer restrictions
- **Solution:** Update Google Cloud Console API restrictions to include new file paths
- **Check:** Verify maps display correctly on statistics and detail pages

#### UserSpice Page Permissions
- **Problem:** New pages and redirects need proper access permissions configured
- **Solution:** Update page permissions in UserSpice admin panel
- **Required for:** Both redirect pages AND new destination pages

#### Deployment Verification Checklist
- [ ] Google Maps display correctly on all pages
- [ ] All redirected pages work and maintain proper permissions
- [ ] New pages have appropriate UserSpice permission levels
- [ ] Contact forms send to correct email addresses
- [ ] Version information displays correctly in footer
- [ ] Test critical user workflows (car registration, editing, contact forms)

---

## 🛡️ Security & CSP Management

The application implements a comprehensive Content Security Policy to prevent XSS attacks and unauthorized resource loading while supporting all required external services.

### CSP Configuration Location
**File:** `usersc/includes/security_headers.php`

### Supported External Services
- **Google Services**: Maps, Charts, reCAPTCHA, Tag Manager (Google Analytics removed in v2.6.12)
- **Cloudflare**: Analytics with wildcard pattern support for versioned scripts
- **CDN Resources**: JSDelivr, Cloudflare CDN, Bootstrap CDN, jQuery, DataTables
- **Font Services**: Google Fonts, FontAwesome (including kit support)

### Avatar/Profile Picture Management
**Important:** Gravatar functionality has been disabled to maintain CSP compliance and improve privacy:
- **Issue**: UserSpice core attempts to load profile pictures from `www.gravatar.com`
- **CSP Conflict**: Gravatar domain is not in the allowed `img-src` directive
- **Solution**: Custom JavaScript in `/usersc/plugins/hooker/hooks/account_body_hook.php` prevents avatar loading
- **Privacy Benefit**: No external requests to Gravatar service protect user privacy

### CSP Validation & Testing

#### Automated Testing Tools
1. **Playwright CSP Tests**: `tests/playwright/csp-validation.spec.js`
   - Browser-based CSP violation detection
   - Tests critical pages: statistics, car details, listing, login
   - Validates external resource loading (Google Charts, Cloudflare Analytics)

2. **Static Policy Validator**: `tests/validate-csp-policy.php`
   - Command-line tool: `php tests/validate-csp-policy.php`
   - Validates all required domains are present
   - Checks security best practices

#### Running CSP Tests
```bash
# Static policy validation
php tests/validate-csp-policy.php

# Browser-based violation testing
npm run test:csp

# Full security test suite (includes CSP tests)
npm run test:security
```

### CSP Troubleshooting

#### Common Issues
1. **Google Charts CSS blocked**: Ensure `www.gstatic.com/charts/*` in style-src
2. **Cloudflare Analytics blocked**: Verify `static.cloudflareinsights.com/*` in script-src  
3. **FontAwesome issues**: Check kit.fontawesome.com domains in script-src/style-src
4. **Maps not loading**: Validate maps.googleapis.com in all relevant directives

#### Adding New External Resources
1. Add domains to appropriate CSP directive in `security_headers.php`
2. Update required domains list in `tests/validate-csp-policy.php`
3. Run validation tests to ensure no regressions
4. Test on actual pages with browser console monitoring

---

## 📊 Current Development Status

### ✅ Production Ready Features
- **Security**: Enterprise-grade security implementation with comprehensive CSRF protection, prepared statements, and secure session handling
- **Testing**: 35/35 Playwright browser tests passing (100% success rate) plus comprehensive PHPUnit security test suite
- **Organization**: Complete file reorganization by function with backward-compatible redirects
- **CSP Management**: Comprehensive Content Security Policy with automated validation tools
- **Documentation**: Complete setup, development, and deployment documentation
- **PHP 8+ Compatibility**: Full compatibility with modern PHP versions, comprehensive null handling
- **Accessibility**: WCAG 2.1 compliant identification guide with semantic HTML and screen reader support
- **Mobile Responsiveness**: Optimized layouts and lazy loading for all screen sizes
- **Privacy Enhancement**: Complete Google Analytics removal (v2.6.12) for improved privacy and performance

### 📋 Active Development Areas
Current GitHub Issues are organized into development phases:
- **Phase 1 Critical Issues** - Bug fixes and stability improvements
- **Phase 2-5** - Core enhancements, UX improvements, and optional features

See GitHub Issues for detailed development roadmap and current work items.