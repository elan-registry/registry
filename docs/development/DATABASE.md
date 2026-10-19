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
- **System Tables**: `audit`, `fix_script_runs` for system operations

## Database Naming Conventions

### Table Naming

**ElanRegistry-owned tables created from v2.30.2 onwards use the `er_` prefix.** This convention establishes a clear separation between project-owned tables and upstream UserSpice tables.

**Why:** The existing registry tables (`cars`, `car_transfer_requests`, `deleted_accounts_archive`, `fix_script_runs`) have no consistent naming prefix. `elan_factory_info` is an accidental exception. Since the project continues to add tables — particularly system configuration and workflow tables that will be added alongside UserSpice's own `settings` and utility tables — a namespace prefix prevents future collisions and makes it obvious at a glance which tables belong to the application logic vs. which are upstream.

**Existing tables are not being renamed.** This convention applies only to **new tables created from v2.30.2 onward.**

**Examples:**

- `er_verification_settings` (v2.30.2) — single-row gate for the verification system feature switch
- `er_email_events` (v2.30.2) — durable log of inbound Brevo delivery-status webhook events (#1887)
- `er_cron_job_runs` (v2.30.2) — generic "when did this job last run" table for `CronJobGuard`, replacing per-job bespoke settings columns (#2034)
- Any future feature-config, workflow state, or application-owned table created after this issue

**Upstream tables (not renamed):** `settings`, `users`, `users_session`, `us_*`, etc. (UserSpice), and `cars`, `car_transfer_requests`, `deleted_accounts_archive`, `elan_factory_info`, `car_models`, `fix_script_runs` (pre-existing project tables).

---

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
| `email_opted_out` | `TINYINT(1) NOT NULL DEFAULT 0` | The owner asked to stop all registry email except account mail (password reset, sign-in, email change) (#1883, #2360). Renamed from `email_suppressed` in #2101. Set by the opt-out link in the verification email. Cleared by Resume (owner) and by Clear Suppression (admin). |
| `email_opted_out_at` | `datetime NULL` | When the owner opted out. NULL when the flag is 0. Can also be NULL for a flag that the #2101 migration copied, when no `EMAIL SUPPRESSED` history row dates it. |
| `email_blocked` | `TINYINT(1) NOT NULL DEFAULT 0` | Brevo will not deliver to `email_blocked_address` (spam complaint, unsubscribe, or blocklist) (#2101). Set by delivery events and the suppression import. An admin clears it, and a confirmed change to another address clears it. |
| `email_blocked_address` | `varchar(155) NULL` | The address that is blocked. Stored normalized (lower case). Never empty while `email_blocked = 1`. |
| `email_blocked_at` | `datetime NULL` | When the block happened. |
| `email_blocked_cause` | `varchar(16) NULL` | `spam`, `unsubscribed`, `blocklist`, or NULL when the cause is unknown. |
| `email_bounced` | `TINYINT(1) NOT NULL DEFAULT 0` | The address in `email_bounced_address` bounced (#1884, #2101). Set by a hard bounce, by soft-bounce escalation, and by the admin Mark Bounced action. |
| `email_bounced_address` | `varchar(155) NULL` | The address that bounced. Stored normalized (lower case). |
| `email_bounced_at` | `datetime NULL` | When the bounce happened. Every path that sets `email_bounced` also sets it (#2320). The one exception is a flag that the #2101 migration copied with no dated event or `EMAIL BOUNCED` row: its date is NULL. |
| `email_bounce_cleared_at` | `datetime NULL` | When the bounce was last cleared. Soft bounces before this time do not count toward a new escalation. |
| `email_address_since` | `datetime NULL` | When the owner's current address became the address of record. Set to `users.join_date` at sign-up and to the restore time at restore. |
| `email_address_seen` | `varchar(155) NULL` | The normalized address that `email_address_since` applies to. |

`user_id` has a unique key, `uniq_profiles_user_id`, so an owner has at most
one `profiles` row.

**System of record (#2101).** The `profiles` row is the only place that stores
the three deliverability flags: `email_opted_out`, `email_blocked` and
`email_bounced`. No `cars` column holds a flag. A flag therefore applies to
every car of the owner, including a car that the owner registers later. An
owner with no `profiles` row gets no verification email, and the admin queue
shows the state "no profile". Account mail (for example password reset) is
never blocked by a flag.

Each flag change writes one `logs` row with `logtype` `EmailBounced`
(`LogCategories::LOG_CATEGORY_EMAIL_BOUNCED`). The
`logs.metadata` JSON holds `owner_id`, `flag`, `old`, `new` and `source`. The
row holds no email address. `profiles` has no history table, so these `logs`
rows are the audit trail. See `DeliverabilityFlagLog` in
[CLASSES.md](CLASSES.md). The migration is in
`database/migrations/20261012000000_move_deliverability_flags_to_profiles.php`.

### Car Registry

#### `cars` - Vehicle records

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `ctime`, `mtime` | `datetime` | Creation and modification times; `mtime` is `NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (the `ON UPDATE` clause is deliberate — see verification system) |
| `vericode` | `varchar(64)` | Verification code (HMAC-SHA256 hashed; see CarVerificationManager) |
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
| `verification_attempts` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | Count of verification emails sent to this car's owner in the rolling 1-year window (#1884). Resets when `verification_attempts_since` rolls past 1 year; used to cap sending frequency. |
| `verification_attempts_since` | `datetime NULL` | Start of the rolling 1-year window for `verification_attempts` (#1884). `NULL` until the first send; reset to now when `verification_attempts_since` exceeds 1 year old. |

**Note**: `cars` has no email deliverability column. Since #2101 the flags
live on `profiles` (see above). The old columns `email_bounced`,
`email_bounced_address` and `email_suppressed` are dropped.

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
| `operation` | `varchar(32)` | Operation type (INSERT/UPDATE/DELETE/VERIFIED/VERIFIED SOLD). Since #2101 no code writes the operations `EMAIL SUPPRESSED`, `EMAIL BOUNCED`, `EMAIL BOUNCE CLEARED`, `EMAIL SUPPRESSION CLEARED` and `SUPPRESSION CLEARED BY OWNER`. Old rows with those values stay in the table. The audit trail for flag changes is now in `logs`. |
| `car_id` | `int UNSIGNED` | Original car ID |
| `timestamp` | `datetime NOT NULL DEFAULT CURRENT_TIMESTAMP` | Change timestamp (INDEXED as `idx_cars_hist_timestamp`) |
| *(All car columns)* | | Mirror of `cars` table structure including `chassis_override`, `owner_last_updated`, `vericode_sent_at`, `verification_attempts`, `verification_attempts_since`. `year` is `SMALLINT UNSIGNED NULL` to match cars. `ctime` and `mtime` are `datetime NULL`. The nullability asymmetry against `cars.mtime` (`NOT NULL`) is deliberate: history rows preserve whatever the source row held, while `cars.mtime` is live data with `ON UPDATE CURRENT_TIMESTAMP`. |

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

#### `er_email_events` - Brevo delivery-status event log (#1887, #2358, #2359)

Each event belongs to the user (the owner), not to one car. The table has no
audit trigger and no FK. This codebase has no FK constraints on car-adjacent
tables. `cars.user_id`'s own FK was dropped in
`20260719120000_drop_cars_user_id_fk.php`.

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int UNSIGNED` | PRIMARY KEY, AUTO_INCREMENT |
| `user_id` | `int NOT NULL` | Owner the event applies to. Added by `20261013000000_key_er_email_events_by_user.php` (#2358). |
| `car_id` | `int NULL` | Car of the row. A `sent` row has the car that it starts a send cycle for. A webhook or reconciliation row has the car of the matched verification `sent` row (step (a) of the matcher), or NULL when the match came from the plugin row (step (b)). A suppression-import row has `car_id` NULL. Rows written before the #2358 migration keep their `car_id` until the prune deletes them. A collapsed row can carry the `car_id` of another car of the owner. The chip still finds it by its message ID. A car delete also sets `car_id` to NULL. |
| `email` | `varchar(155) NOT NULL` | Recipient address, stored normalized (trimmed, lower case) (INDEXED). The prune sets it to `''` on a car's latest `sent` row only when the row is older than 90 days. |
| `event` | `varchar(32) NOT NULL` | Brevo event name (`hard_bounce`, `soft_bounce`, `blocked`, `invalid`, `spam`, `delivered`, `unique_opened`, …) or the local `sent` event |
| `reason` | `text NULL` | Optional reason/detail text from the payload (e.g. why a bounce occurred). The applier cuts it to 255 characters. Absent for events like `spam`. |
| `brevo_message_id` | `varchar(255) NOT NULL DEFAULT ''` | Brevo's message id for the send this event applies to. A `sent` row has the real Brevo message id, or a local id (`local:` followed by the SHA-256 of the new vericode) when the mailer gave no new id (#2359). A suppression-import row has a synthetic id (`suppression-import-` followed by an MD5 hash). **`NOT NULL DEFAULT ''`, not nullable, is correctness-critical**: MySQL treats every `NULL` as distinct in a unique index, so a nullable column would silently defeat the unique index below for exactly the rows that need dedup most. |
| `occurred_at` | `datetime NOT NULL` | When the event occurred, per the payload's `ts_event`/`ts` field. The receipt time replaces a missing or invalid value, and a value more than 5 minutes after receipt (#2359) |

**Indexes:**

- `UNIQUE (user_id, brevo_message_id, event)` is the dedup key. A retried
  or duplicate delivery of the same event lands as one row. The write is
  `INSERT ... ON DUPLICATE KEY UPDATE er_email_events.id = er_email_events.id`
  (#2359). The first write wins. A duplicate changes no column, so a
  `delivered` row that resets the soft-bounce window does not move. The
  statement is a no-op for a duplicate. The connection does not set
  `MYSQL_ATTR_FOUND_ROWS`, so the affected-row count is 1 for a new row and 0
  for a duplicate. The writer uses this count to tell whether an event is new.
  The column name has the table prefix, because the `INSERT ... SELECT` source
  also has an `id` column.
- `(user_id, occurred_at)`: no current query is known to use this index.
  #2359 decides which lookups it needs.
- `(brevo_message_id)` serves the message lookup.
- `(car_id, occurred_at)` and `(email)` stay from the earlier schema.

**Owner-level rows.** An event is stored once for the owner, whatever the
number of the owner's cars. A step (a) match gives the row the car of the
`sent` row. A step (b) match gives `car_id` NULL. A `sent` row starts its
car's send cycle. The migration does not clear `car_id`, so a row written
before it keeps its car until the prune deletes it. After a car's cycle start,
the chip counts a row with the same `car_id`, a row with the same
`brevo_message_id` as the car's `sent` row, and a row with `car_id` NULL only
when its event is a hard bounce or a suppression event. Any other row of
another car of the owner does not count. So a row that the migration collapsed
under another car's `car_id` still shows on the car whose message it is for. See
[EMAIL_SYSTEM.md, Event recording](EMAIL_SYSTEM.md#event-recording-and-the-car-chip).

**Retention.** The nightly reconciliation job prunes the table
(`CarRepository::deleteEmailEventsOlderThan()`):

| Rows | Kept for |
|------|----------|
| Terminal events (`hard_bounce`, `blocked`, `invalid`, `invalid_email`, `spam`, `unsubscribed`) | 24 months |
| Every other row (`soft_bounce`, `delivered`, `sent`, `unique_opened`, unknown names) | 90 days |
| The latest `sent` row of each car, when its `user_id` is the car's current owner | Kept. Its `email` is set to `''` only when the row is older than 90 days. |

The latest `sent` row is the row with the latest `occurred_at` for its car.
The highest `id` breaks a tie. A `sent` row of a previous owner of the car is
not kept. It ages out after 90 days. A `sent` row with `car_id` NULL is never a
car's latest row, so the prune deletes it after 90 days. The prune runs with
the verification switch off. See
[EMAIL_SYSTEM.md, Event retention](EMAIL_SYSTEM.md#event-retention).

**Written by:** `app/api/webhooks/brevo.php` through
`BrevoWebhookEventProcessor`, the reconciliation job and the suppression
sync. All three call `EmailEventApplier::apply()` once for each matched event.
It calls `CarRepository::insertOwnerEmailEvent()` inside one transaction that
starts with a lock on the owner's `profiles` row. A `sent` row is the
exception to the car rule above: its car comes from the send. The send path writes `sent` rows
through `CarRepository::insertEmailEvent()`.

**Changed or removed by:**

- `CarAdministrationService::delete()` calls
  `CarRepository::detachEmailEventsFromCar()`. The events stay with the
  owner and `car_id` becomes NULL.
- `CarAdministrationService::merge()` calls
  `CarRepository::transferEmailEvents()`. It changes `car_id` only.
- A car transfer to a new owner changes no row. The previous owner's rows
  stay with the previous owner, and the new owner sees the car as not sent.
- `usersc/scripts/after_user_deletion.php` runs a privacy step first, in its
  own transaction. It deletes the user's `plg_sendinblue_messages` rows
  (`deleteSendinblueMessagesForUser()`), then the user's `er_email_events`
  rows (`deleteEmailEventsForUser()`), including rows with `car_id` NULL.

**Migration.** `20261013000000_key_er_email_events_by_user.php` sets
`user_id` from the car owner, or else from the one user with the same
address. It deletes rows that match no user or only `noowner`. It collapses
rows with the same user, message ID and event into one row with the earliest
`occurred_at`. `down()` restores the schema only. It deletes the rows with
`car_id` NULL and does not restore the rows that `up()` deleted or collapsed.
The database backup is the only full rollback. Run order:
[DEPLOYMENT.md](DEPLOYMENT.md#migration-that-keys-email-events-by-user-2358).

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

**Populated By**: Phinx seed `database/seeds/CarModelsSeed.php`, which loads
`database/seeds/data/car_models.csv`. Run it with
`vendor/bin/phinx seed:run -s CarModelsSeed`.

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

#### `fix_script_runs` - Database maintenance tracking

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY, AUTO_INCREMENT |
| `script_name` | `varchar(255)` | Name of FIX script executed |
| `run_date` | `timestamp` | Execution timestamp |

#### `settings` - Site-wide configuration (singleton row, `id = 1`)

Single-row config table, mostly legacy UserSpice/site-settings fields — see
the migration history in `database/migrations/` for the rest.

#### `er_verification_settings` - Verification system configuration and telemetry (singleton row, `id = 1`)

| Column | Type | Description |
|--------|------|-------------|
| `id` | `int` | PRIMARY KEY (always `1`, single row) |
| `enabled` | `boolean NOT NULL DEFAULT 0` | Feature switch; gates all verification-related sends (#1881–#1883). Admin-gated toggle on the Verification System tab; `setEnabled(true)` throws `VerificationConfigException` if Brevo is not ready. |
| `last_cron_request_at` | `datetime NULL` | Timestamp of the most recent accepted cron transport hit (#1974). Used by `VerificationSettings::cronReady()` to check if the 10-minute cron transport is responsive. `NULL` until first successful hit. |
| `unmatched_recipient_count` | `int unsigned NOT NULL DEFAULT 0` | Counter of verification-tagged Brevo events (webhook and reconciliation) of our mail whose recipient no single user holds (#1887, #2085, #2359). An event with another tag, or with no tag, does not count. The suppression import does not count. Used for operational monitoring. Incremented by the webhook receiver (`app/api/webhooks/brevo.php`) and `BrevoEventReconciliationJob`, never reset. |
| `batch_size` | `tinyint unsigned NOT NULL DEFAULT 5` | Maximum number of verification emails to send in one admin batch (#1884). Controls the preview table size in the Verification tab send-tool section and the default batch count; read via `VerificationSettings::batchSize()`. |

**Written By**: `VerificationSettings` class (all writes), via `app/api/webhooks/brevo.php`,
`BrevoEventReconciliationJob`, `BrevoSuppressionSyncJob`, admin toggle endpoint, cron transport

#### `er_cron_job_runs` - Generic cron job "last run" tracking (#2034)

Backs `CronJobGuard::claim()`'s atomic-claim guard. Replaces the earlier
`settings.reconciliation_last_run` bespoke column (#2027, dropped by the
same migration that creates this table) — more cron jobs were coming
(issues 1889 and 1885, plus others), and a bespoke-column-per-job approach
would have meant a new migration for each one.

| Column | Type | Description |
|--------|------|-------------|
| `job_name` | `varchar(64)` | PRIMARY KEY. Must be in `CronJobGuard::ALLOWED_JOB_NAMES` for `claim()` to act on it — the allowlist and this table's seeded rows must stay in sync. |
| `enabled` | `boolean NOT NULL DEFAULT true` | Lets an operator pause a single job without touching UserSpice's own `crons` table (which only supports add/delete, not pause). `claim()`'s own query requires `enabled = 1`; a disabled job's claim silently no-ops the same way a too-recent claim does. |
| `last_run_at` | `datetime NULL` | Last claimed run. `NULL` means never run. Stamped by `CronJobGuard::claim()` *before* the job's `execute()` runs (it must, to block a concurrent claim), so on its own this column only records "a run was claimed," never "a run succeeded." Written only via `CronJobGuard`'s atomic UPDATE, never directly. |
| `last_failure_at` | `datetime NULL` | (#2148) When the job's `execute()` last threw. `NULL` means it never has. `CronJobRunsReader::badgeFor()` compares this against `last_run_at` (`last_failure_at >= last_run_at`) to render a distinct "Last run failed" badge — without this column, a job crashing every night is indistinguishable from a healthy one, since `last_run_at` alone reads identically either way. A later successful run naturally supersedes an earlier failure with no explicit reset step. Written only via `CronJobGuard::recordFailure()`, called from `AbstractCronJob::run()`'s catch block. |
| `created_at` | `datetime NOT NULL` | Set at seed/registration time. Distinguishes "registered, never run" (row present, `last_run_at NULL`) from "not a registered job at all" (no row) — a bare `job_name`/`last_run_at` pair can't tell those apart once a row exists. |
| `last_skip_logged_at` | `datetime NULL` | (#1889) Rate-limits `AbstractCronJob`'s disabled-job skip log to roughly once per guard interval. Deliberately a separate column from `last_run_at`: a disabled job never reaches `CronJobGuard::claim()`, so `last_run_at` stays frozen for as long as the job stays paused, and any throttle keyed on it would degrade to logging on every hit — the #1974 pathology this exists to avoid. Written only by `AbstractCronJob`, immediately after the skip line actually fires. |
| `last_sent_count` | `int NULL` | (#1885) Sent-car count from a job's most recent run, for admin dashboard display. `NULL` until the job's `execute()` first records a run — distinct from a recorded run that sent zero, which is `0`. Generic/job-agnostic naming; not scoped to one job, though `SendVerificationBatchJob` is the only writer so far. Read via `CronJobRunsReader::lastOutcomeCounts()`. |
| `last_skipped_count` | `int NULL` | (#1885) Skipped-car count from a job's most recent run. Same NULL-until-first-run semantics as `last_sent_count`. |
| `last_failed_count` | `int NULL` | (#1885) Failed-car count from a job's most recent run — for `SendVerificationBatchJob` specifically, this also absorbs the `unrecorded` bucket (an email sent successfully but whose bookkeeping write failed), a deliberate conservative merge documented on `SendVerificationBatchJob::execute()`. Same NULL-until-first-run semantics as `last_sent_count`. |

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

### No Enforced Foreign Key Constraints

`cars.user_id → users.id` is **not** enforced at the database level. A FK
(`fk_cars_user_id`, `ON DELETE SET NULL`) was added by
`database/migrations/20260709202522_add_foreign_key_constraints.php`, then
deliberately dropped by
`database/migrations/20260719120000_drop_cars_user_id_fk.php`: its `ON DELETE
SET NULL` cascade fired during `DELETE FROM users` and nulled `cars.user_id`
before `usersc/scripts/after_user_deletion.php`'s reassignment hook ran, so
the hook always saw 0 cars to reassign to the `noowner` account. No FK is
planned as a replacement — the index on `user_id` (also named
`fk_cars_user_id`) was retained for query performance, but nothing enforces
referential integrity on this column. Application code that reads
`cars.user_id` must account for it pointing at a user row that no longer
exists (see `car_id` in the `er_email_events` table above for the equivalent
statement about car-adjacent tables generally).

### Data Access Patterns

**Note**: This database no longer uses views. All data access is performed
through direct queries or the application layer. For combined user and profile
data, use `(new Owner($userId))->data()`.

## System Features

### Database Triggers

**Car Audit Triggers** (implemented):

- `cars_insert`: Automatically logs new car registrations to `cars_hist` table
- `cars_update`: Logs car modifications to `cars_hist` table with bypass via
  `@disable_triggers` variable
- `cars_delete`: Logs car deletions to `cars_hist` table

**Trigger Details**:

- All triggers capture complete car record snapshots including owner data, `chassis_override`, and verification system columns (`owner_last_updated`, `vericode_sent_at`, `verification_attempts`, `verification_attempts_since`)
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

1. Run the privacy step first, in its own transaction. It locks the user's
   `profiles` row with `SELECT ... FOR UPDATE`, and reads the user's addresses.
   It deletes the user's `plg_sendinblue_messages` rows
   (`deleteSendinblueMessagesForUser()`), then the user's `er_email_events`
   rows (`deleteEmailEventsForUser()`), then the user's `profiles` row. The
   profile lock is the lock that the event step takes first, so no event
   row can arrive between the address read and the deletes. If the step
   fails, it rolls back, logs a CRITICAL line, and the hook continues with
   steps 2 and 3. The personal data then remains. See
   [EMAIL_SYSTEM.md, Event recording](EMAIL_SYSTEM.md#event-recording-and-the-car-chip)
   for the known gaps.
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

`deleted_accounts_archive` keeps `email_opted_out` (`TINYINT(1) NOT NULL
DEFAULT 0`) and `email_opted_out_at` (`datetime NULL`) from the owner's
profile (#2101). A restore puts them on the new `profiles` row, so a restore
does not enable mail to an owner who opted out. The archive does not keep
`email_blocked` or `email_bounced`. A restore loses a bounce from soft-bounce
escalation or Mark Bounced. Brevo keeps its own block on the address, so the
next send to a blocked address returns a `blocked` event that sets
`email_bounced` again.

Backing up everything is deliberate: a backup that omits tables cannot restore
the database. Before narrowing the set, note that #1714 was caused by two of
three hardcoded table lists drifting apart — derive any subset, never
hand-maintain one.
