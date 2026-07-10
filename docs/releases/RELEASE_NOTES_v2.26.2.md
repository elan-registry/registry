# Elan Registry v2.26.2 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - DB Infrastructure & Raw SQL Migration

## Required Actions After Deployment

### Before pushing

1. Verify no orphaned rows that would block the FK migration:
   ```sql
   SELECT COUNT(*) FROM car_transfer_requests
   WHERE existing_car_id NOT IN (SELECT id FROM cars);
   ```
   Must return `0`. Resolve any orphans before deploying.

2. Bootstrap the new post-receive hook on each server (one-time — old hook doesn't self-update):
   ```bash
   scp scripts/server-hooks/post-receive \
       a2hosting:/home/unibrain/git/elanregistry.git/hooks/post-receive
   ssh a2hosting "chmod +x /home/unibrain/git/elanregistry.git/hooks/post-receive"
   ```
   After this, all future pushes are fully automated (composer install + migrations run on every push).

### After pushing

3. Migrations and `composer install` run automatically via the hook. Verify push output shows no errors and ends with:
   - `Production deployment complete for branch: main`

4. Remove the now-redundant `usersc/vendor/` directory on each server (phpdotenv moved to `vendor/`):
   ```bash
   ssh a2hosting "rm -rf /home/unibrain/elanregistry.org/usersc/vendor/"
   ```
   Test server:
   ```bash
   ssh a2hosting "rm -rf /home/unibrain/test.elanregistry.org/usersc/vendor/"
   ```

## User-Facing Changes

None — all changes in this release are internal infrastructure and refactoring.

## Admin-Facing Changes

### New Features

- **Phinx migration infrastructure** ([#693](https://github.com/unibrain1/elanregistry/issues/693)): Formal database migration runner using [Phinx](https://phinx.org). Migrations in `database/migrations/` are tracked in `phinxlog`, run via `composer migrate`, and are safe for CI/CD automation. The admin dashboard shows a warning banner when pending migrations exist. First migration enforces FK constraints on `cars.user_id` and `car_transfer_requests.existing_car_id`.
- **Automated deployment hooks** ([#1254](https://github.com/unibrain1/elanregistry/issues/1254)): Post-receive hooks now run `composer install` and `phinx migrate` automatically on every push. A single shared hook script (`scripts/server-hooks/post-receive`) self-configures from the git repo path — no separate prod/test files needed. The hook self-updates from the deployed working tree on each push.

### Improvements

- **Year sorting** ([#1161](https://github.com/unibrain1/elanregistry/issues/1161)): `cars.year` stored as `SMALLINT` instead of `VARCHAR` — corrects sort order in any admin views that order by year.
- **Transfer token uniqueness enforced on prod** ([#1272](https://github.com/unibrain1/elanregistry/issues/1272)): Fixes the FK migration so it no longer fails on prod due to column signedness mismatches. Adds `UNIQUE KEY security_token` and 12 other missing indexes, plus two FK constraints (`fk_transfer_created_by`, `fk_transfer_requested_by`) that were in the schema but not the migration.
- **`logs` table crash safety** ([#1273](https://github.com/unibrain1/elanregistry/issues/1273)): Converted the `logs` table from MyISAM to InnoDB on dev and prod (test was already InnoDB). Restores crash recovery, row-level locking, and transaction support for audit log writes.
- **Remove `cars.user_id` FK** ([#1279](https://github.com/unibrain1/elanregistry/issues/1279)): Dropped the `cars.user_id → users.id ON DELETE SET NULL` FK added in #693. The constraint raced with `after_user_deletion.php`, NULLing `cars.user_id` before the hook could reassign ownership to noowner. The application-level hook handles all reassignment correctly without the FK. Migration `20260710230000` drops the FK (and defensively drops any `before_user_delete_reassign_cars` trigger if present).
- **`fix_script_runs` charset alignment** ([#1274](https://github.com/unibrain1/elanregistry/issues/1274)): Migrated `fix_script_runs` from deprecated `utf8mb3`/`utf8mb3_unicode_ci` to `utf8mb4`/`utf8mb4_unicode_ci` on dev and test (prod already correct). Ensures all environments share a consistent, future-proof charset and `completed_at NOT NULL` nullability.
- **`TransferStatus` backed enum** ([#1169](https://github.com/unibrain1/elanregistry/issues/1169)): Replaced bare-string status constants in `CarTransferRepository` with a PHP 8.1 `TransferStatus` enum. `updateStatus()` now requires a typed `TransferStatus` value — invalid statuses are caught at compile time rather than at runtime. The `isTerminal()` method replaces the `TERMINAL_STATUSES` constant array.

## Issues Resolved

- [#693](https://github.com/unibrain1/elanregistry/issues/693) — feat: create database migration runner infrastructure and apply first FK constraint migration
- [#1148](https://github.com/unibrain1/elanregistry/issues/1148) — refactor: complete raw-SQL migration for remaining files not covered by #962
- [#1153](https://github.com/unibrain1/elanregistry/issues/1153) — chore: route user_settings.php location-sync DB writes through CarRepository / Owner *(consolidated into #1148)*
- [#1161](https://github.com/unibrain1/elanregistry/issues/1161) — fix: correct cars.year column type to SMALLINT and drop unused ModifiedBy column
- [#1162](https://github.com/unibrain1/elanregistry/issues/1162) — refactor: eliminate car_user junction table — replace with direct cars.user_id queries
- [#1168](https://github.com/unibrain1/elanregistry/issues/1168) — refactor: tighten Car facade return types and narrow CarRepository generic method signatures
- [#1247](https://github.com/unibrain1/elanregistry/issues/1247) — refactor: narrow CarRepository::insert() and ::update() to car-specific method signatures *(consolidated into #1168)*
- [#1169](https://github.com/unibrain1/elanregistry/issues/1169) — refactor: introduce TransferStatus backed enum for car_transfer_requests status values
- [#1254](https://github.com/unibrain1/elanregistry/issues/1254) — chore: add composer install and phinx migrate to deployment hooks; single self-configuring hook replaces prod/test variants; swap ElanRegistryAutoloader for vendor/autoload.php
- [#1272](https://github.com/unibrain1/elanregistry/issues/1272) — fix: update AddForeignKeyConstraints migration to handle prod column type mismatches and add 13 missing indexes
- [#1273](https://github.com/unibrain1/elanregistry/issues/1273) — chore: migrate logs table from MyISAM to InnoDB on dev and prod
- [#1279](https://github.com/unibrain1/elanregistry/issues/1279) — fix: drop cars.user_id FK to eliminate race condition with after_user_deletion.php hook
- [#1274](https://github.com/unibrain1/elanregistry/issues/1274) — chore: migrate fix_script_runs to utf8mb4 charset and enforce completed_at NOT NULL on all environments
