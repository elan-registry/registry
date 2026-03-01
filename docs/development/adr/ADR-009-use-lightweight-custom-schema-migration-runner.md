# ADR-009: Use Lightweight Custom Schema Migration Runner

## Status

In Review

## Date

2026-02-25

## Context

The application currently uses web-accessible "FIX scripts" in `/FIX/` to apply
schema changes: column renames, index creation, storage engine conversions, and
new table creation. Each FIX script is a standalone PHP page with a two-phase
UI, progress logging, and a run record written to `fix_script_runs`.

This approach has a fundamental security problem: schema changes should run at
deploy time via CLI, not via browser. A web-accessible script that executes DDL
statements is an unnecessary attack surface, even when protected by an admin
session check. If an attacker can access the page, they can trigger destructive
schema changes.

Beyond the security concern, the FIX script system has no migration tracking
that separates "schema-changing migrations" from "admin utility scripts." Running
a FIX script twice is not safe; the system provides no atomic guarantee that a
given schema change has been applied exactly once. The 243-line FIX script
template provides progress logging and audit trail features that are useful for
administrative utilities, but are over-engineered for the single concern of "apply
this DDL if it has not been applied yet."

The project has approximately 10 historical schema changes and expects moderate,
steady growth: new tables for upcoming features such as the PDF reference library
(ADR-013 introduces FIX script 26 for the `reference_documents` table) and
future enhancements. The scale does not justify a full migration framework, but
the security and tracking gaps in the current approach require a solution.

The broader FIX script restructuring — separating schema migrations from admin
utilities and ad-hoc scripts — is tracked in GitHub issue #595. This ADR
addresses only the narrower question of what mechanism to use for schema
migrations.

### Problem Statement

The application needs a way to:

1. Apply schema changes (DDL/DML) at deploy time, not via browser
2. Track which changes have been applied so they are never run twice
3. Keep migration files simple: no framework DSL, no scaffolding, no rollback

   machinery unless explicitly needed

4. Avoid adding significant external dependencies

## Decision

Implement a lightweight custom schema migration runner consisting of three
components: a migrations directory, a tracking table, and a CLI runner script.

### Migration Files: `/database/migrations/`

Migration files live in `/database/migrations/` and use timestamp-based names
to establish execution order and prevent conflicts between concurrent developers:

```text
YYYYMMDD_HHmmss_description.sql     # Pure DDL/DML
YYYYMMDD_HHmmss_description.php     # PHP when init.php context is required
```

SQL files contain standard DDL or DML statements. PHP files may include
`init.php`when they need the`$db` object or application classes. No template,
no scaffolding, no boilerplate beyond what the change itself requires.

Existing FIX scripts that are purely schema migrations (not admin utilities) are
candidates for conversion to this format as part of the issue #595 restructuring.

### Tracking Table: `schema_migrations`

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    id           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    migration    VARCHAR(255)    NOT NULL,
    executed_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

The `migration`column stores the filename (without path). The`UNIQUE` constraint
provides the idempotency guarantee: attempting to record a migration that has
already run raises a database error before any DDL executes.

### CLI Runner: `/scripts/run-migrations.php`

The runner is executed via CLI only — never via web server. It:

1. Scans `/database/migrations/`for`.sql`and`.php` files in filename order
2. Queries `schema_migrations` for already-executed filenames
3. For each unexecuted file (in order):
   - SQL files: executed via `mysqli::multi_query()`
   - PHP files: executed via `include`
   - On success: inserts the filename into `schema_migrations`
   - On failure: logs the error, stops execution, exits non-zero
4. Supports `--dry-run` flag: prints pending migrations without executing them

**Invocation:**

```bash
# Show pending migrations without running them
php scripts/run-migrations.php --dry-run

# Run all pending migrations
php scripts/run-migrations.php
```

The runner exits with a non-zero status code on failure, making it suitable for
inclusion in a deployment script that checks exit codes.

### Deployment Integration

Migrations run as an explicit step during deployment, documented in
`docs/development/DEPLOYMENT.md`. The deployment checklist includes:

```text
[ ] Run: php scripts/run-migrations.php
[ ] Verify: no errors in output, exit code 0
```

Migrations are never triggered by a web request, admin panel action, or
application initialization sequence.

### What This Replaces

This mechanism replaces the one-time schema-migration responsibility of the FIX
script system. Admin utilities and ad-hoc diagnostic scripts that currently live
in `/FIX/` are not affected by this ADR; their restructuring is tracked separately
in issue #595.

## Consequences

### Positive

- **Schema changes tracked atomically.** The `UNIQUE` constraint on

  `schema_migrations.migration` ensures a given migration is recorded at most
  once. The runner checks the table before executing; a partial failure leaves
  no record, so re-running the runner safely retries the failed migration.

- **Deployment safety.** Migrations run via CLI at deploy time, not via browser.

  The migration runner has no web-facing entry point and therefore no associated
  attack surface.

- **No framework dependency.** The runner is approximately 100 lines of PHP using

  only `mysqli` and standard file functions. No Composer package, no learning
  curve, no framework version to keep current.

- **Simple migration files.** A migration is a `.sql` file with DDL statements

  or a `.php`file that includes`init.php`. Developers already know how to write
  both. There is no DSL, no class to extend, no `up()`and`down()` method
  convention to follow.

