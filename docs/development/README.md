# Development Documentation

This index separates onboarding, reference, operations, and decision records.

## Claim scope

- **Repository fact** — Check this claim in tracked source or configuration.
- **Live setting** — Check this value on the named host or vendor account. Record
  the check date and evidence in the document or change record.
- **Historical record** — This claim describes a past decision or state. Check
  the date and status before you use it as current guidance.

The docs checker verifies selected repository facts. It cannot verify values on
test or production hosts or in vendor accounts.

## New contributors: read in this order

1. [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) — project purpose, user roles, and
   scope.
2. [ENVIRONMENT.md](ENVIRONMENT.md) — local setup and environment files.
3. [PAGE_LOADING_FLOW.md](PAGE_LOADING_FLOW.md) — request initialization.
4. [CODING_STANDARDS.md](CODING_STANDARDS.md) — PHP and code review rules.
5. [TESTING_STRATEGY.md](TESTING_STRATEGY.md) — test tiers and project-specific
   test behavior. See [tests/README.md](../../tests/README.md) for commands.
6. [UI_STANDARDS.md](UI_STANDARDS.md) — read before you change the interface.

## Architecture and implementation reference

- [CLASSES.md](CLASSES.md) — application classes, including Car and Owner.
- [DATABASE.md](DATABASE.md) — schema, tables, and relationships.
- [ERROR_HANDLING.md](ERROR_HANDLING.md) — API responses, exceptions, and the
  frontend API client.
- [STRICT_TYPE_HANDLING.md](STRICT_TYPE_HANDLING.md) — `dbInt()` and type helpers.
- [DATATABLES.md](DATATABLES.md) — DataTables setup and asset versions.
- [BACKUP_SYSTEM.md](BACKUP_SYSTEM.md) — BackupManager API.
- [LOG_CATEGORIES.md](LOG_CATEGORIES.md) — log category constants.
- [UserSpice framework guidance](../../usersc/plugins/ai_prompts/prompts/00_start_here.md.php)
  — read before you build a custom framework solution.
- [QUICK_REFERENCE.md](QUICK_REFERENCE.md) — commands and common patterns.
- [CSS_AND_ASSETS.md](CSS_AND_ASSETS.md) — asset source and build process.

## Operations

- [DEPLOYMENT.md](DEPLOYMENT.md) — deployment procedure and live settings.
- [CLEANUP_LEDGER.md](CLEANUP_LEDGER.md) — open cleanup finds, grouped by file.
- [EMAIL_SYSTEM.md](EMAIL_SYSTEM.md) — email integration and vendor settings.
- [FIX_SCRIPTS.md](FIX_SCRIPTS.md) — admin maintenance script rules.
- [ISSUE_WORKFLOW.md](ISSUE_WORKFLOW.md) — capture, planning, build, and ship
  loops: signal labels, the theme gate, review rules, backlog hygiene.

## Templates

- [RELEASE_NOTES_TEMPLATE.md](RELEASE_NOTES_TEMPLATE.md) — release notes template.
- [RELEASE_INSTRUCTIONS_TEMPLATE.md](RELEASE_INSTRUCTIONS_TEMPLATE.md) — deploy
  sheet rendered by `/finish-milestone`.

## Historical decisions

- [Architecture Decision Records](adr/README.md) — dated decisions and their
  current status. Treat superseded decisions as historical.

## External references

- [Architecture Guide](https://github.com/elan-registry/registry/wiki/Elan-Registry-Architecture-and-Database-Design)
- [UserSpice Integration Guide](https://github.com/elan-registry/registry/wiki/Customization-and-Integration-Patterns)
