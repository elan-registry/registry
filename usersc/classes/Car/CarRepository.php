<?php

declare(strict_types=1);

namespace ElanRegistry\Car;

use DB;

/**
 * CarRepository - Database access layer for car operations
 *
 * Extracted from Car.php to provide a focused, testable data access layer.
 * Wraps DB operations for cars, car_user, cars_hist, and elan_factory_info tables.
 *
 * @package ElanRegistry\Car
 * @since v2.15.0
 * @see https://github.com/unibrain1/elanregistry/issues/463
 */
class CarRepository
{
    private DB $db;

    private bool $transactionOwner = false;

    /**
     * @param DB $db Database instance
     */
    public function __construct(DB $db)
    {
        $this->db = $db;
    }

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
     * Find all cars
     *
     * @return array<object> Array of car data objects
     */
    public function findAll(): array
    {
        return $this->db->findAll('cars')->results();
    }

    /**
     * Insert a record into a table
     *
     * @param string $table Table name
     * @param array<string, mixed> $fields Field values
     * @return bool True on success
     */
    public function insert(string $table, array $fields): bool
    {
        return $this->db->insert($table, $fields);
    }

    /**
     * Update a record in a table
     *
     * @param string $table Table name
     * @param int $id Record ID
     * @param array<string, mixed> $fields Field values
     * @return bool True on success
     */
    public function update(string $table, int $id, array $fields): bool
    {
        return $this->db->update($table, $id, $fields);
    }

    /**
     * Delete a car by ID
     *
     * @param int $carId Car ID
     * @return bool True on success
     */
    public function deleteCar(int $carId): bool
    {
        $this->db->query("DELETE FROM cars WHERE id = ?", [$carId]);
        return !$this->db->error();
    }

    /**
     * Delete car-user relationship by car ID
     *
     * @param int $carId Car ID
     * @return bool True on success
     */
    public function deleteCarUser(int $carId): bool
    {
        $this->db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
        return !$this->db->error();
    }

    /**
     * Delete all car-user relationships for a user ID
     *
     * Used during user deletion cleanup to remove all of a user's car assignments.
     *
     * @param int $userId User ID
     * @return bool True on success
     */
    public function deleteCarUserByUserId(int $userId): bool
    {
        $this->db->query("DELETE FROM car_user WHERE userid = ?", [$userId]);
        return !$this->db->error();
    }

    /**
     * Insert a car-user relationship
     *
     * @param int $userId User ID
     * @param int $carId Car ID
     * @return bool True on success
     */
    public function insertCarUser(int $userId, int $carId): bool
    {
        return $this->db->insert('car_user', ['userid' => $userId, 'car_id' => $carId]);
    }

    /**
     * Update car-user relationship to new owner
     *
     * @param int $newUserId New user ID
     * @param int $carId Car ID
     * @return bool True on success
     */
    public function updateCarUser(int $newUserId, int $carId): bool
    {
        $this->db->query("UPDATE car_user SET userid = ? WHERE car_id = ?", [$newUserId, $carId]);
        return !$this->db->error();
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
        return $this->update('cars', $carId, ['vericode' => $verificationCode]);
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
        return $this->update('cars', $carId, ['last_verified' => $dateTime]);
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
        return $this->update('cars', $carId, ['solddate' => $soldDate]);
    }

    /**
     * Update the image JSON for a car
     *
     * @param int $carId Car ID
     * @param string $imageJson JSON-encoded image list (empty string clears all images)
     * @return bool True on success
     */
    public function updateImage(int $carId, string $imageJson): bool
    {
        return $this->update('cars', $carId, ['image' => $imageJson]);
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
        return $this->db->query("SELECT car_id AS id FROM car_user WHERE userid = ?", [$ownerId])->results();
    }

    /**
     * Get car history records
     *
     * @param int $carId Car ID
     * @return array<object>|null History records or null if none
     */
    public function getHistory(int $carId): ?array
    {
        $data = $this->db->query("SELECT * from cars_hist WHERE car_id = ? ORDER BY timestamp DESC", [$carId]);
        if ($data->count()) {
            return $data->results();
        }
        return null;
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

    /**
     * Get the underlying DB instance
     *
     * @return DB Database instance
     */
    public function getDb(): DB
    {
        return $this->db;
    }
}
