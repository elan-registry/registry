# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when
working with code in this repository.

## Required Reading for All Sessions

**CRITICAL:** Read these files at the start of every Claude Code session:

- `CLAUDE.md` (this file) - Essential development guidance
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/DEVELOPMENT_WORKFLOW.md` - Detailed development processes
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

## Recommended Reading Path

**Essential Context:**

1. `CLAUDE.md` (this file) - Overview and quick reference
2. `docs/development/QUICK_REFERENCE.md` - Common tasks lookup
3. `docs/development/INSTALLATION.md` - Setup and testing commands

**Core Understanding:**

1. `docs/development/ARCHITECTURE.md` - System architecture and patterns
2. `docs/development/DATABASE.md` - Database schema and relationships
3. `docs/development/CODING_STANDARDS.md` - Coding standards and conventions
4. `docs/development/INTEGRATION.md` - UserSpice integration

**As Needed - Specialized Topics:**

- `docs/development/ERROR_HANDLING.md` - Error handling, exceptions, API responses
- `docs/development/PAGE_LOADING_FLOW.md` - Initialization and file loading sequence
- `docs/development/CLASSES.md` - Application class documentation
- `docs/development/BACKUP_SYSTEM.md` - BackupManager class
- `docs/development/DATATABLES.md` - DataTables configuration
- `docs/development/CSS_AND_ASSETS.md` - Stylesheets and CDN resources
- `docs/development/FIX_SCRIPTS.md` - Database maintenance scripts
- `docs/development/STRICT_TYPE_HANDLING.md` - Strict type handling
- `docs/testing/TESTING.md` - Writing and running tests

**See [docs/README.md](docs/README.md) for complete documentation index**

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at
<https://elanregistry.org>. Built on UserSpice (<https://userspice.com>) for
authentication, with custom car registry functionality.

> **For complete architecture, see
> [ARCHITECTURE.md](docs/development/ARCHITECTURE.md)**

**Directory Structure:**

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/error/` - Branded HTTP error pages (403, 404, 500)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes
- `/tests/` - PHPUnit and Playwright test files

**Key Integration Points:**

- **Page Security**: All protected pages require `securePage($php_self)` check.
  See [INTEGRATION.md](docs/development/INTEGRATION.md).
- **New PHP Directories**: Update `$path` array in `/z_us_root.php`
- **Database**: MySQL 8.0+ with audit trails via triggers.
  See [DATABASE.md](docs/development/DATABASE.md).
- **Classes**: Car, CarView, ElanRegistryOwner, ChassisValidator, and support
  classes. See [CLASSES.md](docs/development/CLASSES.md).

## Development Setup

### System Requirements

- PHP 8.1+ required (8.2+ recommended)
- MySQL 8.0+
- Uses `johnathanmiller/secure-env-php` for encrypted environment variables

### Quick Start Commands

```bash
composer install                # PHP dependencies
npm install                     # Node dependencies (for testing)
./scripts/setup-git-hooks.sh    # Pre-commit quality checks (RECOMMENDED)

# PHP testing
composer test:quick             # Unit tests only (<30s)
composer test:medium            # Unit + Integration (<2min)
composer test:full              # All PHP tests
composer test:coverage          # Coverage report

# UI testing
npm run playwright:install      # Install browsers
npm run playwright:test         # Run UI tests
```

### Pre-commit Quality Checks

```bash
./scripts/setup-git-hooks.sh    # Setup once per developer
```

Validates PHP coding standards, runs markdown linting, and executes unit tests
on critical file changes. Bypass with `git commit --no-verify` (emergency only).

## Essential Development Guidelines

### PHP 8+ Requirements

- All functions must have complete parameter and return type hints
- New files must include `declare(strict_types=1)`
- Use typed exception classes for error handling
- Complete PHPDoc blocks required for all public methods
- See [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) for full details

### Security Requirements

- All forms must use CSRF tokens
- Use prepared statements for all SQL queries
- Input validation and sanitization for all user inputs
- **CRITICAL**: Never commit credentials, API keys, or sensitive data
- Use environment variables for sensitive configuration

### Error Handling

Use centralized error handling with typed exceptions, LogCategories constants,
and ApiResponse for AJAX endpoints.
See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) for patterns.

### Frontend API Client (Pattern A - v2.12.0+)

All new AJAX endpoints must use `ElanRegistryAPI` client with Pattern A
response format (`{success, message, ...}`). Available globally via `footer.php`.
See [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) for usage patterns
and migration guide from jQuery.ajax().

### Server Environment Globals (v2.13.0+)

Validated server globals available: `$scheme`, `$is_https`, `$host`, `$method`,
`$request_uri`, `$current_url`, `$current_origin`, `$php_self`, `$remote_addr`.
Use these instead of direct `$_SERVER` access.
See [PAGE_LOADING_FLOW.md](docs/development/PAGE_LOADING_FLOW.md) for details.

### Code Quality

**ALWAYS run before completing any task:**

- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

### Release Notes

Update or create release notes when creating a pull request using the template
at `docs/development/RELEASE_NOTES_TEMPLATE.md`.

### Terminology Standards

- **Users**: Authentication/session context (UserSpice framework, `users` table)
- **Owners**: Car registry business domain (UI elements, business logic)
- Use `getUserWithProfile($userId)` for combined user+profile data access
- See [CLASSES.md](docs/development/CLASSES.md) for ElanRegistryOwner patterns

## Quick Deployment Reference

**Use the correct remote for each environment:**

```bash
git push origin main && git push origin --tags   # GitHub
git push test feature/v2.9.1                      # Staging
git push prod main && git push prod --tags        # PRODUCTION
```

See [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) for complete procedures.

---

**For detailed information, see:**

- [ARCHITECTURE.md](docs/development/ARCHITECTURE.md) - System architecture
- [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) - Code quality
- [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) - Error handling and API patterns
- [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) - Release and deployment
- [ENVIRONMENT.md](docs/development/ENVIRONMENT.md) - Environment setup
