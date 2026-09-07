# ADR-003: Implement Database Audit Trails via Triggers and History Tables

## Status

**Accepted** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry tracks ownership history and modifications to car
records over time. For a historical registry of vehicles manufactured 1963-1974,
maintaining a complete audit trail of data changes is essential for:

- **Accountability**: knowing who changed what and when
- **Historical accuracy**: preserving the provenance chain of car records
- **Data recovery**: being able to reconstruct previous states
- **Dispute resolution**: verifying ownership history during transfers
- **GDPR compliance**: documenting data handling for user deletion

The application tracks two primary business entities: the `cars` table (vehicle
records with denormalized owner data per ADR-002) and the `car_user` junction
table (car ownership relationships). Modifications to either table require
auditable snapshots for the registry's core function of documenting vehicle
provenance.

The application also needed a solution that would capture changes from all
sources: web forms, admin actions, FIX scripts, and direct SQL maintenance
operations. A purely application-level approach was ruled out because it cannot
guarantee capture when changes originate from database scripts or admin console
queries.

## Decision

Implement database audit trails using **MySQL triggers** that automatically
capture complete row snapshots into history tables on every data modification,
supplemented by application-level history writes that add business-domain
context.

### Trigger Implementation

Three AFTER triggers on the `cars` table enforce automatic capture:

1. **`cars_insert`** (AFTER INSERT) -- Fires unconditionally. Captures `NEW.*`
   snapshot (all 28 car table columns) with operation type `'INSERT'`.

2. **`cars_update`** (AFTER UPDATE) -- Fires only when `@disable_triggers IS
   NULL`. Captures `OLD.*` (pre-update state) snapshot with operation type
   `'UPDATE'`. The bypass mechanism allows bulk administrative operations to skip
   audit logging.

3. **`cars_delete`** (AFTER DELETE) -- Fires unconditionally. Captures `OLD.*`
   snapshot with operation type `'DELETE'`.

All triggers insert into `cars_hist` using explicit column lists (28 data columns
plus 4 audit metadata columns) to ensure schema alignment.

#### Bypass Mechanism: @disable_triggers Session Variable

The `@disable_triggers` session variable provides a way to suppress UPDATE
trigger firing during bulk operations:

```php
// Example: bulk geocoding operation
$db->query("SET @disable_triggers = 1");
// Run bulk updates without flooding cars_hist
foreach ($cars as $car) {
    $db->query("UPDATE cars SET geo_location = ? WHERE id = ?", [$location, $carId]);
}
$db->query("SET @disable_triggers = NULL");
```

This bypass mechanism is only used in FIX scripts for administrative database
maintenance. No production application code currently relies on this feature.

### History Table Design -- cars_hist

The `cars_hist` table mirrors the structure of the `cars` table plus audit
metadata:

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | bigint PRIMARY KEY | Auto-increment surrogate key (separate from car_id) |
| `operation` | varchar(32) NOT NULL | Operation type: INSERT, UPDATE, DELETE, NEWOWNER, MERGE, LOCATION_SYNC, OWNER_SYNC, VERIFIED, VERIFIED SOLD |
| `car_id` | int NOT NULL KEY | Original car ID (indexed for efficient queries) |
| `timestamp` | timestamp DEFAULT CURRENT_TIMESTAMP KEY | When history row was written (indexed) |
| All 28 `cars` columns | [See DATABASE.md] | Complete car record snapshot including denormalized owner data |

**Key properties:**

- No foreign key constraint on `car_id` -- allows history to survive deletion of
  the source car
- Indexed on `car_id` and `timestamp` for efficient historical queries
- Stores complete row snapshots, enabling point-in-time reconstruction without
  complex diffing logic
- Captures denormalized owner data (name, email, location) at time of change,
  providing complete ownership provenance

### car_user_hist -- Junction Table Audit

