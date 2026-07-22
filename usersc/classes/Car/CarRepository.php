<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DB;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarNotFoundException;
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

    public function __construct(private DB $db) {}

    /**
     * Find a car by ID
     *
     * @param int $carId Car ID
     * @return object|null Car data object or null if not found
     */
    public function findById(int $carId): ?object
    {
        $data = $this->db->get('cars', ['id', '=', $carId]);
        if ($data->count() === 0) {
            return null;
        }
        return $data->first();
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
     * @param int    $carId        Car ID
     * @param string $newJson      New JSON-encoded image list
     * @param string $expectedJson The image value that must currently be stored (CAS guard)
     * @return bool True if the row was updated, false on concurrent modification
     * @throws CarDatabaseException If the query itself fails
     */
    public function updateImage(int $carId, string $newJson, string $expectedJson): bool
    {
        $this->db->query(
            'UPDATE cars SET image = ? WHERE id = ? AND image = ?',
            [$newJson, $carId, $expectedJson]
        );
        if ($this->db->error()) {
            throw new CarDatabaseException('Image update query failed');
        }
        return $this->db->count() === 1;
    }

    /**
     * Find a car by verification code
     *
     * @param string $code Verification code
     * @return object|null Car data or null
     */
    public function findByVerificationCode(string $code): ?object
    {
        $result = $this->db->query('SELECT * FROM cars WHERE vericode = ?', [$code]);
        if ($result->count() > 0) {
            return $result->first();
        }
        return null;
    }

    /**
     * Find car IDs owned by a specific user
     *
     * @param int $ownerId Owner user ID
     * @return array<object> Array of objects with 'id' property
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->db->query("SELECT id FROM cars WHERE user_id = ?", [$ownerId])->results();
    }

    /**
     * Get car history records
     *
     * @param int $carId Car ID
     * @return array<object> History records (empty if none)
     */
    public function getHistory(int $carId): array
    {
        return $this->db->query(
            'SELECT id, car_id, ctime, mtime, timestamp, operation,
                    model, series, variant, year, type, chassis, chassis_override, color, engine,
                    purchasedate, solddate, comments, image,
                    user_id, fname, join_date, city, state, country, lat, lon, website
             FROM cars_hist WHERE car_id = ? ORDER BY timestamp DESC',
            [$carId]
        )->results();
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
     * Look up factory information by chassis serial number
     *
     * @param string $chassis Full chassis number
     * @param int $suffixLength Length of chassis suffix to try as secondary search
     * @return object|null Factory info object or null
     */
    public function getFactoryInfo(string $chassis, int $suffixLength): ?object
    {
        $search = [$chassis, substr($chassis, -$suffixLength)];

        foreach ($search as $serialNumber) {
            $factory = $this->db->query('SELECT * FROM elan_factory_info WHERE serial = ? ', [$serialNumber]);
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
