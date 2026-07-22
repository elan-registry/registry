<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

use DB;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

/**
 * CarTransferRepository - Database access layer for car transfer requests
 *
 * Consolidates all SQL access to the car_transfer_requests table to provide a
 * focused, testable data access layer.
 *
 * @package ElanRegistry\Transfer
 * @since v2.25.6
 * @see https://github.com/unibrain1/elanregistry/issues/1062
 */
class CarTransferRepository
{
    public function __construct(private DB $db) {}

    /**
     * Find a transfer request by ID.
     *
     * @param int $id Transfer request ID
     * @return object|null Transfer request data object or null if not found
     * @throws CarDatabaseException on database error
     */
    public function findById(int $id): ?object
    {
        $result = $this->db->query(
            'SELECT * FROM car_transfer_requests WHERE id = ?',
            [$id]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::findById failed for id=$id: " . $this->db->errorString());
            throw new CarDatabaseException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Find a pending transfer request by ID.
     *
     * @param int $id Transfer request ID
     * @return object|null Object with id and requested_by_user_id, or null if not found
     * @throws CarDatabaseException on database error
     */
    public function findPendingById(int $id): ?object
    {
        $result = $this->db->query(
            "SELECT id, requested_by_user_id FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$id]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::findPendingById failed for id=$id: " . $this->db->errorString());
            throw new CarDatabaseException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Find a pending transfer request by ID, joined to its car.
     *
     * @param int $id Transfer request ID
     * @return object|null Full transfer request row (ctr.*) plus car_id and current_owner_id aliases, or null if not found
     * @throws CarDatabaseException on database error
     */
    public function findPendingWithCarById(int $id): ?object
    {
        $result = $this->db->query(
            "SELECT ctr.*, c.id AS car_id, c.user_id AS current_owner_id
             FROM car_transfer_requests ctr
             JOIN cars c ON ctr.existing_car_id = c.id
             WHERE ctr.id = ? AND ctr.status = 'pending'",
            [$id]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::findPendingWithCarById failed for id=$id: " . $this->db->errorString());
            throw new CarDatabaseException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Determine whether a user already has an active (pending, non-expired) transfer request for a car.
     *
     * Expired requests (status = 'pending' but expires_at in the past) are excluded,
     * consistent with getPendingWithCarAndUsers(). Without this filter, an expired request
     * would block re-submission while being invisible to admins.
     *
     * @param int $carId Car ID
     * @param int $userId Requesting user ID
     * @return bool True if an active pending request exists
     * @throws CarDatabaseException on database error (fail-closed: does not silently permit duplicates)
     */
    public function hasPendingForCar(int $carId, int $userId): bool
    {
        $result = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = 'pending' AND expires_at > NOW()",
            [$carId, $userId]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::hasPendingForCar failed for car=$carId user=$userId: " . $this->db->errorString());
            throw new CarDatabaseException('Database error checking for pending transfer request');
        }
        return $result->count() > 0;
    }

    /**
     * Get all pending, non-expired transfer requests with car and user details.
     *
     * @return array<object> Array of transfer request objects with car and owner/requester fields
     * @throws CarDatabaseException on database error
     */
    public function getPendingWithCarAndUsers(): array
    {
        $result = $this->db->query(
            "SELECT ctr.*,
                    c.chassis, c.year, c.type, c.color, c.series,
                    current_owner.fname AS current_fname, current_owner.lname AS current_lname, current_owner.email AS current_email,
                    requester.fname AS requester_fname, requester.lname AS requester_lname, requester.email AS requester_email
             FROM car_transfer_requests ctr
             JOIN cars c ON ctr.existing_car_id = c.id
             JOIN users current_owner ON c.user_id = current_owner.id
             JOIN users requester ON ctr.requested_by_user_id = requester.id
             WHERE ctr.status = 'pending' AND ctr.expires_at > NOW()
             ORDER BY ctr.request_date DESC"
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::getPendingWithCarAndUsers failed: ' . $this->db->errorString());
            throw new CarDatabaseException('Database error loading pending transfer requests');
        }
        return $result->results();
    }

    /**
     * Get counts of today's completed and denied transfer requests, grouped by status.
     *
     * @return array<object> Array of objects with status and count fields
     * @throws CarDatabaseException on database error
     */
    public function getTodayStatusCounts(): array
    {
        $result = $this->db->query(
            "SELECT status, COUNT(*) AS count
             FROM car_transfer_requests
             WHERE DATE(completed_date) = CURDATE() AND status IN ('completed', 'denied')
             GROUP BY status"
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::getTodayStatusCounts failed: ' . $this->db->errorString());
            throw new CarDatabaseException('Database error loading today transfer stats');
        }
        return $result->results();
    }

    /**
     * Count pending, non-expired transfer requests.
     *
     * @return int Number of pending requests
     * @throws CarDatabaseException on database error
     */
    public function countPending(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS count FROM car_transfer_requests WHERE status = 'pending' AND expires_at > NOW()"
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::countPending failed: ' . $this->db->errorString());
            throw new CarDatabaseException('Database error counting pending transfer requests');
        }
        return $result->count() > 0 ? (int) $result->first()->count : 0;
    }

    /**
     * Create a transfer request.
     *
     * @param array<string, mixed> $fields Field values
     * @return int New transfer request ID (always > 0)
     * @throws CarDatabaseException on insert failure or if the database returns no ID
     */
    public function create(array $fields): int
    {
        if (!$this->db->insert('car_transfer_requests', $fields)) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::create insert failed: ' . $this->db->errorString());
            throw new CarDatabaseException('Database error creating transfer request');
        }
        $id = $this->db->lastId();
        if ($id <= 0) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::create returned no ID after insert');
            throw new CarDatabaseException('Database error: no ID returned after creating transfer request');
        }
        return $id;
    }

    /**
     * Update the status, admin notes, and completion date of a transfer request.
     *
     * Terminal statuses (Completed, Denied, Expired) also record completed_date = NOW().
     * Non-terminal statuses (Pending, Approved) leave completed_date unchanged.
     *
     * Returns false only when the query succeeds but no row was matched — the expected
     * TOCTOU case where another admin processed the request first. Throws on DB error
     * so callers can distinguish "already processed" from "infrastructure failure".
     *
     * Note: terminal transitions include `AND status = 'pending'` in the WHERE clause
     * as a TOCTOU guard. Non-terminal transitions (Pending, Approved) do not — callers
     * are responsible for ensuring the row is in an expected state before calling.
     *
     * @param int $id Transfer request ID
     * @param TransferStatus $status New status
     * @param string $adminNotes Admin notes to record
     * @return bool True if one or more rows were updated; false if no row matched (already processed)
     * @throws CarDatabaseException on database error
     */
    public function updateStatus(int $id, TransferStatus $status, string $adminNotes): bool
    {
        if ($status->isTerminal()) {
            // AND status = 'pending' is the atomic TOCTOU gate: a second admin's
            // UPDATE will match 0 rows (already terminal) and return false.
            $result = $this->db->query(
                "UPDATE car_transfer_requests SET status = ?, admin_notes = ?, completed_date = NOW() WHERE id = ? AND status = 'pending'",
                [$status->value, $adminNotes, $id]
            );
        } else {
            $result = $this->db->query(
                "UPDATE car_transfer_requests SET status = ?, admin_notes = ? WHERE id = ?",
                [$status->value, $adminNotes, $id]
            );
        }

        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::updateStatus failed for id=$id status={$status->value}: " . $this->db->errorString());
            throw new CarDatabaseException('Database error updating transfer request status');
        }
        return $result->count() > 0;
    }
}
