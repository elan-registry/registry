<?php

declare(strict_types=1);

namespace ElanRegistry\Transfer;

use DB;
use LogCategories;

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
    private const VALID_STATUSES = ['pending', 'completed', 'denied', 'approved', 'expired'];

    /** Terminal statuses that record a completion timestamp. */
    private const TERMINAL_STATUSES = ['completed', 'denied', 'expired'];

    /**
     * Production always receives DB::getInstance(); declared as object so test
     * doubles (which implement query() only) can be injected without a type error.
     */
    private object $db;

    /**
     * @param object $db Database instance (accepts test doubles; production always passes DB)
     */
    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * Find a transfer request by ID.
     *
     * @param int $id Transfer request ID
     * @return object|null Transfer request data object or null if not found
     * @throws \RuntimeException on database error
     */
    public function findById(int $id): ?object
    {
        $result = $this->db->query(
            'SELECT * FROM car_transfer_requests WHERE id = ?',
            [$id]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::findById failed for id=$id: " . $this->db->errorString());
            throw new \RuntimeException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Find a pending transfer request by ID.
     *
     * @param int $id Transfer request ID
     * @return object|null Object with id and requested_by_user_id, or null if not found
     * @throws \RuntimeException on database error
     */
    public function findPendingById(int $id): ?object
    {
        $result = $this->db->query(
            "SELECT id, requested_by_user_id FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$id]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::findPendingById failed for id=$id: " . $this->db->errorString());
            throw new \RuntimeException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Find a pending transfer request by ID, joined to its car.
     *
     * @param int $id Transfer request ID
     * @return object|null Full transfer request row (ctr.*) plus car_id and current_owner_id aliases, or null if not found
     * @throws \RuntimeException on database error
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
            throw new \RuntimeException('Database error looking up transfer request');
        }
        return $result->count() > 0 ? $result->first() : null;
    }

    /**
     * Determine whether a user already has a pending transfer request for a car.
     *
     * @param int $carId Car ID
     * @param int $userId Requesting user ID
     * @return bool True if a pending request exists
     * @throws \RuntimeException on database error (fail-closed: does not silently permit duplicates)
     */
    public function hasPendingForCar(int $carId, int $userId): bool
    {
        $result = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = 'pending'",
            [$carId, $userId]
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "CarTransferRepository::hasPendingForCar failed for car=$carId user=$userId: " . $this->db->errorString());
            throw new \RuntimeException('Database error checking for pending transfer request');
        }
        return $result->count() > 0;
    }

    /**
     * Get all pending, non-expired transfer requests with car and user details.
     *
     * @return array<object> Array of transfer request objects with car and owner/requester fields
     * @throws \RuntimeException on database error
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
            throw new \RuntimeException('Database error loading pending transfer requests');
        }
        return $result->results();
    }

    /**
     * Get counts of today's completed and denied transfer requests, grouped by status.
     *
     * @return array<object> Array of objects with status and count fields
     * @throws \RuntimeException on database error
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
            throw new \RuntimeException('Database error loading today transfer stats');
        }
        return $result->results();
    }

    /**
     * Count pending, non-expired transfer requests.
     *
     * @return int Number of pending requests (0 on failure)
     */
    public function countPending(): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS count FROM car_transfer_requests WHERE status = 'pending' AND expires_at > NOW()"
        );
        if ($this->db->error()) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::countPending failed: ' . $this->db->errorString());
            return 0;
        }
        return $result->count() > 0 ? (int) $result->first()->count : 0;
    }

    /**
     * Create a transfer request.
     *
     * @param array<string, mixed> $fields Field values
     * @return int New transfer request ID, or 0 on failure
     */
    public function create(array $fields): int
    {
        if (!$this->db->insert('car_transfer_requests', $fields)) {
            logger(0, LogCategories::LOG_CATEGORY_DATABASE_ERROR, 'CarTransferRepository::create insert failed: ' . $this->db->errorString());
            return 0;
        }
        return $this->db->lastId();
    }

    /**
     * Update the status, admin notes, and completion date of a transfer request.
     *
     * Terminal statuses (completed, denied, expired) also record completed_date = NOW().
     * Non-terminal statuses (pending, approved) leave completed_date unchanged.
     *
     * Returns false if the update fails or if no row was matched (e.g. status already changed).
     *
     * @param int $id Transfer request ID
     * @param string $status New status value
     * @param string $adminNotes Admin notes to record
     * @return bool True if exactly one row was updated
     * @throws \InvalidArgumentException if $status is not one of: pending, completed, denied, approved, expired
     */
    public function updateStatus(int $id, string $status, string $adminNotes): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid transfer status: '$status'");
        }

        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            $result = $this->db->query(
                "UPDATE car_transfer_requests SET status = ?, admin_notes = ?, completed_date = NOW() WHERE id = ?",
                [$status, $adminNotes, $id]
            );
        } else {
            $result = $this->db->query(
                "UPDATE car_transfer_requests SET status = ?, admin_notes = ? WHERE id = ?",
                [$status, $adminNotes, $id]
            );
        }

        if ($this->db->error()) {
            return false;
        }
        return $result->count() > 0;
    }
}
