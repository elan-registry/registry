# ADR-010: Use noowner System Account for GDPR-Compliant User Deletion

## Status

**In Review** (retroactive)

## Date

Retroactive -- documented 2026-02-25

## Context

The Lotus Elan Registry maintains a historical database of Lotus Elan cars manufactured between 1963 and 1974. Car records have independent historical value beyond any individual owner -- researchers, restorers, and enthusiasts rely on the registry to trace provenance, verify authenticity, and understand the surviving population of these vehicles.

When a user exercises their GDPR Article 17 right to erasure (or when the system removes inactive/spam accounts via automated cleanup), the registry faces a fundamental tension:

- **Privacy obligation**: The user's personal data (name, email, location, profile) must be deleted.
- **Data preservation obligation**: Car records represent community knowledge accumulated over two decades. Deleting cars alongside their owners would destroy irreplaceable historical data.
- **Referential integrity obligation**: The `cars` table has a `user_id` foreign key. Simply deleting the user creates orphaned records and application errors.

The problem is compounded by the denormalized schema (ADR-002), which copies owner PII (`fname`, `lname`, `email`, `city`, `state`, `country`, `lat`, `lon`, `website`) into the `cars` table and, via triggers (ADR-003), into `cars_hist` rows.

## Decision

Use a dedicated **system user account** with username `noowner` (fname='No', lname='Owner', NULL password) as the reassignment target when a user is deleted. The noowner account is a real row in the `users` table that cannot authenticate (NULL password hash) and is excluded from all automated cleanup processes.

### noowner Account Properties

| Property | Value | Rationale |
|---|---|---|
| `username` | `'noowner'` | Lookup key used by deletion script |
| `fname` | `'No'` | Displays as "No Owner" in UI via `fname . ' ' . lname` |
| `lname` | `'Owner'` | See above |
| `password` | `NULL` | Prevents authentication; no login possible |
| `email` | `'noowner@example.com'` | Placeholder; not a real mailbox |
| `id` | `83` (production) | Assigned at creation in 2012; not guaranteed across environments |

### Mechanism

The deletion process is implemented as a UserSpice hook at `usersc/scripts/after_user_deletion.php`, called by the framework's `deleteUsers()` function in `users/helpers/users.php`. The hook fires inside a `foreach` loop after the `users` row and `user_permission_matches` rows have already been deleted by UserSpice.

**Execution sequence per deleted user (variable `$id`):**

| Step | SQL Operation | Table(s) | Purpose |
|---|---|---|---|
| 1 | `SELECT id FROM users WHERE username = 'noowner'` | `users` | Dynamic lookup of noowner ID |
| 2 | `SELECT car_id FROM car_user WHERE userid = ?` | `car_user` | Enumerate cars owned by deleted user |
| 3 | `DELETE FROM profiles WHERE user_id = ?` | `profiles` | Remove PII (city, state, country, bio, etc.) |
| 4 | `DELETE FROM car_user WHERE userid = ?` | `car_user` | Remove old ownership junction records |
| 5 | `INSERT INTO car_user (userid, car_id) VALUES (?, ?)` (per car) | `car_user` | Create new junction records pointing to noowner |
| 6 | `UPDATE cars SET user_id = ? WHERE user_id = ?` | `cars` | Reassign primary ownership; triggers `cars_hist` row (ADR-003) |
| 7 | `logger(...)` with `LOG_CATEGORY_USER_DELETION` | `logs` | Audit trail entry |

**Fallback path**: If noowner does not exist, the script deletes `profiles`, deletes `car_user` rows, sets `cars.user_id = NULL`, and logs a warning.

### Database Schema

**`users` table** (noowner record):
- Standard UserSpice user record with NULL password
- `active = 1` (must remain active for FK integrity)
- `permissions = 0` (no admin or registry permissions)
- Located dynamically: `SELECT id FROM users WHERE username = 'noowner'`

**`profiles` table**:
- Deleted for the departing user (PII removal)
- noowner does not require a profile record

**`car_user` junction table**:
- Old rows (userid = deleted user) removed
- New rows (userid = noowner, car_id = each car) inserted

**`cars` table** (per ADR-002):
- `user_id` updated from deleted user ID to noowner ID
- Denormalized fields inherit noowner's data via trigger mechanism

**`cars_hist` table** (per ADR-003):
- New row inserted by `cars_after_update` trigger capturing the reassignment
- Pre-existing historical rows retain original owner's PII (known GDPR gap)

**`logs` table**:
- Audit entry with `LogCategories::LOG_CATEGORY_USER_DELETION = 'UserDeletion'`
- Records car count and noowner ID for traceability

### Integration Points

