<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;

/**
 * Reconcile Owner Fields Script (v2.30.1)
 *
 * Repeatable, admin-triggered maintenance job that detects and repairs drift
 * between the nine denormalized owner-contact columns on `cars` (email, fname,
 * lname, city, state, country, lat, lon, website) and their authoritative
 * source in `users` / `profiles`.
 *
 * Those columns are normally kept fresh by every write path that touches an
 * owner's contact fields: the owner's own profile save
 * (`usersc/user_settings.php`), confirming an email change
 * (`sync_owner_email_on_verify.php`), editing a car (`OwnerContactRefresher`),
 * and two admin actions (the manual sync endpoint, and
 * `process-owner-update.php`). Save-time sync is inherently incomplete:
 * historical rows, direct DB edits, imports, and any future write site that
 * forgets to call the sync all drift silently and stay drifted. This script
 * is the backstop that finds and repairs that drift on demand — it is not a
 * substitute for fixing a write site that fails to sync.
 *
 * Three-step flow (Analyze → Details → Execute), mirroring
 * `21-Fix-Page-Permissions.php`:
 *   1. Analyze  — read-only aggregate drift counts per field, plus an
 *                 informational count of cars whose `user_id` points at a
 *                 user row that no longer exists.
 *   2. Details  — read-only per-car before/after values, fetched in full and
 *                 paged/searched/sorted client-side by DataTables.
 *   3. Execute  — loops the distinct owners with drift and calls
 *                 `Owner::syncOwnerFieldsToCars()` for each.
 *
 * IMPORTANT — no outer transaction. `Owner::syncOwnerFieldsToCars()` throws
 * `OwnerDatabaseException` when called inside an open transaction, because it
 * manages its own per-car transactions and an outer one would make every
 * per-car rollback a silent no-op. The execute loop below therefore must NOT
 * call `$db->beginTransaction()`, unlike `21-Fix-Page-Permissions.php`'s own
 * per-page update pattern.
 *
 * IMPORTANT — never writes `owner_last_updated`. A mechanical refresh is not
 * the owner confirming their car's data; leaving that column alone is what
 * keeps a synced car eligible for verification. `syncOwnerFieldsToCars()`
 * already honors this and must not be bypassed here.
 *
 * Orphaned `user_id` cars are reported in the Analyze summary only. Repairing
 * them is out of scope for a contact-field sync job, and they are excluded
 * from the owner-ID list that drives Execute. Cars parked on the `noowner`
 * system account are excluded on the same basis: that account holds
 * placeholder contact data, so every such car reads as "drifted" against it
 * and a sync would overwrite the car's last-known real owner details with the
 * placeholder.
 *
 * IMPORTANT — a car's own `website` is never overwritten with a different one.
 * As of #1963, website is owner-level only, and every live sync path already
 * overwrites it unconditionally — a differing per-car value found here is
 * legacy data entered before that decision, not a value the product still
 * treats as legitimately car-specific. This protection exists purely to avoid
 * silently destroying old per-car pages that predate #1963 without a human
 * looking at them first; it is not an ongoing product rule, and no new such
 * conflicts are expected going forward. Where a car holds a non-empty website
 * that differs from its owner's, the sync would destroy it, so the whole
 * owner is skipped for the run and flagged for manual review —
 * `Owner::syncOwnerFieldsToCars()` is shared with the profile-save and
 * sync-location callers and offers no per-field opt-out to use instead.
 *
 * KNOWN PERFORMANCE CHARACTERISTIC: the drift queries are full scans over
 * `cars JOIN users LEFT JOIN profiles` with a nine-clause OR predicate — no
 * index supports that shape. Acceptable for an admin-triggered, infrequent job
 * at current fleet scale.
 *
 * Issue #1961.
 */
require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';

if (!securePage($php_self)) {
    die();
}

if (!isAdmin()) {
    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
        'Non-admin attempted owner field reconciliation script');
    require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
    echo '<div class="alert alert-danger mt-3">Administrator access required.</div>';
    exit;
}

// Required as a global even though this file otherwise uses dbi():
// admin_script_record_completion() (fix-script-core.php) does `global $db`.
// Do not remove it as apparently-dead code.
$db = DB::getInstance();

if (!$db) {
    logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
        '26-Reconcile-Owner-Fields: DB::getInstance() returned falsy — database connection unavailable');
    die('<div class="alert alert-danger" style="margin:2rem">Database connection failed. The maintenance script cannot run. Check application logs for details.</div>');
}

$csrfToken = Token::generate();

/**
 * How many owners may fail in an unbroken row before the Execute loop treats
 * the failures as a systemic fault and aborts.
 *
 * Per-owner failures are absorbed so one bad owner cannot end a 100+-owner
 * run, but a fault that affects every owner alike (a dropped connection, a
 * schema change) would otherwise be retried once per owner — burying the real
 * error under volume and taking a long time to do it.
 */
const RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS = 5;

if (!function_exists('reconcileShouldAbortForConsecutiveErrors')) {
    /**
     * Whether the Execute loop should abort after this many consecutive
     * per-owner infrastructure failures, rather than continuing to the next
     * owner.
     *
     * Extracted as a pure function (no side effects, no DB access) purely so
     * the threshold comparison itself — easy to get backwards or off-by-one
     * in a future edit — is unit-testable without driving the whole
     * `securePage()`-gated Execute page. The counter increment/reset around
     * each owner still lives inline in the execute handler.
     *
     * @param int $consecutiveErrors Consecutive per-owner failures observed
     *        so far, including the one just recorded
     * @return bool True once `$consecutiveErrors` reaches
     *         {@see RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS}
     */
    function reconcileShouldAbortForConsecutiveErrors(int $consecutiveErrors): bool
    {
        return $consecutiveErrors >= RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS;
    }
}

/**
 * The nine denormalized owner-contact columns, mapped to the source table
 * alias that holds their authoritative value (`u` = users, `p` = profiles).
 *
 * This is the single definition behind every drift-detection query in this
 * script, so the aggregate, owner-ID and per-car queries cannot drift apart.
 * It mirrors `Owner::ownerContactFields()`, which defines the same nine
 * columns on the write side.
 *
 * @var array<string, string>
 */
const RECONCILE_OWNER_FIELDS = [
    'email'   => 'u',
    'fname'   => 'u',
    'lname'   => 'u',
    'city'    => 'p',
    'state'   => 'p',
    'country' => 'p',
    'lat'     => 'p',
    'lon'     => 'p',
    'website' => 'p',
];

/**
 * The FROM/JOIN clause shared by every drift-detection query.
 *
 * `cars c JOIN users u` deliberately uses an inner join: a car whose `user_id`
 * has no matching user row is an orphan, counted separately by
 * {@see findOrphanedOwnerCarCount()} and never repaired here.
 */
const RECONCILE_FROM_CLAUSE = 'FROM cars c '
    . 'JOIN users u ON u.id = c.user_id '
    . 'LEFT JOIN profiles p ON p.user_id = u.id '
    . 'WHERE c.user_id > 0';

/**
 * Owner-contact fields whose columns are strings — `cars`'s string columns
 * collate `utf8mb4_unicode_ci` while `users`/`profiles` (upstream UserSpice
 * tables) collate `utf8mb4_general_ci`. Comparing them directly raises
 * MySQL's "Illegal mix of collations" error, which this project's
 * `DB::query()` swallows (returns a zero-row/error result rather than
 * throwing) — every drift comparison would silently report "no drift"
 * instead of failing loudly. `lat`/`lon` are `float` and never need this.
 *
 * Declared at file scope, not inside the `function_exists()` guard below:
 * PHP does not allow `const` inside a conditional block.
 *
 * @var list<string>
 */
const RECONCILE_STRING_FIELDS = ['email', 'fname', 'lname', 'city', 'state', 'country', 'website'];

/**
 * Username of the system account that cars are reassigned to when their real
 * owner is deleted (including GDPR erasure). See `app/admin/index.php`'s
 * reassignment path and DATABASE.md.
 *
 * Looked up by username rather than by ID: the row exists in every
 * environment but its `id` does not match across them (83 on the dev DB, 1
 * elsewhere), so a hardcoded ID would silently exclude the wrong account —
 * or a real owner.
 */
const RECONCILE_NO_OWNER_USERNAME = 'noowner';

