# Elan Registry v2.26.2 Release Notes

**Release Date:** 2026-07-11
**Type:** Patch Release - DB Infrastructure & Raw SQL Migration

## Required Actions After Deployment

### Before pushing

1. Bootstrap the new post-receive hook on each server (one-time — old hook doesn't self-update):
   ```bash
   scp scripts/server-hooks/post-receive \
       a2hosting:/home/unibrain/git/elanregistry.git/hooks/post-receive
   ssh a2hosting "chmod +x /home/unibrain/git/elanregistry.git/hooks/post-receive"
   ```
   After this, all future pushes are fully automated (composer install + migrations run on every push).

### After pushing

2. Migrations and `composer install` run automatically via the hook. Verify push output shows no errors and ends with:
   - `Production deployment complete for branch: main`

3. Remove the now-redundant `usersc/vendor/` directory on each server (phpdotenv moved to `vendor/`):
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

- **Phinx migration infrastructure** ([#693](https://github.com/unibrain1/elanregistry/issues/693)): Formal database migration runner using [Phinx](https://phinx.org). Migrations in `database/migrations/` are tracked in `phinxlog`, run via `composer migrate`, and are safe for CI/CD automation. The admin dashboard shows a warning banner when pending migrations exist.
- **Automated deployment hooks** ([#1254](https://github.com/unibrain1/elanregistry/issues/1254)): Post-receive hooks now run `composer install` and `phinx migrate` automatically on every push. A single shared hook script (`scripts/server-hooks/post-receive`) self-configures from the git repo path — no separate prod/test files needed. The hook self-updates from the deployed working tree on each push.

### Improvements

- **Year sorting** ([#1161](https://github.com/unibrain1/elanregistry/issues/1161)): `cars.year` stored as `SMALLINT` instead of `VARCHAR` — corrects sort order in any admin views that order by year.
- **`logs` table crash safety** ([#1273](https://github.com/unibrain1/elanregistry/issues/1273)): Converted the `logs` table from MyISAM to InnoDB on dev and prod (test was already InnoDB). Restores crash recovery, row-level locking, and transaction support for audit log writes.
- **`fix_script_runs` charset alignment** ([#1274](https://github.com/unibrain1/elanregistry/issues/1274)): Migrated `fix_script_runs` from deprecated `utf8mb3`/`utf8mb3_unicode_ci` to `utf8mb4`/`utf8mb4_unicode_ci` on dev and test (prod already correct). Ensures all environments share a consistent, future-proof charset and `completed_at NOT NULL` nullability.
- **`TransferStatus` backed enum** ([#1169](https://github.com/unibrain1/elanregistry/issues/1169)): Replaced bare-string status constants in `CarTransferRepository` with a PHP 8.1 `TransferStatus` enum. `updateStatus()` now requires a typed `TransferStatus` value — invalid statuses are caught at compile time rather than at runtime. The `isTerminal()` method replaces the `TERMINAL_STATUSES` constant array.
- **Eliminate `car_user` junction table** ([#1162](https://github.com/unibrain1/elanregistry/issues/1162)): Dropped `car_user` and `car_user_hist` tables (and their 3 audit triggers). `cars.user_id` is now the single authoritative source for car ownership — eliminates the dual-write path and drift risk that surfaced in #1160. Migration `20260711000000` auto-reconciles any ownership drift before dropping the tables. All admin queries and domain classes updated to read directly from `cars.user_id`.
- **Tighten Car facade and CarRepository types** ([#1168](https://github.com/unibrain1/elanregistry/issues/1168), [#1247](https://github.com/unibrain1/elanregistry/issues/1247)): `Car::data()` now returns `?object` (was `mixed`), `Car::owner()` returns `?array` (was `array|object`). Removed the `Car::findAll()` shortcut (callers use `CarRepository::findAll()` directly) and the null branch of `Car::find()`. `CarRepository::insert(string $table, ...)` and `update(string $table, ...)` replaced with car-specific `insertCar()` and `updateCar()` — eliminates the generic table-agnostic signatures. PHPStan now resolves exact types on all Car data access paths.
- **PHPStan coverage extended to full project** ([#1317](https://github.com/unibrain1/elanregistry/issues/1317)): Replaced the `usersc/classes/`-only config with a single `phpstan.neon` that enumerates all project-owned paths at level 5. Paths are listed explicitly so untracked upstream files in `usersc/plugins/`, `usersc/templates/`, and `usersc/widgets/` are excluded on developer machines. Pre-existing errors captured in `phpstan-baseline.neon`; `reportUnmatchedIgnoredErrors: true` enforces fix-when-you-touch-it — CI rejects stale entries as errors are resolved. See CODING_STANDARDS.md for the workflow.

## Issues Resolved

- [#693](https://github.com/unibrain1/elanregistry/issues/693) — feat: create database migration runner infrastructure (Phinx)
- [#1148](https://github.com/unibrain1/elanregistry/issues/1148) — refactor: complete raw-SQL migration for remaining files not covered by #962
- [#1153](https://github.com/unibrain1/elanregistry/issues/1153) — chore: route user_settings.php location-sync DB writes through CarRepository / Owner *(consolidated into #1148)*
- [#1161](https://github.com/unibrain1/elanregistry/issues/1161) — fix: correct cars.year column type to SMALLINT and drop unused ModifiedBy column
- [#1162](https://github.com/unibrain1/elanregistry/issues/1162) — refactor: eliminate car_user junction table — replace with direct cars.user_id queries
- [#1168](https://github.com/unibrain1/elanregistry/issues/1168) — refactor: tighten Car facade return types and narrow CarRepository generic method signatures
- [#1247](https://github.com/unibrain1/elanregistry/issues/1247) — refactor: narrow CarRepository::insert() and ::update() to car-specific method signatures *(consolidated into #1168)*
- [#1169](https://github.com/unibrain1/elanregistry/issues/1169) — refactor: introduce TransferStatus backed enum for car_transfer_requests status values
- [#1254](https://github.com/unibrain1/elanregistry/issues/1254) — chore: add composer install and phinx migrate to deployment hooks; single self-configuring hook replaces prod/test variants; swap ElanRegistryAutoloader for vendor/autoload.php
- [#1273](https://github.com/unibrain1/elanregistry/issues/1273) — chore: migrate logs table from MyISAM to InnoDB on dev and prod
- [#1274](https://github.com/unibrain1/elanregistry/issues/1274) — chore: migrate fix_script_runs to utf8mb4 charset and enforce completed_at NOT NULL on all environments
- [#1317](https://github.com/unibrain1/elanregistry/issues/1317) — tech-debt: extend PHPStan analysis to full repo with fix-when-you-touch-it enforcement