- **Automated cleanup** (`users/cron/spam_inactive_cleanup.php`): Excludes noowner via `u.username NOT IN ('admin', 'noowner')` in all three detection queries (legacy spam, suspicious patterns, inactive users)
- **Admin UI** (`app/admin/assets/manage-consolidated.js`): Provides a "No Owner" checkbox for manual car reassignment. Currently hard-codes noowner ID as 83
- **Recovery script** (`FIX/_ARCHIVE/02-Cleanup-Orphaned-Profiles.php`): Reassigns orphaned cars (whose user_id points to a deleted user) back to noowner
- **Privacy policy** (`docs/faq/PRIVACY.md`): Explicitly documents the noowner pattern: "Car Ownership: Transferred to a system account called 'noowner'"
- **ElanRegistryOwner class**: No special handling -- treats noowner as a standard user record, which is intentional

### Security Model

- **Authentication**: noowner has NULL password hash -- cannot authenticate through any login path
- **Authorization**: noowner has permissions level 0 -- no admin or registry access even if authentication were possible
- **Cleanup exclusion**: Explicitly excluded from all automated deletion queries by username check AND `protected` flag
- **Hook integrity**: The deletion hook fires for every `deleteUsers()` call, including admin panel deletions and cron cleanup -- no deletion path bypasses it
- **SQL safety**: All queries use prepared statements via the DB class
- **Audit trail**: Both application-level logging (`LogCategories`) and database-level triggers (`cars_hist`) record the reassignment

## Consequences

### Positive

- **Preserves irreplaceable historical data** -- car records survive user deletion; registry completeness maintained for community benefit
- **Maintains referential integrity** -- `cars.user_id` always points to a valid `users` row, preventing NULL FK issues, broken joins, and application errors
- **GDPR-compatible by design** -- personal data deleted; car records become pseudonymized, attributed to a system account with no real-world identity
- **Simple, auditable implementation** -- entire mechanism is a single ~48-line PHP script using standard prepared statements
- **Leverages existing framework hook** -- UserSpice's `after_user_deletion.php` hook requires no framework modification; fires automatically for all deletion paths
- **Enables recovery** -- orphaned car cleanup scripts can detect and repair broken ownership by reassigning to noowner
- **Full audit trail** -- database triggers (ADR-003) capture the ownership reassignment in `cars_hist`; application logging provides a second audit dimension
- **Transparent to end users** -- privacy policy explains the process plainly; users understand their PII is deleted while car data is preserved anonymously

### Negative

- **Does not scrub PII from historical records** -- `cars_hist` retains the deleted user's PII in pre-deletion snapshot rows; only prospective ownership points to noowner (most significant GDPR gap)
- **No transactional guarantees** -- the six SQL operations are not wrapped in a database transaction; mid-sequence failure leaves cars in inconsistent state
- **Hard-coded ID in admin JavaScript** -- `manage-consolidated.js` hard-codes noowner as ID 83 in three locations; breaks in non-production environments
- **No migration script creates noowner** -- the account is a manual setup requirement; new installations silently activate the fallback path
- **Magic string repeated across codebase** -- the username `'noowner'` appears as a bare string in at least 5 files with no centralized constant
- **Hook fires after user row deletion** -- by the time the hook executes, the user's PII is already gone from the `users` table; future PII scrubbing must use `cars.user_id` rather than joining to `users`
- **Delete-then-reinsert on car_user** -- between deletion and reinsertion, concurrent queries may see cars without junction records

### Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| GDPR data subject access request reveals PII in `cars_hist` after deletion | Medium | High | Implement PII scrubbing in `cars_hist` on deletion; document legitimate interest legal basis for historical retention |
| noowner account accidentally deleted by admin | Low | Critical | Set `protected = 1` flag; exclude from admin deletion UI; add startup validation check |
| Hard-coded ID 83 breaks in non-production environments | High (dev/staging) | Medium | Replace with API endpoint or PHP-rendered constant for dynamic lookup |
| Mid-deletion failure leaves orphaned car_user state | Low | Medium | Wrap deletion script in database transaction with rollback on failure |
| noowner missing in fresh installation; fallback sets NULL user_id | Medium (new installs) | Medium | Create FIX script to ensure noowner exists; add application startup check |
| Concurrent deletion of multiple users causes duplicate car_user rows | Very Low | Low | Add unique constraint on `car_user(userid, car_id)`; use `INSERT IGNORE` |
| Automated cleanup inadvertently targets noowner due to query change | Low | Critical | Unit test verifying noowner exclusion; set `protected` column as defense-in-depth |

## Alternatives Considered

### A. Hard-Delete Cars With User

Delete all car records when their owner is deleted, cascading through `cars`, `car_user`, and `cars_hist`.