if (!function_exists('findNoOwnerAccountId')) {
    /**
     * The `noowner` system account's user ID, or null if this environment has
     * no such row.
     *
     * @param DatabaseInterface $db Database handle
     * @return int|null
     */
    function findNoOwnerAccountId(DatabaseInterface $db): ?int
    {
        $result = $db->query('SELECT id FROM users WHERE username = ? LIMIT 1', [RECONCILE_NO_OWNER_USERNAME]);
        if ($result->error()) {
            // Fail safe rather than fatal: under-excluding leaves noowner cars
            // visible in Analyze/Details, where an admin can still catch them,
            // whereas throwing would block the entire maintenance tool over a
            // lookup for an account that is optional to begin with.
            return null;
        }

        $row = $result->first();

        return is_object($row) ? (int) $row->id : null;
    }
}

if (!function_exists('reconcileFromClause')) {
    /**
     * The shared FROM/JOIN/WHERE clause, with the `noowner` system account
     * excluded.
     *
     * Cars reassigned to `noowner` are unowned in every sense that matters
     * here: the account carries placeholder contact data ("No Owner",
     * `noowner@invalid`) and no meaningful profile, so every such car reads as
     * drifted against it, and syncing would overwrite the car's last-known
     * real owner details with the placeholder. They are therefore excluded
     * from drift detection exactly as orphaned `user_id` cars are — counted
     * for information by {@see findNoOwnerAccountCarCount()}, never repaired.
     *
     * Every drift query resolves the ID itself via {@see findNoOwnerAccountId()}
     * rather than taking it as an optional parameter: an omitted argument at any
     * one of the five call sites would silently reinstate the overwrite bug this
     * exists to prevent, and the lookup is a single primary-key-adjacent read on
     * an indexed unique column.
     *
     * @param int|null $noOwnerId The `noowner` account's ID, or null if this
     *                            environment has none (then nothing is excluded)
     * @return string SQL fragment; the ID is cast to int before interpolation
     *                and never originates from user input
     */
    function reconcileFromClause(?int $noOwnerId): string
    {
        return $noOwnerId === null
            ? RECONCILE_FROM_CLAUSE
            : RECONCILE_FROM_CLAUSE . ' AND c.user_id <> ' . (int) $noOwnerId;
    }
}

if (!function_exists('findNoOwnerAccountCarCount')) {
    /**
     * Count cars parked on the `noowner` system account.
     *
     * Reported for information only, alongside the orphaned-`user_id` count —
     * these cars are never repaired and never appear in
     * {@see findOwnerIdsWithDrift()}.
     *
     * @param DatabaseInterface $db         Database handle
     * @param int|null          $noOwnerId  The `noowner` account's ID, or null
     *                                      if this environment has none
     * @return int Number of cars owned by the system account; 0 when absent
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerFieldDriftSummary()} for why an unchecked failure
     *         here would silently read as "none".
     */
    function findNoOwnerAccountCarCount(DatabaseInterface $db, ?int $noOwnerId): int
    {
        if ($noOwnerId === null) {
            return 0;
        }

        $result = $db->query('SELECT COUNT(*) AS no_owner FROM cars WHERE user_id = ?', [$noOwnerId]);
        if ($result->error()) {
            throw new \RuntimeException('No-owner account car count query failed: ' . $result->errorString());
        }

        $row = $result->first();
        if (!is_object($row)) {
            throw new \RuntimeException('No-owner account car count returned no aggregate row.');
        }

        return (int) $row->no_owner;
    }
}

if (!function_exists('reconcileFieldMismatchExpression')) {
    /**
     * SQL boolean expression that is true when one field differs between the
     * car row and its owner's authoritative source.
     *
     * Uses MySQL's null-safe `<=>` so a NULL on one side and a value on the
     * other counts as drift, while NULL on both sides does not. String fields
     * pin the comparison to `cars`'s collation explicitly (see
     * RECONCILE_STRING_FIELDS) to avoid the collation-mismatch error above.
     *
     * `website` gets one further adjustment: `Owner::find()` (usersc/classes/Owner.php)
     * normalizes a NULL `profiles.website` to `''` before `ownerContactFields()`
     * ever reads it, so `syncOwnerFieldsToCars()` always writes `''` for an
     * owner with no website — never NULL. But `cars.website` itself holds a mix
     * of NULL and '' for "no value" too (both occur in real data — historical
     * rows predating this normalization, direct inserts, etc.), and the two
     * must be treated as equal on EITHER side, not just coalesced on the
     * owner's: coalescing only the owner side would turn a genuine
     * NULL-on-both-sides match (never drift) into a false NULL-vs-'' mismatch
     * for every car whose own website happens to be NULL rather than ''.
     * Coalescing both sides to '' here treats NULL and '' as the same "no
     * website" value symmetrically, matching what a sync actually converges
     * to (always '', never NULL) while not disturbing a car that already
     * legitimately has NULL matching an owner's NULL.
     *
     * `city`/`state`/`country` get the identical NULL-to-'' normalization in
     * `Owner::find()`, and therefore have the exact same latent failure mode:
     * an owner with no `profiles` row reads as `NULL` here and would show
     * permanent, unresolvable drift against a car whose value is `''` after a
     * sync, on every future run, for as long as that owner's profile stays
     * incomplete. This is a DELIBERATE, CONFIRMED product decision to leave
     * un-coalesced, not an oversight (an automated reviewer flagged this
     * exact gap during #1961's review, and the decision to leave it was
     * reconfirmed after the flag) — missing-location owners are meant to
     * surface as visibly stuck here, pointing an admin at the dedicated
     * "Owner Data Quality" tool
     * (`app/admin/index.php?tab=owner-mgmt`, `app/admin/includes/tab-owner_mgmt.php`)
     * that finds and lets an admin fix incomplete profiles directly, rather
     * than have this script silently absorb the gap the way it now does for
     * `website`. `email`/`fname`/`lname`/`lat`/`lon` are never normalized by
     * `Owner::find()` at all, so no other field needs this treatment either
     * way.
     *
     * @param string $field One of the keys of RECONCILE_OWNER_FIELDS
     * @return string SQL fragment; built entirely from this script's own
     *                constants, never from user input
     */
    function reconcileFieldMismatchExpression(string $field): string
    {
        $sourceAlias = RECONCILE_OWNER_FIELDS[$field];
        $sourceExpr = in_array($field, RECONCILE_STRING_FIELDS, true)
            ? "{$sourceAlias}.{$field} COLLATE utf8mb4_unicode_ci"
            : "{$sourceAlias}.{$field}";
        $carExpr = "c.{$field}";

        if ($field === 'website') {
            $sourceExpr = "COALESCE({$sourceExpr}, '')";
            $carExpr = "COALESCE(c.{$field}, '')";
        }

        return "NOT ({$carExpr} <=> {$sourceExpr})";
    }
}

if (!function_exists('reconcileDriftPredicate')) {
    /**
     * SQL boolean expression that is true when a car differs from its owner in
     * at least one of the nine owner-contact fields.
     *
     * Defined once and reused by all three query sites so they cannot diverge.
     *
     * @return string SQL fragment; built entirely from this script's own
     *                constants, never from user input
     */
    function reconcileDriftPredicate(): string
    {
        $clauses = [];
        foreach (array_keys(RECONCILE_OWNER_FIELDS) as $field) {
            $clauses[] = reconcileFieldMismatchExpression($field);
        }

        return '(' . implode(' OR ', $clauses) . ')';
    }
}

if (!function_exists('reconcileWebsiteConflictPredicate')) {
    /**
     * SQL boolean expression that is true when a car's `website` would be
     * DESTROYED rather than merely refreshed by a sync.
     *
     * Every other owner-contact column is a fact about the owner, so the
     * owner's copy is authoritative by definition. `website` predates #1963's
     * decision that it is owner-level too — a car holding a differing value
     * here is legacy data (a build thread, a marque listing) entered before
     * that decision, not a value the product still treats as car-specific.
     * Copying the owner's value over it is unrecoverable data loss, not a
     * repair, which is why this predicate exists at all — not because the
     * product still expects new conflicts of this kind going forward.
     *
     * A conflict therefore requires BOTH sides to hold a real, non-empty
     * value that differ — a car with a real website and an owner with none
     * has nothing worth protecting (the sync would only ever clear a blank
     * to a blank, differing only in NULL vs. '' representation, never
     * destroying real data), so that case is deliberately excluded here and
     * synced normally like any other field.
     *
     * @return string SQL fragment; built entirely from this script's own
     *                constants, never from user input
     */
    function reconcileWebsiteConflictPredicate(): string
    {
        return "(c.website IS NOT NULL AND c.website <> '' "
            . "AND p.website IS NOT NULL AND p.website <> '' AND "
            . reconcileFieldMismatchExpression('website') . ')';
    }
}

