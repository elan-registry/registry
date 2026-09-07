<!-- markdownlint-disable MD013 MD058 MD060 MD022 -->

# Database Schema Documentation

## Overview

**Database**: `unibrain_registry`  
**MySQL Version**: 8.0.39+  
**Character Set**: UTF-8/Latin1 (mixed)

### Core Components

- **User Management**: `users`, `profiles` tables with authentication and
  geographic data
- **Car Registry**: `cars`, `cars_hist` tables with comprehensive vehicle
  records and audit trails
- **Ownership Transfers**: `car_transfer_requests` table for self-service
  ownership transfer workflow
- **Factory Data**: `elan_factory_info` reference table for Lotus Elan
  specifications
- **System Tables**: `audit`, `country`, `fix_script_runs` for system operations
  and reference data

## Changing the schema — use Phinx

**Every structural change goes through a Phinx migration.** Do not hand-edit
the schema by any other route, and do not use a FIX script for DDL — schema changes
run at deploy time via CLI, not through a web-accessible page. See
[ADR-009](adr/ADR-009-use-phinx-for-database-schema-migrations.md) for why.

```bash
vendor/bin/phinx create AddApiTokenToUsers   # scaffold a new migration
composer migrate:status                      # what is applied vs pending
composer migrate:dry-run                     # preview SQL without applying
composer migrate                             # apply pending migrations
composer migrate:rollback                    # revert the most recent migration
```