**Rejected because:**
- Destroys irreplaceable data -- car records represent 20+ years of community knowledge
- Violates the registry's core mission to preserve knowledge of Lotus Elans
- No recovery path once car data is deleted
- GDPR requires deletion of *personal data*, not factual records about physical objects; disproportionate response

### B. Soft-Delete/Archive User Record, Retain Ownership Link

Mark the user record as "deleted" (`active = 0`, `deleted_at = NOW()`) but keep the row with PII intact. Cars continue pointing to the soft-deleted user.

**Rejected because:**
- GDPR non-compliant -- PII remains in the database, accessible to admins
- Retaining PII without legal basis is difficult to justify for a hobby car registry
- Increases data breach surface -- soft-deleted records are still in the database
- Every query touching users must filter for deleted status; missing a filter leaks data

### C. Anonymous Placeholder Without Real User Record

Use a sentinel value (`user_id = 0` or `user_id = -1`) instead of a real user record.

**Rejected because:**
- Breaks foreign key constraints unless FK checks are disabled
- Pervasive application changes -- every `users` join must handle the sentinel case
- Denormalization conflict (ADR-002) -- no user record to derive display values from
- Inconsistent with UserSpice patterns which assume user IDs reference valid rows
- Testing burden multiplied -- every feature must handle both real IDs and sentinel

### D. Tombstone Records With Synthetic IDs Per Deletion

Create a unique placeholder user per deletion (e.g., `username = 'deleted_12345'`), preserving one-to-one relationship between original owner and their cars.

**Rejected because:**
- Unbounded growth -- every deletion creates a new user record; `users` table accumulates tombstones
- Pseudo-PII risk -- synthetic IDs correlated with deletion logs may constitute pseudonymized data under GDPR
- No demonstrated need -- the registry does not need to distinguish "deleted user A's cars" from "deleted user B's cars"
- Same exclusion overhead as noowner but multiplied across potentially hundreds of records

## Known Issues and Future Improvements

| Priority | Issue | Recommendation |
|---|---|---|
| P1 | PII retained in `cars_hist` after deletion | Add `UPDATE cars_hist SET fname='Deleted', lname='User', email=NULL, city=NULL, state=NULL, country=NULL, lat=NULL, lon=NULL, website=NULL WHERE user_id = ?` to deletion script |
| P1 | Hard-coded ID 83 in admin JS | Replace with PHP-rendered JavaScript variable or API endpoint for dynamic lookup |
| P1 | No transaction wrapping in deletion script | Wrap steps 2-6 in `START TRANSACTION` / `COMMIT` with `ROLLBACK` on failure |
| P2 | No migration creates noowner | Create FIX script to ensure noowner exists (idempotent) |
| P2 | No PHP constant for noowner username | Define `NOOWNER_USERNAME = 'noowner'` constant; update all references |
| P2 | noowner account not protected against admin deletion | Set `users.protected = 1`; verify admin UI respects flag |
| P3 | Hook fires after user row deletion | Document limitation; use `cars.user_id` for future PII scrubbing |
| P3 | Recovery script uses overly broad lookup | Change to `WHERE username = ?` only (remove fname/lname fallbacks) |

## References

| Item | File |
|---|---|
| Deletion hook script | [/usersc/scripts/after_user_deletion.php](../../usersc/scripts/after_user_deletion.php) |
| UserSpice deleteUsers() | [/users/helpers/users.php](../../users/helpers/users.php) |
| Spam/inactive cleanup | [/users/cron/spam_inactive_cleanup.php](../../users/cron/spam_inactive_cleanup.php) |
| Admin UI (No Owner checkbox) | [/app/admin/assets/manage-consolidated.js](../../app/admin/assets/manage-consolidated.js) |
| Orphaned car recovery | [/FIX/_ARCHIVE/02-Cleanup-Orphaned-Profiles.php](../../FIX/_ARCHIVE/02-Cleanup-Orphaned-Profiles.php) |
| Privacy policy | [/docs/faq/PRIVACY.md](../faq/PRIVACY.md) |
| LogCategories constant | [/usersc/classes/LogCategories.php](../../usersc/classes/LogCategories.php) |
| ElanRegistryOwner class | [/usersc/classes/ElanRegistryOwner.php](../../usersc/classes/ElanRegistryOwner.php) |
| Database documentation | [/docs/development/DATABASE.md](../development/DATABASE.md) |
| Unit tests | [/tests/unit/users/UserDeletionCleanupTest.php](../../tests/unit/users/UserDeletionCleanupTest.php) |
| Denormalization rationale | [ADR-002](ADR-002-denormalized-cars-table-cached-owner-data.md) |
| Audit trail triggers | [ADR-003](ADR-003-database-audit-trails-triggers-history-tables.md) |
| Car transfer workflow | [ADR-008](ADR-008-implement-self-service-car-ownership-transfer-workflow.md) |
