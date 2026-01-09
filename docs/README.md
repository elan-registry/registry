# Documentation Structure

This directory contains organized documentation for the Lotus Elan Registry
project, structured for different audiences and use cases.

## Quick Navigation

- **🔗 [Unified Document Viewer](view.php)** - Access all documentation
  through a single interface
- **👥 [User FAQ](faq/index.php)** - End-user guides and frequently asked
  questions
- **🔧 [Admin Documentation](faq/admin/index.php)** - Administrative
  procedures and technical guides
- **💻 [Development Docs](development/)** - Technical documentation for
  developers

## Directory Organization

### `/faq/` - User Documentation and FAQ

**Audience:** End users, car owners, registry members
**Access:** Public (no authentication required)

- **[index.php](faq/index.php)** - Main FAQ and documentation portal for
  users
- **[PRIVACY.md](view.php?doc=PRIVACY.md)** - Privacy policy and data
  handling practices
- **[CAR_TRANSFER_USER_GUIDE.md](view.php?doc=CAR_TRANSFER_USER_GUIDE.md)**
  \- Comprehensive user guide for car ownership transfer requests
- **[CAR_TRANSFER_FAQ.md](view.php?doc=CAR_TRANSFER_FAQ.md)** - Frequently
  asked questions about car transfer process

### `/faq/admin/` - Administrative Documentation

**Audience:** Registry administrators, technical staff
**Access:** Restricted (Administrator/Editor permissions required)

#### Core Administrative Guides

- **[index.php](faq/admin/index.php)** - Administrative documentation portal
- **[CAR_TRANSFER_ADMIN_GUIDE.md](view.php?doc=CAR_TRANSFER_ADMIN_GUIDE.md)**
  \- Complete administrator guide for managing car transfers
- **[CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md](view.php?doc=CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md)**
  \- Quick reference guide for daily admin operations
- **[CAR_TRANSFER_TROUBLESHOOTING.md](view.php?doc=CAR_TRANSFER_TROUBLESHOOTING.md)**
  \- Systematic troubleshooting procedures for transfer issues

#### System Documentation

- **[EMAIL_STYLING_GUIDELINES.md](view.php?doc=EMAIL_STYLING_GUIDELINES.md)**
  \- Email template styling standards and guidelines
- **[SPAM_CLEANUP_SYSTEM.md](view.php?doc=SPAM_CLEANUP_SYSTEM.md)** -
  Automated user cleanup system documentation

### Root Level - Strategic Documentation

**Audience:** Product managers, stakeholders, leadership
**Access:** Public (version controlled)

- **[PRD.md](PRD.md)** - Product Requirements Document with feature
  specifications and requirements

### `/development/` - Development Documentation

**Audience:** Developers, DevOps, AI assistants
**Access:** Public (version controlled)

#### 🚀 START HERE

Essential documents for getting started:

- **[CLAUDE.md](../CLAUDE.md)** - Root instructions for Claude Code AI
  assistant (quick reference and index)
- **[QUICK_REFERENCE.md](development/QUICK_REFERENCE.md)** - Quick reference
  for common tasks and commands
- **[QUICK_START.md](development/QUICK_START.md)** - Development setup and
  testing commands
- **[INSTALLATION.md](development/INSTALLATION.md)** - Complete installation
  procedures

#### 📚 CORE DOCUMENTATION

Fundamental architecture and patterns:

- **[ARCHITECTURE.md](development/ARCHITECTURE.md)** - System architecture,
  database, and class patterns
- **[PAGE_LOADING_FLOW.md](development/PAGE_LOADING_FLOW.md)** - Complete
  reference for page initialization and file loading sequence
- **[DATABASE.md](development/DATABASE.md)** - Complete database schema
  documentation and table relationships
- **[INTEGRATION.md](development/INTEGRATION.md)** - UserSpice integration
  and custom functions
- **[PROJECT_CONVENTIONS.md](development/PROJECT_CONVENTIONS.md)** -
  Project-specific coding standards and conventions
- **[CODING_STANDARDS.md](development/CODING_STANDARDS.md)** - Code quality
  requirements and standards
- **[STRICT_TYPE_HANDLING.md](development/STRICT_TYPE_HANDLING.md)** - PHP
  strict type handling patterns and solutions

#### 🔧 SPECIALIZED TOPICS

Specific workflows and advanced topics:

- **[DEVELOPMENT_WORKFLOW.md](development/DEVELOPMENT_WORKFLOW.md)** -
  Detailed development processes
- **[DEPLOYMENT.md](development/DEPLOYMENT.md)** - Production deployment
  procedures
- **[FIX_SCRIPTS.md](development/FIX_SCRIPTS.md)** - FIX script creation
  guidelines
- **[BACKUP_SYSTEM.md](development/BACKUP_SYSTEM.md)** - BackupManager class
  API and usage patterns
- **[STATIC_ANALYSIS.md](development/STATIC_ANALYSIS.md)** - Code quality and
  static analysis tools
- **[RELEASE_NOTES_TEMPLATE.md](development/RELEASE_NOTES_TEMPLATE.md)** -
  Template for creating release notes

### `/testing/` - Testing Documentation

**Audience:** QA engineers, technical leads, system architects
**Access:** Public (version controlled)

- **[TESTING.md](testing/TESTING.md)** - Comprehensive testing strategy
  including PHPUnit and Playwright test execution
- **[PLAYWRIGHT_E2E.md](testing/PLAYWRIGHT_E2E.md)** - Two-tier Playwright
  testing strategy (local development vs production)

### `/releases/` - Release Documentation

**Audience:** Project managers, stakeholders, users
**Access:** Public

- Version-specific release notes and change logs
- Feature announcements and upgrade guides

## Documentation System Features

### Unified Document Viewer

The `view.php` system provides:

- ✅ **Consistent formatting** - All markdown documents rendered with unified
  styling
- ✅ **Access control** - Admin documents protected by UserSpice
  permissions
- ✅ **Security** - XSS protection and input validation
- ✅ **Navigation** - Breadcrumb navigation and cross-references
- ✅ **Mobile responsive** - Optimized for all device sizes

### Content Management

- **Markdown format** - Easy to write and maintain
- **Version controlled** - Full change history in Git
- **Categorized access** - Public vs. admin-only content
- **Cross-referenced** - Automatic linking between related documents

## Contributing to Documentation

### Writing Guidelines

1. **Use clear headings** - Structure content with meaningful headings
2. **Include examples** - Provide code examples and screenshots where helpful
3. **Link liberally** - Cross-reference related documentation
4. **Keep current** - Update documentation when features change

### File Organization

- Place user-facing docs in `/faq/`
- Place admin docs in `/faq/admin/`
- Place testing docs in `/testing/`
- Place development docs in `/development/`
- Use descriptive filenames with consistent naming conventions

### Access Control

- Public documentation goes in `/faq/` (no authentication required)
- Admin documentation goes in `/faq/admin/` (requires Administrator/Editor
  permissions)
- Development documentation in `/development/` and `/testing/` is public but
  version-controlled

## External Documentation

Plugin and vendor documentation remains in their respective directories:

- `/usersc/plugins/*/README.md` - Individual plugin documentation
- Third-party vendor documentation in `/vendor/` and `/node_modules/` directories

---

*For questions about the documentation system, see the [CAR_TRANSFER_FAQ.md](view.php?doc=CAR_TRANSFER_FAQ.md) or contact the administrators.*
