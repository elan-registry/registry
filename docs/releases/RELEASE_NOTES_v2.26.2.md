# Elan Registry v2.26.2 Release Notes

**Release Date:** [DATE]
**Type:** Patch Release - DB Infrastructure & Raw SQL Migration

## Required Actions After Deployment

1. Run pending database migrations:
   ```bash
   composer migrate
   ```
   Migration in this release: FK constraints on `cars.user_id` and `car_transfer_requests.existing_car_id`; also fixes `expires_at` zero-date default to `NULL DEFAULT NULL`.
2. Verify no orphaned rows in `car_transfer_requests` before deploying (the FK migration will fail if `existing_car_id` references a non-existent car).
3. After migrations run, confirm with `composer migrate:status` (all migrations should show `up`).

## User-Facing Changes

None — all changes in this release are internal infrastructure and refactoring.

## Admin-Facing Changes

### New Features

- **Phinx migration infrastructure** ([#693](https://github.com/unibrain1/elanregistry/issues/693)): Formal database migration runner using [Phinx](https://phinx.org). Migrations in `database/migrations/` are tracked in `phinxlog`, run via `composer migrate`, and are safe for CI/CD automation. The admin dashboard shows a warning banner when pending migrations exist. First migration enforces FK constraints on `cars.user_id` and `car_transfer_requests.existing_car_id`.

### Improvements

- **Year sorting** ([#1161](https://github.com/unibrain1/elanregistry/issues/1161)): `cars.year` stored as `SMALLINT` instead of `VARCHAR` — corrects sort order in any admin views that order by year.

## Issues Resolved

- [#693](https://github.com/unibrain1/elanregistry/issues/693) — feat: create database migration runner infrastructure and apply first FK constraint migration
- [#1148](https://github.com/unibrain1/elanregistry/issues/1148) — refactor: complete raw-SQL migration for remaining files not covered by #962
- [#1153](https://github.com/unibrain1/elanregistry/issues/1153) — chore: route user_settings.php location-sync DB writes through CarRepository / Owner *(consolidated into #1148)*
- [#1161](https://github.com/unibrain1/elanregistry/issues/1161) — fix: correct cars.year column type to SMALLINT and drop unused ModifiedBy column
- [#1162](https://github.com/unibrain1/elanregistry/issues/1162) — refactor: eliminate car_user junction table — replace with direct cars.user_id queries
- [#1168](https://github.com/unibrain1/elanregistry/issues/1168) — refactor: tighten Car facade return types and narrow CarRepository generic method signatures
- [#1247](https://github.com/unibrain1/elanregistry/issues/1247) — refactor: narrow CarRepository::insert() and ::update() to car-specific method signatures *(consolidated into #1168)*
- [#1169](https://github.com/unibrain1/elanregistry/issues/1169) — refactor: introduce TransferStatus backed enum for car_transfer_requests status values
