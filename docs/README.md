# Documentation Structure

This directory contains organized documentation for the Lotus Elan Registry
project, structured for different audiences and use cases.

## Quick Navigation

- **👥 [Owner Guides](guides/index.php)** - End-user guides for car owners
- **💻 [Development Docs](development/)** - Technical documentation for developers

## Directory Organization

### `/guides/` - Owner Guides

**Audience:** End users, car owners, registry members
**Access:** Public (no authentication required)

- **[index.php](guides/index.php)** - Owner guides portal
- **[Privacy Policy](../app/owner/privacy.php)** - Privacy policy and data handling practices
- **[Transfer FAQ](guides/car-transfer-faq.php)** - Frequently asked questions about car ownership transfers

### `/reference/` - Technical Reference

**Audience:** Car owners, researchers
**Access:** Public

- **[index.php](reference/index.php)** - Reference documentation portal
- **[Chassis Validation](reference/chassis-validation.php)** - Chassis number formats and validation rules
- **[Paint Colors](reference/paint-colors.php)** - Factory paint color reference
- **[Technical Articles](reference/technical-articles.php)** - Technical documentation
- **[Workshop](reference/workshop.php)** - Workshop and parts resources

### `/stories/` - Car Stories

**Audience:** Car owners, enthusiasts
**Access:** Public

- **[car-stories.php](../docs/car-stories.php)** - Car stories portal

### `/development/` - Development Documentation

**Audience:** Developers, DevOps, AI assistants
**Access:** Public (version controlled)

#### 🚀 START HERE

- **[CLAUDE.md](../CLAUDE.md)** - Root instructions for Claude Code AI assistant
- **[QUICK_REFERENCE.md](development/QUICK_REFERENCE.md)** - Quick reference for common tasks and commands
- **[INSTALLATION.md](development/INSTALLATION.md)** - Complete installation and setup procedures

#### 📚 CORE DOCUMENTATION

- **[Architecture Guide][arch-wiki]** - System architecture, database, and class patterns
- **[PAGE_LOADING_FLOW.md](development/PAGE_LOADING_FLOW.md)** - Page initialization and file loading sequence
- **[DATABASE.md](development/DATABASE.md)** - Complete database schema and table relationships
- **[UserSpice Integration Guide][us-wiki]** - UserSpice integration and custom functions
- **[CODING_STANDARDS.md](development/CODING_STANDARDS.md)** - Code quality requirements and project conventions
- **[STRICT_TYPE_HANDLING.md](development/STRICT_TYPE_HANDLING.md)** - PHP strict type handling patterns

#### 🔧 SPECIALIZED TOPICS

- **[DEPLOYMENT.md](development/DEPLOYMENT.md)** - Production deployment procedures
- **[FIX_SCRIPTS.md](development/FIX_SCRIPTS.md)** - Fix script creation guidelines
- **[BACKUP_SYSTEM.md](development/BACKUP_SYSTEM.md)** - BackupManager class API and usage
- **[EMAIL_SYSTEM.md](development/EMAIL_SYSTEM.md)** - Brevo email plugin setup and configuration
- **[ERROR_HANDLING.md](development/ERROR_HANDLING.md)** - Error handling patterns and API responses
- **[CLASSES.md](development/CLASSES.md)** - Custom application class documentation
- **[CSS_AND_ASSETS.md](development/CSS_AND_ASSETS.md)** - Stylesheets, CDN resources, and CSS minification
- **[DATATABLES.md](development/DATATABLES.md)** - DataTables configuration and server-side processing
- **[ENVIRONMENT.md](development/ENVIRONMENT.md)** - Environment setup and configuration
- **[STATIC_ANALYSIS.md](development/STATIC_ANALYSIS.md)** - Code quality and static analysis tools
- **[RELEASE_NOTES_TEMPLATE.md](development/RELEASE_NOTES_TEMPLATE.md)** - Template for creating release notes

### `/testing/` - Testing Documentation

**Audience:** QA engineers, technical leads
**Access:** Public (version controlled)

- **[TESTING.md](testing/TESTING.md)** - PHPUnit and Playwright test execution
- **[PLAYWRIGHT_E2E.md](testing/PLAYWRIGHT_E2E.md)** - Two-tier Playwright testing strategy

### `/releases/` - Release Documentation

**Audience:** Project managers, stakeholders, users
**Access:** Public

- Version-specific release notes and change logs

## Contributing to Documentation

### Guide Pages (`/guides/`)

Guide content is pre-compiled to static HTML and inlined as PHP heredocs in
individual pages. To update a guide, edit the `$htmlContent` heredoc directly
in the relevant PHP file. No build step required.

### File Organization

- User-facing guides → `docs/guides/`
- Development docs → `docs/development/` (Markdown)
- Testing docs → `docs/testing/` (Markdown)
- Reference pages → `docs/reference/`

### Access Control

- Public guides in `docs/guides/` — no authentication required
- Development docs in `docs/development/` and `docs/testing/` — public, version-controlled

## External Documentation

- `/usersc/plugins/*/README.md` — Individual plugin documentation
- Third-party docs in `/vendor/` and `/node_modules/`

[arch-wiki]: https://github.com/unibrain1/elanregistry/wiki/Elan-Registry-Architecture-and-Database-Design
[us-wiki]: https://github.com/unibrain1/elanregistry/wiki/Customization-and-Integration-Patterns