- **Idempotent runner.** Re-running the runner after a failed deployment is safe.

  Already-executed migrations are skipped; only pending migrations are applied.

- **Consistent with project philosophy.** The project minimizes external

  dependencies (ADR-005 uses a small Composer package for env encryption; ADR-004
  avoids third-party HTTP clients). A custom 100-line runner fits that philosophy
  better than adopting Phinx or Doctrine Migrations.

### Negative

- **No built-in rollback.** Rolling back a migration requires writing a new

  forward migration that reverses the change. There is no `down()` method and no
  `migrate:rollback` command. For a registry application with infrequent schema
  changes, this is an acceptable trade-off; rollback requirements are rare and
  forward-migration rollbacks are explicit and auditable.

- **Custom runner code to maintain.** The runner is application code. If

  `mysqli::multi_query()` behavior changes in a future PHP version, or if edge
  cases arise in SQL parsing, the runner must be updated. A framework would
  absorb this maintenance.

- **Developers must remember the deployment step.** There is no automatic

  migration on application startup. A deployment that skips the migration step
  leaves the schema out of sync with the code. This is mitigated by the
  deployment checklist and, optionally, a pre-flight check in the application
  that warns when pending migrations exist.

### Risks

**Developer forgets to run migrations during deployment** (Medium likelihood,
Medium impact). Add migration step to deployment checklist; consider a startup
warning if `schema_migrations` is behind the file count in
`/database/migrations/`.

**Two developers create migrations with same timestamp** (Low likelihood, Low
impact). Timestamp-based naming reduces conflicts to near-zero likelihood;
resolve via rename before merge.

**Runner `multi_query()` silently skips a failed statement** (Low likelihood,
High impact). Check `mysqli::$error` after each query; runner exits non-zero
and does not record the migration on any failure.

**Migration file contains a partial change that cannot be retried** (Low
likelihood, Medium impact). Wrap reversible DDL in transactions where MySQL
supports it; document known non-transactional operations (e.g., `CREATE TABLE`)
in runner README.

## Alternatives Considered

### Full Migration Framework (Phinx, Doctrine Migrations, Laravel Migrations)

Adopt an established PHP migration framework via Composer.

**Rejected because:**

- Adds a significant Composer dependency with its own version constraints and

  upgrade path. Phinx alone requires ~15 transitive packages.

- The application has approximately 10 historical migrations and expects

  moderate growth. A full framework is over-engineered at this scale; the
  framework's features (rollback, seeding, environment management) are not
  required.

- Integration with UserSpice's page-based initialization sequence and the

  non-standard deploy environment (shared hosting, no CI/CD pipeline) adds
  configuration complexity that offsets the framework's benefits.

- Revisit this decision if migration count exceeds 50 or if rollback

  requirements emerge from operational experience.

### Web-Accessible FIX Scripts (Status Quo)

Continue applying schema changes via web-accessible PHP scripts in `/FIX/`.

**Rejected because:**

- Schema-changing scripts should not be accessible via browser. A logged-in

  admin session check is a weaker boundary than no web-facing entry point at all.

- The FIX system provides no atomic tracking that a given schema change has run

  exactly once. `fix_script_runs` records a run attempt but does not prevent
  re-execution.

- The 243-line FIX script template is over-engineered for a file whose sole

  purpose is "execute this DDL once."

### Raw Manual SQL via phpMyAdmin or CLI

Execute schema changes manually without any runner or tracking.

**Rejected because:**

- No tracking: there is no record of which changes have been applied to which

  environment. Reproducing the schema on a fresh installation requires reading
  through deployment notes.

- No idempotency: running a `CREATE TABLE` statement twice raises an error;

  running an `ALTER TABLE` that has already been applied may silently corrupt
  data or fail.

- Error-prone: manual execution is the most common source of "works on staging,

  broken in production" incidents.

### Keep FIX Scripts but Restrict to CLI

Adapt the existing FIX script mechanism to run via CLI instead of web, without
introducing a separate migration runner.

**Rejected because:**

- The FIX template provides a two-phase web UI with progress logging; stripping

  that down to CLI output requires rewriting most of the template, at which point
  a new, purpose-built runner is simpler.

- The FIX system does not provide timestamp-ordered execution of multiple

  migrations in a single command. Each FIX script is a standalone invocation;
  a deployment would require running them individually in the correct order.

- Keeps the FIX template as the migration interface, which conflates admin

  utilities with schema migrations and makes it harder to distinguish "must run
  at deployment" from "run when an admin requests it."

## References

- **GitHub Issue #595**: Restructure FIX Scripts into Admin Tools, CLI

  Migrations, and Ad-Hoc Scripts

- **ADR-013**: Store PDF Reference Library on A2 Hosting with Database-Driven

  Metadata — introduces FIX script 26 (`reference_documents` table) as a
  migration candidate

- **FIX Script Documentation**: [docs/development/FIX_SCRIPTS.md](../development/FIX_SCRIPTS.md)
- **Deployment Procedures**: [docs/development/DEPLOYMENT.md](../development/DEPLOYMENT.md)

  (to be updated with migration step)

- **Nygard ADR Format**:
  [https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
