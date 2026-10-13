<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DateTimeImmutable;
use ElanRegistry\AppConstants;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
use ElanRegistry\Exceptions\CarValidationException;
use ElanRegistry\LogCategories;
use ElanRegistry\Migration\DeliverabilityMigrationQueries;
use InvalidArgumentException;

/**
 * CarRepository - Database access layer for car operations
 *
 * Extracted from Car.php to provide a focused, testable data access layer.
 * Wraps DB operations for cars, cars_hist, elan_factory_info, and car_models tables,
 * and for the deliverability columns of profiles, which are the system of
 * record for an owner's email deliverability (#2101).
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarRepository
{
    /**
     * Length of the freshness window, in months.
     *
     * freshnessCutoff() and the Verified badge tooltip (CarBadges) use this
     * value, so the rule and the text cannot disagree. freshnessSql() writes
     * the same window as `INTERVAL 1 YEAR`.
     */
    public const FRESHNESS_MONTHS = 12;

    /**
     * Verification queue pills accepted by findVerificationQueue().
     *
     * Public so the dashboard can check a `?status=` value against the same list.
     */
    public const QUEUE_STATUSES = ['all', 'eligible', 'pending', 'bounced', 'suppressed', 'verified', 'sold'];

    /**
     * Dashboard window choices: URL value => `$windowDays` argument (null = all time).
     *
     * One list for the queue `?window=` and the recent-activity
     * `?activity_window=` values. app/admin/index.php checks both against it,
     * and the tab renders its select options from it.
     */
    public const QUEUE_WINDOW_CHOICES = [7 => 7, 30 => 30, 90 => 90, 365 => 365, 0 => null];

    /** Dashboard queue `?show=` row-limit choices, checked by app/admin/index.php. */
    public const QUEUE_SHOW_CHOICES = [10, 25, 50, 100];

    /** cars_hist.operation that app/verify/verify_car.php writes when an owner clicks Verify. */
    private const OPERATION_VERIFIED = 'VERIFIED';

    /** cars_hist.operation that app/verify/verify_car.php writes when an owner confirms Sold. */
    private const OPERATION_VERIFIED_SOLD = 'VERIFIED SOLD';

    /** er_email_events.event that CarVerificationSendService writes for each send. It starts a send cycle. */
    private const EVENT_SENT = 'sent';

    public const BLOCKED_CAUSE_SPAM = 'spam';
    public const BLOCKED_CAUSE_UNSUBSCRIBED = 'unsubscribed';
    public const BLOCKED_CAUSE_BLOCKLIST = 'blocklist';

    /** @var list<string> Values that `profiles.email_blocked_cause` accepts, besides NULL (cause unknown). */
    public const BLOCKED_CAUSES = [
        self::BLOCKED_CAUSE_SPAM,
        self::BLOCKED_CAUSE_UNSUBSCRIBED,
        self::BLOCKED_CAUSE_BLOCKLIST,
    ];

    /**
     * SQL that normalizes a column the way
     * {@see DeliverabilityMigrationQueries::normalizeAddress()} does: strip
     * leading and trailing white space, then lower case. Wrap a column name
     * as `NORMALIZE_SQL_OPEN . <column> . NORMALIZE_SQL_CLOSE`.
     *
     * MySQL `TRIM()` removes spaces only. PHP `trim()` also removes a tab, a
     * newline, a carriage return and `\x0B`, so the SQL form uses
     * REGEXP_REPLACE with `[[:space:]]` (#2356). The two forms agree for an
     * ASCII address with leading or trailing white space from the set
     * `[ \t\n\r\x0B]`. The known differences are:
     *
     * - PHP `trim()` removes `\0`. The SQL form does not.
     * - The SQL form removes `\f`, a no-break space and other Unicode
     *   spaces. PHP `trim()` does not.
     * - MySQL `LOWER()` lowers non-ASCII letters. PHP `strtolower()` does not.
     *
     * Address validation rejects these characters, so a stored address does
     * not hold them. Both sides of the compare are normalized, so a stored
     * `_address` that a writer did not normalize still agrees with the PHP
     * rule. The predicates add `COLLATE utf8mb4_bin` because the compare must
     * be byte-exact, as PHP `===` is. The column collation ignores case and
     * trailing spaces.
     */
    private const NORMALIZE_SQL_OPEN  = 'LOWER(REGEXP_REPLACE(';
    private const NORMALIZE_SQL_CLOSE = ", '^[[:space:]]+|[[:space:]]+$', ''))";

    private const CURRENT_ADDRESS_SQL = self::NORMALIZE_SQL_OPEN . 'u.email' . self::NORMALIZE_SQL_CLOSE;

    /**
     * SQL for an `email_blocked` that applies to the owner's current address.
     * A NULL or empty address applies (fail closed). Aliases `p` and `u`.
     */
    private const FLAG_ACTIVE_BLOCKED = "p.email_blocked = 1 AND (p.email_blocked_address IS NULL"
        . ' OR ' . self::NORMALIZE_SQL_OPEN . 'p.email_blocked_address' . self::NORMALIZE_SQL_CLOSE . " = ''"
        . ' OR ' . self::NORMALIZE_SQL_OPEN . 'p.email_blocked_address' . self::NORMALIZE_SQL_CLOSE
        . ' = ' . self::CURRENT_ADDRESS_SQL . ' COLLATE utf8mb4_bin)';

    /**
     * SQL for an `email_bounced` that applies to the owner's current address.
     * A NULL or empty address applies (fail closed). Aliases `p` and `u`.
     */
    private const FLAG_ACTIVE_BOUNCED = "p.email_bounced = 1 AND (p.email_bounced_address IS NULL"
        . ' OR ' . self::NORMALIZE_SQL_OPEN . 'p.email_bounced_address' . self::NORMALIZE_SQL_CLOSE . " = ''"
        . ' OR ' . self::NORMALIZE_SQL_OPEN . 'p.email_bounced_address' . self::NORMALIZE_SQL_CLOSE
        . ' = ' . self::CURRENT_ADDRESS_SQL . ' COLLATE utf8mb4_bin)';

    /** Largest `$limit` that recentFlagChanges() accepts. A larger value is clamped. */
    private const FLAG_CHANGES_MAX_LIMIT = 100;

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
     * Restore a car's pre-send vericode/vericode_sent_at after a failed
     * verification-email send (CarVerificationSendService::sendOne())
     *
     * Deliberately NOT implemented via updateCar(): DB::update() returns
     * true for any UPDATE that executes without a driver error, including
     * one that matches zero rows (e.g. the car was deleted between the
     * vericode rotation and this restore attempt) — so a caller relying on
     * updateCar()'s bool return cannot distinguish "the car is still there"
     * from "the car is gone." This method answers that question directly.
     *
     * rowCount() after an UPDATE reports rows CHANGED, not rows MATCHED (no
     * MYSQL_ATTR_FOUND_ROWS is set on this connection), so count() === 0 is
     * ambiguous: it covers both "no such car" and the entirely routine case
     * where the restore writes back the values the row already holds (a car
     * never sent to before has vericode/vericode_sent_at NULL, and the
     * restore writes NULL/NULL). A follow-up read resolves the ambiguity —
     * the same confirm-read pattern VerificationSettings::setEnabled() uses.
     *
     * @param int $carId Car ID
     * @param string|null $vericode The pre-send stored (hashed) vericode value
     * @param string|null $vericodeSentAt The pre-send vericode_sent_at value
     * @return bool True if the car row exists and now holds the restored
     *              values — including when the write was a no-op because the
     *              row already held them; false only if no car with $carId
     *              exists (the car was deleted mid-send)
     * @throws CarDatabaseException If the query itself fails
     */
    public function restoreVerificationCodeState(int $carId, ?string $vericode, ?string $vericodeSentAt): bool
    {
        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            [$vericode, $vericodeSentAt, $carId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::restoreVerificationCodeState failed for car={$carId}: " . $this->db->errorString()
            );
        }

        if ($this->db->count() > 0) {
            return true;
        }

        // count() === 0 — either the row is gone or the write changed nothing.
        // Confirm which with a read.
        $this->db->query('SELECT id FROM cars WHERE id = ?', [$carId]);
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::restoreVerificationCodeState confirm-read failed for car={$carId}: "
                . $this->db->errorString()
            );
        }

        return is_object($this->db->first());
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
     * Update the stored verification-code hash for a car
     *
     * @param int $carId Car ID
     * @param string $hashedVerificationCode The HMAC-SHA256 digest from hashVericode();
     *                                       never the plaintext code. Callers are
     *                                       responsible for hashing — this method
     *                                       writes the value verbatim to cars.vericode.
     * @return bool True on success
     */
    public function updateVerificationCode(int $carId, string $hashedVerificationCode): bool
    {
        return $this->updateCar($carId, ['vericode' => $hashedVerificationCode]);
    }

    /**
     * Update the last-verified timestamp for a car
     *
     * @deprecated No production callers since v2.30.3 — CarVerificationManager::markVerified()
     *             writes `last_verified` via updateCar() directly so it can set
     *             `owner_last_updated` in the same atomic write. Tracked for removal in #2107.
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
     * Read an owner's deliverability state from the profile (#2101)
     *
     * The profile is the system of record for email deliverability. Since
     * #2101 `profiles.user_id` is unique, so an owner has at most one row.
     * Null means the owner has no `profiles` row. Callers must treat that as
     * "do not mail" (the verification queries exclude such an owner).
     *
     * It returns the stored values with no address match. A stored block or
     * bounce can be for an old address. To find whether a flag applies now,
     * use {@see effectiveEmailFlags()}.
     *
     * @param int  $userId    Owner user ID
     * @param bool $forUpdate True to lock the row (SELECT ... FOR UPDATE). Use
     *                        it only inside an active transaction, before a
     *                        write that depends on the value read.
     * @return object{id: int|string, user_id: int|string, email_opted_out: int|string, email_opted_out_at: ?string,
     *         email_blocked: int|string, email_blocked_address: ?string, email_blocked_at: ?string,
     *         email_blocked_cause: ?string, email_bounced: int|string, email_bounced_address: ?string,
     *         email_bounced_at: ?string, email_bounce_cleared_at: ?string}|null
     * @throws CarDatabaseException If the query fails
     */
    public function findProfileEmailState(int $userId, bool $forUpdate = false): ?object
    {
        $result = $this->db->query(
            'SELECT id, user_id, email_opted_out, email_opted_out_at,
                    email_blocked, email_blocked_address, email_blocked_at, email_blocked_cause,
                    email_bounced, email_bounced_address, email_bounced_at, email_bounce_cleared_at
               FROM profiles
              WHERE user_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$userId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findProfileEmailState failed for user={$userId}: " . $this->db->errorString()
            );
        }
        if ($this->db->count() === 0) {
            return null;
        }
        $row = $result->first();

        return is_object($row) ? $row : null;
    }

    /**
     * Build the SQL predicate for a flag that applies to the owner's current address
     *
     * Returns `p.<flag> = 1 AND (<address term>)`. The address term is true
     * when the flag's `_address` is NULL, empty, or equal to the normalized
     * `users.email`. A flag with no stored address applies (fail closed).
     * This is the owner-level rule. The SQL comes from
     * class constants only, never from `$flag`. The caller joins `profiles`
     * as `p` and `users` as `u`, and puts the result in parentheses. For
     * `email_opted_out`, which is not tied to an address, use
     * `p.email_opted_out = 1`.
     *
     * @param string $flag {@see DeliverabilityFlagLog::FLAG_BLOCKED} or {@see DeliverabilityFlagLog::FLAG_BOUNCED}
     * @return string SQL boolean expression
     * @throws CarValidationException If $flag is not one of the two permitted flag names
     */
    public static function emailFlagActiveSql(string $flag): string
    {
        if ($flag === DeliverabilityFlagLog::FLAG_BLOCKED) {
            return self::FLAG_ACTIVE_BLOCKED;
        }
        if ($flag === DeliverabilityFlagLog::FLAG_BOUNCED) {
            return self::FLAG_ACTIVE_BOUNCED;
        }

        throw new CarValidationException(
            'CarRepository::emailFlagActiveSql received an unknown flag. Use '
            . DeliverabilityFlagLog::FLAG_BLOCKED . ' or ' . DeliverabilityFlagLog::FLAG_BOUNCED . '.'
        );
    }

    /**
     * Read the deliverability flags that apply to one owner now
     *
     * One query reads the profile and the owner's `users.email`. A stored
     * `email_blocked` or `email_bounced` applies when its normalized
     * `_address` is empty or equals the normalized `users.email`
     * ({@see DeliverabilityMigrationQueries::normalizeAddress()}).
     * `email_opted_out` applies whatever the address. This is the PHP form of
     * {@see emailFlagActiveSql()}.
     *
     * @param int $userId Owner user ID
     * @return EffectiveEmailFlags {@see EffectiveEmailFlags::none()} when the
     *                             owner has no profiles row or no users row
     * @throws CarDatabaseException If the query fails
     */
    public function effectiveEmailFlags(int $userId): EffectiveEmailFlags
    {
        $result = $this->db->query(
            'SELECT p.email_opted_out, p.email_opted_out_at,
                    p.email_blocked, p.email_blocked_address, p.email_blocked_at, p.email_blocked_cause,
                    p.email_bounced, p.email_bounced_address, p.email_bounced_at,
                    u.email AS user_email
               FROM profiles p
              INNER JOIN users u ON u.id = p.user_id
              WHERE p.user_id = ?',
            [$userId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::effectiveEmailFlags failed for user={$userId}: " . $this->db->errorString()
            );
        }
        $row = $this->db->count() === 0 ? null : $result->first();
        if (!is_object($row)) {
            return EffectiveEmailFlags::none();
        }

        $current = DeliverabilityMigrationQueries::normalizeAddress(self::nullableString($row->user_email));

        return new EffectiveEmailFlags(
            true,
            (int) $row->email_opted_out === 1,
            self::nullableString($row->email_opted_out_at),
            self::flagApplies($row->email_blocked, $row->email_blocked_address, $current),
            self::nullableString($row->email_blocked_address),
            self::nullableString($row->email_blocked_at),
            self::nullableString($row->email_blocked_cause),
            self::flagApplies($row->email_bounced, $row->email_bounced_address, $current),
            self::nullableString($row->email_bounced_address),
            self::nullableString($row->email_bounced_at),
            self::nullableString($row->user_email)
        );
    }

    /**
     * Tell whether a stored flag applies to the normalized current address
     *
     * A set flag applies when its normalized address is empty (fail closed)
     * or equals `$current`. This is the PHP form of `FLAG_ACTIVE_BLOCKED`
     * and `FLAG_ACTIVE_BOUNCED`. Change the two together.
     */
    private static function flagApplies(mixed $flag, mixed $address, string $current): bool
    {
        if ((int) $flag !== 1) {
            return false;
        }

        $stored = DeliverabilityMigrationQueries::normalizeAddress(self::nullableString($address));

        return $stored === '' || $stored === $current;
    }

    /**
     * Tell whether a stored flag address is NULL or empty after normalization
     */
    private static function isEmptyAddress(mixed $address): bool
    {
        return DeliverabilityMigrationQueries::normalizeAddress(self::nullableString($address)) === '';
    }

    /**
     * Cast a column value to a string, and keep NULL
     */
    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * Read an owner's opt-out flag (`profiles.email_opted_out`, #1883)
     *
     * @param int $userId Owner user ID
     * @return int|null 0 or 1 as stored, or null when the owner has no profiles row
     * @throws CarDatabaseException If the query fails
     */
    public function findProfileOptedOut(int $userId): ?int
    {
        $state = $this->findProfileEmailState($userId);

        return $state === null ? null : (int) $state->email_opted_out;
    }

    /**
     * Set or clear an owner's opt-out (`email_opted_out` and `email_opted_out_at`)
     *
     * Locks the profile row, then writes only when the value changes, so a
     * repeat opt-out keeps the first `email_opted_out_at`. The opt-out is set
     * whatever the state of `email_blocked` and `email_bounced`: it is the
     * owner's own request and must outlive a later clear of those flags.
     *
     * @param int  $userId    Owner user ID
     * @param bool $optedOut  True to set the opt-out, false to clear it
     * @return int|null The old flag value (0 or 1), or null when the owner has
     *                  no profiles row (nothing is written)
     * @throws CarDatabaseException If a query fails
     */
    public function setProfileOptedOut(int $userId, bool $optedOut): ?int
    {
        $state = $this->findProfileEmailState($userId, true);
        if ($state === null) {
            return null;
        }
        $old = (int) $state->email_opted_out;
        $new = $optedOut ? 1 : 0;
        if ($old === $new) {
            return $old;
        }

        $this->writeProfile(
            $userId,
            'email_opted_out = ?, email_opted_out_at = ?',
            [$new, $optedOut ? date(AppConstants::DATETIME_FORMAT) : null],
            'setProfileOptedOut'
        );

        return $old;
    }

    /**
     * Set an owner's block (`email_blocked` with its address, time and cause)
     *
     * Reads the profile row with SELECT ... FOR UPDATE. The lock holds only
     * inside the caller's transaction. Writes when the flag is 0, or when it
     * is set for an address that differs from `$address` under the matching
     * rule. A repeat block of the same address keeps the first time and cause.
     *
     * @param int         $userId    Owner user ID
     * @param string      $address   The blocked address. Stored normalized.
     * @param string|null $cause     One of {@see self::BLOCKED_CAUSES}, or null when unknown
     * @param string      $blockedAt When the block happened, in AppConstants::DATETIME_FORMAT
     * @return int|null The old flag value (0 or 1), or null when the owner has
     *                  no profiles row (nothing is written)
     * @throws CarValidationException If the address is empty, the cause is not
     *                                permitted, or the time is not a valid datetime
     * @throws CarDatabaseException If a query fails
     */
    public function setProfileBlocked(int $userId, string $address, ?string $cause, string $blockedAt): ?int
    {
        $normalized = self::requireAddress($address, 'setProfileBlocked', $userId);
        if ($cause !== null && !in_array($cause, self::BLOCKED_CAUSES, true)) {
            throw new CarValidationException(
                "CarRepository::setProfileBlocked (userId={$userId}): unknown block cause '{$cause}'."
            );
        }
        $at = self::parseTimestamp($blockedAt, 'email_blocked_at')->format(AppConstants::DATETIME_FORMAT);

        $state = $this->findProfileEmailState($userId, true);
        if ($state === null) {
            return null;
        }
        $old = (int) $state->email_blocked;
        if ($old === 1 && self::sameAddress($state->email_blocked_address, $normalized)) {
            return $old;
        }

        $this->writeProfile(
            $userId,
            'email_blocked = 1, email_blocked_address = ?, email_blocked_at = ?, email_blocked_cause = ?',
            [$normalized, $at, $cause],
            'setProfileBlocked'
        );

        return $old;
    }

    /**
     * Clear an owner's block (`email_blocked` with its address, time and cause)
     *
     * @param int $userId Owner user ID
     * @return int|null The old flag value (0 or 1), or null when the owner has
     *                  no profiles row (nothing is written)
     * @throws CarDatabaseException If a query fails
     */
    public function clearProfileBlocked(int $userId): ?int
    {
        $state = $this->findProfileEmailState($userId, true);
        if ($state === null) {
            return null;
        }
        $old = (int) $state->email_blocked;
        if ($old === 0) {
            return $old;
        }

        $this->writeProfileBlockClear($userId, 'clearProfileBlocked');

        return $old;
    }

    /**
     * Write the block clear on a profile row that the caller has locked
     *
     * @throws CarDatabaseException If the query fails
     */
    private function writeProfileBlockClear(int $userId, string $method): void
    {
        $this->writeProfile(
            $userId,
            'email_blocked = 0, email_blocked_address = NULL, email_blocked_at = NULL, email_blocked_cause = NULL',
            [],
            $method
        );
    }

    /**
     * Set an owner's bounce (`email_bounced` with its address and time)
     *
     * Reads the profile row with SELECT ... FOR UPDATE before the write. The
     * lock holds only inside the caller's transaction. Writes when the flag
     * is 0, or when it is set for an address that differs from
     * `$address` under the matching rule, so every new bounce gets a new
     * `email_bounced_at` (#2320). A repeat bounce of the same address keeps
     * the first time.
     *
     * @param int    $userId    Owner user ID
     * @param string $address   The address that bounced. Stored normalized.
     * @param string $bouncedAt When the bounce happened, in AppConstants::DATETIME_FORMAT
     * @return int|null The old flag value (0 or 1), or null when the owner has
     *                  no profiles row (nothing is written)
     * @throws CarValidationException If the address is empty or the time is not a valid datetime
     * @throws CarDatabaseException If a query fails
     */
    public function setProfileBounced(int $userId, string $address, string $bouncedAt): ?int
    {
        $normalized = self::requireAddress($address, 'setProfileBounced', $userId);
        $at = self::parseTimestamp($bouncedAt, 'email_bounced_at')->format(AppConstants::DATETIME_FORMAT);

        $state = $this->findProfileEmailState($userId, true);
        if ($state === null) {
            return null;
        }
        $old = (int) $state->email_bounced;
        if ($old === 1 && self::sameAddress($state->email_bounced_address, $normalized)) {
            return $old;
        }

        $this->writeProfile(
            $userId,
            'email_bounced = 1, email_bounced_address = ?, email_bounced_at = ?',
            [$normalized, $at],
            'setProfileBounced'
        );

        return $old;
    }

    /**
     * Clear an owner's bounce and record when it was cleared
     *
     * Reads the profile row with SELECT ... FOR UPDATE before the write. The
     * lock holds only inside the caller's transaction. Sets
     * `email_bounce_cleared_at`, so soft bounces before the clear do not count
     * toward a new escalation ({@see countSoftBouncesSinceLastDelivered()}).
     *
     * @param int $userId Owner user ID
     * @return int|null The old flag value (0 or 1), or null when the owner has
     *                  no profiles row (nothing is written)
     * @throws CarDatabaseException If a query fails
     */
    public function clearProfileBounced(int $userId): ?int
    {
        $state = $this->findProfileEmailState($userId, true);
        if ($state === null) {
            return null;
        }
        $old = (int) $state->email_bounced;
        if ($old === 0) {
            return $old;
        }

        $this->writeProfileBounceClear($userId, 'clearProfileBounced');

        return $old;
    }

    /**
     * Clear the bounce and the block when a confirmed email change gives a new address
     *
     * Call it inside the caller's transaction. It locks the profile row and
     * the users row (`SELECT ... FOR UPDATE OF p, u`) before it reads. The
     * users lock stops a concurrent Owner::update() from a change to
     * `users.email` between this read and the `email_address_seen` write.
     * Then it makes one compare: the normalized `users.email` against
     * `email_address_seen`. When they are equal (a re-verify of the same
     * address), it writes nothing. A flag stored for an old address stays.
     * A flag with no stored address stays and applies (fail closed). When
     * they differ, it does these steps:
     *
     * 1. Clears `email_bounced` with its address and date, and sets
     *    `email_bounce_cleared_at`, if the bounce is set.
     * 2. Clears `email_blocked` with its address, date and cause, if the
     *    block is set.
     * 3. Sets `email_address_since` to now and `email_address_seen` to the
     *    normalized `users.email`.
     *
     * `email_opted_out` stays. The caller writes the flag-change log rows and
     * logs the no-address anomaly.
     *
     * @param int $userId Owner user ID
     * @return array{bounced: int, blocked: int, bouncedHadNoAddress: bool, blockedHadNoAddress: bool, changed: bool}|null
     *         The flag values before the call and whether the address changed.
     *         A flag was cleared only when `changed` is true and its old value
     *         is 1. A `...HadNoAddress` value is true when that flag was set
     *         with a NULL or empty address. Null when the owner has no
     *         profiles row or no users row (nothing is written).
     * @throws CarDatabaseException If a query fails
     */
    public function clearFlagsForConfirmedEmailChange(int $userId): ?array
    {
        // Owner::update() locks the profiles row, then writes the users row.
        // This query must lock in the same order to prevent a deadlock.
        // STRAIGHT_JOIN makes MySQL read, and so lock, `p` before `u`.
        $result = $this->db->query(
            'SELECT p.email_bounced, p.email_bounced_address, p.email_blocked, p.email_blocked_address,
                    p.email_address_seen, u.email AS user_email
               FROM profiles p
              STRAIGHT_JOIN users u ON u.id = p.user_id
              WHERE p.user_id = ?
                FOR UPDATE OF p, u',
            [$userId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::clearFlagsForConfirmedEmailChange failed for user={$userId}: "
                . $this->db->errorString()
            );
        }
        $row = $this->db->count() === 0 ? null : $result->first();
        if (!is_object($row)) {
            return null;
        }

        $bounced = (int) $row->email_bounced;
        $blocked = (int) $row->email_blocked;
        $current = DeliverabilityMigrationQueries::normalizeAddress(self::nullableString($row->user_email));
        $seen    = DeliverabilityMigrationQueries::normalizeAddress(self::nullableString($row->email_address_seen));
        $changed = $current !== $seen;

        if ($changed) {
            if ($bounced === 1) {
                $this->writeProfileBounceClear($userId, 'clearFlagsForConfirmedEmailChange');
            }
            if ($blocked === 1) {
                $this->writeProfileBlockClear($userId, 'clearFlagsForConfirmedEmailChange');
            }
            $this->writeProfile(
                $userId,
                'email_address_since = ?, email_address_seen = ?',
                [date(AppConstants::DATETIME_FORMAT), $current],
                'clearFlagsForConfirmedEmailChange'
            );
        }

        return [
            'bounced'             => $bounced,
            'blocked'             => $blocked,
            'bouncedHadNoAddress' => $bounced === 1 && self::isEmptyAddress($row->email_bounced_address),
            'blockedHadNoAddress' => $blocked === 1 && self::isEmptyAddress($row->email_blocked_address),
            'changed'             => $changed,
        ];
    }

    /**
     * Write the bounce clear on a profile row that the caller has locked
     *
     * @throws CarDatabaseException If the query fails
     */
    private function writeProfileBounceClear(int $userId, string $method): void
    {
        $this->writeProfile(
            $userId,
            'email_bounced = 0, email_bounced_address = NULL, email_bounced_at = NULL, email_bounce_cleared_at = ?',
            [date(AppConstants::DATETIME_FORMAT)],
            $method
        );
    }

    /**
     * Run one UPDATE on the owner's profile row
     *
     * The caller has read (and locked) the row, so the row exists. The
     * affected-row count is not checked: MySQL reports 0 for an UPDATE that
     * changes nothing, which is not an error here.
     *
     * @param int $userId Owner user ID
     * @param string $setSql SET clause, literal from this class only
     * @param list<int|string|null> $params Values for the SET clause placeholders
     * @param string $method Calling method name, for the exception message
     * @throws CarDatabaseException If the query fails
     */
    private function writeProfile(int $userId, string $setSql, array $params, string $method): void
    {
        $this->db->query("UPDATE profiles SET {$setSql} WHERE user_id = ?", array_merge($params, [$userId]));
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::{$method} failed for user={$userId}: " . $this->db->errorString()
            );
        }
    }

    /**
     * Normalize an address and reject an empty one
     *
     * @throws CarValidationException If the address is empty after normalization
     */
    private static function requireAddress(string $address, string $method, int $userId): string
    {
        $normalized = DeliverabilityMigrationQueries::normalizeAddress($address);
        if ($normalized === '') {
            throw new CarValidationException(
                "CarRepository::{$method} (userId={$userId}): a non-empty address is required when setting the flag."
            );
        }

        return $normalized;
    }

    /**
     * Compare a stored address with a normalized one under the matching rule
     *
     * An empty stored address matches nothing, so a flag with no address is
     * rewritten with the new address.
     */
    private static function sameAddress(mixed $stored, string $normalized): bool
    {
        $storedNormalized = DeliverabilityMigrationQueries::normalizeAddress(is_string($stored) ? $stored : null);

        return $storedNormalized !== '' && $storedNormalized === $normalized;
    }

    /**
     * Record one verification-email send attempt against a car, rolling the
     * counter over on a yearly window (#1884)
     *
     * Both columns move together in a single statement: when the window has never
     * been opened (`verification_attempts_since IS NULL`) or has expired (older
     * than one year), the counter resets to 1 and the window reopens at NOW();
     * otherwise the counter increments and the window start is left alone. Doing
     * this in one UPDATE keeps the pair consistent without a read-modify-write
     * race between concurrent sends.
     *
     * ZERO ROWS AFFECTED IS NOT AMBIGUOUS HERE. MySQL reports 0 affected rows for
     * an UPDATE that writes the values a row already holds, but this CASE
     * always changes at least one column for any row it matches — the counter
     * either resets to 1 or increments, and neither can equal its prior value. So
     * 0 affected rows means no car matched $carId, which is a caller bug.
     *
     * That case is logged and reported as false rather than thrown, because the
     * only caller (CarVerificationSendService::sendOne()) runs this *after* the
     * email has already gone out: throwing would turn a successful send into a
     * reported failure. A genuine query error is a different matter and does throw.
     *
     * @param int $carId Car ID
     * @return bool True if the car's attempt counters were updated; false if no car
     *              matched $carId (logged, not thrown)
     * @throws CarDatabaseException If the query fails
     */
    public function incrementVerificationAttempts(int $carId): bool
    {
        $this->db->query(
            'UPDATE cars
                SET verification_attempts = CASE
                      WHEN verification_attempts_since IS NULL
                           OR verification_attempts_since < NOW() - INTERVAL 1 YEAR
                      THEN 1 ELSE verification_attempts + 1 END,
                    verification_attempts_since = CASE
                      WHEN verification_attempts_since IS NULL
                           OR verification_attempts_since < NOW() - INTERVAL 1 YEAR
                      THEN NOW() ELSE verification_attempts_since END
              WHERE id = ?',
            [$carId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::incrementVerificationAttempts failed for car={$carId}: " . $this->db->errorString()
            );
        }

        if ($this->db->count() === 0) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarRepository::incrementVerificationAttempts affected no rows for car={$carId}: no such car.");
            return false;
        }

        return true;
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
     * This is the PHP-side counterpart for callers that hold a single car's
     * timestamps already and would otherwise re-derive the rule by hand, so the
     * rule has exactly one definition per language: a caller that reimplements
     * "fresh" inline is how the #1953 defect returns.
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
     * @throws CarValidationException If either argument is an empty or unparseable date string,
     *                                or if PHP cannot calculate the freshness cutoff
     */
    public static function isFresh(?string $lastVerified, string $ownerLastUpdated): bool
    {
        return self::freshnessSource($lastVerified, $ownerLastUpdated) !== null;
    }

    /**
     * Get the operand that makes a car fresh, with its parsed date.
     *
     * This is the rule of isFresh(), which calls this method. It reads the
     * clock one time, so the fresh decision and the source use the same
     * cutoff second.
     * - `confirmed`: `last_verified` is not null and is inside the window.
     *   The date is `last_verified`.
     * - `current`: `last_verified` is null or outside the window, and
     *   `owner_last_updated` is inside the window. The date is
     *   `owner_last_updated`.
     *
     * Uses PHP's clock. See the CLOCK CONSISTENCY note on isFresh().
     *
     * @param string|null $lastVerified     Datetime string, or null if never verified
     * @param string      $ownerLastUpdated Datetime string (NOT NULL by schema)
     * @return array{source: 'confirmed'|'current', date: DateTimeImmutable}|null The source and its date, or null when the car is not fresh
     * @throws CarValidationException If either argument is an empty or unparseable date string,
     *                                or if PHP cannot calculate the freshness cutoff
     */
    public static function freshnessSource(?string $lastVerified, string $ownerLastUpdated): ?array
    {
        // Both operands are always parsed before the method returns. It does not
        // short-circuit on a fresh owner_last_updated. A malformed value is a
        // programming error or data corruption, and it must surface whichever
        // operand carries it — a caller whose last_verified is garbage would
        // otherwise get a silent `true` for as long as the owner timestamp happened
        // to be recent, and the defect would only appear a year later. This is the
        // one place the PHP form intentionally diverges from the SQL form's OR
        // short-circuit: SQL cannot raise on a malformed DATETIME because the
        // column type makes one unrepresentable.
        $ownerDate    = self::parseTimestamp($ownerLastUpdated, 'owner_last_updated');
        $verifiedDate = $lastVerified === null
            ? null
            : self::parseTimestamp($lastVerified, 'last_verified');

        // Read the clock once, so both operands are compared to the same second.
        $cutoff = self::freshnessCutoff();

        if ($verifiedDate !== null && $verifiedDate->getTimestamp() >= $cutoff) {
            return ['source' => 'confirmed', 'date' => $verifiedDate];
        }
        if ($ownerDate->getTimestamp() >= $cutoff) {
            return ['source' => 'current', 'date' => $ownerDate];
        }

        return null;
    }

    /**
     * Decide if one datetime string is inside the 1-year freshness window.
     *
     * This is the window that isFresh() applies to each operand.
     *
     * Uses PHP's clock. See the CLOCK CONSISTENCY note on isFresh().
     *
     * @param string $timestamp Datetime string in `Y-m-d H:i:s` format (a `T` separator is also accepted)
     * @param string $column    Column name for the exception message
     * @return bool True when $timestamp is on or after the time one year ago
     * @throws CarValidationException If $timestamp is empty, malformed, or not a real calendar date,
     *                                or if PHP cannot calculate the freshness cutoff
     */
    public static function isWithinFreshnessWindow(string $timestamp, string $column): bool
    {
        return self::parseTimestamp($timestamp, $column)->getTimestamp() >= self::freshnessCutoff();
    }

    /**
     * Get the start of the 1-year freshness window as a Unix timestamp.
     *
     * This is the one PHP definition of the window. freshnessSource() (and so
     * isFresh()) and isWithinFreshnessWindow() compare parsed dates to it.
     * freshnessSource() reads it once, so both of its comparisons use the same
     * second. Each call reads the
     * clock again, so two calls can return different values.
     *
     * Uses PHP's clock. See the CLOCK CONSISTENCY note on isFresh().
     *
     * @return int Unix timestamp of the time FRESHNESS_MONTHS months ago
     * @throws CarValidationException If strtotime() cannot calculate the cutoff
     */
    public static function freshnessCutoff(): int
    {
        $relative = '-' . self::FRESHNESS_MONTHS . ' months';
        $cutoff = strtotime($relative);
        // A cast would turn false into 0, the Unix epoch, and every car would be fresh.
        if ($cutoff === false) {
            throw new CarValidationException(
                "CarRepository freshness check could not calculate the cutoff: strtotime('{$relative}') returned false."
            );
        }

        return $cutoff;
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
     * Parse a freshness datetime string, rejecting empty or malformed input.
     *
     * Validates the calendar, not merely the shape: a well-formed but
     * impossible date such as '2026-02-30 12:00:00' is rejected rather than
     * silently rolled over to 2026-03-02.
     *
     * This is the one parser for `last_verified` and `owner_last_updated`.
     * isFresh() and isWithinFreshnessWindow() use it. A caller that shows the
     * date (for example CarBadges::verifiedStatus()) uses the returned object,
     * so the shown date is the date that this method validated.
     *
     * @param string $value  Datetime string to parse (a `T` separator is also accepted)
     * @param string $column Column name, for the exception message
     * @return DateTimeImmutable The parsed datetime
     * @throws CarValidationException If $value is empty, malformed, or not a
     *                                real calendar date
     */
    public static function parseTimestamp(string $value, string $column): DateTimeImmutable
    {
        if ($value === '') {
            throw new CarValidationException(
                "CarRepository freshness check received an empty {$column} value; "
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
        $parsed     = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $normalized);
        $errors     = DateTimeImmutable::getLastErrors();

        if (
            $parsed === false
            || ($errors !== false
                && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        ) {
            throw new CarValidationException(
                "CarRepository freshness check received a malformed {$column} value: '{$value}'. "
                . 'Expected a valid Y-m-d H:i:s datetime as stored by the column.'
            );
        }

        return $parsed;
    }

    /**
     * Find cars eligible for a verification email, ordered oldest-verified first.
     *
     * A car is eligible when it is not marked sold, has a non-null, non-empty
     * (deliverable) email address that has not bounced and has not been
     * suppressed, and is stale — that is, it was neither verified nor updated
     * by its owner within the last year (see stalenessSql()).
     *
     * A car is also ineligible when it has no live owner: cars.user_id IS NULL,
     * user_id points at a users row that no longer exists (cars.user_id has no
     * FK — see DATABASE.md's "No Enforced Foreign Key Constraints" — so this is
     * reachable, e.g. if usersc/scripts/after_user_deletion.php's reassignment
     * transaction fails after the users row is already deleted), or the owner
     * is the `noowner` system account — the placeholder a car is reassigned to
     * when its real owner is erased. Ownership is required via an INNER JOIN
     * against users; the system account is resolved dynamically by username
     * rather than a hardcoded ID, so a reseeded or re-IDed account is still
     * excluded. Emailing such a car would mail an erased owner's last-known
     * address, which the registry's privacy commitment forbids.
     *
     * The send mails the owner's `users.email`, so the owner must have one.
     * `cars.email` is only a copy and is not read.
     *
     * Finally, a car is ineligible when its owner has opted out, when a
     * block or a bounce applies to the owner's current address
     * ({@see emailFlagActiveSql()}), or when the owner has no profiles row.
     * See the join comment in verificationEligibleFromSql().
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

        $from = self::verificationEligibleFromSql();

        $result = $this->db->query(
            "SELECT cars.* {$from}
              ORDER BY cars.last_verified ASC
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
     * Build the FROM/JOIN/WHERE clause that selects verification-eligible cars
     *
     * The one definition of eligibility. findVerificationEligible() selects
     * rows with it, and countVerificationSummary() counts rows with it, so the
     * Eligible card and the batch preview cannot disagree. The clause binds no
     * parameters. It uses the aliases `cars`, `u` (users) and `p` (profiles).
     *
     * @return string SQL that starts with `FROM cars` and ends with the last WHERE condition
     * @throws CarValidationException If the freshness alias is rejected (unreachable — literal)
     */
    private static function verificationEligibleFromSql(): string
    {
        // solddate is a DATE column; under STRICT_TRANS_TABLES, comparing it to ''
        // is a hard SQL error (ERROR 1525: Incorrect DATE value), not a no-op — the
        // column has no empty-string state, only NULL. u.email similarly needs an
        // explicit NULL check: `!= ''` alone evaluates to UNKNOWN (not FALSE)
        // for a NULL email under SQL's three-valued logic. UNKNOWN excludes the
        // row from this WHERE just as FALSE would, so the effect is the desired
        // one, but the mechanism is not the obvious one.

        // The owner's profile is the system of record for deliverability
        // (#2101), so the flags are read from it and not from the car. A car
        // registered after the owner opted out, bounced or was blocked is
        // then excluded too (#2319). An opt-out excludes the car whatever the
        // address. A block or a bounce excludes it only while its address
        // matches the owner's current address. The batch mails users.email,
        // so only that address counts. A flag with a NULL or empty address
        // applies (fail closed).
        //
        // LEFT JOIN, then `p.id IS NOT NULL`: an owner with no profiles row is
        // treated as blocked, because nothing records whether that owner may
        // be mailed. The admin queue shows such a car as "no profile". The
        // join cannot duplicate a car row, because `profiles.user_id` is unique.
        //
        // INNER JOIN, not LEFT JOIN: cars.user_id has no FK to users.id (dropped
        // deliberately — see DATABASE.md's "No Enforced Foreign Key Constraints"),
        // so it can point at a row that no longer exists (e.g. a deleted user
        // whose after_user_deletion.php reassignment failed partway). A LEFT
        // JOIN would admit such a row via `u.username IS NULL` — indistinguishable
        // from "no join match because deliberately ownerless" — reopening the
        // erased-owner leak this method exists to close. INNER JOIN requires a
        // live users row, so cars.user_id IS NOT NULL is redundant (the join
        // already excludes NULL) but kept for clarity/defense in depth.
        //
        // The 60-day re-send cooldown, per the FRD's Eligibility Criteria.
        // vericode_sent_at is the ONLY column a send writes — neither
        // last_verified nor owner_last_updated is touched by sending, so the
        // staleness expression above cannot tell a car that was emailed last
        // night apart from one that has never been emailed at all. Without
        // this clause the nightly cron therefore re-picks the identical batch
        // on consecutive nights, and every owner receives both of their two
        // yearly allowed sends roughly 24 hours apart before the attempt cap
        // finally bites on the third night — the queue never advances past the
        // same head of the backlog.
        //
        // NULL-safe for the same reason last_verified is: a car that has never
        // been sent to has vericode_sent_at NULL, and a bare `<` comparison
        // evaluates to UNKNOWN for those rows, silently excluding exactly the
        // cars the first batch should contain.
        //
        // This and the attempt cap below are two halves of one rule and
        // neither works alone. This clause is what SPREADS the sends out (60
        // days of silence before a car may be tried again); the cap is what
        // BOUNDS them (a silent owner would otherwise re-enter every 60 days
        // forever, six emails a year). Drop this clause and the cap degrades
        // into a bare total-sends limit with no cadence at all; drop the cap
        // and the cooldown has no ceiling.
        //
        // The attempt cap: mirrors incrementVerificationAttempts()'s own
        // rolling-12-month reset logic exactly (2 sends per window, then the
        // car waits out the rest of the year), per the FRD's Eligibility
        // Criteria. Without this clause the counter is written but never
        // read, and a stale car re-enters every batch with no limit.
        $stale   = self::stalenessSql('cars');
        $blocked = self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BLOCKED);
        $bounced = self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BOUNCED);
        // MySQL TRIM() removes only spaces. The send trims all white space,
        // so a tab-only address must also count as empty here.
        $address = self::CURRENT_ADDRESS_SQL;

        return "FROM cars
              INNER JOIN users u ON u.id = cars.user_id
              " . self::PROFILE_JOIN_SQL . "
              WHERE cars.solddate IS NULL
                AND p.id IS NOT NULL
                AND p.email_opted_out = 0
                AND NOT ({$blocked})
                AND NOT ({$bounced})
                AND u.email IS NOT NULL AND {$address} != ''
                AND cars.user_id IS NOT NULL
                AND u.username != 'noowner'
                AND {$stale}
                AND (cars.vericode_sent_at IS NULL
                     OR cars.vericode_sent_at < NOW() - INTERVAL 60 DAY)
                AND (cars.verification_attempts_since IS NULL
                     OR cars.verification_attempts_since < NOW() - INTERVAL 1 YEAR
                     OR cars.verification_attempts < 2)";
    }

    /**
     * The profile join that the dashboard queries add after `FROM cars`
     *
     * `profiles.user_id` is unique, so the join adds at most one row per car.
     * A car whose owner has no profiles row gets NULL for every `p.` column.
     */
    private const PROFILE_JOIN_SQL = 'LEFT JOIN profiles p ON p.user_id = cars.user_id';

    /**
     * The owner join that the dashboard queries add after {@see self::PROFILE_JOIN_SQL}
     *
     * {@see emailFlagActiveSql()} compares a flag's address with
     * `u.email`. `users.id` is the primary key, so the join adds at most one
     * row per car. A car whose `user_id` has no users row gets `u.email`
     * NULL, so the address term of the predicate does not count its
     * flag. Eligibility uses an INNER JOIN and excludes such a car.
     */
    private const USERS_JOIN_SQL = 'LEFT JOIN users u ON u.id = cars.user_id';

    /**
     * Build the WHERE condition for a car with a bounce on the owner's current address
     *
     * Needs the `cars` alias, {@see self::PROFILE_JOIN_SQL} and {@see self::USERS_JOIN_SQL}.
     *
     * @return string SQL boolean expression
     */
    private static function verificationBouncedWhereSql(): string
    {
        return '(' . self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BOUNCED) . ')';
    }

    /**
     * Build the WHERE condition for a car with a live owner who has no profiles row
     *
     * Such a car is never eligible ({@see verificationEligibleFromSql()}).
     * It is in the `all` queue so that the admin sees it, as "no profile",
     * and can fix the row. A sold car, an ownerless car and a `noowner` car
     * are left out: none of them is a verification candidate.
     *
     * Needs the `cars` alias and {@see self::PROFILE_JOIN_SQL}.
     *
     * @return string SQL boolean expression
     */
    private static function verificationNoProfileWhereSql(): string
    {
        return "(p.id IS NULL
                 AND cars.solddate IS NULL
                 AND EXISTS (SELECT 1 FROM users nu WHERE nu.id = cars.user_id AND nu.username != 'noowner'))";
    }

    /**
     * Build the WHERE condition for a Pending car: sent, link still live, no response yet
     *
     * A car is Pending when all of these are true:
     * - It has a live verification link: `vericode` is set and
     *   `vericode_sent_at` is within {@see CarVerificationEmailComposer::LINK_TTL_DAYS}.
     *   A transfer clears `vericode` but keeps `vericode_sent_at`, so the
     *   `vericode` check removes a car whose link no longer works.
     * - The owner did not respond: `last_verified` is NULL or earlier than
     *   the send, and `solddate` is NULL. Verify and Sold do not clear
     *   `vericode_sent_at`, so this check is what removes a car that answered.
     * - The owner has not opted out, and no block or bounce applies to the
     *   owner's current address ({@see emailFlagActiveSql()}). The batch skips those cars, so
     *   they show under their own pill and not as Pending. A car whose owner has no profiles row can
     *   still be Pending: it was sent before the row went missing.
     *
     * The condition binds no parameters. It needs the `cars` alias,
     * {@see self::PROFILE_JOIN_SQL} and {@see self::USERS_JOIN_SQL}.
     *
     * @return string SQL boolean expression
     */
    private static function verificationPendingWhereSql(): string
    {
        $ttlDays = CarVerificationEmailComposer::LINK_TTL_DAYS;
        $bounced = self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BOUNCED);
        $blocked = self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BLOCKED);

        return "(cars.vericode IS NOT NULL
                 AND cars.vericode_sent_at IS NOT NULL
                 AND cars.vericode_sent_at >= NOW() - INTERVAL {$ttlDays} DAY
                 AND (cars.last_verified IS NULL OR cars.last_verified < cars.vericode_sent_at)
                 AND cars.solddate IS NULL
                 AND COALESCE(({$bounced}), 0) = 0
                 AND COALESCE(({$blocked}), 0) = 0
                 AND COALESCE(p.email_opted_out, 0) = 0)";
    }

    /**
     * Build the WHERE condition for a Suppressed car: a block applies to the owner's current address, or the owner opted out
     *
     * Until #2361 separates them, one pill holds both flags. Needs the `cars`
     * alias, {@see self::PROFILE_JOIN_SQL} and {@see self::USERS_JOIN_SQL}.
     *
     * @return string SQL boolean expression
     */
    private static function verificationSuppressedWhereSql(): string
    {
        return '((' . self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BLOCKED) . ') OR p.email_opted_out = 1)';
    }

    /**
     * Build the SQL condition and parameters for a cars_hist time window
     *
     * @param string $column Qualified timestamp column (literal from this class only)
     * @param int|null $windowDays Days back from now, or null for all time
     * @return array{0: string, 1: array<int>} SQL fragment that starts with ` AND` (or ''), and its bound values
     */
    private static function windowSql(string $column, ?int $windowDays): array
    {
        if ($windowDays === null) {
            return ['', []];
        }

        return [" AND {$column} >= NOW() - INTERVAL ? DAY", [$windowDays]];
    }

    /**
     * Reject a window that is not null and not a positive number of days
     *
     * @throws CarValidationException If $windowDays is zero or negative
     */
    private static function assertValidWindowDays(?int $windowDays, string $method): void
    {
        if ($windowDays !== null && $windowDays < 1) {
            throw new CarValidationException(
                "CarRepository::{$method} received an invalid window of {$windowDays} days. "
                . 'Use a positive number of days, or null for all time.'
            );
        }
    }

    /**
     * Count the cars in each verification dashboard state
     *
     * Definitions (the queue pills use the same ones, see findVerificationQueue()):
     * - `eligible`: the predicate of findVerificationEligible(), counted.
     * - `pending`: see verificationPendingWhereSql(). Current state.
     * - `bounced`: a bounce applies to the owner's current address. Current state.
     * - `suppressed`: a block applies to the owner's current address, or the owner opted out. Current state.
     * - `verified`: distinct existing cars with a `cars_hist` `VERIFIED` row in the window.
     * - `sold`: distinct existing cars with a `cars_hist` `VERIFIED SOLD` row in the window.
     * - `all`: distinct cars in eligible, pending, bounced or suppressed, plus
     *   the cars of a live owner with no profiles row (see
     *   verificationNoProfileWhereSql()). Verified and Sold are history, not
     *   a queue state, so they are not in `all`.
     *
     * Bounced and Suppressed can overlap, so `all` can be less than the sum.
     *
     * @param int|null $windowDays Window for `verified` and `sold`, in days back from now. Null = all time.
     * @return array{all: int, eligible: int, pending: int, bounced: int, suppressed: int, verified: int, sold: int}
     * @throws CarValidationException If $windowDays is zero or negative
     * @throws CarDatabaseException If the query fails
     */
    public function countVerificationSummary(?int $windowDays): array
    {
        self::assertValidWindowDays($windowDays, 'countVerificationSummary');

        $eligibleFrom = self::verificationEligibleFromSql();
        $pending      = self::verificationPendingWhereSql();
        $bounced      = self::verificationBouncedWhereSql();
        $suppressed   = self::verificationSuppressedWhereSql();
        $noProfile    = self::verificationNoProfileWhereSql();
        [$window, $windowParams] = self::windowSql('h.timestamp', $windowDays);

        $carsFrom = 'FROM cars ' . self::PROFILE_JOIN_SQL . ' ' . self::USERS_JOIN_SQL;
        $histFrom = 'FROM cars_hist h INNER JOIN cars ON cars.id = h.car_id';

        $this->db->query(
            "SELECT
                (SELECT COUNT(*) {$eligibleFrom}) AS eligible,
                (SELECT COUNT(*) {$carsFrom} WHERE {$pending}) AS pending,
                (SELECT COUNT(*) {$carsFrom} WHERE {$bounced}) AS bounced,
                (SELECT COUNT(*) {$carsFrom} WHERE {$suppressed}) AS suppressed,
                (SELECT COUNT(DISTINCT h.car_id) {$histFrom} WHERE h.operation = ?{$window}) AS verified,
                (SELECT COUNT(DISTINCT h.car_id) {$histFrom} WHERE h.operation = ?{$window}) AS sold,
                (SELECT COUNT(*) {$carsFrom}
                  WHERE cars.id IN (SELECT cars.id {$eligibleFrom})
                     OR {$pending}
                     OR {$bounced}
                     OR {$suppressed}
                     OR {$noProfile}) AS all_count",
            array_merge(
                [self::OPERATION_VERIFIED],
                $windowParams,
                [self::OPERATION_VERIFIED_SOLD],
                $windowParams
            )
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::countVerificationSummary failed (window=' . ($windowDays ?? 'all') . '): '
                . $this->db->errorString()
            );
        }

        $row = $this->db->first();
        if (!is_object($row) || !isset($row->eligible)) {
            // Scalar subqueries always give one row. No row means the query
            // did not run as written, so do not show zero counts.
            throw new CarDatabaseException('CarRepository::countVerificationSummary returned no count row');
        }

        return [
            'all'        => (int) $row->all_count,
            'eligible'   => (int) $row->eligible,
            'pending'    => (int) $row->pending,
            'bounced'    => (int) $row->bounced,
            'suppressed' => (int) $row->suppressed,
            'verified'   => (int) $row->verified,
            'sold'       => (int) $row->sold,
        ];
    }

    /**
     * Find the cars for one verification queue pill
     *
     * Each status uses the same definition as its count in
     * countVerificationSummary(). Order per status:
     * - `pending`: longest-waiting first (`vericode_sent_at` ASC).
     * - `eligible`: oldest-verified first, the same as findVerificationEligible().
     * - `verified` / `sold`: most recent history row first.
     * - `all` / `bounced` / `suppressed`: car id ASC.
     *
     * `$windowDays` applies only to `verified` and `sold`. The other statuses
     * are current state. For `verified` / `sold`, each car shows once, with the
     * time of its latest matching `cars_hist` row in `hist_timestamp`. For the
     * other statuses `hist_timestamp` is null.
     *
     * The Eligible view keeps its preview-before-send through
     * findVerificationEligible(). This method only lists the rows for the table.
     *
     * The profile columns are the owner's state. `email_blocked_active` and
     * `email_bounced_active` are 1 only while the flag applies to the
     * owner's current address ({@see emailFlagActiveSql()}). `email_blocked_cause` is
     * the stored cause, also when the block does not apply. For a car whose
     * owner has no profiles row, `has_profile` is 0, the three flag columns
     * are 0 and `email_blocked_cause` is null.
     *
     * @param string $status One of {@see self::QUEUE_STATUSES}
     * @param int|null $windowDays Window for `verified` and `sold`, in days back from now. Null = all time.
     * @param int $limit Maximum rows to return (values below 1 return no rows)
     * @return array<object{id: int|string, year: int|string|null, chassis: string, fname: ?string, lname: ?string,
     *         user_id: int|string|null, vericode_sent_at: ?string, last_verified: ?string, solddate: ?string,
     *         has_profile: int|string, email_opted_out: int|string, email_blocked_active: int|string,
     *         email_blocked_cause: ?string, email_bounced_active: int|string, hist_timestamp: ?string}>
     * @throws CarValidationException If $status is not in QUEUE_STATUSES, or $windowDays is zero or negative
     * @throws CarDatabaseException If the query fails
     */
    public function findVerificationQueue(string $status, ?int $windowDays, int $limit): array
    {
        if (!in_array($status, self::QUEUE_STATUSES, true)) {
            throw new CarValidationException(
                "CarRepository::findVerificationQueue received an unknown status '{$status}'. "
                . 'Use one of: ' . implode(', ', self::QUEUE_STATUSES) . '.'
            );
        }
        self::assertValidWindowDays($windowDays, 'findVerificationQueue');

        // Interpolated, not bound, for the reason in findVerificationEligible().
        $limit = max(0, $limit);

        $columns = 'cars.id, cars.year, cars.chassis, cars.fname, cars.lname, cars.user_id,
                    cars.vericode_sent_at, cars.last_verified, cars.solddate,
                    (p.id IS NOT NULL) AS has_profile,
                    COALESCE(p.email_opted_out, 0) AS email_opted_out,
                    COALESCE((' . self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BLOCKED) . '), 0)
                        AS email_blocked_active,
                    p.email_blocked_cause,
                    COALESCE((' . self::emailFlagActiveSql(DeliverabilityFlagLog::FLAG_BOUNCED) . '), 0)
                        AS email_bounced_active';
        $carsFrom   = 'FROM cars ' . self::PROFILE_JOIN_SQL . ' ' . self::USERS_JOIN_SQL;
        $pending    = self::verificationPendingWhereSql();
        $bounced    = self::verificationBouncedWhereSql();
        $suppressed = self::verificationSuppressedWhereSql();
        $noProfile  = self::verificationNoProfileWhereSql();
        $params     = [];

        switch ($status) {
            case 'eligible':
                $sql = "SELECT {$columns}, NULL AS hist_timestamp " . self::verificationEligibleFromSql()
                    . ' ORDER BY cars.last_verified ASC';
                break;
            case 'pending':
                $sql = "SELECT {$columns}, NULL AS hist_timestamp {$carsFrom} WHERE {$pending}"
                    . ' ORDER BY cars.vericode_sent_at ASC, cars.id ASC';
                break;
            case 'bounced':
                $sql = "SELECT {$columns}, NULL AS hist_timestamp {$carsFrom} WHERE {$bounced}"
                    . ' ORDER BY cars.id ASC';
                break;
            case 'suppressed':
                $sql = "SELECT {$columns}, NULL AS hist_timestamp {$carsFrom} WHERE {$suppressed}"
                    . ' ORDER BY cars.id ASC';
                break;
            case 'verified':
            case 'sold':
                [$window, $params] = self::windowSql('timestamp', $windowDays);
                array_unshift(
                    $params,
                    $status === 'verified' ? self::OPERATION_VERIFIED : self::OPERATION_VERIFIED_SOLD
                );
                $sql = "SELECT {$columns}, h.hist_timestamp
                          {$carsFrom}
                          INNER JOIN (
                                SELECT car_id, MAX(timestamp) AS hist_timestamp
                                  FROM cars_hist
                                 WHERE operation = ?{$window}
                                 GROUP BY car_id
                               ) h ON h.car_id = cars.id
                         ORDER BY h.hist_timestamp DESC, cars.id DESC";
                break;
            default: // 'all'
                $sql = "SELECT {$columns}, NULL AS hist_timestamp {$carsFrom}
                         WHERE cars.id IN (SELECT cars.id " . self::verificationEligibleFromSql() . ")
                            OR {$pending}
                            OR {$bounced}
                            OR {$suppressed}
                            OR {$noProfile}
                         ORDER BY cars.id ASC";
                break;
        }

        $result = $this->db->query("{$sql} LIMIT {$limit}", $params);

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findVerificationQueue failed (status={$status} window="
                . ($windowDays ?? 'all') . " limit={$limit}): " . $this->db->errorString()
            );
        }

        return $result->results();
    }

    /**
     * Find the most recent `VERIFIED` and `VERIFIED SOLD` history rows
     *
     * Reads the `cars_hist` row only, so the year, chassis and owner name are
     * the values at the time of the event. A later merge or deletion of the
     * car does not remove the row from this list. Newest first, with `id`
     * as the tiebreak for equal timestamps.
     *
     * @param int|null $windowDays Days back from now, or null for all time
     * @param int $limit Maximum rows to return (values below 1 return no rows)
     * @return array<object{id: int|string, car_id: int|string, operation: string, timestamp: string,
     *         year: int|string|null, chassis: string, fname: ?string, lname: ?string}>
     * @throws CarValidationException If $windowDays is zero or negative
     * @throws CarDatabaseException If the query fails
     */
    public function findRecentVerificationActivity(?int $windowDays, int $limit = 20): array
    {
        self::assertValidWindowDays($windowDays, 'findRecentVerificationActivity');

        // Interpolated, not bound, for the reason in findVerificationEligible().
        $limit = max(0, $limit);
        [$window, $windowParams] = self::windowSql('h.timestamp', $windowDays);

        $result = $this->db->query(
            "SELECT h.id, h.car_id, h.operation, h.timestamp, h.year, h.chassis, h.fname, h.lname
               FROM cars_hist h
              WHERE h.operation IN (?, ?){$window}
              ORDER BY h.timestamp DESC, h.id DESC
              LIMIT {$limit}",
            array_merge([self::OPERATION_VERIFIED, self::OPERATION_VERIFIED_SOLD], $windowParams)
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::findRecentVerificationActivity failed (window=' . ($windowDays ?? 'all')
                . " limit={$limit}): " . $this->db->errorString()
            );
        }

        return $result->results();
    }

    /**
     * Update the sold date for a car
     *
     * @deprecated No production callers since v2.30.3 — CarVerificationManager::markSold()
     *             writes `solddate` via updateCar() directly so it can set
     *             `owner_last_updated` in the same atomic write. Tracked for removal in #2107.
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
     * @param string|null $ownerLastUpdated When set, the same UPDATE also writes
     *                                  this value to owner_last_updated (an
     *                                  owner removed a photo). It is one UPDATE,
     *                                  not two, so the cars_update trigger writes
     *                                  one cars_hist row and the CAS guard also
     *                                  protects the timestamp. Null leaves
     *                                  owner_last_updated unchanged.
     * @return bool True if the row was updated, false on concurrent modification
     * @throws CarDatabaseException If the query itself fails
     */
    public function updateImage(
        int $carId,
        string $newJson,
        ?string $expectedJson,
        ?string $ownerLastUpdated = null
    ): bool {
        // `<=>` is MySQL's null-safe equality. Plain `=` is never true against a
        // NULL column, and cars.image is nullable with no default, so a car that
        // has never had an image cannot be matched by `image = ''` — the CAS
        // would reject every such update. `<=>` matches NULL to NULL and behaves
        // identically to `=` for non-NULL values.
        if ($ownerLastUpdated === null) {
            $this->db->query(
                'UPDATE cars SET image = ? WHERE id = ? AND image <=> ?',
                [$newJson, $carId, $expectedJson]
            );
        } else {
            $this->db->query(
                'UPDATE cars SET image = ?, owner_last_updated = ? WHERE id = ? AND image <=> ?',
                [$newJson, $ownerLastUpdated, $carId, $expectedJson]
            );
        }
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
     * @param string $code The plaintext verification code; it is hashed via
     *                     hashVericode() before the lookup, since cars.vericode
     *                     stores only the HMAC-SHA256 digest.
     * @return object|null Car data, or null if not found. The returned object's
     *                     ->vericode property (if accessed) is always the stored
     *                     hash, never plaintext.
     * @throws CarDatabaseException If the query fails
     */
    public function findByVerificationCode(string $code): ?object
    {
        $hashedCode = hashVericode($code);
        $result = $this->db->query('SELECT * FROM cars WHERE vericode = ?', [$hashedCode]);
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
     * Find the owner of a car, for a deliverability write
     *
     * A delivery event arrives for a car, but the flag belongs to the owner's
     * profile (#2101). The caller uses `user_id` and `username` to skip a car
     * with no owner, a deleted owner, or the `noowner` system account.
     *
     * @param int $carId Car ID
     * @return object{user_id: int|string|null, username: ?string}|null Null when the car does not exist.
     *         `username` is null when `user_id` is NULL or points at no users row.
     * @throws CarDatabaseException If the query fails
     */
    public function findOwnerOfCar(int $carId): ?object
    {
        $result = $this->db->query(
            'SELECT c.user_id, u.username
               FROM cars c
               LEFT JOIN users u ON u.id = c.user_id
              WHERE c.id = ?',
            [$carId]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findOwnerOfCar failed for car={$carId}: " . $this->db->errorString()
            );
        }
        if ($this->db->count() === 0) {
            return null;
        }
        $row = $result->first();

        return is_object($row) ? $row : null;
    }

    /**
     * Find when an owner's profile flag was last cleared by a given source
     *
     * Reads the `logs` rows that {@see DeliverabilityFlagLog::record()} writes.
     * The row must have `logtype` = LOG_CATEGORY_EMAIL_BOUNCED and metadata
     * with this integer `owner_id`, this `flag`, `new` = 0 and this `source`.
     * The time is the metadata `cleared_at` when the row has a valid one
     * (#2357), else `logdate`.
     *
     * `logs.metadata` is a BLOB, so the query converts it to utf8mb4 before
     * it reads JSON (a plain JSON_EXTRACT raises error 3144). The `logtype`
     * filter comes first, so the query uses `idx_logs_logtype_logdate`. A row
     * with NULL or invalid metadata is skipped. `lognote` is never read.
     *
     * @param int $ownerId `users.id` of the owner (more than 0)
     * @param string $flag One of {@see DeliverabilityFlagLog::FLAGS}
     * @param string $source One of {@see DeliverabilityFlagLog::SOURCES}
     * @return string|null The clear time in AppConstants::DATETIME_FORMAT, or null when no row matches
     * @throws InvalidArgumentException If an argument is not a permitted value
     * @throws CarDatabaseException If the query fails
     */
    public function latestFlagClearAt(int $ownerId, string $flag, string $source): ?string
    {
        self::assertValidOwnerId($ownerId, 'latestFlagClearAt');
        DeliverabilityFlagLog::assertValidFlag($flag);
        DeliverabilityFlagLog::assertValidSource($source);

        $rows = $this->findFlagLogRows(
            $ownerId,
            ' AND ' . self::flagLogJsonSql('flag') . ' = ?'
            . ' AND ' . self::flagLogJsonSql('new') . ' = 0'
            . ' AND ' . self::flagLogJsonSql('source') . ' = ?',
            [$flag, $source],
            self::FLAG_CHANGES_MAX_LIMIT,
            'latestFlagClearAt'
        );

        return $rows === [] ? null : $rows[0]['date'];
    }

    /**
     * Find an owner's most recent profile flag changes, newest first
     *
     * Reads the same `logs` rows as {@see latestFlagClearAt()}, with the same
     * BLOB conversion, the same exact integer `owner_id` match and the same
     * date rule. Sorted `logdate DESC, id DESC`. A row whose metadata is NULL,
     * not valid JSON, or does not hold a permitted flag, source and 0/1 values
     * is skipped. The result holds no address.
     *
     * @param int $ownerId `users.id` of the owner (more than 0)
     * @param int $limit Maximum rows, clamped to 1 to 100
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     * @throws InvalidArgumentException If $ownerId is not more than 0
     * @throws CarDatabaseException If the query fails
     */
    public function recentFlagChanges(int $ownerId, int $limit): array
    {
        self::assertValidOwnerId($ownerId, 'recentFlagChanges');
        $limit = max(1, min(self::FLAG_CHANGES_MAX_LIMIT, $limit));

        return $this->findFlagLogRows($ownerId, '', [], $limit, 'recentFlagChanges');
    }

    /**
     * Read flag-change log rows for one owner, newest first, and decode them
     *
     * The SQL keeps the owner and the extra conditions. PHP then checks each
     * row's metadata again and drops a row that does not hold the
     * {@see DeliverabilityFlagLog} contract, so a malformed row never reaches
     * a caller. A dropped row still counts toward `$limit`, so the result can
     * hold fewer rows than `$limit`. latestFlagClearAt() reads up to 100 rows
     * and takes the first valid one for this reason.
     *
     * @param int $ownerId Owner user ID (already validated)
     * @param string $extraWhereSql Extra conditions that start with ` AND`, literal from this class only
     * @param list<string> $extraParams Values for the extra conditions
     * @param int $limit Maximum rows to return (already clamped to 1 to 100)
     * @param string $method Calling method name, for the exception message
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     * @throws CarDatabaseException If the query fails
     */
    private function findFlagLogRows(
        int $ownerId,
        string $extraWhereSql,
        array $extraParams,
        int $limit,
        string $method
    ): array {
        // $ownerId is bound and cast to UNSIGNED in SQL: DB::query() binds
        // every value as a string, and a JSON value compared with a string
        // matches a JSON string "12" but not the integer 12. CAST makes the
        // compare a JSON integer compare, so a string owner_id never matches.
        // The LIMIT is interpolated for the reason in findVerificationEligible();
        // it is an int clamped to 1 to 100.
        $result = $this->db->query(
            'SELECT id, user_id, logdate, CONVERT(metadata USING utf8mb4) AS metadata_json
               FROM logs
              WHERE logtype = ?
                AND ' . self::flagLogJsonSql('owner_id') . ' = CAST(? AS UNSIGNED)'
                . $extraWhereSql . '
              ORDER BY logdate DESC, id DESC
              LIMIT ' . $limit,
            array_merge([LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, (string) $ownerId], $extraParams)
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::{$method} failed for owner={$ownerId}: " . $this->db->errorString()
            );
        }

        $rows = [];
        foreach ($result->results() as $row) {
            $decoded = self::decodeFlagLogRow($row, $ownerId);
            if ($decoded !== null) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * Build the SQL that reads one key from `logs.metadata` as JSON
     *
     * The CASE makes MySQL check JSON_VALID before JSON_EXTRACT. JSON_EXTRACT
     * on text that is not JSON raises an error, and MySQL does not promise
     * the order in which it checks AND conditions. NULL metadata gives NULL,
     * which matches nothing.
     *
     * @param string $key Metadata key, literal from this class only
     * @return string SQL expression, a JSON value or NULL
     */
    private static function flagLogJsonSql(string $key): string
    {
        return "(CASE WHEN JSON_VALID(CONVERT(metadata USING utf8mb4))"
            . " THEN JSON_EXTRACT(CONVERT(metadata USING utf8mb4), '$.{$key}') END)";
    }

    /**
     * Decode one flag-change log row, or return null when its metadata breaks the contract
     *
     * @param object $row Row with `user_id`, `logdate` and `metadata_json`
     * @param int $ownerId The owner the query asked for
     * @return array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}|null
     */
    private static function decodeFlagLogRow(object $row, int $ownerId): ?array
    {
        $json = $row->metadata_json ?? null;
        if (!is_string($json) || $json === '') {
            return null;
        }
        $meta = json_decode($json, true);
        if (!is_array($meta)) {
            return null;
        }

        $flag   = $meta['flag'] ?? null;
        $source = $meta['source'] ?? null;
        $old    = $meta['old'] ?? null;
        $new    = $meta['new'] ?? null;
        if (
            ($meta['owner_id'] ?? null) !== $ownerId
            || !is_string($flag) || !in_array($flag, DeliverabilityFlagLog::FLAGS, true)
            || !is_string($source) || !in_array($source, DeliverabilityFlagLog::SOURCES, true)
            || !in_array($old, [0, 1], true) || !in_array($new, [0, 1], true)
        ) {
            return null;
        }

        return [
            'date'           => self::flagLogDate($meta['cleared_at'] ?? null, (string) $row->logdate),
            'flag'           => $flag,
            'old'            => $old,
            'new'            => $new,
            'source'         => $source,
            'acting_user_id' => (int) $row->user_id,
        ];
    }

    /**
     * Pick the date of a flag-change row: a valid metadata `cleared_at`, else `logdate`
     */
    private static function flagLogDate(mixed $clearedAt, string $logdate): string
    {
        if (is_string($clearedAt) && $clearedAt !== '') {
            try {
                return self::parseTimestamp($clearedAt, 'cleared_at')->format(AppConstants::DATETIME_FORMAT);
            } catch (CarValidationException) {
                // A malformed cleared_at is not a reason to lose the row. logdate is always set.
            }
        }

        return $logdate;
    }

    /**
     * @throws InvalidArgumentException If $ownerId is not more than 0
     */
    private static function assertValidOwnerId(int $ownerId, string $method): void
    {
        if ($ownerId <= 0) {
            throw new InvalidArgumentException("CarRepository::{$method}: owner ID must be more than 0, got {$ownerId}");
        }
    }

    /**
     * Find the email/verification state for every car this user owns
     *
     * Backs the admin user-view's email block (#1924), which shows one row per
     * owned car alongside the car-button list. The rows are ordered
     * `model, year`. The car-button list (`findByOwner()`) has no order, so
     * the two lists need not match.
     *
     * The rows hold car columns only. For the owner's deliverability flags,
     * use {@see effectiveEmailFlags()} (#2356).
     *
     * TYPES ARE AS PDO RETURNS THEM, not as the schema declares them. The
     * type of a numeric column depends on the PDO settings (for example
     * `ATTR_STRINGIFY_FETCHES`).
     *
     * @param int $ownerId Owner user ID
     * @return array<object{
     *   id: int|string, model: string, series: ?string, variant: ?string, year: int|string|null,
     *   email: ?string, owner_last_updated: string, last_verified: ?string
     * }> One row per car owned by this user, ordered (model, year)
     * @throws CarDatabaseException If the query fails
     */
    public function findVerificationStateByOwner(int $ownerId): array
    {
        $result = $this->db->query(
            'SELECT cars.id, cars.model, cars.series, cars.variant, cars.year, cars.email,
                    cars.owner_last_updated, cars.last_verified
               FROM cars
              WHERE cars.user_id = ?
              ORDER BY cars.model, cars.year',
            [$ownerId]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::findVerificationStateByOwner failed for user={$ownerId}: " . $this->db->errorString()
            );
        }

        return $result->results();
    }

    /**
     * Find the cars of the owner whose account email matches a given address
     *
     * Used by the Brevo webhook, the event reconciliation job and the
     * suppression sync to map an event's recipient address to cars. The
     * verification send mails `users.email`, so the match uses that address.
     * `cars.email` is only a copy, and an old copy must not take the event.
     *
     * Both sides are normalized ({@see self::CURRENT_ADDRESS_SQL}), so case
     * and leading or trailing white space do not stop a match. The
     * `noowner` system account is left out: an event must never flag it.
     * More than one account can hold the same address, so this returns the
     * cars of each such owner.
     *
     * @param string $email Recipient address from the event
     * @return array<object> Objects with `id` (the car) and `email` (the owner's
     *                       `users.email`). Empty when no owner matches.
     * @throws CarDatabaseException If the query fails
     */
    public function findByEmail(string $email): array
    {
        if (trim($email) === '') {
            return [];
        }

        // PHP trim() keeps \f and a no-break space, but the SQL wrapper
        // removes them. Such an address normalizes to '' and would match
        // every owner with an empty email, so the SQL also rejects ''.
        $result = $this->db->query(
            'SELECT cars.id, u.email
               FROM cars
              INNER JOIN users u ON u.id = cars.user_id
              WHERE u.username != \'noowner\'
                AND ' . self::CURRENT_ADDRESS_SQL . " != ''
                AND " . self::CURRENT_ADDRESS_SQL . ' = '
                . self::NORMALIZE_SQL_OPEN . '?' . self::NORMALIZE_SQL_CLOSE . ' COLLATE utf8mb4_bin
              ORDER BY cars.id',
            [$email]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::findByEmail failed: ' . $this->db->errorString()
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
     * cycles after the later of these two times count:
     * - the most recent `delivered` event for this email, and
     * - the `email_bounce_cleared_at` of the car owner's profile (#2101), so a
     *   soft bounce before an admin Clear Bounced does not count again.
     * When neither exists, all soft-bounce cycles on record count. This is the
     * query the event applier uses to decide whether repeated soft bounces
     * escalate to a bounce.
     *
     * @param string $email Email address to count soft bounces for
     * @param int $carId Car the event was recorded against. Its owner's
     *                   profile gives `email_bounce_cleared_at`. A car with no
     *                   owner or no profile has no clear time.
     * @return int Number of distinct soft-bounce message ids since the later of the two times
     * @throws CarDatabaseException If the query fails
     */
    public function countSoftBouncesSinceLastDelivered(string $email, int $carId): int
    {
        $this->db->query(
            "SELECT COUNT(DISTINCT brevo_message_id) AS cnt
               FROM er_email_events
              WHERE email = ?
                AND event = 'soft_bounce'
                AND occurred_at > GREATEST(
                    COALESCE(
                        (SELECT MAX(occurred_at) FROM er_email_events WHERE email = ? AND event = 'delivered'),
                        '1970-01-01 00:00:00'
                    ),
                    COALESCE(
                        (SELECT p.email_bounce_cleared_at
                           FROM cars c
                           INNER JOIN profiles p ON p.user_id = c.user_id
                          WHERE c.id = ?),
                        '1970-01-01 00:00:00'
                    )
                )",
            [$email, $email, $carId]
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                "CarRepository::countSoftBouncesSinceLastDelivered failed for car={$carId}: "
                . $this->db->errorString()
            );
        }

        $row = $this->db->first();
        if (!is_object($row) || !isset($row->cnt)) {
            // A COUNT() query always yields exactly one row — reaching here
            // means the query did not execute as written, not that the true
            // count is zero. Returning 0 would silently disable soft-bounce
            // escalation for this email instead of surfacing the fault.
            throw new CarDatabaseException(
                "CarRepository::countSoftBouncesSinceLastDelivered returned no COUNT row for car={$carId}"
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
     * Find the most recent er_email_events row for each of a set of car ids
     *
     * Self-joins the table against its own `(car_id, MAX(occurred_at))`
     * aggregate rather than using a window function — no window functions are
     * used anywhere else in this codebase's SQL. If a car somehow has two rows
     * sharing the same maximum `occurred_at`, the join yields both and the
     * later one wins the array key; either is equally "latest" by the only
     * ordering the table records. No-ops cleanly on an empty array rather than
     * issuing a query with an empty IN() list.
     *
     * @param array<int> $carIds
     * @return array<int, object{event: string, occurred_at: string, reason: ?string}>
     *         Keyed by car_id. Cars with no event history are simply absent from
     *         the returned array — callers must treat a missing key as "no events
     *         recorded," not query it as an error.
     * @throws CarDatabaseException If the query fails
     */
    public function findLatestEmailEventsByCarIds(array $carIds): array
    {
        if ($carIds === []) {
            return [];
        }

        $ids          = array_values($carIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $result = $this->db->query(
            "SELECT e.car_id, e.event, e.occurred_at, e.reason
               FROM er_email_events e
               JOIN (
                     SELECT car_id, MAX(occurred_at) AS max_occurred_at
                       FROM er_email_events
                      WHERE car_id IN ({$placeholders})
                      GROUP BY car_id
                    ) latest
                 ON latest.car_id = e.car_id
                AND latest.max_occurred_at = e.occurred_at
              WHERE e.car_id IN ({$placeholders})",
            array_merge($ids, $ids)
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::findLatestEmailEventsByCarIds failed for car_ids=' . implode(',', $ids)
                . ': ' . $this->db->errorString()
            );
        }

        $latest = [];
        foreach ($result->results() as $row) {
            $latest[(int) $row->car_id] = $row;
        }

        return $latest;
    }

    /**
     * Find the er_email_events row that sets each car's Status chip
     *
     * This is the rule in the car-owner-verification FRD §10.10.
     *
     * 1. Scope to the current send cycle. The cycle starts at the car's
     *    latest `sent` row and holds that row and every later event. A car
     *    with no `sent` row (for example, only suppression-import rows) uses
     *    all of its events.
     * 2. In the cycle, a terminal event wins over all other events, at any
     *    timestamp. Terminal events are EmailEventApplier::HARD_BOUNCE_EVENTS
     *    and EmailEventApplier::SUPPRESSION_EVENTS. A message that was
     *    delivered and then hard-bounced shows the bounce.
     * 3. Then the latest row wins: `occurred_at DESC, id DESC`. The `id`
     *    tiebreak stops the chip from changing between two rows that share
     *    a timestamp.
     *
     * Why the cycle is not a `brevo_message_id` match: the `sent` row that
     * CarVerificationSendService writes has a local id
     * (`local:` + SHA-256 of the code), because the mailer returns no Brevo
     * id. Webhook rows carry Brevo's own `message-id`. The two ids never
     * match, and `cars` has no message-id column. The `sent` row's
     * `occurred_at` is the send time (it equals `cars.vericode_sent_at`),
     * and webhook times use the same server clock, so the time boundary
     * gives the cycle.
     *
     * A webhook event matches cars by email, so it is recorded on every car
     * of that owner. An event for one car's message can therefore show on
     * another car of the same owner if that car's cycle is open.
     *
     * Ranking happens in SQL. PHP keeps the first row for each car. A cycle
     * holds only a few rows, so this is less complex than a three-level
     * MAX() self-join and needs no window function.
     *
     * @param array<int> $carIds Positive car ids. Duplicates are removed.
     * @return array<int, object{car_id: int|string, event: string, occurred_at: string, reason: ?string,
     *         brevo_message_id: string}>
     *         Keyed by car_id. A car with no event in its cycle is absent.
     *         Callers must treat a missing key as "no events", not as an error.
     * @throws CarValidationException If an element of $carIds is not a positive int
     * @throws CarDatabaseException If the query fails
     */
    public function findLatestEmailEventPerCarWithPrecedence(array $carIds): array
    {
        $ids = self::normalizeCarIds($carIds, 'findLatestEmailEventPerCarWithPrecedence');
        if ($ids === []) {
            return [];
        }

        $terminal = array_values(array_unique(array_merge(
            EmailEventApplier::HARD_BOUNCE_EVENTS,
            EmailEventApplier::SUPPRESSION_EVENTS
        )));

        $idPlaceholders       = implode(',', array_fill(0, count($ids), '?'));
        $terminalPlaceholders = implode(',', array_fill(0, count($terminal), '?'));

        $result = $this->db->query(
            "SELECT e.car_id, e.event, e.occurred_at, e.reason, e.brevo_message_id
               FROM er_email_events e
               LEFT JOIN (
                     SELECT car_id, MAX(occurred_at) AS cycle_start
                       FROM er_email_events
                      WHERE car_id IN ({$idPlaceholders})
                        AND event = ?
                      GROUP BY car_id
                    ) cycle
                 ON cycle.car_id = e.car_id
              WHERE e.car_id IN ({$idPlaceholders})
                AND (cycle.cycle_start IS NULL OR e.occurred_at >= cycle.cycle_start)
              ORDER BY e.car_id ASC,
                       CASE WHEN e.event IN ({$terminalPlaceholders}) THEN 1 ELSE 0 END DESC,
                       e.occurred_at DESC,
                       e.id DESC",
            array_merge($ids, [self::EVENT_SENT], $ids, $terminal)
        );

        if ($this->db->error()) {
            throw new CarDatabaseException(
                'CarRepository::findLatestEmailEventPerCarWithPrecedence failed for car_ids=' . implode(',', $ids)
                . ': ' . $this->db->errorString()
            );
        }

        $chosen = [];
        foreach ($result->results() as $row) {
            $carId = (int) $row->car_id;
            if (!isset($chosen[$carId])) {
                $chosen[$carId] = $row;
            }
        }

        return $chosen;
    }

    /**
     * Check, dedupe and re-index a list of car ids before they are bound
     *
     * @param array<mixed> $carIds Values to check
     * @param string $method Calling method name, for the error message
     * @return list<int> Unique positive car ids
     * @throws CarValidationException If an element is not an int, or is less than 1
     */
    private static function normalizeCarIds(array $carIds, string $method): array
    {
        foreach ($carIds as $carId) {
            if (!is_int($carId) || $carId < 1) {
                throw new CarValidationException(
                    "CarRepository::{$method} received an invalid car id of type " . get_debug_type($carId)
                    . '. Each car id must be a positive int.'
                );
            }
        }

        return array_values(array_unique($carIds));
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