if (!function_exists('findOwnerIdsWithWebsiteConflict')) {
    /**
     * The distinct owner IDs owning at least one car with a website conflict.
     *
     * The Execute step skips these owners ENTIRELY — not just the conflicted
     * car. `Owner::syncOwnerFieldsToCars()` is a shared write path (the owner's
     * own profile save and the admin sync-location action call it too) with no
     * per-car or per-field exclusion mechanism, so the only way this job can
     * decline to overwrite one column is to decline the whole owner. That
     * delays the sync of that owner's other, uncontested fields until an admin
     * resolves the conflict by hand — the deliberate trade for never silently
     * destroying a car's own website.
     *
     * @param DatabaseInterface $db Database handle
     * @return list<int> Owner (user) IDs, ascending. An empty list is a
     *         legitimate success result (no conflicts anywhere), which is why
     *         this function checks only `error()` and not row presence.
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerFieldDriftSummary()}. An unchecked failure here
     *         would read as "no conflicts" and let Execute overwrite exactly
     *         the values this guard exists to protect.
     */
    function findOwnerIdsWithWebsiteConflict(DatabaseInterface $db): array
    {
        $sql = 'SELECT DISTINCT c.user_id ' . reconcileFromClause(findNoOwnerAccountId($db))
            . ' AND ' . reconcileWebsiteConflictPredicate()
            . ' ORDER BY c.user_id';

        $result = $db->query($sql);
        if ($result->error()) {
            throw new \RuntimeException('Website conflict owner query failed: ' . $result->errorString());
        }

        $ownerIds = [];
        foreach ($result->results() as $row) {
            $ownerIds[] = (int) $row->user_id;
        }

        return $ownerIds;
    }
}

if (!function_exists('findWebsiteConflictCars')) {
    /**
     * Every car with a website conflict, with both competing values.
     *
     * Unpaginated on purpose: this is a small, admin-reviewable exception list
     * (a car must hold a non-empty website that differs from its owner's to
     * qualify), and the Execute step needs the whole set in one pass to name
     * the offending cars in its skip message.
     *
     * @param DatabaseInterface $db Database handle
     * @return list<array{carId: int, ownerId: int, carWebsite: string, ownerWebsite: string|null}>
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerIdsWithWebsiteConflict()}.
     */
    function findWebsiteConflictCars(DatabaseInterface $db): array
    {
        $sql = 'SELECT c.id AS car_id, c.user_id AS owner_id, c.website AS car_website, '
            . 'p.website AS owner_website ' . reconcileFromClause(findNoOwnerAccountId($db))
            . ' AND ' . reconcileWebsiteConflictPredicate()
            . ' ORDER BY c.user_id, c.id';

        $result = $db->query($sql);
        if ($result->error()) {
            throw new \RuntimeException('Website conflict car query failed: ' . $result->errorString());
        }

        $cars = [];
        foreach ($result->results() as $row) {
            $cars[] = [
                'carId'        => (int) $row->car_id,
                'ownerId'      => (int) $row->owner_id,
                'carWebsite'   => (string) $row->car_website,
                'ownerWebsite' => $row->owner_website === null ? null : (string) $row->owner_website,
            ];
        }

        return $cars;
    }
}

if (!function_exists('findOwnerFieldDriftSummary')) {
    /**
     * Aggregate drift counts across the whole fleet.
     *
     * @param DatabaseInterface $db Database handle
     * @return array{fields: array<string, int>, carsWithDrift: int, ownersWithDrift: int}
     *         Per-field mismatch counts keyed by column name, the number of
     *         cars differing in at least one field, and the number of distinct
     *         owners those cars belong to.
     * @throws \RuntimeException If the query fails. `DB::query()` never throws
     *         on a failed statement — it returns an errored result with no
     *         rows — so an unchecked failure here would report "zero drift"
     *         instead of failing. That exact class of bug has already bitten
     *         this script twice (a collation mismatch and a LIMIT binding
     *         error), so every query in this file checks explicitly.
     */
    function findOwnerFieldDriftSummary(DatabaseInterface $db): array
    {
        $predicate = reconcileDriftPredicate();

        $selects = [];
        foreach (array_keys(RECONCILE_OWNER_FIELDS) as $field) {
            $selects[] = 'SUM(' . reconcileFieldMismatchExpression($field) . ") AS drift_{$field}";
        }
        $selects[] = "SUM({$predicate}) AS cars_with_drift";
        $selects[] = "COUNT(DISTINCT CASE WHEN {$predicate} THEN c.user_id END) AS owners_with_drift";

        $sql = 'SELECT ' . implode(', ', $selects) . ' ' . reconcileFromClause(findNoOwnerAccountId($db));

        $result = $db->query($sql);
        if ($result->error()) {
            throw new \RuntimeException('Owner field drift summary query failed: ' . $result->errorString());
        }

        // A prepare()-level failure can leave DB's error flag unset, so the
        // absent row is the other half of the evidence: SELECT SUM(...) always
        // returns exactly one row, even over an empty table.
        $row = $result->first();
        if (!is_object($row)) {
            throw new \RuntimeException('Owner field drift summary returned no aggregate row.');
        }

        $fields = [];
        foreach (array_keys(RECONCILE_OWNER_FIELDS) as $field) {
            $fields[$field] = (int) ($row->{'drift_' . $field} ?? 0);
        }

        return [
            'fields'          => $fields,
            'carsWithDrift'   => (int) ($row->cars_with_drift ?? 0),
            'ownersWithDrift' => (int) ($row->owners_with_drift ?? 0),
        ];
    }
}

if (!function_exists('findOrphanedOwnerCarCount')) {
    /**
     * Count cars whose `user_id` references a user row that no longer exists.
     *
     * Reported for information only — these cars are never repaired by this
     * script and never appear in {@see findOwnerIdsWithDrift()}.
     *
     * @param DatabaseInterface $db Database handle
     * @return int Number of orphaned cars
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerFieldDriftSummary()} for why an unchecked failure
     *         here would silently read as "no orphans".
     */
    function findOrphanedOwnerCarCount(DatabaseInterface $db): int
    {
        $result = $db->query(
            'SELECT COUNT(*) AS orphaned FROM cars c '
            . 'LEFT JOIN users u ON u.id = c.user_id '
            . 'WHERE c.user_id > 0 AND u.id IS NULL'
        );
        if ($result->error()) {
            throw new \RuntimeException('Orphaned owner car count query failed: ' . $result->errorString());
        }

        // As above: COUNT(*) always returns one row, so a missing row is
        // evidence of a prepare()-level failure the error flag did not catch.
        $row = $result->first();
        if (!is_object($row)) {
            throw new \RuntimeException('Orphaned owner car count returned no aggregate row.');
        }

        return (int) ($row->orphaned ?? 0);
    }
}

if (!function_exists('findOwnerIdsWithDrift')) {
    /**
     * The distinct owner IDs having at least one drifted car.
     *
     * This is the work list the Execute step iterates.
     *
     * @param DatabaseInterface $db Database handle
     * @return list<int> Owner (user) IDs, ascending. An empty list is a
     *         legitimate success result (no drift anywhere), which is why this
     *         function checks only `error()` and not row presence.
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerFieldDriftSummary()}. An unchecked failure here
     *         would make Execute silently repair nothing.
     */
    function findOwnerIdsWithDrift(DatabaseInterface $db): array
    {
        $sql = 'SELECT DISTINCT c.user_id ' . reconcileFromClause(findNoOwnerAccountId($db))
            . ' AND ' . reconcileDriftPredicate()
            . ' ORDER BY c.user_id';

        $result = $db->query($sql);
        if ($result->error()) {
            throw new \RuntimeException('Owner drift work-list query failed: ' . $result->errorString());
        }

        $ownerIds = [];
        foreach ($result->results() as $row) {
            $ownerIds[] = (int) $row->user_id;
        }

        return $ownerIds;
    }
}

