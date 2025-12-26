# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Quick Summary

This is a PHP web application for the Lotus Elan Registry (<https://elanregistry.org>) built on UserSpice with custom car registry functionality.

**Key Commands:**

```bash
# Development Setup
composer install                    # Install PHP dependencies
npm install                         # Install Node dependencies
./scripts/setup-git-hooks.sh        # Setup pre-commit checks (RECOMMENDED)

# Testing
composer test:quick                 # Fast unit tests (<30s)
vendor/bin/phpunit tests/           # All PHP tests
npm test                            # All UI tests (requires setup)

# Deployment
git push test main                  # Deploy to test environment
git push prod main                  # Deploy to production
```

## 📋 Required Reading for All Sessions

**CRITICAL:** Read these files at the start of every Claude Code session:

### Essential Development Documentation

1. **[ARCHITECTURE.md](docs/development/ARCHITECTURE.md)** - System architecture, database, class patterns
2. **[INTEGRATION.md](docs/development/INTEGRATION.md)** - UserSpice integration and custom functions
3. **[QUICK_START.md](docs/development/QUICK_START.md)** - Setup, testing, and essential commands
4. **[STANDARDS.md](docs/development/STANDARDS.md)** - Project-specific coding standards
5. **[CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md)** - Comprehensive code quality requirements
6. **[DEVELOPMENT_WORKFLOW.md](docs/development/DEVELOPMENT_WORKFLOW.md)** - Detailed development processes
7. **[DEPLOYMENT.md](docs/development/DEPLOYMENT.md)** - Production deployment procedures
8. **[ENVIRONMENT.md](docs/development/ENVIRONMENT.md)** - Environment setup and configuration

### Specialized Topics

- **[FIX_SCRIPTS.md](docs/development/FIX_SCRIPTS.md)** - Database maintenance script guidelines
- **[TESTING.md](docs/technical/TESTING.md)** - Testing strategy and test execution
- **[BACKUP_SYSTEM.md](docs/technical/BACKUP_SYSTEM.md)** - BackupManager class documentation

## 🏗️ Quick Architecture Reference

### Core Structure

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes and utilities
- `/docs/` - Documentation (faq/, faq/admin/, development/, technical/)
- `/tests/` - PHPUnit and Playwright test files

### Key Technologies

- **Backend**: PHP 8.1+ with UserSpice framework
- **Database**: MySQL 8.0+ with comprehensive audit trails
- **Frontend**: Bootstrap 4/5 with responsive design
- **APIs**: Google Maps JavaScript API, Google Geocoding API
- **Testing**: PHPUnit 12, Playwright

## 🔧 Essential Development Guidelines

### Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for SQL queries
- Never commit credentials, API keys, or sensitive data
- Use environment variables for all sensitive configuration

### Code Quality Requirements

**ALWAYS before completing any task:**

1. Run `mcp__ide__getDiagnostics` to check for linting/type errors
2. Fix any diagnostics before considering task complete
3. Run appropriate test suites for modified functionality

### Error Logging Standards

**Use UserSpice logger for all error conditions:**

```php
logger(
    $user->data()->id ?? 0,
    'ErrorCategory',  // SystemError, ValidationError, DatabaseError, etc.
    'Descriptive error message'
);
```

### Message Handling Standards

**Use modern UserSpice session-based messaging:**

```php
// Set messages
usError('Error message');
usSuccess('Success message');

// Display all messages
sessionValMessages($errors, $successes, null);
```

### Terminology Standards

- **Users**: Authentication/session management (UserSpice framework context)
- **Owners**: Car registry business domain (ElanRegistry context)
- Use "users" for database tables and auth logic
- Use "owners" for UI elements and business logic

## 🚀 Quick Deployment Reference

**🚨 CRITICAL:** Use the correct remote for each environment!

```bash
# Push to GitHub for repository backup
git push origin main && git push origin --tags

# Deploy to test server for validation
git push test feature/branch-name
git push test v2.9.1

# Push to PRODUCTION SERVER (live site)
git push prod main
git push prod --tags
```

**For complete deployment procedures, see [DEPLOYMENT.md](docs/development/DEPLOYMENT.md)**

## 📊 Documentation Structure

### User/Owner Documentation (`docs/faq/`)

- **Public Access** - No authentication required
- Car transfer guides, privacy policy, add car guide
- Access via: `/docs/faq/index.php`

### Admin Documentation (`docs/faq/admin/`)

- **Restricted Access** - Administrator/Editor permissions required
- Admin guides, database schema, troubleshooting, PRD
- Access via: `/docs/faq/admin/index.php`

### Developer Documentation (`docs/development/`)

- **Public/Version Controlled** - For developers and maintainers
- Architecture, integration, coding standards, deployment
- This is where you'll find most technical documentation

### Technical Documentation (`docs/technical/`)

- **Public/Version Controlled** - For QA and technical leads
- Testing strategy, Playwright guides, backup system

### Release Documentation (`docs/releases/`)

- **Public** - For project managers and stakeholders
- Version-specific release notes and change logs

## 📊 Production Status

### Production Ready Features

- **Security**: Enterprise-grade security with comprehensive CSRF protection
- **Testing**: 35/35 Playwright browser tests passing (100% success rate)
- **PHP 8+ Compatibility**: Full compatibility with modern PHP versions
- **Documentation**: Complete setup, development, and deployment docs

See GitHub Issues for detailed development roadmap.

## 💡 Quick Tips

### Working with UserSpice

- Add new PHP directories to `$path` array in `/z_us_root.php`
- Pages using `securePage()` must be registered in UserSpice admin
- Use existing `getUserWithProfile($userId)` for combined user+profile data

### Working with Database

- Use `DB::getInstance()` singleton pattern
- All car operations have audit trails via `cars_hist` table
- Use `logger()` for all database maintenance operations

### Creating FIX Scripts

- Start with `/FIX/_TEMPLATE_Fix-Script.php`
- Use sequential naming: `##-Descriptive-Name.php`
- Always use transactions and proper error handling
- See [FIX_SCRIPTS.md](docs/development/FIX_SCRIPTS.md) for complete guidelines

### Testing Strategy

- **Unit tests**: Fast, isolated component testing
- **Integration tests**: Database and component integration
- **Regression tests**: Issue-specific bug prevention
- **Playwright tests**: UI, navigation, security, functionality

## 📖 Additional Resources

- **Project Overview**: See [README.md](README.md)
- **Installation Guide**: See [INSTALLATION.md](docs/development/INSTALLATION.md)
- **Documentation Index**: See [docs/README.md](docs/README.md)
- **Release Notes**: See [docs/releases/](docs/releases/)

---

**For detailed information on any topic, refer to the complete documentation files linked above.**
