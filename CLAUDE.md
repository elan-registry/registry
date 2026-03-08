# CLAUDE.md

This file provides essential guidance to Claude Code (claude.ai/code) when
working with code in this repository.

## Documentation Reference

**Essential reading:**

- `CLAUDE.md` (this file) - Overview and quick reference
- `docs/development/CODING_STANDARDS.md` - Code quality requirements
- `docs/development/QUICK_REFERENCE.md` - Common tasks lookup
- `docs/development/DEPLOYMENT.md` - Production deployment procedures
- `docs/development/ENVIRONMENT.md` - Environment setup and configuration

**Core understanding:**

- [GitHub Wiki: Architecture Guide](https://github.com/jimboone/elan-registry/wiki/Architecture) - System architecture and patterns
- `docs/development/DATABASE.md` - Database schema and relationships
- [GitHub Wiki: UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration) - UserSpice integration

**As needed:**

- `docs/development/ERROR_HANDLING.md` - Error handling, exceptions, API responses
- `docs/development/PAGE_LOADING_FLOW.md` - Initialization, file loading, server globals
- `docs/development/CLASSES.md` - Application class documentation
- `docs/development/BACKUP_SYSTEM.md` - BackupManager class
- `docs/development/DATATABLES.md` - DataTables configuration
- `docs/development/CSS_AND_ASSETS.md` - Stylesheets and CDN resources
- `docs/development/FIX_SCRIPTS.md` - Database maintenance scripts
- `docs/development/STRICT_TYPE_HANDLING.md` - Strict type handling
- `docs/development/USERSPICE_FUNCTIONS.md` - UserSpice framework functions reference (check before building custom solutions)
- `docs/testing/TESTING.md` - Writing and running tests

**See [docs/README.md](docs/README.md) for complete documentation index**

## Architecture Overview

This is a PHP web application for the Lotus Elan Registry hosted at
<https://elanregistry.org>. Built on UserSpice (<https://userspice.com>) for
authentication, with custom car registry functionality.

> **For complete architecture, see the
> [GitHub Wiki: Architecture Guide](https://github.com/jimboone/elan-registry/wiki/Architecture)**

**Directory Structure:**

- `/app/` - Main application pages (car listings, details, forms, actions)
- `/error/` - Branded HTTP error pages (403, 404, 500)
- `/users/` - UserSpice authentication system
- `/usersc/` - UserSpice customizations (templates, plugins, overrides)
- `/usersc/classes/` - Custom application classes
- `/tests/` - PHPUnit and Playwright test files

**Key Integration Points:**

- **Page Security**: All protected pages require `securePage($php_self)` check.
  See [GitHub Wiki: UserSpice Integration Guide](https://github.com/jimboone/elan-registry/wiki/Integration).
- **New PHP Directories**: Update `$path` array in `/z_us_root.php`
- **Database**: MySQL 8.0+ with audit trails via triggers.
  See [DATABASE.md](docs/development/DATABASE.md).
- **Classes**: Car, CarView, ElanRegistryOwner, ChassisValidator, and support
  classes. See [CLASSES.md](docs/development/CLASSES.md).

## Development Setup

### System Requirements

- PHP 8.2+ required
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

### UserSpice Framework

Before implementing custom functionality, check
`docs/development/USERSPICE_FUNCTIONS.md` for existing UserSpice functions that
may already provide the needed capability. Avoid duplicating framework
functionality. Key areas: authentication, permissions, database operations,
input handling, session management, CSRF protection, and email.

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

Use validated server globals instead of direct `$_SERVER` access. Initialized
in `usersc/includes/server_globals.php` and available on every page after
`init.php`.

**Available globals:** `$scheme`, `$is_https`, `$host`, `$method`,
`$request_uri`, `$current_url`, `$current_origin`, `$php_self`,
`$remote_addr`, `$referer`, `$user_agent`

**Never use raw `$_SERVER`** — use the globals above instead.
See [PAGE_LOADING_FLOW.md](docs/development/PAGE_LOADING_FLOW.md) for full
details and usage examples.

### Code Quality

**ALWAYS run before completing any task:**

- Use `software-developer` agent for all coding work
  (features, fixes, refactoring) — do not write code directly
- Run `/security-review` to conduct a security review of all changes
- Run `mcp__ide__getDiagnostics` to check all files for diagnostics
- Fix any linting or type errors before considering the task complete
- Run appropriate test suites for modified functionality

## Claude Code Tooling

### Agents (`.claude/agents/`)

| Agent | Purpose |
| ----- | ------- |
| `software-developer` | Primary coding agent for features, fixes, refactoring |
| `security-reviewer` | OWASP security audit of changed files |
| `senior-architect` | Architecture review, security audit, GDPR compliance |
| `senior-product-manager` | Issue refinement, scope, acceptance criteria |
| `senior-test-engineer` | Test strategy, PHPUnit/Playwright tests |
| `technical-documentation-writer` | Docs, README, release notes |

### Skills (`.claude/commands/`)

| Skill | Purpose |
| ----- | ------- |
| `/issue` | Start work on a GitHub issue (branch, plan, implement) |
| `/release` | Automated release workflow with version analysis |
| `/security-review` | Security audit of recent code changes |

### Hooks (`.claude/settings.local.json`)

- **PostToolUse (Edit/Write)**: Auto-runs `php -l` syntax check on PHP files

### MCP Servers (configured in `~/.claude.json`)

- **Playwright**: Browser automation and UI testing
- **GitHub**: GitHub issues, PRs, and actions integration
- **context7**: Live documentation lookup for libraries/frameworks

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

- [GitHub Wiki: Architecture Guide](https://github.com/jimboone/elan-registry/wiki/Architecture) - System architecture
- [CODING_STANDARDS.md](docs/development/CODING_STANDARDS.md) - Code quality
- [ERROR_HANDLING.md](docs/development/ERROR_HANDLING.md) - Error handling and API patterns
- [DEPLOYMENT.md](docs/development/DEPLOYMENT.md) - Release and deployment
- [ENVIRONMENT.md](docs/development/ENVIRONMENT.md) - Environment setup
