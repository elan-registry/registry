# Development Documentation

## Start here

- [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) — **What the registry does and for
  whom** — capabilities by role, deliberate omissions, and known gaps

## Core

- [CODING_STANDARDS.md](CODING_STANDARDS.md) — PHP 8+ requirements, security, code review checklist
- [UI_STANDARDS.md](UI_STANDARDS.md) — **Read before any UI change** — color tokens, card hierarchy, component patterns
- [ERROR_HANDLING.md](ERROR_HANDLING.md) — ApiResponse, exceptions, ElanRegistryAPI frontend client
- [CLASSES.md](CLASSES.md) — Car, Owner, and all application classes
- [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md) — Request initialization sequence
- [DATABASE.md](DATABASE.md) — Schema, tables, relationships
- [ENVIRONMENT.md](ENVIRONMENT.md) — Environment variables, URLs, local DB access
- [TESTING_STRATEGY.md](TESTING_STRATEGY.md) — **Why** the suite is tiered and the
  UserSpice behaviors any new test must account for (commands live in
  [tests/README.md](../../tests/README.md))

## References

- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — 107 audit logging constants
- [USERSPICE_FUNCTIONS.md](USERSPICE_FUNCTIONS.md) — UserSpice framework function reference
- [STRICT_TYPE_HANDLING.md](STRICT_TYPE_HANDLING.md) — dbInt() and type helpers
- [DATATABLES.md](DATATABLES.md) — DataTables configuration
- [BACKUP_SYSTEM.md](BACKUP_SYSTEM.md) — BackupManager API

## Operations

- [ISSUE_WORKFLOW.md](ISSUE_WORKFLOW.md) — Capture, planning, build, and ship
  loops — signal labels, the theme gate, review rules, backlog hygiene
- [DEPLOYMENT.md](DEPLOYMENT.md) — Git remotes, CI checks, release procedures
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) — Brevo setup and configuration
- [FIX_SCRIPTS.md](FIX_SCRIPTS.md) — Admin fix/maintenance script guidelines
- [CSS_AND_ASSETS.md](CSS_AND_ASSETS.md) — CSS file structure and build process
- [QUICK_REFERENCE.md](QUICK_REFERENCE.md) — Commands, patterns, model management
- [RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md) — Template for release notes
- [RELEASE_INSTRUCTIONS_TEMPLATE.md](RELEASE_INSTRUCTIONS_TEMPLATE.md) — Deploy sheet rendered by `/finish-milestone`

## External

- [Architecture Guide](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design)
- [UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns)
