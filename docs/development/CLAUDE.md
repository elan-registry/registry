# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 📋 Required Reading for All Sessions

**CRITICAL:** Read these files at the start of every Claude Code session:

- `docs/development/CLAUDE.md` (this file) - Essential development guidance
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/DEVELOPMENT_WORKFLOW.md` - Detailed development processes
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

## 🏗️ Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at <https://elanregistry.org>. It's built on top of UserSpice (userspice.com) for user authentication and management, with custom car registry functionality.

### Core Application Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/userimages/` - User-uploaded car images organized by car ID
- `/docs/` - Documentation organized by category (elanregistry/, development/, technical/)
- `/tests/` - PHPUnit and Playwright test files

### UserSpice Management Requirements

**CRITICAL:** When working with UserSpice-managed pages:

1. **New Directories with PHP Files**: When adding new folders containing PHP files, update the `$path` array in `/z_us_root.php` to include the new directory path. This ensures proper path resolution and security monitoring.

   ```php
   // Example: Adding 'app/reports/api/' directory
   $path = ['', 'users/', 'usersc/', 'app/', 'app/reports/', 'app/reports/api/', ...];
   ```

2. **securePage() Authentication**: Pages that use `securePage($_SERVER['PHP_SELF'])` are managed by UserSpice's permission system. When creating new pages with `securePage()`:
   - The page must be manually added to UserSpice's page management system
   - Set appropriate permissions through UserSpice admin interface
   - Without proper page registration, `securePage()` will redirect to login/unauthorized pages

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
- `app/reports/statistics.php` - Registry analytics & statistics with Chart.js (tabbed interface)
- `app/contact/send-owner-email.php` - Owner contact functionality

## ⚙️ Development Setup

### System Requirements

- PHP 8.1+ required (8.2+ recommended for full PHPUnit 12 compatibility)
- MySQL 8.0+
- Uses `johnathanmiller/secure-env-php` for encrypted environment variable handling

### Quick Start Commands

```bash
# Install PHP dependencies
composer install

# Install Node dependencies (for testing)
npm install

# PHP test commands (core infrastructure)
composer test:quick        # Unit tests only (<30s)
composer test:medium       # Unit + Integration (<2min)
composer test:full         # All PHP tests
composer test:coverage     # Generate coverage report

# UI testing (requires setup)
npm test                   # Shows setup requirements
npm run playwright:install # Install Playwright browsers
npm run playwright:test    # Run UI tests (after setup)
```

### Testing

```bash
# PHP test suites (working)
composer test:unit         # Fast unit tests
composer test:integration  # Database integration tests
composer test:regression   # Issue-specific regression tests

# UI test suites (requires setup)
npm run playwright:security      # Security-focused tests
npm run playwright:ui           # UI consistency tests
npm run playwright:navigation   # Navigation and redirects
npm run playwright:functionality # Core functionality
npm run playwright:maps         # Maps and charts
npm run playwright:csp          # CSP validation tests
```

## 🔧 Essential Development Guidelines

### PHP 8+ Requirements

- **PHP 8+ Type Declarations**: All functions must have complete parameter and return type hints
- **Strict Typing**: New files must include `declare(strict_types=1)`
- **Custom Exceptions**: Use typed exception classes for proper error handling
- **Security First**: Follow secure coding practices outlined in coding standards
- **Documentation**: Complete PHPDoc blocks required for all public methods

### Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Input validation and sanitization required for all user inputs
- Password hashing uses bcrypt
- Secure session handling implemented
- **CRITICAL**: Never commit credentials, API keys, or sensitive data to git
- Use environment variables for all sensitive configuration

### Error Logging Standards

**All error conditions MUST use UserSpice logger integration for centralized error visibility and audit trails.**

#### Required Error Categories