> **No longer applies (v2.26.2).** `car_user` and `car_user_hist`, and the
> triggers described below, were dropped by migration
> `20260711000000_drop_car_user_tables.php` (issue #1162). Ownership is now a
> single authoritative column, `cars.user_id`, audited through `cars_hist`.
> The rest of this section is retained as a record of the decision as made.

A `car_user_hist` table existed in the schema to audit the `car_user` ownership
relationship table:

| Column | Type |
| --- | --- |
| `id` | bigint PRIMARY KEY auto-increment |
| `operation` | varchar(32) |
| `car_id` | int |
| `userid` | int |
| `timestamp` | timestamp DEFAULT CURRENT_TIMESTAMP |

**Status:** Implemented in #592. AFTER INSERT, AFTER UPDATE, and AFTER DELETE
triggers on `car_user` now populate this table. Indexes added on `car_id` and
`userid` for query performance.

### Application-Level History Writes

Beyond trigger-generated rows, application code writes directly to `cars_hist`
with domain-specific operation types that capture business context:

| Operation | Context | Written By |
| --- | --- | --- |
| `'NEWOWNER'` | Ownership transfer completed | `CarAdministrationService::transfer()` |
| `'MERGE'` | Car record merge (duplicate resolution) | `CarAdministrationService::merge()` |
| `'LOCATION_SYNC'` | Owner location data synchronized (historical rows only, pre-#1873) | `Owner::syncLocationToCars()` (renamed; see below) |
| `'OWNER_SYNC'` | All nine owner-contact fields synchronized (city, state, country, lat, lon, fname, lname, website, email) | `Owner::syncOwnerFieldsToCars()` |
| `'VERIFIED'` | Admin verified car details | `verify_car.php` (removed in #1613) |
| `'VERIFIED SOLD'` | Admin marked car as sold | `verify_car.php` (removed in #1613) |
| `'DELETE'` | Application-level pre-delete snapshot | `CarAdministrationService::delete()` |

These application-level writes provide richer context (e.g., admin user ID,
reason notes, specific fields changed) that database triggers alone cannot
capture. They work in conjunction with the `logs` table (via the `logger()`
function) to provide both structured data snapshots and human-readable narrative
of administrative actions.

#### OWNER_SYNC: an additive operation value, not a rename (#1873)

`Owner::syncLocationToCars()` (5 location fields) was replaced by
`Owner::syncOwnerFieldsToCars()` (9 owner-contact fields) in #1873. The new
method writes `'OWNER_SYNC'` rather than reusing `'LOCATION_SYNC'`. This is a
deliberate, additive choice, consistent with this ADR's ownership of the
operation-value enumeration:

- `'LOCATION_SYNC'` is retained on existing historical rows and is not
  written by any current code path.
- No backfill renames old `'LOCATION_SYNC'` rows to `'OWNER_SYNC'` -- the
  value on a history row should reflect the code path that was actually live
  when the row was written, not be rewritten to match a later rename.
- `'LOCATION_SYNC'` was, and `'OWNER_SYNC'` is, a bare string literal (at the
  call site in `Owner.php`), not a shared constant -- there is no
  operation-value enum in this codebase to update instead.

Two further points, verified empirically during #1873:

- A changed car accumulates **two** `cars_hist` rows per sync call: the
  `cars_update` trigger's own `'UPDATE'` row and the application's
  `'OWNER_SYNC'` row. This is expected, not a double-write bug in the sense
  described elsewhere in this ADR -- the two rows capture different things
  (trigger: raw column snapshot; application: business-context confirmation
  that an owner sync ran).
- The trigger fires on every row **matched**, not every row changed.
  `cars_update` is `AFTER UPDATE ... FOR EACH ROW` gated only by
  `@disable_triggers`, and it compares no `OLD`/`NEW` values. So a car whose
  values already match still receives one trigger `'UPDATE'` row even though
  `ROW_COUNT()` is 0 and the application correctly skips its own insert. An
  unchanged car therefore gains one `cars_hist` row per sync, not zero.
- Consequently `cars_hist` is not a record of value changes alone, and
  callers that count sync operations must filter to
  `operation = 'OWNER_SYNC'` rather than counting all new rows for the car.
- The application-level history insert had a latent defect fixed in #1873:
  `cars_hist` declares `model`, `series`, `variant`, `type`, and `chassis` as
  `NOT NULL` with no default, and `syncLocationToCars()`'s history insert
  supplied none of them. Under `STRICT_TRANS_TABLES` (the local/test
  configuration) this insert fails outright
  (`Field 'model' doesn't have a default value`); production runs permissive
  `sql_mode` and had instead been writing 41 `LOCATION_SYNC` rows since
  2025-09-03 with those columns silently stored as empty strings -- a
  degraded, non-reconstructable audit snapshot rather than an absent one.
  `syncOwnerFieldsToCars()` fixes this by populating the car's identity
  columns from the row already in hand (`getCarsOwned()`'s `SELECT c.*`)
  before writing the history row. Any future application-level insert into
  `cars_hist` should populate every `NOT NULL` column for the same reason.

### Complementary Logging Systems

The `cars_hist` table is one component of a three-part audit system:

- **cars_hist** -- Structured data snapshots of car record changes (what changed)
- **logs** -- Human-readable narrative logged by `logger()` function with
  `LogCategories` constants (who did it and why)
- **audit** -- UserSpice authentication/security events (login, session, permission
  changes) -- separate security concern

### Trigger Maintenance

Schema changes to the `cars` table require coordinated updates:

1. ALTER `cars` table with the new column or constraint
2. ALTER `cars_hist` to add the corresponding column
3. DROP all three triggers (`cars_insert`, `cars_update`, `cars_delete`)
4. Recreate triggers with updated column lists

This process is managed through idempotent FIX scripts (e.g., FIX script 08
rebuilt triggers after removing the deprecated `username` column). The explicit
column lists in triggers make this error-prone if not carefully automated.

## Consequences

### Positive

- **Guaranteed capture at database layer.** Triggers fire regardless of which
  application code path modifies the data. No audit gaps from forgotten logging
  calls or code paths that bypass application-level audit writes.

- **Complete row snapshots.** Each history row contains the full car record
  state at the moment of change, enabling point-in-time reconstruction without
  complex diffing or multi-table joins.

- **Denormalized owner data preserved.** History rows capture not just the car
  details but also the owner's name, email, and location as they existed at
  time of change. This creates a complete ownership provenance chain for dispute
  resolution and historical accuracy.

- **Bypass mechanism prevents history bloat.** The `@disable_triggers` session
  variable allows administrative bulk updates (geocoding, location sync,
  seasonal maintenance) to run without creating thousands of history rows for
  routine operations.

- **Domain-specific operations enrich the audit trail.** Application-level writes
  with operation types like `'NEWOWNER'`, `'MERGE'`, and `'VERIFIED'` add
  business context that database triggers cannot capture. Combined with the
  `logs` table, this provides complete traceability.

- **No external dependencies.** Implemented entirely within MySQL -- no
  additional infrastructure, message queues, CDC tooling, or microservices
  required.

- **Survives source record deletion.** Without foreign key constraints, history
  records persist even after the car record itself is deleted. Historical
  records cannot be accidentally orphaned or cascade-deleted.

### Negative

- **Trigger maintenance burden.** Every schema change to the `cars` table
  requires coordinated updates to `cars_hist` and all three trigger definitions.
  The explicit column lists (28+ columns) make this error-prone and must be
  managed carefully through FIX scripts and testing.

- **Double-write on delete — resolved (v2.25.0, #593).** The
  `CarAdministrationService::delete()` method previously wrote a manual
  `'DELETE'` history row before deleting the car, then the `cars_delete` trigger
  also fired, creating duplicate history entries that required FIX script
  FIX 03. The manual pre-delete insert has been removed; admin context (who
  deleted, reason) is now written via `logger()`. The `cars_delete` trigger is
  the sole source of structural audit records on deletion.

- **History rows are mutable.** Two administrative code paths (`verify_car.php`
  and `send_email.php`) used to UPDATE and DELETE existing `cars_hist` rows,
  violating the append-only immutability assumption of audit trails, with no
  database constraint preventing it. Once an audit row is written, it should
  never be modified. Both files were removed entirely in #1613 (the
  verification workflow they implemented was broken end to end and had no
  navigation entry point), so this specific mutation path no longer exists.
  The absence of database-level constraints preventing history row
  modification remains an architectural gap regardless — a future feature
  could reintroduce the same class of bug.

- **car_user_hist gap resolved (#592).** AFTER INSERT, AFTER UPDATE, and AFTER DELETE
  triggers on `car_user` now populate this table. Indexes on `car_id` and `userid`
  were added for query performance. See the `car_user_hist` section above.

- **GDPR complexity.** Owner PII (name, email, location) is embedded in
  `cars_hist` rows as a direct consequence of the denormalized snapshot design
  (see ADR-002). Current user deletion in `after_user_deletion.php` reassigns
  active ownership to the `noowner` system account but does not scrub PII from
  older history entries -- those rows continue to hold the deleted owner's
  `fname`, `lname`, `email`, `city`, `state`, `lat`, `lon`, and `website`
  values. A future enhancement to `after_user_deletion.php` should scrub PII
  from `cars_hist` rows associated with the deleted user: nullify `email`,
  `city`, `state`, `lat`, `lon`, and `website`; set `fname`/`lname` to
  `'Deleted User'`. The structural audit columns (`car_id`, `chassis`,
  `operation`, `timestamp`) must be preserved so the ownership provenance chain
  remains intact. Legal review is recommended to determine whether the GDPR
  "legitimate interest" basis covers retention of historical registry data or
  whether PII scrubbing is required on deletion.

- **Indexes on car_user_hist.** In addition to the unique key on `id`, the table
  now has indexes on `car_id` and `userid` (added in #592), so queries to find
  all changes to a car or by a user avoid full table scans.

- **Storage growth.** Every car modification creates a full row copy in
  `cars_hist`, including large text columns (`comments` mediumtext, `image`
  mediumtext). Over time, history table size can grow to multiple times the size
  of the active cars table, requiring monitoring and potential archiving
  strategies.

### Risks

| Risk | Likelihood | Impact | Mitigation |
| --- | --- | --- | --- |
| Trigger/schema desync after cars table change | Medium | High | FIX scripts provide repeatable rebuild; validate trigger columns vs schema |
| cars_hist growth impacting query performance | Low | Medium | Indexes on car_id and timestamp; archive old records if table exceeds 10GB |
| @disable_triggers bleeding across pooled connections | Low | Low | PHP connection lifecycle resets session state; variable defaults to NULL |
| GDPR deletion requiring history scrubbing | Low-Medium | High | noowner covers prospective writes; historical PII requires targeted UPDATE |
| Double-writes creating duplicate history rows | Resolved | — | Removed app pre-delete write in v2.25.0 (#593) |
| car_user_hist audit gaps during implementation | Medium | High | Resolved in #592; triggers and tests added |

## Alternatives Considered

### Application-Level Audit Only (Model/Repository Hooks)

Writing audit records exclusively from PHP application code (e.g., in repository
or service classes) instead of database triggers.

**Rejected because:**

- Any code path that directly executes SQL without routing through an
  audit-aware repository creates an audit gap. FIX scripts, admin console
  queries, and direct database maintenance operations would bypass application
  logging entirely.

- The `car_user_hist` table provides concrete evidence of this failure mode: the
  table was designed for application-level logging but no code was ever written
  to populate it, leaving car sharing relationship changes completely unaudited
  until #592 added triggers to fill the gap.

- Bulk operations and administrative scripts frequently bypass the normal
  application layer. A solution that depends on application-level cooperation
  cannot guarantee comprehensive audit coverage.

- Triggers provide a safety net that catches all changes regardless of source,
  which is essential for a registry application where data integrity and
  provenance are core to the business.

### Event Sourcing

Storing all state changes as an immutable sequence of events, deriving current
state from event replay.

**Rejected because:**

- Represents a fundamental architectural change incompatible with the existing
  UserSpice/PHP application model. Would require rebuilding core data access
  patterns and business logic.

- Requires additional infrastructure: event store (database table or separate
  service), projection/denormalization system, and eventual consistency handling.

- Introduces complexity (temporal queries, event versioning, schema evolution)
  disproportionate to the application's scale (a registry with hundreds of cars,
  not millions of transactions).

- No business requirement for event replay, time-travel queries, or temporal
  analysis. Simple "what changed" history is sufficient.

### MySQL Binary Log / Change Data Capture (CDC)

Using MySQL's binary log with tools like Debezium to capture and route change
events to an external audit store.

**Rejected because:**

- Requires additional infrastructure (Kafka cluster, Debezium connector, consumer
  applications, separate audit storage) not available on standard shared hosting.

- Binary log configuration requires MySQL server-level access (e.g., enabling
  `log_bin`, `binlog_format`) typically restricted on shared hosting.

- Adds significant operational complexity (connector deployment, consumer
  monitoring, schema registry) for minimal benefit on an application of this
  scale.

- No benefit over in-database triggers for a single-application database.

### MySQL System-Versioned (Temporal) Tables

Using MySQL 8.0+ system versioning (`WITH SYSTEM VERSIONING`) for native
point-in-time history.

**Rejected because:**

- MySQL 8.0 does not natively support system-versioned tables. This feature is
  implemented in MariaDB, not MySQL. The application's hosting environment runs
  MySQL, not MariaDB.

- Would require migrating to MariaDB, which is not supported by the current
  hosting environment (PHP/MySQL compatibility, driver support, maintenance
  agreements).

- System-versioned tables do not support custom operation types (`'NEWOWNER'`,
  `'MERGE'`, etc.). System-managed versioning is opaque and inflexible.

- Cannot implement the `@disable_triggers` bypass mechanism for bulk operations.

## References

- **Database Schema and Triggers**:
  [docs/development/DATABASE.md](../DATABASE.md) and
  `database/migrations/` (current schema-of-record; the file cited when this
  ADR was written has since been removed)
- **ADR-002 Denormalized Cars Table**:
  [ADR-002](ADR-002-denormalized-cars-table-cached-owner-data.md)
  (explains denormalized owner fields in cars_hist snapshots)
- **Car Administration Service**:
  [CarAdministrationService.php](../../usersc/classes/Car/CarAdministrationService.php)
  (transfer, merge, delete operations)
- **Car Repository**:
  [CarRepository.php](../../usersc/classes/Car/CarRepository.php)
- **LogCategories**:
  [LogCategories.php](../../usersc/classes/LogCategories.php)
  (structured logging categories)
- **Owner**:
  [Owner.php](../../usersc/classes/Owner.php)
  (location sync operations)
- **FIX Script 03**: `03-Remove-Duplicate-History.php`
  (cleanup for delete double-writes) — deleted; recoverable from git history
- **FIX Script 08**: `08-Fix-Car-History-Triggers-Username-Column.php`
  (trigger rebuild example) — deleted; recoverable from git history
- **Coding Standards**:
  [CODING_STANDARDS.md](../CODING_STANDARDS.md)
- **Nygard ADR Format**:
  [Documenting Architecture Decisions](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions)
