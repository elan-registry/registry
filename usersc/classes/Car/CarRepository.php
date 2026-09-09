<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use ElanRegistry\AppConstants;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;

/**
 * CarRepository - Database access layer for car operations
 *
 * Extracted from Car.php to provide a focused, testable data access layer.
 * Wraps DB operations for cars, cars_hist, elan_factory_info, and car_models tables.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarRepository
{
    /** @var array<string, string> Factory suffix code to description mapping */
    private const SUFFIX_MAP = [
        'A' => 'S4 FHC UK Market',
        'B' => 'S4 FHC Export',
        'C' => 'S4 DHC UK Market',
        'D' => 'S4 DHC Export',
        'E' => 'S4 S/E FHC UK Market',
        'F' => 'S4 S/E FHC Export',
        'G' => 'S4 S/E DHC UK Market',
        'H' => 'S4 S/E DHC Export',
        'J' => 'S4 FHC Federal',
        'K' => 'S4 DHC Federal',
        'L' => '+2S and +2S/130 UK Market',
        'M' => '+2S and +2S/130 Export',
        'N' => '+2S and +2S/130 Federal',
    ];

    private bool $transactionOwner = false;

    public function __construct(private DatabaseInterface $db) {}

    /**
     * Find a car by ID
     *
     * @param int $carId Car ID
     * @return object|null Car data object or null if not found
     * @throws CarDatabaseException If the query fails
     */
    public function findById(int $carId): ?object
    {
        $data = $this->db->get('cars', ['id', '=', $carId]);
        if ($data === false) {
            throw new CarDatabaseException("Failed to look up car $carId");
        }
        if ($data->count() === 0) {
            return null;
        }
        $result = $data->first();
        return is_object($result) ? $result : null;
    }

    /**
     * Find a car by ID and lock the row for the duration of the current transaction.
     * Must be called inside an active transaction (InnoDB SELECT...FOR UPDATE).
     *
     * @throws CarDatabaseException If query fails
     */
    public function findByIdForUpdate(int $carId): ?object
    {
        $this->db->query('SELECT * FROM cars WHERE id = ? FOR UPDATE', [$carId]);
        if ($this->db->error()) {
            throw new CarDatabaseException("Failed to lock car $carId for update");
        }
        if ($this->db->count() === 0) {
            return null;
        }
        $result = $this->db->first();
        return is_object($result) ? $result : null;
    }

    /**
     * Insert a new car record
     *
     * @param array<string, mixed> $fields Field values
     * @return bool True on success
     */
    public function insertCar(array $fields): bool
    {
        return $this->db->insert('cars', $fields);
    }

    /**
     * Update an existing car record
     *
     * @param int $carId Car ID
     * @param array<string, mixed> $fields Field values
     * @return bool True on success
     */
    public function updateCar(int $carId, array $fields): bool
    {
        return $this->db->update('cars', $carId, $fields);
    }

    /**
     * Delete a car by ID
     *
     * @param int $carId Car ID
     * @return bool True on success; false if the query itself fails (caller should treat as DB error)
     * @throws CarNotFoundException If no car with $carId exists (0 rows affected)
     */
    public function deleteCar(int $carId): bool
    {
        // Intentionally asymmetric: a query-level error returns false (caller decides how to
        // surface it) while a zero-rows result throws CarNotFoundException (semantically "the car
        // is gone"). CarAdministrationService wraps false in CarDatabaseException and lets
        // CarNotFoundException propagate so callers can distinguish the two failure modes.
        $this->db->query("DELETE FROM cars WHERE id = ?", [$carId]);
        if ($this->db->error()) {
            return false;
        }
        if ($this->db->count() === 0) {
            throw new CarNotFoundException("Car $carId not found for deletion");
        }
        return true;
    }

    /**
     * Bulk-reassign cars.user_id for all cars owned by a user.
     *
     * Used by the deletion hook to transfer a deleted user's cars to another user
     * (or set them ownerless) in a single UPDATE. Returns the number of rows changed.
     *
     * On database error, logs via LOG_CATEGORY_DATABASE_ERROR and throws so that
     * any enclosing transaction can roll back rather than commit over a partial state.
     *
     * @param int      $fromUserId Source user whose cars are being reassigned
     * @param int|null $toUserId   Target user, or null to clear ownership (user_id = NULL)
     * @return int                 Rows affected by the UPDATE (rows where user_id actually changed; 0 if no match or value already equal to target)
     * @throws CarDatabaseException If the UPDATE fails
     */
    public function reassignCarsByUser(int $fromUserId, ?int $toUserId): int
    {
        $this->db->query(
            'UPDATE cars SET user_id = ? WHERE user_id = ?',
            [$toUserId, $fromUserId]
        );

        if ($this->db->error()) {
            $target = $toUserId ?? 'NULL';
            $msg = "CarRepository::reassignCarsByUser failed (from={$fromUserId} to={$target}): " . $this->db->errorString();
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, $msg);
            throw new CarDatabaseException($msg);
        }

        return $this->db->count();
    }

    /**
     * Update a car's fields, scoped to both the car ID and its current owner.
     *
     * Used by the owner-profile sync to copy owner-contact values onto the cars
     * that owner holds. The `user_id` half of the WHERE clause is the point of
     * the method: it prevents writing one owner's details onto a car that was
     * transferred to somebody else after the caller took its list of car IDs.
     *
     * On database error, throws so that any enclosing transaction can roll back
     * rather than commit over a partial state. It deliberately does NOT log —
     * see the note below on why the exception is the durable record.
     *
     * IMPORTANT — the return is rows **changed**, not rows **matched**. PDO is not
     * configured with MYSQL_ATTR_FOUND_ROWS, so MySQL reports only rows whose values
     * actually differed: an UPDATE that rewrites identical values returns 0 even
     * though it matched a row. A 0 return is therefore **ambiguous** between
     * "the row matched but nothing needed to change" and "no row matched this
     * id + user_id pair (the car is no longer owned by this user)".
     *
     * Disambiguating those two cases is the **caller's** responsibility: on a 0
     * return, issue a follow-up ownership check
     * (`SELECT id FROM cars WHERE id = ? AND user_id = ?`) — a row present means
     * success with nothing to write, no row means the car left this owner. Do not
     * treat 0 as failure on its own.
     *
     * This method writes no log row of its own. It is designed to be called inside
     * a per-car transaction, and `logs` is InnoDB on the same connection, so any
     * row logged here would be destroyed by the caller's rollback — erasing the
     * diagnostic exactly when something has gone wrong. The exception message
     * carries the full error string and is the durable record; callers MUST log
     * it after their transaction has closed.
     *
     * @param int                  $carId  Car to update
     * @param int                  $userId Owner the car must currently belong to
     * @param array<string, mixed> $fields Column => value pairs to write; column names
     *                                     are developer-supplied identifiers, never
     *                                     user input
     * @return int                 Rows changed by the UPDATE (0 when nothing differed
     *                             *or* no row matched — see above)
     * @throws CarDatabaseException If the UPDATE fails, or if $fields is empty —
     *                              an empty write returning 0 is indistinguishable
     *                              to the caller from a matched-but-unchanged row,
     *                              so it would be reported as success having
     *                              written nothing
     */
    public function updateCarForOwner(int $carId, int $userId, array $fields): int
    {
        // An empty SET clause is invalid SQL, and returning 0 here would collide with
        // the ambiguous-zero contract above: the caller's ownership check would pass
        // and the car would be reported as synchronized with nothing written.
        if (empty($fields)) {
            throw new CarDatabaseException(
                "CarRepository::updateCarForOwner called with no fields (carId={$carId} userId={$userId}); "
                . 'an empty write cannot be distinguished from a no-op by the caller.'
            );
        }

        // Column names are interpolated, so they are validated against the same
        // identifier pattern and backtick-quoting DB::_sanitizeColumnName() applies.
        // That method is private to DB and absent from DatabaseInterface, so it
        // cannot be reused here; the rule is duplicated rather than skipped.
        $setClause = implode(', ', array_map(
            static function (string $column): string {
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $column)) {
                    throw new CarDatabaseException("Invalid column name: '{$column}'");
                }
                return "`{$column}` = ?";
            },
            array_keys($fields)
        ));

        $this->db->query(
            "UPDATE cars SET {$setClause} WHERE id = ? AND user_id = ?",
            [...array_values($fields), $carId, $userId]
        );

        if ($this->db->error()) {
            // Deliberately no logger() here. This method is designed to be called
            // inside a per-car transaction (see Owner::syncOwnerFieldsToCars()), and
            // `logs` is InnoDB on the same connection — a row written here is
            // destroyed by the caller's rollback, erasing the diagnostic exactly
            // when it is needed. The exception message carries the full error string
            // and is the durable record; callers MUST log it after their transaction
            // has closed. Matches Owner::carBelongsToOwner(), which does the same.
            throw new CarDatabaseException(
                "CarRepository::updateCarForOwner failed (carId={$carId} userId={$userId}): "
                . $this->db->errorString()
            );
        }

        return $this->db->count();
    }

    /**
     * Update the verification code for a car
     *
     * @param int $carId Car ID
     * @param string $verificationCode Verification code to set
     * @return bool True on success
     */
    public function updateVerificationCode(int $carId, string $verificationCode): bool
    {
        return $this->updateCar($carId, ['vericode' => $verificationCode]);
    }

    /**
     * Update the last-verified timestamp for a car
     *
     * @param int $carId Car ID
     * @param string $dateTime Datetime string in AppConstants::DATETIME_FORMAT
     * @return bool True on success
     */
    public function updateLastVerified(int $carId, string $dateTime): bool
    {
        return $this->updateCar($carId, ['last_verified' => $dateTime]);
    }

    /**
     * Update the timestamp at which a verification email was sent for a car
     *
     * @param int $carId Car ID
     * @param string $dateTime Datetime string in AppConstants::DATETIME_FORMAT
     * @return bool True on success
     */
    public function updateVerificationSentAt(int $carId, string $dateTime): bool
    {
        return $this->updateCar($carId, ['vericode_sent_at' => $dateTime]);
    }

    /**
     * Update the email-bounced flag for a car, and the address it bounced against
     *
     * The `$bouncedAddress` default of `null` exists only to make `$bounced =
     * false` (clearing the flag) callable without a throwaway argument — it
     * is NOT safe to omit while setting `$bounced = true`. Passing
     * `updateEmailBounced($id, true)` with no address is rejected rather
     * than silently writing `email_bounced = 1` with a null
     * `email_bounced_address`, which would leave the two columns
     * observably in disagreement (see the integration test asserting they
     * are never independently readable that way).
     *
     * @param int $carId Car ID
     * @param bool $bounced True if the owner's email address bounced
     * @param string|null $bouncedAddress The address the bounce was reported against.
     *                                    Required (non-null, non-empty) when $bounced is
     *                                    true; ignored (always written as null) when false.
     * @return bool True on success
     * @throws CarDatabaseException If $bounced is true and $bouncedAddress is null/empty
     */
    public function updateEmailBounced(int $carId, bool $bounced, ?string $bouncedAddress = null): bool
    {
        if ($bounced && ($bouncedAddress === null || $bouncedAddress === '')) {
            throw new CarDatabaseException(
                "CarRepository::updateEmailBounced (carId={$carId}): a non-empty bounced address is required when setting the flag."
            );
        }

        return $this->updateCar($carId, [
            'email_bounced' => $bounced ? 1 : 0,
            'email_bounced_address' => $bounced ? $bouncedAddress : null,
        ]);
    }

    /**
     * Update the email-suppressed flag for a car
     *
     * @param int $carId Car ID
     * @param bool $suppressed True if Brevo reported the owner's email address as suppressed
     * @return bool True on success
     */
    public function updateEmailSuppressed(int $carId, bool $suppressed): bool
    {
        return $this->updateCar($carId, ['email_suppressed' => $suppressed ? 1 : 0]);
    }

    /**
     * Update the timestamp at which the owner last updated their car record
     *
     * @param int $carId Car ID
     * @param string $dateTime Datetime string in AppConstants::DATETIME_FORMAT
     * @return bool True on success
     */
    public function updateOwnerLastUpdated(int $carId, string $dateTime): bool
    {
        return $this->updateCar($carId, ['owner_last_updated' => $dateTime]);
    }

    /**
     * SQL fragment that is true for a car whose registry data counts as fresh.
     *
     * A car is fresh when it was verified within the last year, or when its owner
     * updated it within the last year. Freshness is the primary definition;
     * staleness is its exact negation (see stalenessSql()).
     *
     * Deliberately excludes `cars.mtime`. That column is
     * `ON UPDATE CURRENT_TIMESTAMP`, so MySQL bumps it on any UPDATE that changes
     * a value — including an owner-profile sync that has nothing to do with the
     * car's data being confirmed. See Owner::syncOwnerFieldsToCars().
     *
     * Two forms that must NOT be used here, both of which reintroduce the
     * never-fires failure this expression exists to fix:
     *   - COALESCE(last_verified, owner_last_updated) returns the first non-NULL,
     *     not the greatest — a car verified three years ago but edited yesterday
     *     would read stale.
     *   - GREATEST(...) returns NULL when any argument is NULL — i.e. every
     *     never-verified car, which is the majority of the registry.
     *
     * CLOCK CONSISTENCY: this fragment compares against MySQL's NOW(), while the
     * PHP-side equivalent isFresh() uses PHP's clock. Both must resolve to the
     * same timezone, or the two forms can disagree at the one-year boundary from
     * clock skew alone. Sharing a host does NOT guarantee that: users/init.php
     * pins PHP to `America/Los_Angeles` on every web request, while MySQL
     * follows its own `time_zone` setting. They agree only when MySQL's resolved
     * zone matches that one — which must be checked per environment, not
     * assumed. (Production 2026-09-05: MySQL `SYSTEM` => MST (-7) and web PHP
     * PDT (-7) agree, but MST does not observe DST and LA does, so they diverge
     * by an hour from November to March.)
     *
     * @param string $alias Table alias to qualify the columns with. Developer-supplied,
     *                      never request-derived; validated as a SQL identifier before
     *                      interpolation so this helper cannot become an injection vector.
     * @return string Parenthesised boolean SQL expression
     * @throws CarValidationException If $alias is not a valid SQL identifier
     */
    public static function freshnessSql(string $alias = 'cars'): string
    {
        self::assertValidAlias($alias);

        return "(({$alias}.last_verified IS NOT NULL"
             . " AND {$alias}.last_verified >= NOW() - INTERVAL 1 YEAR)"
             . " OR {$alias}.owner_last_updated >= NOW() - INTERVAL 1 YEAR)";
    }

    /**
     * SQL fragment that is true for a car whose registry data counts as stale.
     *
     * This is the exact boolean negation of freshnessSql(), never an
     * approximation: neither operand of that expression can evaluate to NULL —
     * `last_verified` is guarded by an explicit IS NOT NULL, and
     * `owner_last_updated` is NOT NULL by schema — so SQL's three-valued logic
     * cannot produce an UNKNOWN that both forms would exclude.
     *
     * @param string $alias Table alias to qualify the columns with. Developer-supplied,
     *                      never request-derived; validated as a SQL identifier before
     *                      interpolation.
     * @return string Boolean SQL expression
     * @throws CarValidationException If $alias is not a valid SQL identifier
     */
    public static function stalenessSql(string $alias = 'cars'): string
    {
        return 'NOT ' . self::freshnessSql($alias);
    }

    /**
     * PHP-side equivalent of freshnessSql() for a single car's timestamps.
     *
     * NOT YET CALLED FROM PRODUCTION CODE, deliberately. Only the SQL form is
     * wired in today, via findVerificationEligible(). This is the designated
     * PHP-side counterpart for callers that hold a single car's timestamps
     * already and would otherwise re-derive the rule by hand — the send
     * pipeline in v2.30.3 is the intended first caller. It is kept rather than
     * deferred so the rule has exactly one definition per language: a caller
     * that reimplements "fresh" inline is how the #1953 defect returns.
     *
     * Delete this paragraph in the same PR that adds the first caller — see
     * issue #1970. A stale "not yet called" note misleads worse than none.
     *
     * Throws on a malformed or empty date string for either parameter rather than
     * returning a boolean. `owner_last_updated` is NOT NULL by schema and
     * `last_verified` is either NULL or a valid datetime, so a malformed value is
     * a programming error, not a data state: returning false would let corruption
     * silently trigger verification email, and returning true would silently
     * suppress it — the exact never-fires failure mode this rule exists to avoid.
     *
     * CLOCK CONSISTENCY: this method uses PHP's clock, while freshnessSql()
     * compares against MySQL's NOW(). Both must resolve to the same timezone, or
     * the two forms can disagree at the one-year boundary from clock skew alone.
     * Sharing a host does NOT guarantee that — see freshnessSql()'s note; PHP is
     * pinned to `America/Los_Angeles` by users/init.php while MySQL follows its
     * own `time_zone`.
     *
     * @param string|null $lastVerified      Datetime string, or null if never verified
     * @param string      $ownerLastUpdated  Datetime string (NOT NULL by schema)
     * @return bool True when the car counts as fresh
     * @throws CarValidationException If either argument is an empty or unparseable date string
     */
    public static function isFresh(?string $lastVerified, string $ownerLastUpdated): bool
    {
        $cutoff = strtotime('-1 year');

        // Both operands are validated BEFORE either comparison, deliberately not
        // short-circuiting on a fresh owner_last_updated. A malformed value is a
        // programming error or data corruption, and it must surface whichever
        // operand carries it — a caller whose last_verified is garbage would
        // otherwise get a silent `true` for as long as the owner timestamp happened
        // to be recent, and the defect would only appear a year later. This is the
        // one place the PHP form intentionally diverges from the SQL form's OR
        // short-circuit: SQL cannot raise on a malformed DATETIME because the
        // column type makes one unrepresentable.
        $ownerTs      = self::parseTimestamp($ownerLastUpdated, 'owner_last_updated');
        $verifiedTs   = $lastVerified === null
            ? null
            : self::parseTimestamp($lastVerified, 'last_verified');

        return $ownerTs >= $cutoff || ($verifiedTs !== null && $verifiedTs >= $cutoff);
    }

    /**
     * Validate a table alias before interpolating it into SQL.
     *
     * Same identifier pattern applied to column names in updateCarForOwner().
     *
     * @param string $alias Candidate SQL identifier
     * @return void
     * @throws CarValidationException If $alias is not a valid SQL identifier
     */
    private static function assertValidAlias(string $alias): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $alias)) {
            throw new CarValidationException("Invalid table alias: '{$alias}'");
        }
    }

    /**
     * Parse a datetime string to a Unix timestamp, rejecting empty or malformed input.
     *
     * Validates the calendar, not merely the shape: a well-formed but
     * impossible date such as '2026-02-30 12:00:00' is rejected rather than
     * silently rolled over to 2026-03-02.
     *
     * @param string $value  Datetime string to parse
     * @param string $column Column name, for the exception message
     * @return int Unix timestamp
     * @throws CarValidationException If $value is empty, malformed, or not a
     *                                real calendar date
     */
    private static function parseTimestamp(string $value, string $column): int
    {
        if ($value === '') {
            throw new CarValidationException(
                "CarRepository::isFresh received an empty {$column} value; "
                . 'the column is NOT NULL by schema, so this indicates corrupt data or a caller bug.'
            );
        }

        // strtotime() alone is far too permissive to enforce this contract: it
        // happily accepts 'now', 'tomorrow', '+1 day', ' ' and a bare '2026',
        // returning a plausible timestamp for each. Any of those reaching here
        // means corrupt data or a caller bug, but strtotime() would silently
        // turn them into a confident true/false — the exact silent-wrong-answer
        // this method throws to avoid.
        //
        // A regex shape check is NOT sufficient on its own. '2026-02-30
        // 12:00:00' and '0000-00-00 00:00:00' both match a \d{4}-\d{2}-\d{2}
        // pattern, and strtotime() silently rolls them over (to 2026-03-02 and
        // -0001-11-30 respectively) rather than returning false — so a corrupt
        // value would be reported FRESH and suppress the owner's verification
        // email for a year. That is #1953's own defect on the PHP side.
        //
        // This is reachable: users/classes/DB.php sets `sql_mode = ''` on every
        // application connection, so MySQL accepts and returns a zero-date in a
        // DATETIME column. NOT NULL blocks NULL, not '0000-00-00 00:00:00'.
        //
        // createFromFormat() with a leading '!' resets unparsed fields and
        // reports rollovers through getLastErrors(), which is what makes the
        // calendar — not merely the shape — the thing being validated.
        $normalized = str_replace('T', ' ', $value);
        $parsed     = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized);
        $errors     = \DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new CarValidationException(
                "CarRepository::isFresh received a malformed {$column} value: '{$value}'. "
                . 'Expected a valid Y-m-d H:i:s datetime as stored by the column.'
            );
        }

        return $parsed->getTimestamp();
    }

    /**
     * Find cars eligible for a verification email, ordered oldest-verified first.
     *
     * A car is eligible when it is not marked sold, has a non-null, non-empty
     * (deliverable) email address that has not bounced, and is stale — that is,
     * it was neither verified nor updated by its owner within the last year (see
     * stalenessSql()).
     *
     * @param int $limit Maximum rows to return (values below 1 return no rows)
     * @param int $offset Rows to skip (negative values are treated as 0)
     * @return array<object> Eligible car rows (empty if none)
     * @throws CarDatabaseException If the query fails
     * @throws CarValidationException If the freshness alias is rejected (unreachable — literal)
     */
    public function findVerificationEligible(int $limit, int $offset): array
    {
        // LIMIT/OFFSET are interpolated rather than bound: DB::query() binds every
        // parameter as PDO::PARAM_STR, and under emulated prepares that renders
        // LIMIT '10', which MySQL rejects. Safe because $limit/$offset are typed
        // `int` in this method's signature (PHP coerces at the call boundary under
        // declare(strict_types=1)) and clamped non-negative below — never string
        // data, so injection is not possible even though the values are interpolated.
        $limit  = max(0, $limit);
        $offset = max(0, $offset);

        // solddate is a DATE column; under STRICT_TRANS_TABLES, comparing it to ''
        // is a hard SQL error (ERROR 1525: Incorrect DATE value), not a no-op — the
        // column has no empty-string state, only NULL. email similarly needs an
        // explicit NULL check: `!= ''` alone evaluates to UNKNOWN (not FALSE)
        // for a NULL email under SQL's three-valued logic. UNKNOWN excludes the
        // row from this WHERE just as FALSE would, so the effect is the desired
        // one, but the mechanism is not the obvious one.
        $stale = self::stalenessSql('cars');

        $result = $this->db->query(
            "SELECT * FROM cars
              WHERE solddate IS NULL
                AND email_bounced = 0
                AND email IS NOT NULL AND email != ''
                AND {$stale}
              ORDER BY last_verified ASC
              LIMIT {$limit} OFFSET {$offset}"
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findVerificationEligible failed (limit={$limit} offset={$offset}): "
                . $this->db->errorString()
            );
        }
        return $result->results();
    }

    /**
     * Update the sold date for a car
     *
     * @param int $carId Car ID
     * @param string $soldDate Date string in Y-m-d format
     * @return bool True on success
     */
    public function updateSoldDate(int $carId, string $soldDate): bool
    {
        return $this->updateCar($carId, ['solddate' => $soldDate]);
    }

    /**
     * Update the image JSON for a car using compare-and-swap to prevent lost updates.
     *
     * Returns true when exactly 1 row was updated, false when 0 rows matched
     * (indicating a concurrent modification — the caller may retry or raise a conflict error).
     *
     * Callers must not issue a no-op write: MySQL reports rows *changed*, not
     * rows *matched* (PDO::MYSQL_ATTR_FOUND_ROWS is unset), so writing a value
     * identical to the stored one returns false despite matching the row.
     * Compare before calling and skip the write when nothing changes.
     *
     * @param int         $carId        Car ID
     * @param string      $newJson      New JSON-encoded image list
     * @param string|null $expectedJson The image value that must currently be
     *                                  stored (CAS guard); null matches a NULL
     *                                  column, which is the state of a car that
     *                                  has never had an image
     * @return bool True if the row was updated, false on concurrent modification
     * @throws CarDatabaseException If the query itself fails
     */
    public function updateImage(int $carId, string $newJson, ?string $expectedJson): bool
    {
        // `<=>` is MySQL's null-safe equality. Plain `=` is never true against a
        // NULL column, and cars.image is nullable with no default, so a car that
        // has never had an image cannot be matched by `image = ''` — the CAS
        // would reject every such update. `<=>` matches NULL to NULL and behaves
        // identically to `=` for non-NULL values.
        $this->db->query(
            'UPDATE cars SET image = ? WHERE id = ? AND image <=> ?',
            [$newJson, $carId, $expectedJson]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException('Image update query failed');
        }
        return $this->db->count() === 1;
    }

    /**
     * Find a car by its composite chassis key (year, type, chassis).
     *
     * @param string $year Model year
     * @param string $type Type code (from CarValidator::parseModel())
     * @param string $chassis Chassis number
     * @return object|null Car data (id, user_id) or null if not found
     * @throws CarDatabaseException If the query fails
     */
    public function findByChassisKey(string $year, string $type, string $chassis): ?object
    {
        $result = $this->db->query(
            'SELECT id, user_id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
            [$year, $type, $chassis]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findByChassisKey failed for year={$year} type={$type} chassis={$chassis}: "
                . $this->db->errorString()
            );
        }
        $row = $result->first();
        return is_object($row) ? $row : null;
    }

    /**
     * Find a car by verification code
     *
     * @param string $code Verification code
     * @return object|null Car data or null
     * @throws CarDatabaseException If the query fails
     */
    public function findByVerificationCode(string $code): ?object
    {
        $result = $this->db->query('SELECT * FROM cars WHERE vericode = ?', [$code]);
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findByVerificationCode failed: " . $this->db->errorString()
            );
        }
        if ($result->count() > 0) {
            return $result->first();
        }
        return null;
    }

    /**
     * Get all cars for sitemap generation
     *
     * @return list<object{id: int, mtime: string}>
     * @throws CarDatabaseException If query fails
     */
    public function getAllForSitemap(): array
    {
        $this->db->query('SELECT id, mtime FROM cars ORDER BY id');
        if ($this->db->error()) {
            throw new CarDatabaseException('Failed to query cars for sitemap generation: ' . $this->db->errorString());
        }
        return $this->db->results();
    }

    /**
     * Find car IDs owned by a specific user
     *
     * @param int $ownerId Owner user ID
     * @return array<object> Array of objects with 'id' property
     * @throws CarDatabaseException If the query fails
     */
    public function findByOwner(int $ownerId): array
    {
        $result = $this->db->query("SELECT id FROM cars WHERE user_id = ?", [$ownerId]);
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findByOwner failed for user={$ownerId}: " . $this->db->errorString()
            );
        }
        return $result->results();
    }

    /**
     * Find cars whose email column matches a given address
     *
     * Used by the Brevo webhook receiver to map an inbound delivery-status
     * event's recipient address back to the car(s) it belongs to. Multiple
     * cars can share one owner email, so this returns every match.
     *
     * @param string $email Email address to match against cars.email
     * @return array<object> Array of objects with 'id' and 'email' properties (empty if no match)
     * @throws CarDatabaseException If the query fails
     */
    public function findByEmail(string $email): array
    {
        $result = $this->db->query("SELECT id, email FROM cars WHERE email = ?", [$email]);
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findByEmail failed for email={$email}: " . $this->db->errorString()
            );
        }
        return $result->results();
    }

    /**
     * Record one Brevo delivery-status event against a car
     *
     * Deliberately does NOT use {@see \DB::insert()}'s `$update = true` mode:
     * that mode's `ON DUPLICATE KEY UPDATE` clause updates every non-`id` key
     * indiscriminately, which would also reassign `car_id`/`email`/`event`/
     * `brevo_message_id` on conflict — exactly the columns the unique index
     * is keyed on. This writes the raw parameterized query instead, naming
     * only `reason` and `occurred_at` in the UPDATE clause, so a duplicate
     * delivery of the same event updates its detail fields without ever
     * touching identity columns.
     *
     * @param int $carId Car ID the event is being recorded against
     * @param string $email Recipient email address from the Brevo payload
     * @param string $event Brevo event name (e.g. 'hard_bounce', 'delivered')
     * @param string|null $reason Optional reason/detail text from the payload
     * @param string $brevoMessageId Brevo's message id for this send (or a
     *                                locally-generated id for the 'sent' event)
     * @param string $occurredAt Datetime string in AppConstants::DATETIME_FORMAT
     * @return int MySQL's `rowCount()` for an `INSERT ... ON DUPLICATE KEY
     *             UPDATE`: 1 for a fresh insert, 2 if a duplicate's `reason`/
     *             `occurred_at` were actually changed, 0 if a duplicate
     *             arrived with identical values (no-op). Never a failure
     *             signal on its own — a real failure throws.
     * @throws CarDatabaseException If the query fails
     */
    public function insertEmailEvent(
        int $carId,
        string $email,
        string $event,
        ?string $reason,
        string $brevoMessageId,
        string $occurredAt
    ): int {
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), occurred_at = VALUES(occurred_at)',
            [$carId, $email, $event, $reason, $brevoMessageId, $occurredAt]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::insertEmailEvent failed for car={$carId} event={$event}: " . $this->db->errorString()
            );
        }

        return $this->db->count();
    }

    /**
     * Count distinct soft-bounce send cycles for an email since its last delivery
     *
     * A "send cycle" is one distinct `brevo_message_id`, not a raw event row —
     * Brevo can report the same soft bounce more than once for one send. Only
     * cycles that occurred after the most recent `delivered` event for this
     * email count; if no `delivered` event exists yet, all soft-bounce cycles
     * on record count. This is the query the webhook processor uses to decide
     * whether repeated soft bounces should escalate to a hard bounce.
     *
     * @param string $email Email address to count soft bounces for
     * @return int Number of distinct soft-bounce message ids since the last delivery
     * @throws CarDatabaseException If the query fails
     */
    public function countSoftBouncesSinceLastDelivered(string $email): int
    {
        $this->db->query(
            "SELECT COUNT(DISTINCT brevo_message_id) AS cnt
               FROM er_email_events
              WHERE email = ?
                AND event = 'soft_bounce'
                AND occurred_at > COALESCE(
                    (SELECT MAX(occurred_at) FROM er_email_events WHERE email = ? AND event = 'delivered'),
                    '1970-01-01 00:00:00'
                )",
            [$email, $email]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::countSoftBouncesSinceLastDelivered failed for email={$email}: " . $this->db->errorString()
            );
        }

        $row = $this->db->first();
        if (!is_object($row) || !isset($row->cnt)) {
            // A COUNT() query always yields exactly one row — reaching here
            // means the query did not execute as written, not that the true
            // count is zero. Returning 0 would silently disable soft-bounce
            // escalation for this email instead of surfacing the fault.
            throw new CarDatabaseException(
                "CarRepository::countSoftBouncesSinceLastDelivered returned no COUNT row for email={$email}"
            );
        }
        return (int) $row->cnt;
    }

    /**
     * Delete all er_email_events rows for a set of car ids
     *
     * Used by account-deletion cleanup: a departing owner's email history
     * must not survive the account, and cars are keyed off `car_id` in this
     * table (see class docblock's account-deletion note). No-ops cleanly on
     * an empty array rather than issuing a query with an empty IN() list.
     *
     * @param array<int> $carIds Car ids whose event history should be deleted
     * @return int Number of rows actually deleted (0 for the empty-array no-op
     *             case, or if the matching cars simply had no event history)
     * @throws CarDatabaseException If the query fails
     */
    public function deleteEmailEventsForCarIds(array $carIds): int
    {
        if ($carIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($carIds), '?'));
        $this->db->query(
            "DELETE FROM er_email_events WHERE car_id IN ({$placeholders})",
            array_values($carIds)
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::deleteEmailEventsForCarIds failed for car_ids=' . implode(',', $carIds)
                . ': ' . $this->db->errorString()
            );
        }

        return $this->db->count();
    }

    /**
     * Delete er_email_events rows older than a given cutoff
     *
     * Used by the nightly reconciliation job (#1889) to enforce the 24-month
     * retention policy for Brevo delivery-status event history stated in the
     * v2.30.0 privacy policy.
     *
     * @param \DateTimeImmutable $cutoff Rows with `occurred_at` before this
     *                                   instant are deleted
     * @return int Number of rows actually deleted (0 if none were older than the cutoff)
     * @throws CarDatabaseException If the query fails
     */
    public function deleteEmailEventsOlderThan(\DateTimeImmutable $cutoff): int
    {
        $this->db->query(
            'DELETE FROM er_email_events WHERE occurred_at < ?',
            [$cutoff->format(AppConstants::DATETIME_FORMAT)]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::deleteEmailEventsOlderThan failed for cutoff='
                . $cutoff->format(AppConstants::DATETIME_FORMAT) . ': ' . $this->db->errorString()
            );
        }

        return $this->db->count();
    }

    /**
     * Get car history records
     *
     * @param int $carId Car ID
     * @return array<object> History records (empty if none)
     * @throws CarDatabaseException If the query fails
     */
    public function getHistory(int $carId): array
    {
        $result = $this->db->query(
            'SELECT id, car_id, ctime, mtime, timestamp, operation,
                    model, series, variant, year, type, chassis, chassis_override, color, engine,
                    purchasedate, solddate, comments, image,
                    fname, join_date, city, state, country, website
             FROM cars_hist WHERE car_id = ? ORDER BY timestamp DESC',
            [$carId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::getHistory failed for car={$carId}: " . $this->db->errorString()
            );
        }
        return $result->results();
    }

    /**
     * Insert a history record
     *
     * @param array<string, mixed> $fields History fields
     * @return bool True on success
     */
    public function insertHistory(array $fields): bool
    {
        return $this->db->insert('cars_hist', $fields);
    }

    /**
     * Transfer history records from one car to another
     *
     * @param int $fromCarId Source car ID
     * @param int $toCarId Target car ID
     * @return bool True on success
     */
    public function transferHistory(int $fromCarId, int $toCarId): bool
    {
        $this->db->query("UPDATE cars_hist SET car_id = ? WHERE car_id = ?", [$toCarId, $fromCarId]);
        return !$this->db->error();
    }

    /**
     * Reassign er_email_events rows from one car to another (car merge, #1887)
     *
     * Unlike deleteEmailEventsForCarIds() (used on account/car deletion, where
     * the history has nowhere to go), a merge's surviving car should keep both
     * cars' bounce/suppression signal rather than lose the source's. No
     * ON DUPLICATE KEY UPDATE here — a genuine collision on the unique index
     * (car_id, brevo_message_id, event) between the two cars' histories throws
     * rather than silently dropping one side's row.
     *
     * @param int $fromCarId Source (merged-away) car ID
     * @param int $toCarId Target (surviving) car ID
     * @return bool True on success
     * @throws CarDatabaseException If the query fails
     */
    public function transferEmailEvents(int $fromCarId, int $toCarId): bool
    {
        $this->db->query(
            'UPDATE er_email_events SET car_id = ? WHERE car_id = ?',
            [$toCarId, $fromCarId]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::transferEmailEvents failed (from={$fromCarId} to={$toCarId}): " . $this->db->errorString()
            );
        }

        return true;
    }

    /**
     * Look up factory information by chassis serial number
     *
     * @param string $chassis Full chassis number
     * @param int $suffixLength Length of chassis suffix to try as secondary search
     * @return object|null Factory info object or null
     * @throws CarDatabaseException If a query fails
     */
    public function getFactoryInfo(string $chassis, int $suffixLength): ?object
    {
        $search = [$chassis, substr($chassis, -$suffixLength)];

        foreach ($search as $serialNumber) {
            $factory = $this->db->query('SELECT * FROM elan_factory_info WHERE serial = ? ', [$serialNumber]);
            if ($this->db->error()) {
                throw new CarDatabaseException(
                    "CarRepository::getFactoryInfo failed for serial={$serialNumber}: " . $this->db->errorString()
                );
            }
            if ($factory->count()) {
                return $factory->first();
            }
        }

        return null;
    }

    /**
     * Convert a factory suffix code to descriptive text
     *
     * @param string $suffix Suffix code — single letter, case-insensitive (e.g. 'A' or 'a')
     * @return string Human-readable description, or "Unknown suffix: {suffix}" if the code is not recognised
     */
    public static function suffixToText(string $suffix): string
    {
        $s = strtoupper($suffix);
        return self::SUFFIX_MAP[$s] ?? "Unknown suffix: " . $s;
    }

    /**
     * Get distinct filter options from car_models for the car listing filter pills.
     *
     * Each sub-array contains objects whose property matches the SQL alias:
     * 'series' elements expose ->series, 'types' elements expose ->type,
     * and 'variants' elements expose ->variant.
     *
     * @return array{series: array<object>, types: array<object>, variants: array<object>}
     */
    public function getFilterOptions(): array
    {
        return [
            'series'   => $this->distinctCarModelValues('series_normalized', 'series'),
            'types'    => $this->distinctCarModelValues('type_code', 'type'),
            'variants' => $this->distinctCarModelValues('variant'),
        ];
    }

    /**
     * Return distinct non-empty values for a single car_models column, ordered alphabetically.
     *
     * IMPORTANT: $column and $alias are interpolated directly into SQL without parameterisation.
     * This is safe only because the method is private and every call site uses a string literal.
     * Never pass values derived from request input or runtime configuration.
     *
     * @param string $column Database column name
     * @param string $alias  Result property name; defaults to $column when omitted
     * @return array<object>
     */
    private function distinctCarModelValues(string $column, string $alias = ''): array
    {
        $alias  = $alias ?: $column;
        $result = $this->db->query(
            "SELECT DISTINCT {$column} AS {$alias} FROM car_models"
            . " WHERE {$column} IS NOT NULL AND {$column} != ''"
            . " ORDER BY {$column}"
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarRepository::distinctCarModelValues failed for column={$column}: " . $this->db->errorString());
            return [];
        }
        return $result->results();
    }

    /**
     * Begin a database transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this repository), this method is a no-op.
     *
     * @return void
     */
    public function beginTransaction(): void
    {
        if ($this->db->inTransaction()) {
            return; // Participating in outer transaction — no-op
        }
        $this->db->beginTransaction();
        $this->transactionOwner = true;
    }

    /**
     * Commit the current transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this repository), this method is a no-op.
     *
     * @return void
     */
    public function commit(): void
    {
        if (!$this->transactionOwner) {
            return; // Outer transaction manages commit — no-op
        }
        $this->transactionOwner = false;
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
    }

    /**
     * Rollback the current transaction
     *
     * When participating in an outer transaction (begun by the caller before
     * this repository), this method is a no-op.
     *
     * @return void
     */
    public function rollback(): void
    {
        if (!$this->transactionOwner) {
            return; // Outer transaction manages rollback — no-op
        }
        $this->transactionOwner = false;
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    /**
     * Get the last inserted ID
     *
     * @return int Last insert ID
     */
    public function lastId(): int
    {
        return $this->db->lastId();
    }

    /**
     * Get the error string from the last operation
     *
     * @return string Error message
     */
    public function errorString(): string
    {
        return $this->db->errorString();
    }

}
