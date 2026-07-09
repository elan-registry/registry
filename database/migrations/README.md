# Database Migrations

This directory contains [Phinx](https://book.cakephp.org/phinx/0/en/migrations.html)
migration files. Each migration applies exactly once per environment and is
tracked in the `phinxlog` database table, so a migration that has already run is
never applied again.

Use migrations for one-time schema changes: `CREATE TABLE`, `ALTER TABLE`,
foreign key constraints, column renames, index changes, and data
transformations that must run once at deploy time. For repeatable admin
utilities that require human judgment to run, use a FIX script instead — see
[`../../docs/development/FIX_SCRIPTS.md`](../../docs/development/FIX_SCRIPTS.md).

## Commands

Prefer the `composer` wrappers for the day-to-day workflow. Only `create` is run
through `vendor/bin/phinx` directly, because there is no composer wrapper for it.

```bash
# Create a new migration (Phinx auto-generates the timestamp prefix)
vendor/bin/phinx create MigrationName

# Apply all pending migrations
composer migrate

# Show which migrations are pending and which have been applied
composer migrate:status

# Preview pending migrations without applying them
composer migrate:dry-run

# Roll back the most recent migration
composer migrate:rollback
```

## Writing Migrations

### Strongly prefer `change()`

`change()` is the default and preferred method. Phinx records the operations you
declare and **auto-reverses them on rollback** — you do not write any rollback
code yourself.

Use Phinx's native API to describe the change:

- `createTable()`
- `addColumn()`
- `addForeignKey()`
- `addIndex()`
- `renameColumn()`
- (and the other `Table` builder methods documented by Phinx)

Only fall back to explicit `up()` + `down()` methods when:

- You are mixing operations where at least one is not auto-reversible. The most
  common case is `changeColumn()` — Phinx records the *new* column definition
  but not the original, so it throws `IrreversibleMigrationException` on
  rollback when `changeColumn` appears inside `change()`.
- You genuinely need different rollback behaviour than a direct inverse (e.g.
  you are fixing a bad default that you intentionally do not want to restore on
  rollback).

This is rare.

**Never use `$this->execute()` with raw SQL unless it is absolutely
unavoidable.** Raw SQL cannot be auto-reversed by Phinx, which defeats rollback
support and breaks the `--dry-run` preview.

### Transactions

- **DML migrations** (`INSERT` / `UPDATE` / `DELETE`) should be wrapped in a
  transaction so a partial failure rolls back cleanly:

  ```php
  $adapter = $this->getAdapter();
  $adapter->beginTransaction();
  // ... your INSERT/UPDATE/DELETE operations ...
  $adapter->commitTransaction();
  ```

- **DDL migrations** (`ALTER TABLE`, `CREATE TABLE`, `ADD CONSTRAINT`) **cannot**
  be wrapped in a transaction. MySQL issues an implicit commit on DDL, so a
  transaction has no effect. Document this clearly in the migration file header
  (see `20260709202522_add_foreign_key_constraints.php` for an example) so the
  next reader knows the change is not atomic.

## Naming Convention

Phinx generates files as `YYYYMMDDHHMMSS_ClassName.php`. The timestamp prefix
establishes execution order and prevents conflicts between contributors working
in parallel. **Never rename a generated migration file** — the filename is part
of how Phinx tracks the migration in `phinxlog`.

## Testing

Always run `composer migrate:dry-run` locally first to confirm the migration
does what you expect. Apply it against a copy of the schema before pushing.

## Recovery from a Failed Migration

If a migration fails during deployment, it is **not** recorded in `phinxlog`.
Fix the migration file and redeploy — Phinx automatically retries the failed
migration on the next run. There is no manual cleanup of `phinxlog` needed.

## Reference

- Phinx migrations documentation:
  <https://book.cakephp.org/phinx/0/en/migrations.html>
