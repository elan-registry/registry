# Development Documentation

Complete development documentation for the Elan Registry application.

## Getting Started

**New to the project?** Start here:

1. **[CLAUDE.md](../../CLAUDE.md)** - Essential guidance for all Claude Code sessions
2. **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Common tasks and commands
3. **[INSTALLATION.md](../wiki/Getting-Started.md)** - Setup and testing (see wiki)

## Documentation by Category

### Core Architecture & Setup

- **[ARCHITECTURE.md](../wiki/Architecture.md)** (wiki) - System architecture, patterns, design decisions
- **[INTEGRATION.md](../wiki/Integration.md)** (wiki) - UserSpice framework integration guide
- **[INSTALLATION.md](../wiki/Getting-Started.md)** (wiki) - Installation and environment setup
- **[ENVIRONMENT.md](ENVIRONMENT.md)** - Environment variables and configuration
- **[PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md)** - Request initialization sequence

### Code Standards & Best Practices

- **[CODING_STANDARDS.md](CODING_STANDARDS.md)** - PHP 8+, security, documentation requirements
- **[ERROR_HANDLING.md](ERROR_HANDLING.md)** - Error handling, exceptions, logging patterns
- **[STRICT_TYPE_HANDLING.md](STRICT_TYPE_HANDLING.md)** - Type safety and strict typing
- **[CSS_AND_ASSETS.md](CSS_AND_ASSETS.md)** - Frontend resources and styling

### Data & Database

- **[DATABASE.md](DATABASE.md)** - Schema, relationships, triggers, queries
- **[LOG_CATEGORIES.md](LOG_CATEGORIES.md)** - 140+ audit logging categories
- **[BACKUP_SYSTEM.md](BACKUP_SYSTEM.md)** - Backup and restore procedures
- **[FIX_SCRIPTS.md](FIX_SCRIPTS.md)** - Database maintenance scripts

### Classes & APIs

- **[CLASSES.md](CLASSES.md)** - Application classes (Car, Owner, ChassisValidator, etc.)
- **[USERSPICE_QUICK_LOOKUP.md](USERSPICE_QUICK_LOOKUP.md)** - UserSpice class method reference (quick lookup)
- **[USERSPICE_FUNCTIONS.md](USERSPICE_FUNCTIONS.md)** - UserSpice framework functions (detailed reference)

### Advanced Topics

- **[DATATABLES.md](DATATABLES.md)** - DataTables configuration and usage
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Release procedures, version management
- **[RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md)** - Release notes format

### Workflow & Development