if (!function_exists('findDriftedCarDetails')) {
    /**
     * One page of per-car drift detail: the car's stored value and the owner's
     * current value, for each field that actually differs.
     *
     * `fields` is pruned — it holds only the subset of the nine owner-contact
     * columns that genuinely differ for that car, not all nine. It is never
     * empty: a row only reaches this output because the SQL drift predicate
     * already matched at least one differing field, hence
     * `non-empty-array` below. Callers must therefore iterate the keys present
     * rather than assuming a fixed nine-key shape.
     *
     * @param DatabaseInterface $db     Database handle
     * @param int               $limit  Maximum rows to return
     * @param int               $offset Rows to skip
     * @return list<array{carId: int, ownerId: int, ownerName: string, fields: non-empty-array<string, array{car: string|null, owner: string|null}>}>
     * @throws \RuntimeException If the query fails — see
     *         {@see findOwnerFieldDriftSummary()}. An unchecked failure here
     *         would render an empty "no drifted cars" table over real drift.
     */
    function findDriftedCarDetails(DatabaseInterface $db, int $limit, int $offset): array
    {
        $selects = ['c.id AS car_id', 'c.user_id AS owner_id', 'u.fname AS owner_fname', 'u.lname AS owner_lname'];
        foreach (RECONCILE_OWNER_FIELDS as $field => $sourceAlias) {
            $selects[] = "c.{$field} AS car_{$field}";
            $selects[] = "{$sourceAlias}.{$field} AS owner_{$field}";
        }

        // LIMIT/OFFSET are interpolated, not bound: this project's DB::query()
        // binds parameters as strings (PDO emulated-prepare default), and
        // MySQL rejects a quoted string in LIMIT/OFFSET position ("You have
        // an error in your SQL syntax ... near ''50' OFFSET '0''") — verified
        // against the live connection. Safe here because both are validated
        // non-negative integers from typed function parameters, never raw
        // user input reaching this string directly.
        $safeLimit = max(0, $limit);
        $safeOffset = max(0, $offset);

        $sql = 'SELECT ' . implode(', ', $selects) . ' ' . reconcileFromClause(findNoOwnerAccountId($db))
            . ' AND ' . reconcileDriftPredicate()
            . " ORDER BY c.user_id, c.id LIMIT {$safeLimit} OFFSET {$safeOffset}";

        $result = $db->query($sql);
        if ($result->error()) {
            throw new \RuntimeException('Drifted car detail query failed: ' . $result->errorString());
        }
        $rows = $result->results();

        $details = [];
        foreach ($rows as $row) {
            $fields = [];
            foreach (array_keys(RECONCILE_OWNER_FIELDS) as $field) {
                $carValue = $row->{'car_' . $field};
                $ownerValue = $row->{'owner_' . $field};
                // `website` is coalesced symmetrically on BOTH sides, the same
                // way reconcileFieldMismatchExpression() does in SQL: a NULL
                // profiles.website can never actually reach the car (Owner::find()
                // normalizes it to '' before any sync writes it), so treating a
                // NULL owner value as real NULL here would flag a pair the sync
                // can never actually produce or resolve. But cars.website itself
                // also holds a mix of NULL and '' for "no value" in real data —
                // coalescing only the owner side would then turn a genuine
                // NULL-matches-NULL car into a false NULL-vs-'' mismatch. Both
                // sides are normalized to keep this pruning decision consistent
                // with the SQL predicate that selected the row in the first
                // place. city/state/country get the identical normalization in
                // Owner::find() but are deliberately left un-coalesced —
                // missing location data is handled elsewhere.
                if ($field === 'website') {
                    $carValue = $carValue ?? '';
                    $ownerValue = $ownerValue ?? '';
                }
                // Both halves are needed: SQL NULL and '' stringify identically,
                // so the string comparison alone would prune a NULL-vs-'' pair
                // that the null-safe `<=>` predicate correctly counts as drift
                // (the sync writes one or the other deliberately) for every
                // field except the website case just normalized above.
                if ((string) $carValue === (string) $ownerValue && ($carValue === null) === ($ownerValue === null)) {
                    continue;
                }
                $fields[$field] = [
                    'car'   => $carValue === null ? null : (string) $carValue,
                    'owner' => $ownerValue === null ? null : (string) $ownerValue,
                ];
            }

            $details[] = [
                'carId'     => (int) $row->car_id,
                'ownerId'   => (int) $row->owner_id,
                'ownerName' => trim((string) ($row->owner_fname ?? '') . ' ' . (string) ($row->owner_lname ?? '')),
                'fields'    => $fields,
            ];
        }

        return $details;
    }
}

// AJAX handlers — must run before any HTML output.
if ($method === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if (!Token::check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid security token']);
        exit;
    }

    if (!isAdmin()) {
        http_response_code(403);
        logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
            'Non-admin attempted AJAX action on owner field reconciliation script');
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    if ($_POST['action'] === 'analyze') {
        try {
            $summary = findOwnerFieldDriftSummary(dbi());
            $orphanedCars = findOrphanedOwnerCarCount(dbi());
            // Reported as a sibling count rather than folded into the summary's
            // `fields` map: those nine are "cars this run will repair", and a
            // conflict is the opposite — cars this run will deliberately refuse
            // to touch, along with the rest of their owner's fleet.
            $conflictCars = findWebsiteConflictCars(dbi());
            $conflictOwnerIds = findOwnerIdsWithWebsiteConflict(dbi());
            $noOwnerAccountCars = findNoOwnerAccountCarCount(dbi(), findNoOwnerAccountId(dbi()));

            $recordingWarning = null;
            if ($summary['carsWithDrift'] === 0) {
                admin_script_record_completion(__FILE__, (int) $user->data()->id, function (string $msg) use (&$recordingWarning) {
                    $recordingWarning = $msg;
                });
            }

            echo json_encode([
                'success'          => true,
                'fields'           => $summary['fields'],
                'carsWithDrift'    => $summary['carsWithDrift'],
                'ownersWithDrift'  => $summary['ownersWithDrift'],
                'orphanedCars'     => $orphanedCars,
                'noOwnerAccountCars'    => $noOwnerAccountCars,
                'websiteConflictCars'   => count($conflictCars),
                'websiteConflictOwners' => count($conflictOwnerIds),
                'recordingWarning' => $recordingWarning,
            ]);
        } catch (\Throwable $e) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                'Owner field drift analysis failed: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error'   => 'Analysis failed. Check server logs for details.',
            ]);
        }
        exit;
    }

    if ($_POST['action'] === 'details') {
        try {
            // TRADE-OFF, chosen deliberately: this fetches EVERY drifted car in
            // one response rather than a bounded page. The plan for #1961 called
            // out the opposite — full-table drift can run to hundreds of cars
            // (118 measured on the dev DB), and server-side chunking kept the
            // response bounded. But pairing that chunking with DataTables' own
            // client-side paging gave the admin two different "show me more"
            // controls that did different things, which read as a bug. The fix
            // is to make DataTables the single owner of paging/search/sort, and
            // that requires handing it the complete result set.
            //
            // findDriftedCarDetails()'s (limit, offset) contract is unchanged —
            // it is covered by pagination tests and used unpaginated only here,
            // via a sentinel limit. Revisit if the fleet grows enough that this
            // response or the client-side table becomes unwieldy.
            $details = findDriftedCarDetails(dbi(), PHP_INT_MAX, 0);

            // Flag the rows Execute will refuse to sync, so an admin reviewing
            // this list before running Step 3 can see which cars are at risk
            // and which owners will be held back entirely because of them.
            $conflictCarIds = [];
            $conflictOwnerIds = [];
            foreach (findWebsiteConflictCars(dbi()) as $conflict) {
                $conflictCarIds[$conflict['carId']] = true;
                $conflictOwnerIds[$conflict['ownerId']] = true;
            }
            foreach ($details as $index => $row) {
                $details[$index]['websiteConflict'] = isset($conflictCarIds[$row['carId']]);
                $details[$index]['ownerSkipped'] = isset($conflictOwnerIds[$row['ownerId']]);
            }

            echo json_encode([
                'success' => true,
                'cars'    => $details,
            ]);
        } catch (\Throwable $e) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                'Owner field drift detail lookup failed: ' . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error'   => 'Failed to load details. Check server logs for details.',
            ]);
        }
        exit;
    }
}