Migrations live in `database/migrations/`, named
`YYYYMMDDHHMMSS_snake_case_description.php`, and extend
`Phinx\Migration\AbstractMigration`. Applied versions are tracked in the
[`phinxlog`](#phinxlog---phinx-migration-tracking) table — never edit it by hand.

**`change()` vs `up()`/`down()`:** use `change()` for operations Phinx can
auto-reverse (create table, add column, add index). Use explicit `up()` and
`down()` when it cannot — `DROP TABLE`, `DROP TRIGGER`, or any data
transformation. `20260711000000_drop_car_user_tables.php` is the reference
example: it drops tables in `up()` and recreates them from the original DDL in
`down()`.

**Migrations run automatically on deploy.** The server-side `post-receive` hook
runs `composer install` then `phinx migrate` on every push to `test` and `prod`.
A failed migration halts the deployment — fix the migration and push again
rather than patching the database by hand.

Two cautions learned from `drop_car_user_tables`:

- **Do all reads before any DDL.** MySQL implicitly commits on DDL, so an
  exception thrown after the first `DROP` leaves the schema half-changed.
- **Reconcile drift before dropping a table.** That migration found two
  production rows where the junction table and `cars.user_id` disagreed, and
  had to resolve them before the drop was safe.

For the full workflow, see
[`database/migrations/README.md`](../../database/migrations/README.md).

## Database Schema

### User Management

#### `users` - Primary user accounts
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `email` | `varchar(155)` | User email, NOT NULL, INDEX |
| `username` | `varchar(255)` | Display username |
| `password` | `varchar(255)` | Encrypted password |
| `fname`, `lname` | `varchar(255)` | First and last name |
| `permissions` | `int` | Permission level |
| `join_date` | `datetime` | Registration date |
| `last_login` | `datetime` | Last login timestamp |
| `email_verified` | `tinyint` | Email verification status |
| `active` | `int` | Account active status |
| `language` | `varchar(15)` | User language preference |

#### `profiles` - Extended user information
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `user_id` | `int` | Foreign key to `users.id` |
| `city`, `state`, `country` | `varchar(100)` | Location information |
| `lat`, `lon` | `float` | Geographic coordinates |
| `bio` | `text` | User biography |
| `website` | `varchar(100)` | Personal website |

### Car Registry

#### `cars` - Vehicle records

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `ctime`, `mtime` | `datetime` | Creation and modification times; `mtime` is `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (the `ON UPDATE` clause is deliberate — see verification system) |
| `vericode` | `varchar(32)` | Verification code |
| `last_verified` | `datetime NULL` | Last verification date |
| `model` | `varchar(30)` | Car model (Elan) |
| `series` | `varchar(12)` | Car series (S1, S2, S3, S4, +2, Sprint) |
| `variant` | `varchar(15)` | Car variant |
| `year` | `SMALLINT UNSIGNED NULL` | Manufacturing year (1963–1974) |
| `type` | `char(3)` | Vehicle type code |
| `chassis` | `varchar(15)` | Chassis number (INDEXED) |
| `chassis_override` | `TINYINT(1) NOT NULL DEFAULT 0` | Flag indicating whether chassis validation was overridden by the user. Set to `1` when validation was overridden; `0` for normal/valid chassis. |
| `color` | `varchar(25)` | Vehicle color |
| `engine` | `varchar(15)` | Engine specification |
| `purchasedate`, `solddate` | `date` | Purchase and sale dates |
| `comments` | `mediumtext` | Additional notes and history |
| `image` | `mediumtext` | Legacy image field (deprecated) |
| `user_id` | `int` | Primary owner user ID |
| `email`, `fname`, `lname` | `varchar(155)` | Owner contact info (synced as of v2.30.1) |
| `join_date` | `datetime` | Owner join date (set at car creation only — never synced) |
| `city`, `state`, `country` | `varchar(100)` | Owner location (synced, INDEXED) |
| `lat`, `lon` | `float` | Geographic coordinates (synced) |
| `website` | `varchar(100)` | Owner website (synced as of v2.30.1) |
| `owner_last_updated` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` | Timestamp of owner's last action on this car (used for verification system); **has no `ON UPDATE` clause** — this absence is deliberate to prevent any write from resetting the verification clock |
| `vericode_sent_at` | `datetime NULL` | Timestamp when verification code was sent to owner |
| `email_bounced` | `TINYINT(1) NOT NULL DEFAULT 0` | Flag indicating whether verification emails bounced. Set to `1` if email failed; `0` if deliverable or not yet tested. |

**Note**: Nine owner-related fields — `email`, `fname`, `lname`, `city`,
`state`, `country`, `lat`, `lon`, `website` — are denormalized onto `cars`
for performance and are kept current via `Owner::syncOwnerFieldsToCars()`,
which runs whenever the owner edits their profile. Before v2.30.1, only the
five location fields (`city`, `state`, `country`, `lat`, `lon`) synced this
way; `email`, `fname`, `lname`, and `website` were written once at car
creation (`website` was not even set then — it started `null`) and never
refreshed. `join_date` is **not** in this sync and is still set only at car
creation.

#### `cars_hist` - Car audit trail
| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY |
| `operation` | `varchar(32)` | Operation type (INSERT/UPDATE/DELETE) |
| `car_id` | `int UNSIGNED` | Original car ID |
| `timestamp` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` | Change timestamp (INDEXED as `idx_cars_hist_timestamp`) |
| *(All car columns)* | | Mirror of `cars` table structure including `chassis_override`, `owner_last_updated`, `vericode_sent_at`, and `email_bounced`. `year` is `SMALLINT UNSIGNED NULL` to match cars. `ctime` and `mtime` are `datetime NULL`. The nullability asymmetry against `cars.mtime` (`NOT NULL`) is deliberate: history rows preserve whatever the source row held, while `cars.mtime` is live data with `ON UPDATE CURRENT_TIMESTAMP`. |

> #### Removed: `car_user` and `car_user_hist`
>
> These tables were **dropped in v2.26.2** by migration
> `20260711000000_drop_car_user_tables.php` (issue #1162). Do not write queries
> against them.
>
> `car_user` was a junction table recording ownership as `(userid, car_id)`
> rows, but ownership is already authoritative on `cars.user_id`. Because
> `car_user` had no foreign key back to `cars` or `users`, the two
> representations drifted — rows pointed at cars whose `cars.user_id` said
> something different, producing inconsistent owner data in reports.
>
> **Ownership is a single column: `cars.user_id`.** There is no many-to-many
> car sharing. Every JOIN that went through the removed junction table was
> rewritten to read `cars.user_id` directly, and its audit history and triggers
> were dropped with it. Ownership changes are audited in `cars_hist` instead —
> see [Audit trail triggers](#audit-trail-triggers).

#### `car_transfer_requests` - Ownership transfer workflow

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `existing_car_id` | `int UNSIGNED` | Car being transferred (INDEXED) |
| `requested_by_user_id` | `int` | User requesting transfer (INDEXED) |
| `request_date` | `timestamp` | Transfer request date (INDEXED) |
| `status` | `enum` | pending, approved, denied, completed, expired (INDEXED) |
| `security_token` | `varchar(64)` | Unique security token (UNIQUE) |
| `expires_at` | `timestamp` | Token expiration time (INDEXED) |
| `admin_notes` | `text` | Administrative notes |
| `current_owner_response_date` | `timestamp` | Owner response timestamp |
| `completed_date` | `timestamp` | Transfer completion date |
| `denial_reason` | `text` | Reason for denial |
| `submitted_*` | various | Submitted car data fields (15 fields) |
| `created_by` | `int` | User who created request (INDEXED) |
| `modified_date` | `timestamp` | Last modification date |

**Note**: This table implements the self-service ownership transfer workflow,
storing both the transfer request metadata and a snapshot of all submitted car
data for verification and potential updates.

### Factory Reference Data

#### `elan_factory_info` - Lotus Elan factory specifications

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `year`, `month` | `varchar(4)`, `varchar(2)` | Manufacturing date |
| `batch` | `varchar(4)` | Production batch |
| `type` | `varchar(2)` | Vehicle type code |
| `serial`, `suffix` | `varchar(5)`, `varchar(1)` | Serial number and suffix |
| `engineletter` | `varchar(3)` | Engine letter code |
| `enginenumber` | `varchar(10)` | Engine number |
| `gearbox` | `varchar(1)` | Gearbox type code |
| `color` | `varchar(256)` | Factory original color |
| `builddate` | `date` | Build/invoice date |
| `note` | `mediumtext` | Additional notes and documentation |

#### `car_models` - Lotus Elan model definitions and year ranges

**Purpose**: Reference table for Lotus Elan model types extracted from cardefinition.js

**Source**: Extracted from `/app/assets/js/cardefinition.js` MENU array

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int unsigned` | PRIMARY KEY, AUTO_INCREMENT |
| `year_available_from` | `int` | First production year (1963-1974) |
| `year_available_to` | `int` | Last production year (1963-1974) |
| `display_name` | `varchar(100)` | Full display name from cardefinition.js |
| `human_readable_short` | `varchar(50)` | Short name without parenthetical |
| `series` | `varchar(15)` | Series identifier (S1, S2, S3, S4, Sprint, +2, etc.) |
| `variant` | `varchar(20)` | Body style (Roadster, FHC, DHC, Federal, Race) |
| `type_code` | `char(3)` | Lotus type code (26, 36, 45, 50, 26R) |
| `model_value` | `varchar(50)` | Composite key "series\|variant\|type" (UNIQUE) |
| `series_normalized` | `varchar(15)` | GENERATED: Normalized series (strips SE/S/E/Race) |

**Indexes**:

- `unique_model_combo` (series, variant, type_code) - Enforce model uniqueness
- `idx_year_range` (year_available_from, year_available_to) - Year range filtering
- `idx_series_normalized` (series_normalized) - Filtering by normalized series
- `idx_type_code` (type_code) - Filtering by Lotus type code

**Populated By**: Fix script `app/admin/scripts/fix/26-Load-Car-Models.php`

**Accessed Via**: `ElanRegistry\Reference\CarModel` class

**Example Records**:

```text
id=1, years=1963-1964, series="S1", variant="Roadster", type_code="26", model_value="S1|Roadster|26"
id=5, years=1971-1974, series="S4", variant="FHC", type_code="36", model_value="S4|FHC|36"
```

**Used By**:

- Issue #298-1: Factory Colors normalization (series filtering)
- Issue #298-3: Series normalization (model-based filtering)
- Issue #298-4: Color suggestion API (model-based color filtering)
- Phase 2: Dynamic model dropdowns (replacing hardcoded cardefinition.js)

### System Tables

#### `audit` - UserSpice audit logging

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `user` | `int` | User ID who performed action |
| `page` | `varchar(255)` | Page or action performed |
| `timestamp` | `timestamp` | Action timestamp |
| `ip` | `varchar(255)` | IP address of user |
| `viewed` | `int(1)` | View status flag |

#### `country` - Country reference data

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `name` | `varchar(100)` | Country name |

#### `fix_script_runs` - Database maintenance tracking

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `script_name` | `varchar(255)` | Name of FIX script executed |
| `run_date` | `timestamp` | Execution timestamp |

#### `phinxlog` - Phinx migration tracking

Phinx's own tracking table. It stores one row per applied migration, recording
the migration version, name, and start/end timestamps. Phinx creates and
maintains this table automatically; **do not modify it manually.** Schema
migrations live in `database/migrations/` — see
[`database/migrations/README.md`](../../database/migrations/README.md).

| Column | Type | Description |
|--------|------|-------------|
| `version` | `bigint` | Migration version (the `YYYYMMDDHHMMSS` timestamp prefix) |
| `migration_name` | `varchar(100)` | Migration class name |
| `start_time` | `timestamp` | When the migration started |
| `end_time` | `timestamp` | When the migration completed |
| `breakpoint` | `tinyint(1)` | Rollback breakpoint flag |

## Database Relationships

### Primary Relationships

- **Users ↔ Profiles**: One-to-one relationship
  (`users.id` → `profiles.user_id`)
- **Users ↔ Cars**: One-to-many direct ownership
  (`users.id` → `cars.user_id`)
- **Cars → History**: One-to-many audit trail (`cars.id` → `cars_hist.car_id`)

### Enforced Foreign Key Constraints

The following foreign keys are enforced at the database level. They were added
by the Phinx migration
`database/migrations/20260709202522_add_foreign_key_constraints.php`.

- `cars.user_id → users.id` **ON DELETE SET NULL** (constraint
  `fk_cars_user_id`) — deleting a user leaves the car record intact with a
  null owner rather than deleting the car.
- `car_transfer_requests.existing_car_id → cars.id` **ON DELETE CASCADE**
  (constraint `fk_transfer_existing_car`) — deleting a car removes its
  associated transfer requests.

### Data Access Patterns

**Note**: This database no longer uses views. All data access is performed through direct queries or the application layer. For combined user and profile data, use `(new Owner($userId))->data()` — `getUserWithProfile()` was removed in v2.26.2 (#1148).

## System Features

### Database Triggers

**Car Audit Triggers** (implemented):

- `cars_insert`: Automatically logs new car registrations to `cars_hist` table
- `cars_update`: Logs car modifications to `cars_hist` table with bypass via
  `@disable_triggers` variable
- `cars_delete`: Logs car deletions to `cars_hist` table

**Trigger Details**:

- All triggers capture complete car record snapshots including owner data, `chassis_override`, and verification system columns (`owner_last_updated`, `vericode_sent_at`, `email_bounced`)
- All triggers use current schema (no deprecated columns); `chassis_override` added in #915, verification columns added in #1155
- Each trigger records operation type (INSERT/UPDATE/DELETE) and timestamp
- The `cars_update` trigger captures pre-update values (via `OLD.*`) for all columns except `chassis_override`, which captures the new value (`NEW.chassis_override`) — this asymmetry is deliberate and preserves the audit trail semantics

**Car-User Relationship Triggers** — removed in v2.26.2 along with the
`car_user` / `car_user_hist` tables (issue #1162). Ownership changes are audited
through the `cars` triggers above.

### Special System Accounts

- **`noowner` (ID: 83)**: Fallback owner for cars when users are deleted
  (GDPR compliance)
- **`admin` (ID: 1)**: Primary administrative account

**Note**: The `noowner` user is located dynamically by username, not hardcoded
ID.

### User Deletion & GDPR Compliance

**Cleanup Process** (`/usersc/scripts/after_user_deletion.php`):

1. Remove orphaned `profiles` records
2. Transfer car ownership to the `noowner` user (preserves registry data)
3. Expire any non-terminal transfer requests the user initiated
4. All changes automatically logged via database triggers

**Maintenance Utilities** (`/app/admin/scripts/fix/02-Cleanup-Orphaned-Profiles.php`):

- Cleanup orphaned profiles and relationships
- Reassign ownerless cars to `noowner`
- Real-time progress reporting

**Backups hold personal data and credentials** (#1714): `BackupManager` dumps
every base table in the schema, so a backup captures `users`, `profiles`,
`users_session`, `us_ip_list`, `logs` and `audit`. It also covers UserSpice's
auth tables — `us_totp_secrets`, `us_passkeys`, `us_oauth_server_tokens`,
`us_oauth_client_login_tokens` — which makes a backup file a **credential**
store, not just a PII store. Those four features are currently disabled
(`settings.passkeys`/`totp`/`oauth_server`/`oauth` are all `0`) and their tables
are empty, so only session rows are live today; enabling any of them widens what
every backup contains, so treat backup files accordingly if one is turned on. Deletion purges live rows but does not rewrite
existing backup files, so an erasure request is fully satisfied only once the
backups covering that user age out. That window is bounded and self-purging —
`BACKUP_RETENTION_*` in `usersc/includes/config.php` sets 7 days for automated
backups and 30 for manual/rollback. `backups/` is blocked from web access by
`.htaccess`, and no code path restores from a backup file (account restore reads
the in-database `deleted_accounts_archive` table instead).

Backing up everything is deliberate: a backup that omits tables cannot restore
the database. Before narrowing the set, note that #1714 was caused by two of
three hardcoded table lists drifting apart — derive any subset, never
hand-maintain one.