- `SystemError` - File operations, environment issues, general system failures
- `ValidationError` - Input validation failures, invalid data, malformed requests
- `FileError` - Upload/processing failures, image operations, file system issues
- `DatabaseError` - Database operation failures, query errors, connection issues
- `CarErrors` - Car-related error conditions
- `CarActions` - Car-related user operations
- `DatabaseMaintenance` - All database maintenance operations

#### Error Logging Pattern

```php
// REQUIRED: Replace error_log() calls with UserSpice logger
try {
    // Operation that might fail
    $result = riskyOperation();
} catch (Exception $e) {
    logger($user->data()->id ?? 0, 'ErrorCategory', 'Descriptive error message: ' . $e->getMessage());
    throw new SpecificException('User-friendly message');
}

// For validation errors
if (empty($requiredField)) {
    logger($user->data()->id ?? 0, 'ValidationError', 'Required field missing: fieldName');
    throw new ValidationException('Field is required');
}
```

### Message Handling Standards

**All error and success messages MUST use the modern UserSpice session-based messaging system for consistent UX.**

```php
// Set error messages (instead of deprecated display_errors())
if (!empty($errors)) {
    foreach ($errors as $error) {
        usError($error);
    }
}

// Set success messages (instead of deprecated display_successes())  
if (!empty($successes)) {
    foreach ($successes as $success) {
        usSuccess($success);
    }
}

// Display all messages (replaces manual Bootstrap alert HTML)
sessionValMessages($errors, $successes, null);
```

### Code Quality Requirements

**ALWAYS run the following commands before completing any task:**

- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

This is a CRITICAL step that must NEVER be skipped when working on any code-related task.

## 🚀 Quick Deployment Reference

**🚨 CRITICAL:** When deploying to production, always use the `prod` remote, NOT `origin`!

```bash
# Push code to PRODUCTION SERVER (live site)
git push prod main

# Push to GitHub for repository backup
git push origin main && git push origin --tags
```

**📋 See [DEPLOYMENT.md](DEPLOYMENT.md) for complete deployment procedures**

## 📊 Current Development Status

### ✅ Production Ready Features

- **Security**: Enterprise-grade security implementation with comprehensive CSRF protection
- **Testing**: 35/35 Playwright browser tests passing (100% success rate) plus comprehensive PHPUnit security test suite
- **PHP 8+ Compatibility**: Full compatibility with modern PHP versions, comprehensive null handling
- **Documentation**: Complete setup, development, and deployment documentation

### 📋 Active Development Areas

Current GitHub Issues are organized into development phases:

- **Phase 1 Critical Issues** - Bug fixes and stability improvements
- **Phase 2-5** - Core enhancements, UX improvements, and optional features

See GitHub Issues for detailed development roadmap and current work items.

## 📊 Recent Major Changes

### Chart.js Migration (Issue #285) - v2.8.1

**Completed Migration from Google Charts to Chart.js:**
- **Statistics Page Enhanced**: Converted to tabbed interface with lazy loading
  - Overview, Geographic, Production, Colors, Data Quality tabs
  - 11+ interactive charts with Bootstrap theming
  - Performance optimized with caching (1 day prod, 5 minutes dev)
- **Analytics Page Consolidated**: All analytics features moved to statistics page
- **Security Improved**: Removed Google Charts CSP dependencies
- **Self-Hosted Solution**: Chart.js CDN configurable via Admin Panel

**Key Features:**
- Responsive Bootstrap-themed charts
- Lazy loading for performance
- Environment-based caching system
- API endpoints for dynamic data loading
- Comprehensive analytics dashboard

---

**📖 For detailed information, see the complete documentation files:**

- [DEVELOPMENT_WORKFLOW.md](DEVELOPMENT_WORKFLOW.md) - Detailed development processes
- [DEPLOYMENT.md](DEPLOYMENT.md) - Production deployment procedures
- [CODING_STANDARDS.md](CODING_STANDARDS.md) - Comprehensive coding standards
- [ENVIRONMENT.md](ENVIRONMENT.md) - Environment setup and configuration