require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <style>
                .fix-results-container {
                    max-height: 500px;
                    overflow-y: auto;
                    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                    font-size: 0.875rem;
                    line-height: 1.4;
                }

                .fix-status-line {
                    margin: 0.25rem 0;
                    padding: 0.125rem 0;
                }

                .reconcile-table {
                    font-size: 0.85rem;
                }

                .reconcile-table th {
                    background-color: #f8f9fa;
                    font-weight: 600;
                }

                /* The conflict flag must survive DataTables' striping, which
                   paints alternate rows itself. */
                #detailsTable tbody tr.reconcile-conflict-row > td {
                    background-color: #fdf3f4;
                }

                /* Marks the first row of each car's group while the table is
                   sorted by Car. Blanking the repeated Car/Owner cells alone
                   leaves no cue where one car ends and the next begins — this
                   rule draws that boundary. */
                #detailsTable tbody tr.reconcile-group-start > td {
                    border-top: 2px solid #adb5bd;
                }
            </style>

            <link rel="stylesheet" href="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>usersc/css/datatables.min.css">
            <script src="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>usersc/js/datatables.min.js"></script>

            <!-- Initial Description Card -->
            <div class="row" id="descriptionSection">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-refresh"></i> Reconcile Owner Fields
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Finds cars whose denormalized owner-contact columns have drifted away from their owner's current <code>users</code> / <code>profiles</code> data, and repairs them.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Compares nine columns on each car — <code>email</code>, <code>fname</code>, <code>lname</code>, <code>city</code>, <code>state</code>, <code>country</code>, <code>lat</code>, <code>lon</code>, <code>website</code> — against the owner's current values</li>
                                    <li>Reports per-field drift counts, plus how many cars and owners are affected</li>
                                    <li>Repairs drift by calling the same owner-sync routine used when an owner saves their own profile</li>
                                    <li>Safe to run repeatedly — a run with no drift makes no changes</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> What this script does <em>not</em> do:</h5>
                                <ul class="mb-0">
                                    <li>Does not touch <code>owner_last_updated</code> — a mechanical refresh is not the owner confirming their car's data, so synced cars stay eligible for verification</li>
                                    <li>Does not repair cars whose <code>user_id</code> points at a user that no longer exists — those are reported for information only</li>
                                    <li>Does not repair cars parked on the <code>noowner</code> system account — that account holds placeholder data, so syncing would overwrite each car's last-known real owner details. Also reported for information only</li>
                                    <li>Does not replace the save-time sync hooks; it is a backstop for drift those hooks missed</li>
                                    <li>Does not overwrite a car's own <code>website</code> with a different one from the owner's profile — a differing value here is legacy data entered before v2.30.1 made website owner-level only. If any of an owner's cars has such a conflict, that <strong>whole owner is skipped</strong> for the run and flagged for manual review</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button data-action="startAnalysis" class="btn btn-success">
                                    <i class="fa fa-search"></i> Step 1: Analyze Drift
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analysis Results Section -->
            <div class="row" id="analysisSection" style="display: none;">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-list-alt"></i> Analysis Results
                            </h2>
                        </div>
                        <div class="card-body" id="analysisResults">
                            <div class="text-center">
                                <i class="fa fa-spinner fa-spin fa-3x"></i>
                                <p class="mt-3">Analyzing owner field drift...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Changes Section -->
            <div class="row" id="detailsSection" style="display: none;">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-list"></i> Drifted Cars
                            </h2>
                        </div>
                        <div class="card-body">
                            <div id="detailedChanges"></div>
                            <div id="detailsFooter" class="text-center mt-4"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="row mb-4" id="progressSection" style="display: none;">
                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-cogs"></i> Progress
                            </h2>
                            <small class="text-muted">
                                <i class="fa fa-clock-o"></i> Started: <span id="startTimeText"></span>
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="progress car-progress mb-2">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                                    id="progressBar"
                                    role="progressbar"
                                    style="width: 0%;"
                                    aria-valuenow="0"
                                    aria-valuemin="0"
                                    aria-valuemax="100">0%</div>
                            </div>
                            <div id="currentStatus" class="text-muted small">
                                <i class="fa fa-cog fa-spin"></i> Initializing...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-bar-chart"></i> Summary
                            </h2>
                        </div>
                        <div class="card-body" id="summaryContent">
                            <div class="text-muted">
                                <em>Waiting for process to complete...</em>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Log Section -->
            <div class="row mb-4" id="logSection" style="display: none;">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fa fa-list-alt"></i> Progress Log
                            </h3>
                        </div>
                        <div class="card-body fix-results-container" id="resultsContainer">
                            <div class="fix-status-line text-muted">
                                <small><em>Initializing process...</em></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
                let processStarted = false;
                const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>';
                const FIELD_LABELS = {
                    email: 'Email',
                    fname: 'First Name',
                    lname: 'Last Name',
                    city: 'City',
                    state: 'State',
                    country: 'Country',
                    lat: 'Latitude',
                    lon: 'Longitude',
                    website: 'Website'
                };

                function escapeHtml(s) {
                    if (s == null) return '';
                    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
                }

                function formatValue(v) {
                    if (v == null) return '<em class="text-muted">NULL</em>';
                    if (v === '') return '<em class="text-muted">(empty)</em>';
                    return escapeHtml(v);
                }

                function updateProgress(current, total, statusMessage) {
                    if (total === 0) return;

                    const percentage = Math.round((current / total) * 100);
                    const progressBar = document.getElementById('progressBar');

                    progressBar.style.width = percentage + '%';
                    progressBar.setAttribute('aria-valuenow', percentage);
                    progressBar.textContent = percentage + '%';

                    if (statusMessage) {
                        const statusElement = document.getElementById('currentStatus');
                        const icon = document.createElement('i');
                        icon.className = percentage >= 100
                            ? 'fa fa-check-circle text-success'
                            : 'fa fa-cog fa-spin';
                        statusElement.replaceChildren(icon, document.createTextNode(' ' + statusMessage));
                    }
                }

                function showCompletionSummary(stats, isError) {
                    const statusText = isError
                        ? 'Reconciliation completed with errors.'
                        : 'Reconciliation completed successfully!';
                    updateProgress(100, 100, statusText);

                    const icon = isError
                        ? '<i class="fa fa-exclamation-triangle text-warning"></i>'
                        : '<i class="fa fa-check-circle text-success"></i>';
                    const title = isError ? 'Completed with Errors' : 'Complete!';

                    const summaryContent = document.getElementById('summaryContent');
                    summaryContent.innerHTML = `
        <div class="mb-3">
            <h5>${icon} ${title}</h5>
            <small class="text-muted">Completed at: ${new Date().toLocaleString()}</small>
        </div>
        <div class="mb-3">
            ${stats}
        </div>
        <div class="text-center">
            <button data-action="returnToMenu" class="btn btn-outline-primary">
                <i class="fa fa-arrow-left"></i> Return to FIX Menu
            </button>
        </div>
    `;
                }

                function addLogMessage(message) {
                    const container = document.getElementById('resultsContainer');
                    if (!container) return;

                    const line = document.createElement('div');
                    line.className = 'fix-status-line';

                    if (message.includes('✅')) {
                        line.className += ' text-success';
                    } else if (message.includes('✗') || message.includes('❌')) {
                        line.className += ' text-danger';
                    } else if (message.includes('===')) {
                        line.className += ' text-info font-weight-bold';
                    } else if (message.includes('Processing')) {
                        line.className += ' text-primary';
                    }

                    line.textContent = message;
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                }

                function startAnalysis() {
                    document.getElementById('descriptionSection').style.display = 'none';
                    document.getElementById('analysisSection').style.display = 'block';

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=analyze&csrf=' + encodeURIComponent(CSRF_TOKEN)
                    })
                    .then(response => response.json())
                    .then(data => {
                        const resultsElement = document.getElementById('analysisResults');

                        if (!data.success) {
                            resultsElement.innerHTML = `
                                <div class="alert alert-danger">
                                    <h4><i class="fa fa-exclamation-circle"></i> Analysis Error</h4>
                                    <p>${escapeHtml(data.error)}</p>
                                </div>
                                <div class="text-center">
                                    <button data-action="returnToMenu" class="btn btn-primary">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            `;
                            return;
                        }

                        // Deliberately its own alert, not a row in the per-field
                        // drift table: those nine counts are cars this run will
                        // repair, while a conflict is a car it will refuse to
                        // touch — along with everything else its owner holds.
                        const conflictHtml = data.websiteConflictCars > 0
                            ? `<div class="alert alert-danger"><h5><i class="fa fa-exclamation-triangle"></i> Website Conflicts — Owners Will Be Skipped</h5>
                                   <p class="mb-0"><strong>${data.websiteConflictCars}</strong> car(s) across <strong>${data.websiteConflictOwners}</strong> owner(s) hold a website that differs from their owner's profile website. Syncing would destroy the car's own value, so <strong>every one of those owners is skipped entirely</strong> by Step 3 — including their other, correct fields. Resolve each conflict by hand, then re-run.</p>
                               </div>`
                            : '';

                        const orphanHtml = data.orphanedCars > 0
                            ? `<div class="alert alert-secondary"><i class="fa fa-unlink"></i> <strong>${data.orphanedCars}</strong> car(s) reference a user that no longer exists. These are reported for information only and are not repaired by this script.</div>`
                            : '';

                        const noOwnerHtml = data.noOwnerAccountCars > 0
                            ? `<div class="alert alert-secondary"><i class="fa fa-user-times"></i> <strong>${data.noOwnerAccountCars}</strong> car(s) are parked on the <code>noowner</code> system account, which holds placeholder contact data rather than a real owner's. Syncing them would overwrite each car's last-known real owner details with that placeholder, so they are excluded from drift detection entirely — reported here for information only.</div>`
                            : '';

                        if (data.carsWithDrift === 0) {
                            const recordingWarningHtml = data.recordingWarning
                                ? `<div class="alert alert-warning mt-2 mb-0">${escapeHtml(data.recordingWarning)}</div>`
                                : '';
                            resultsElement.innerHTML = `
                                <div class="alert alert-success">
                                    <h4><i class="fa fa-check-circle"></i> No Owner Field Drift Found!</h4>
                                    <p>Every car's owner-contact columns already match their owner's current details.</p>
                                </div>
                                ${conflictHtml}
                                ${orphanHtml}
                                ${noOwnerHtml}
                                ${recordingWarningHtml}
                                <div class="text-center">
                                    <button data-action="returnToMenu" class="btn btn-outline-primary">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            `;
                            return;
                        }

                        let rows = '';
                        Object.keys(FIELD_LABELS).forEach(field => {
                            const count = data.fields[field] || 0;
                            if (count > 0) {
                                rows += `<tr><td>${escapeHtml(FIELD_LABELS[field])}</td><td><code>${escapeHtml(field)}</code></td><td class="text-end">${count}</td></tr>`;
                            }
                        });

                        resultsElement.innerHTML = `
                            <div class="alert alert-warning">
                                <h4><i class="fa fa-exclamation-triangle"></i> Drift Found</h4>
                                <p class="mb-0"><strong>${data.carsWithDrift}</strong> car(s) across <strong>${data.ownersWithDrift}</strong> owner(s) differ from their owner's current details.</p>
                            </div>
                            ${conflictHtml}
                            ${orphanHtml}
                            ${noOwnerHtml}
                            <table class="table table-sm table-bordered reconcile-table">
                                <thead><tr><th>Field</th><th>Column</th><th class="text-end">Cars Affected</th></tr></thead>
                                <tbody>${rows}</tbody>
                            </table>
                            <div class="text-center mt-4">
                                <button data-action="showDetailedChanges" class="btn btn-success">
                                    <i class="fa fa-arrow-right"></i> Step 2: Review Drifted Cars
                                </button>
                                <button data-action="abortProcess" class="btn btn-danger ms-2">
                                    <i class="fa fa-times"></i> Abort
                                </button>
                            </div>
                        `;
                    })
                    .catch(error => {
                        document.getElementById('analysisResults').innerHTML = `
                            <div class="alert alert-danger">
                                <h4><i class="fa fa-exclamation-circle"></i> Error</h4>
                                <p>Failed to fetch analysis: ${escapeHtml(error.message)}</p>
                            </div>
                        `;
                    });
                }

                function showDetailedChanges() {
                    document.getElementById('detailsSection').style.display = 'block';
                    document.getElementById('detailedChanges').innerHTML = `
                        <table id="detailsTable" class="table table-striped table-hover table-sm reconcile-table w-100">
                            <thead><tr><th>Car</th><th>Owner</th><th>Field</th><th>Current Car Value</th><th>Owner's Value</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    `;
                    document.getElementById('detailsFooter').innerHTML = '<i class="fa fa-spinner fa-spin fa-2x"></i>';
                    loadDetails();
                }

                /**
                 * Flatten one fetched page of cars into one DataTables row per
                 * (car, drifted field) pair.
                 *
                 * The pre-DataTables table grouped a car's fields under a single
                 * rowspan'd cell. DataTables sorts, searches and paginates whole
                 * <tr>s, so a real rowspan would be torn apart the first time a
                 * column header was clicked. Each row therefore carries its own
                 * copy of carId/ownerName, and the repeats are hidden at render
                 * time instead — see applyCarGrouping().
                 */
                function flattenDetailRows(cars) {
                    const rows = [];
                    cars.forEach(car => {
                        Object.keys(car.fields).forEach(field => {
                            rows.push({
                                carId: car.carId,
                                ownerId: car.ownerId,
                                ownerName: car.ownerName,
                                field: field,
                                fieldLabel: FIELD_LABELS[field] || field,
                                carValue: car.fields[field].car,
                                ownerValue: car.fields[field].owner,
                                // Only the website pair is the conflicted one; the
                                // owner's other rows are flagged as held back, not
                                // as the conflict itself.
                                isConflict: !!car.websiteConflict && field === 'website',
                                ownerSkipped: !!car.ownerSkipped
                            });
                        });
                    });
                    return rows;
                }

                /**
                 * Column index of the Car column, which grouping keys off.
                 */
                const DETAILS_CAR_COLUMN = 0;

                /**
                 * Whether the table is currently ordered by the Car column.
                 *
                 * The visual grouping below only makes sense while a car's rows
                 * are adjacent, which is only guaranteed under this ordering. Sort
                 * by any other column and grouping switches off entirely — every
                 * Car/Owner cell shows its own value again — rather than blanking
                 * cells whose "group" is one arbitrary row long.
                 */
                function isGroupedByCar(api) {
                    const order = api.order();
                    return Array.isArray(order) && order.length > 0
                        && Array.isArray(order[0]) && order[0][0] === DETAILS_CAR_COLUMN;
                }

                /**
                 * Full display-order HTML for a row's Car and Owner cells.
                 *
                 * Kept here rather than in the columns' `render` callbacks because
                 * grouping has to be able to restore these cells, and DataTables
                 * memoizes a row's rendered cells (settings.data[i].displayData) on
                 * first render and reuses them for the life of the row — a `render`
                 * that consulted neighbouring rows would compute once and then be
                 * wrong after every re-sort. See applyCarGrouping().
                 */
                function carCellHtml(rowData) {
                    return escapeHtml(rowData.carId);
                }

                function ownerCellHtml(rowData) {
                    return escapeHtml(rowData.ownerName) +
                        '<br/><small class="text-muted">#' + parseInt(rowData.ownerId, 10) + '</small>';
                }

                /**
                 * Blank the Car/Owner cells of any row that merely repeats the car
                 * named by the row above it, and mark the first row of each car
                 * group so CSS can rule a line above it.
                 *
                 * Driven from `rowCallback`, which DataTables fires for every row on
                 * every draw — including after a re-sort, a search, or a page change
                 * — so the decision is always re-derived against the order actually
                 * on screen. `createdRow` would not do: it fires once per row, when
                 * its <tr> is first built, and rows are reused across draws.
                 *
                 * Grouping applies only while the table is sorted by Car. Under any
                 * other order a car's rows are not adjacent, so there is no group to
                 * collapse and every cell is restored to its full value.
                 *
                 * @param {HTMLTableRowElement} tr        The row's <tr>
                 * @param {object}              rowData   That row's data object
                 * @param {object}              api       The DataTables API instance
                 * @param {number}              displayIndex Index within the current page
                 */
                function applyCarGrouping(tr, rowData, api, displayIndex) {
                    // Cell lookup by index, not by :nth-child — Responsive can hide
                    // columns, but it removes the <td> only after this callback, and
                    // cells[] stays in column order regardless.
                    const carCell = tr.cells[DETAILS_CAR_COLUMN];
                    const ownerCell = tr.cells[DETAILS_CAR_COLUMN + 1];
                    if (!carCell || !ownerCell) return;

                    let continuesGroup = false;
                    if (isGroupedByCar(api)) {
                        // rows({page:'current'}) is in display order, so the
                        // preceding row is simply the previous display index. The
                        // first row of a page always starts a group: its
                        // predecessor, if any, is not visible next to it.
                        if (displayIndex > 0) {
                            const previous = api.rows({ page: 'current' }).data()[displayIndex - 1];
                            continuesGroup = !!previous && previous.carId === rowData.carId;
                        }
                    }

                    carCell.innerHTML = continuesGroup ? '' : carCellHtml(rowData);
                    ownerCell.innerHTML = continuesGroup ? '' : ownerCellHtml(rowData);
                    tr.classList.toggle('reconcile-group-start', isGroupedByCar(api) && !continuesGroup);
                }

                /**
                 * Build the DataTable over the complete result set.
                 *
                 * The whole set arrives in one fetch (see the `action=details`
                 * handler), so DataTables owns paging, search and sort outright —
                 * there is no second, server-side "show me more" control competing
                 * with its own pager.
                 */
                function renderDetailsTable(rows) {
                    if ($.fn.DataTable.isDataTable('#detailsTable')) {
                        $('#detailsTable').DataTable().destroy();
                        $('#detailsTable tbody').empty();
                    }

                    $('#detailsTable').DataTable({
                        data: rows,
                        fixedHeader: true,
                        responsive: true,
                        pageLength: 25,
                        order: [[DETAILS_CAR_COLUMN, 'asc']],
                        language: { emptyTable: 'No drifted cars to show.' },
                        columns: [
                            {
                                data: 'carId',
                                title: 'Car',
                                // Sort/search/type calls must see the real value —
                                // blanking is applied to the live cell in
                                // rowCallback, never to the sortable data, or
                                // ordering by Car would collapse to one key.
                                render: function (data, type) {
                                    return type === 'display' ? carCellHtml({ carId: data }) : data;
                                }
                            },
                            {
                                data: 'ownerName',
                                title: 'Owner',
                                render: function (data, type, row) {
                                    return type === 'display' ? ownerCellHtml(row) : data;
                                }
                            },
                            {
                                data: 'fieldLabel',
                                title: 'Field',
                                render: function (data, type, row) {
                                    if (type !== 'display') return data;
                                    let html = escapeHtml(data);
                                    if (row.isConflict) {
                                        html += ' <span class="badge bg-danger" title="The car has its own website that differs from the owner\'s. Syncing would destroy it, so this owner is skipped entirely."><i class="fa fa-exclamation-triangle"></i> Conflict</span>';
                                    } else if (row.ownerSkipped) {
                                        html += ' <span class="badge bg-warning text-dark" title="Held back: another of this owner\'s cars has a website conflict.">Held back</span>';
                                    }
                                    return html;
                                }
                            },
                            {
                                data: 'carValue',
                                title: "Current Car Value",
                                render: function (data, type) {
                                    return type === 'display' ? formatValue(data) : (data == null ? '' : data);
                                }
                            },
                            {
                                data: 'ownerValue',
                                title: "Owner's Value",
                                render: function (data, type) {
                                    return type === 'display' ? formatValue(data) : (data == null ? '' : data);
                                }
                            }
                        ],
                        // rowCallback, not createdRow: createdRow fires once, when a
                        // row's <tr> is first created, and DataTables reuses that
                        // <tr> for the life of the table. Group boundaries depend on
                        // which row happens to precede this one in the CURRENT
                        // order, so they must be recomputed on every draw — which is
                        // exactly when rowCallback fires.
                        rowCallback: function (tr, rowData, displayIndex) {
                            tr.classList.toggle('reconcile-conflict-row', !!rowData.isConflict);
                            applyCarGrouping(tr, rowData, this.api(), displayIndex);
                        }
                    });
                }

                /**
                 * Fetch every drifted car in one request and hand the complete set
                 * to DataTables, which then owns paging, search and sort.
                 */
                function loadDetails() {
                    const footer = document.getElementById('detailsFooter');
                    footer.innerHTML = '<i class="fa fa-spinner fa-spin fa-2x"></i>';

                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=details&csrf=' + encodeURIComponent(CSRF_TOKEN)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            footer.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(data.error)}</div>`;
                            return;
                        }

                        renderDetailsTable(flattenDetailRows(data.cars));

                        footer.innerHTML = `
                            <p class="text-muted">Showing all ${data.cars.length} drifted car(s).</p>
                            <div class="alert alert-info text-start">
                                <h5><i class="fa fa-info-circle"></i> Next Steps:</h5>
                                <ul class="mb-0">
                                    <li>Each affected owner's details are copied to every car they own</li>
                                    <li><code>owner_last_updated</code> is deliberately left unchanged</li>
                                    <li>Each car is written in its own transaction and audited in <code>cars_hist</code></li>
                                    <li>Rows badged <span class="badge bg-danger">Conflict</span> are <strong>not</strong> synced — the car has its own website that differs from the owner's, and every car belonging to that owner is held back with it</li>
                                    <li>While sorted by <strong>Car</strong>, each car's fields are grouped under a single Car/Owner heading; sorting by any other column shows those values on every row</li>
                                </ul>
                            </div>
                            <button data-action="startProcessing" class="btn btn-success btn-lg">
                                <i class="fa fa-play"></i> Step 3: Reconcile Owner Fields
                            </button>
                            <button data-action="abortProcess" class="btn btn-danger btn-lg ms-2">
                                <i class="fa fa-times"></i> Abort
                            </button>
                        `;
                    })
                    .catch(error => {
                        footer.innerHTML = `<div class="alert alert-danger mb-0">Failed to load details: ${escapeHtml(error.message)}</div>`;
                    });
                }

                function startProcessing() {
                    if (processStarted) return;
                    processStarted = true;

                    document.getElementById('progressSection').style.display = '';
                    document.getElementById('logSection').style.display = '';

                    document.getElementById('startTimeText').textContent = new Date().toLocaleString();
                    document.getElementById('resultsContainer').innerHTML = '';

                    document.getElementById('execute-form').submit();
                }

                function abortProcess() {
                    if (confirm('Are you sure you want to abort? No changes will be made.')) {
                        window.close();
                    }
                }

                document.addEventListener('click', function(e) {
                    const btn = e.target.closest('[data-action]');
                    if (!btn) return;
                    switch (btn.dataset.action) {
                        case 'startAnalysis': startAnalysis(); break;
                        case 'showDetailedChanges': showDetailedChanges(); break;
                        case 'abortProcess': abortProcess(); break;
                        case 'startProcessing': startProcessing(); break;
                        case 'returnToMenu':
                            window.close();
                            break;
                    }
                });
            </script>

            <iframe id="execute-frame" name="execute-frame" style="display:none;"></iframe>
            <form id="execute-form" method="POST" action="" target="execute-frame">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="execute" value="1">
            </form>

            <?php
            // STEP 3: Execute reconciliation
            if ($method === 'POST' && isset($_POST['execute']) && !Token::check($_POST['csrf'] ?? '')) {
                // Without this branch, a stale/expired token (the page was left open
                // across a session boundary) fell through silently to whatever renders
                // below — the admin sees no explanation for why nothing happened inside
                // the hidden iframe. Matches the isAdmin() branch below's pattern for
                // messaging a rejected execute attempt back to the parent page.
                logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
                    'CSRF token check failed on owner field reconciliation execute form');
                $nonce = htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8');
                $msg = json_encode('❌ Session expired. Please reload the page and try again.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                echo '<script nonce="' . $nonce . '">
                    if (window.parent && window.parent.addLogMessage) { window.parent.addLogMessage(' . $msg . '); }
                    else if (window.addLogMessage) { addLogMessage(' . $msg . '); }
                </script>';
                exit;
            }

            if ($method === 'POST' && isset($_POST['execute']) && Token::check($_POST['csrf'] ?? '')) {
                if (!isAdmin()) {
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
                        'Non-admin attempted execute form on owner field reconciliation script');
                    $nonce = htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8');
                    $msg = json_encode('❌ Access denied: Administrator permission required.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    echo '<script nonce="' . $nonce . '">
                        if (window.parent && window.parent.addLogMessage) { window.parent.addLogMessage(' . $msg . '); }
                        else if (window.addLogMessage) { addLogMessage(' . $msg . '); }
                    </script>';
                    exit;
                }

                /**
                 * Streams one progress line (and optionally a progress-bar update)
                 * from this hidden iframe up to the parent page.
                 *
                 * @param string   $message    Line to append to the progress log
                 * @param int|null $percentage Progress percentage, or null to leave the bar alone
                 */
                function outputMessage(string $message, ?int $percentage = null): void {
                    global $userspice_nonce;
                    $jsMessage = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $nonce = htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8');
                    echo '<script nonce="' . $nonce . '">
                        if (window.parent && window.parent.addLogMessage) {
                            window.parent.addLogMessage(' . $jsMessage . ');
                        } else if (window.addLogMessage) {
                            addLogMessage(' . $jsMessage . ');
                        }
                    </script>';
                    if ($percentage !== null) {
                        echo '<script nonce="' . $nonce . '">
                            if (window.parent && window.parent.updateProgress) {
                                window.parent.updateProgress(' . $percentage . ', 100, ' . $jsMessage . ');
                            } else if (window.updateProgress) {
                                updateProgress(' . $percentage . ', 100, ' . $jsMessage . ');
                            }
                        </script>';
                    }
                    ob_flush();
                    flush();
                }

                $ownersScanned = 0;
                $carsUpdated = 0;
                $carsSkipped = 0;
                $carsFailed = 0;
                $ownerErrors = 0;
                $ownersSkippedForConflict = 0;
                $consecutiveOwnerErrors = 0;
                // Initialized here as well as inside the try below, so a failure
                // at the owner-ID fetch step still leaves it defined for the
                // summary output that reads it afterwards.
                $totalOwners = 0;
                $scriptFailed = false;
                $actingUserId = (int) $user->data()->id;

                try {
                    outputMessage('🔍 Finding owners with drifted cars...');
                    $ownerIds = findOwnerIdsWithDrift(dbi());
                    $totalOwners = count($ownerIds);

                    // Computed once, before the loop: a per-iteration query would
                    // re-scan the whole fleet for every owner.
                    $conflictsByOwner = [];
                    foreach (findWebsiteConflictCars(dbi()) as $conflict) {
                        $conflictsByOwner[$conflict['ownerId']][] = $conflict;
                    }

                    if ($totalOwners === 0) {
                        outputMessage('✅ No drift found — nothing to reconcile.');
                    } else {
                        outputMessage("Found {$totalOwners} owner(s) with at least one drifted car");
                        outputMessage('');
                        outputMessage('=== Reconciling Owner Fields ===');
                    }

                    foreach ($ownerIds as $ownerId) {
                        $ownersScanned++;
                        $percentage = (int) round(($ownersScanned / max(1, $totalOwners)) * 100);

                        // A website conflict holds back the WHOLE owner, not just
                        // the conflicted car: syncOwnerFieldsToCars() writes all
                        // nine fields to every car the owner holds and offers no
                        // per-car or per-field opt-out, and it is shared with the
                        // profile-save and sync-location callers, so it must not
                        // grow one for this job's sake. Declining the owner delays
                        // their other, correct fields until an admin resolves the
                        // conflict — the deliberate trade for never destroying a
                        // car's own website.
                        if (isset($conflictsByOwner[$ownerId])) {
                            $ownersSkippedForConflict++;

                            foreach ($conflictsByOwner[$ownerId] as $conflict) {
                                $ownerWebsite = $conflict['ownerWebsite'] ?? '(none)';
                                if ($ownerWebsite === '') {
                                    $ownerWebsite = '(empty)';
                                }
                                outputMessage(
                                    "⚠️ Owner {$ownerId}: SKIPPED — car {$conflict['carId']} website conflict "
                                    . "(car: '{$conflict['carWebsite']}', owner: '{$ownerWebsite}') needs manual review",
                                    $percentage
                                );
                            }

                            logger($actingUserId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                "Owner field reconciliation: owner {$ownerId} skipped entirely — "
                                . count($conflictsByOwner[$ownerId]) . ' car(s) have a website value that '
                                . 'differs from the owner profile and would be overwritten. Car IDs: '
                                . implode(', ', array_column($conflictsByOwner[$ownerId], 'carId')));

                            continue;
                        }

                        // NO outer transaction here: syncOwnerFieldsToCars() manages its own
                        // per-car transactions and throws if one is already open.
                        try {
                            $result = (new Owner($ownerId, dbi()))->syncOwnerFieldsToCars();

                            $carsUpdated += $result->updatedCount();
                            $carsSkipped += $result->skippedCount();
                            $carsFailed  += $result->failedCount();

                            $consecutiveOwnerErrors = 0;

                            if ($result->isCompleteSuccess()) {
                                outputMessage("✅ Owner {$ownerId}: " . $result->successMessage(), $percentage);
                            } else {
                                outputMessage("✗ Owner {$ownerId}: " . $result->failedCarsPhrase(), $percentage);
                                // Name the cars, not just how many: a later debugging
                                // session should not have to cross-reference by timestamp.
                                $failedIds = $result->failed === [] ? 'none' : implode(', ', $result->failed);
                                logger($actingUserId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                    "Owner field reconciliation: owner {$ownerId} had {$result->failedCount()} "
                                    . "failed car(s) — car IDs: {$failedIds}");
                            }
                        } catch (OwnerDatabaseException | CarDatabaseException $e) {
                            // Only infrastructure-level failures are absorbed here. One
                            // owner's DB trouble must not abort a run that may cover 100+
                            // owners. Any OTHER \Throwable is a programming error in this
                            // script (a \TypeError, a leaked transaction) that will repeat
                            // identically for every remaining owner, so it deliberately
                            // propagates to the outer handler and aborts the run.
                            $ownerErrors++;
                            $consecutiveOwnerErrors++;

                            // A failure thrown mid-transaction could leave one open, which
                            // would then trip syncOwnerFieldsToCars()'s inTransaction()
                            // guard for every subsequent owner. Close it here so one
                            // failure cannot cascade.
                            if (dbi()->inTransaction()) {
                                dbi()->rollBack();
                            }

                            outputMessage("❌ Owner {$ownerId} failed: " . get_class($e) . ': ' . $e->getMessage(), $percentage);
                            logger($actingUserId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                "Owner field reconciliation failed for owner {$ownerId}: " . $e->getMessage());

                            if (reconcileShouldAbortForConsecutiveErrors($consecutiveOwnerErrors)) {
                                throw new \RuntimeException(
                                    RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS . ' consecutive owners failed to sync, which '
                                    . 'indicates a systemic fault rather than per-owner trouble — aborting rather than '
                                    . 'retrying it for every remaining owner. Last error: ' . $e->getMessage(),
                                    0,
                                    $e
                                );
                            }
                        }
                    }

                    outputMessage('');
                    outputMessage("Owners scanned: {$ownersScanned} | Cars updated: {$carsUpdated} | "
                        . "Cars skipped: {$carsSkipped} | Cars failed: {$carsFailed} | Owner errors: {$ownerErrors} | "
                        . "Owners held back by website conflict: {$ownersSkippedForConflict}");

                    logger($actingUserId, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                        "Owner field reconciliation completed — owners scanned: {$ownersScanned}, "
                        . "cars updated: {$carsUpdated}, cars skipped: {$carsSkipped}, "
                        . "cars failed: {$carsFailed}, owner-level errors: {$ownerErrors}, "
                        . "owners skipped for website conflict: {$ownersSkippedForConflict}");
                } catch (\Throwable $e) {
                    $scriptFailed = true;
                    outputMessage('❌ ERROR during reconciliation: ' . $e->getMessage());
                    outputMessage("Progress before failure: {$ownersScanned} owner(s) processed, {$carsUpdated} car(s) updated");
                    try {
                        logger($actingUserId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                            'Owner field reconciliation aborted: ' . $e->getMessage());
                    } catch (\Throwable $_) {
                        // Secondary failure — logger unavailable, ignore silently
                    }
                }

                $hadErrors = $scriptFailed || $ownerErrors > 0 || $carsFailed > 0;

                $recordingFailed = false;
                admin_script_record_completion(__FILE__, $actingUserId, function (string $msg) use (&$recordingFailed) {
                    $recordingFailed = true;
                    outputMessage($msg);
                });
                if (!$recordingFailed) {
                    outputMessage($hadErrors ? '⚠️  Script completion recorded (with errors)' : '✅ Script completion recorded');
                }

                outputMessage('');
                if (!$scriptFailed) {
                    outputMessage($hadErrors
                        ? 'Script completed with errors at ' . date('h:i:sa')
                        : 'Script completed at ' . date('h:i:sa'));
                }

                if (!$scriptFailed) {
                    $isErrorJs = $hadErrors ? 'true' : 'false';
                    $statsHtml = "
            <div class='row'>
                <div class='col-sm-6'><strong>Owners Scanned:</strong> {$ownersScanned}</div>
                <div class='col-sm-6'><strong>Cars Updated:</strong> {$carsUpdated}</div>
                <div class='col-sm-6'><strong>Cars Skipped:</strong> {$carsSkipped}</div>
                <div class='col-sm-6'><strong>Cars Failed:</strong> {$carsFailed}</div>
                <div class='col-sm-12'><strong>Owner-Level Errors:</strong> {$ownerErrors}</div>
                <div class='col-sm-12'><strong>Owners Held Back (Website Conflict):</strong> {$ownersSkippedForConflict}</div>
            </div>
        ";
                    echo '<script nonce="' . htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') . '">
    if (window.parent && window.parent.showCompletionSummary) {
        window.parent.showCompletionSummary(`' . $statsHtml . '`, ' . $isErrorJs . ');
    } else if (window.showCompletionSummary) {
        showCompletionSummary(`' . $statsHtml . '`, ' . $isErrorJs . ');
    }
    </script>';
                }
            }

            ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return buttons -->
<div style="margin-top: 20px; text-align: center;">
    <?= admin_script_close_button() ?>
    <button data-action="returnToMenu" class="btn btn-outline-secondary ms-2">
        <i class="fa fa-list" aria-hidden="true"></i> FIX Menu
    </button>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; ?>
