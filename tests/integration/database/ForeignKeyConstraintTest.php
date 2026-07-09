<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for FK constraints added in issue #693.
 *
 * Migration: 20260709202522_add_foreign_key_constraints.php
 *
 * Three constraints under test:
 *   1. cars.user_id → users.id ON DELETE SET NULL
 *   2. car_transfer_requests.existing_car_id → cars.id ON DELETE CASCADE
 *   3. car_transfer_requests.expires_at allows NULL (column re-typed to NULL DEFAULT NULL)
 */
#[Group('integration')]
final class ForeignKeyConstraintTest extends IntegrationTestCase
{
    /** @var int[] Transfer request IDs to clean up in tearDown (before base class deletes cars) */
    private array $createdTransferIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->createdTransferIds = [];
    }

    protected function tearDown(): void
    {
        // Clean up any transfer requests that were NOT consumed by a CASCADE delete.
        // The base class tearDown deletes cars (which cascades), so rows referencing
        // still-existing cars need to be removed first to avoid FK errors on the
        // car_transfer_requests side (the base class already issues this DELETE, but
        // running it here again for rows we tracked is harmless and defensive).
        foreach ($this->createdTransferIds as $id) {
            try {
                $this->db->query("DELETE FROM car_transfer_requests WHERE id = ?", [$id]);
            } catch (\Throwable $e) {
                // Ignore — row may already be gone via CASCADE
            }
        }
        $this->createdTransferIds = [];

        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Insert a minimal car_transfer_requests row for a given car and user.
     * Mirrors the pattern in TransferRequestTest::createTransferRequest().
     *
     * @param int   $carId      The existing_car_id value
     * @param int   $userId     Used for both requested_by_user_id and created_by
     * @param array $overrides  Column overrides (e.g. ['expires_at' => null])
     * @return int              The newly inserted row ID
     */
    private function createTransferRequest(int $carId, int $userId, array $overrides = []): int
    {
        $defaults = [
            'existing_car_id'      => $carId,
            'requested_by_user_id' => $userId,
            'security_token'       => bin2hex(random_bytes(32)),
            'expires_at'           => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'      => 'S4|SE|FHC',
            'submitted_series'     => 'S4',
            'submitted_variant'    => 'SE',
            'submitted_year'       => '1973',
            'submitted_type'       => 'FHC',
            'submitted_chassis'    => 'FKTEST001',
            'submitted_color'      => 'Red',
            'submitted_engine'     => 'ENG001',
            'submitted_comments'   => 'FK constraint test',
            'submitted_email'      => 'fktest@example.com',
            'submitted_fname'      => 'FK',
            'submitted_lname'      => 'Test',
            'submitted_city'       => 'Portland',
            'submitted_state'      => 'Oregon',
            'submitted_country'    => 'United States',
            'created_by'           => $userId,
        ];

        $row = array_merge($defaults, $overrides);

        $this->db->query(
            'INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year, submitted_type,
                submitted_chassis, submitted_color, submitted_engine, submitted_comments,
                submitted_email, submitted_fname, submitted_lname, submitted_city, submitted_state, submitted_country,
                created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $row['existing_car_id'],
                $row['requested_by_user_id'],
                $row['security_token'],
                $row['expires_at'],
                $row['submitted_model'],
                $row['submitted_series'],
                $row['submitted_variant'],
                $row['submitted_year'],
                $row['submitted_type'],
                $row['submitted_chassis'],
                $row['submitted_color'],
                $row['submitted_engine'],
                $row['submitted_comments'],
                $row['submitted_email'],
                $row['submitted_fname'],
                $row['submitted_lname'],
                $row['submitted_city'],
                $row['submitted_state'],
                $row['submitted_country'],
                $row['created_by'],
            ]
        );

        $id = (int) $this->db->lastId();
        if ($id <= 0) {
            throw new \RuntimeException("createTransferRequest: INSERT failed — check NOT NULL columns");
        }

        $this->createdTransferIds[] = $id;
        return $id;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    /**
     * FK #1: deleting a user NULLs cars.user_id (ON DELETE SET NULL).
     *
     * Arrange: create a test user and a car owned by that user.
     * Act:     delete the user row directly (bypassing application logic so we
     *          are testing the DB constraint, not the deletion service).
     * Assert:  cars.user_id for that car is now NULL.
     */
    public function test_deleteUser_setsCarUserIdNull(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId);

        // Confirm the car is owned by this user before the delete.
        $before = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
        $this->assertSame($userId, (int) $before->user_id, 'Pre-condition: car must be owned by the test user');

        // Delete the user — the FK constraint should SET NULL on cars.user_id.
        // deleteTestUser() removes the user from the tracking list so tearDown
        // does not attempt a redundant second delete.
        $deleted = $this->deleteTestUser($userId);
        $this->assertTrue($deleted, 'deleteTestUser() must succeed');

        $after = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNull($after->user_id, 'cars.user_id must be NULL after the owning user is deleted');
    }

    /**
     * FK #2: deleting a car cascades to car_transfer_requests (ON DELETE CASCADE).
     *
     * Arrange: create a test car and a transfer request referencing it.
     * Act:     delete the car row directly.
     * Assert:  the transfer request row no longer exists.
     */
    public function test_deleteCar_cascadesTransferRequests(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId);

        $transferId = $this->createTransferRequest($carId, $userId);

        // Confirm the transfer request exists before the delete.
        $before = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE id = ?",
            [$transferId]
        );
        $this->assertSame(1, $before->count(), 'Pre-condition: transfer request must exist');

        // Delete the car via raw SQL so the CASCADE actually fires.
        // deleteTestCar() pre-deletes related rows first, which would prevent
        // the cascade from being exercised.
        $this->db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
        $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);
        $this->db->query("DELETE FROM cars WHERE id = ?", [$carId]);
        $this->untrackCarId($carId); // car is gone; suppress tearDown's redundant DELETE

        $after = $this->db->query(
            "SELECT id FROM car_transfer_requests WHERE id = ?",
            [$transferId]
        );
        $this->assertSame(0, $after->count(), 'car_transfer_requests row must be gone after cascaded car delete');

        // Remove from our own tracking — the CASCADE already deleted it.
        $this->createdTransferIds = array_values(
            array_diff($this->createdTransferIds, [$transferId])
        );
    }

    /**
     * FK #3: car_transfer_requests.expires_at accepts NULL.
     *
     * Before the migration the column was NOT NULL DEFAULT '0000-00-00 00:00:00'.
     * After: NULL DEFAULT NULL.
     *
     * Arrange: create a test car and user.
     * Act:     insert a transfer request with expires_at explicitly NULL.
     * Assert:  no exception is thrown and the row exists with a NULL expires_at.
     */
    public function test_transferRequest_expiresAtAcceptsNull(): void
    {
        $userId = $this->createTestUser();
        $carId  = $this->createTestCar($userId);

        $transferId = $this->createTransferRequest($carId, $userId, ['expires_at' => null]);

        $row = $this->db->query(
            "SELECT id, expires_at FROM car_transfer_requests WHERE id = ?",
            [$transferId]
        )->first();

        $this->assertNotNull($row, 'Transfer request row must exist after insert with NULL expires_at');
        $this->assertNull($row->expires_at, 'expires_at must be stored as NULL');
    }
}