- **[QUICK_REFERENCE.md](QUICK_REFERENCE.md)** - Common commands, patterns, quick lookup
- **[Development Workflow](https://github.com/jimboone/elan-registry/wiki/Development-Workflow)** (wiki) - Development processes
- **[STATIC_ANALYSIS.md](../wiki/Developer-Tools.md)** (wiki) - Code quality tools

## Documentation Purpose & Audience

| Document | Audience | Primary Purpose |
| --- | --- | --- |
| **CLAUDE.md** | AI assistants + developers | Session guidance, quick reference |
| **QUICK_REFERENCE.md** | Developers | Copy-paste examples, command lookup |
| **CODING_STANDARDS.md** | Developers | Code quality requirements |
| **ERROR_HANDLING.md** | Developers | Error patterns, exceptions, logging |
| **CLASSES.md** | Developers | Application class documentation |
| **USERSPICE_QUICK_LOOKUP.md** | AI assistants | Method signature reference |
| **USERSPICE_FUNCTIONS.md** | Developers | Framework function details |
| **DATABASE.md** | Developers | Schema, relationships, optimization |
| **ARCHITECTURE.md** (wiki) | Developers | System design, patterns |
| **INTEGRATION.md** (wiki) | Developers | UserSpice patterns, concepts |

## Reading Paths

### Path 1: New Developer Onboarding

1. CLAUDE.md - Overview
2. QUICK_REFERENCE.md - Common tasks
3. ARCHITECTURE (wiki) - System design
4. INTEGRATION (wiki) - Framework usage
5. CODING_STANDARDS.md - Quality requirements
6. Specialized docs as needed

### Path 2: Feature Implementation

1. QUICK_REFERENCE.md - Patterns
2. CLASSES.md - Domain models
3. DATABASE.md - Data access
4. ERROR_HANDLING.md - Error patterns
5. Specialized docs as needed

### Path 3: API Integration

1. ERROR_HANDLING.md - Response format
2. USERSPICE_QUICK_LOOKUP.md - Method reference
3. CLASSES.md - Domain models
4. QUICK_REFERENCE.md - Code examples

### Path 4: Deployment & Release

1. DEPLOYMENT.md - Release procedures
2. ENVIRONMENT.md - Configuration
3. BACKUP_SYSTEM.md - Data safety
4. QUICK_REFERENCE.md - Deployment commands

## File Organization

```text
/docs/
├── README.md                           # Documentation index (this file)
├── development/                        # Technical documentation
│   ├── ARCHITECTURE.md                 # (moved to wiki)
│   ├── BACKUP_SYSTEM.md                # Backup procedures
│   ├── CLASSES.md                      # Application classes
│   ├── CODING_STANDARDS.md             # Code quality requirements
│   ├── CSS_AND_ASSETS.md               # Frontend assets
│   ├── DATABASE.md                     # Database schema
│   ├── DATATABLES.md                   # DataTables library
│   ├── DEPLOYMENT.md                   # Release procedures
│   ├── ENVIRONMENT.md                  # Configuration
│   ├── ERROR_HANDLING.md               # Error patterns
│   ├── FIX_SCRIPTS.md                  # Database maintenance
│   ├── INTEGRATION.md                  # (moved to wiki)
│   ├── INSTALLATION.md                 # (moved to wiki)
│   ├── LOG_CATEGORIES.md               # Audit logging
│   ├── PAGE_LOADING_FLOW.md            # Request flow
│   ├── QUICK_REFERENCE.md              # Common patterns
│   ├── RELEASE_NOTES_TEMPLATE.md       # Release format
│   ├── STRICT_TYPE_HANDLING.md         # Type safety
│   ├── USERSPICE_FUNCTIONS.md          # Framework reference
│   └── USERSPICE_QUICK_LOOKUP.md       # Quick method reference
├── faq/                                # User documentation
├── wiki/                               # GitHub wiki (external)
└── README.md                           # Documentation index
```

## Key Documentation Principles

1. **Separation of Concerns**:
   - Quick lookup tables in separate files (USERSPICE_QUICK_LOOKUP.md)
   - Detailed reference in separate files (USERSPICE_FUNCTIONS.md)
   - Pattern examples in code-focused files (QUICK_REFERENCE.md)

2. **Multiple Audiences**:
   - AI assistants: Method references, structured tables (USERSPICE_QUICK_LOOKUP.md)
   - Human developers: Narrative, examples, concepts (QUICK_REFERENCE.md)
   - Code reviewers: Standards, checklists (CODING_STANDARDS.md)

3. **Single Source of Truth**:
   - Server globals: Documented in CLAUDE.md, referenced elsewhere
   - Error handling: Documented in ERROR_HANDLING.md, not duplicated
   - Frameworks: Reference in wiki (INTEGRATION.md), quick lookup here

4. **Cross-Linking**:
   - ERROR_HANDLING.md references QUICK_REFERENCE.md for additional examples
   - CODING_STANDARDS.md references ERROR_HANDLING.md for patterns
   - QUICK_REFERENCE.md references specialized docs for details

## Contributing to Documentation

When updating documentation:

1. Identify the primary document (single source of truth)
2. Update that document
3. Update cross-references in related documents
4. Run markdown linting: `markdownlint-cli2 docs/**/*.md`
5. Ensure code examples are accurate and tested

## Quick Commands

```bash
# Check for documentation issues
markdownlint-cli2 docs/development/*.md

# Find all references to a pattern
grep -r "ERROR_HANDLING\|QUICK_REFERENCE" docs/

# Check documentation consistency
grep -n "See.*QUICK_REFERENCE" docs/development/*.md
```

## Documentation Status

| Document | Status | Last Updated |
| --- | --- | --- |
| CLAUDE.md | ✅ Current | v2.15.0 |
| QUICK_REFERENCE.md | ✅ Current | v2.15.0 |
| CODING_STANDARDS.md | ✅ Current | v2.15.0 |
| ERROR_HANDLING.md | ✅ Current | v2.15.0 |
| CLASSES.md | ✅ Current | v2.15.0 |
| DATABASE.md | ✅ Current | v2.14.0 |
| ARCHITECTURE.md (wiki) | ✅ Current | v2.15.0 |
| INTEGRATION.md (wiki) | ✅ Current | v2.15.0 |
| INSTALLATION.md (wiki) | ✅ Current | v2.15.0 |

---

**Total Documentation**: 17 core files + wiki
**Total Lines**: ~7,000 lines of structured documentation
**Last Review**: Phase 2 Documentation Optimization (v2.15.0)
