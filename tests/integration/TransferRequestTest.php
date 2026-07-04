<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for the transfer-request initiation step.
 *
 * Covers the DB operations performed by app/api/cars/transfer-request.php.
 * The endpoint file itself cannot be included (it calls send() / exit), so
 * these tests exercise the same SQL queries and guard logic directly against
 * the real database.
 *
 * Complements CarTransferWorkflowTest (approve/deny coverage).
 */
#[Group('integration')]
#[Group('transfer')]
final class TransferRequestTest extends IntegrationTestCase
{
    /** @var int[] Transfer request IDs to clean up in tearDown */
    private array $createdTransferIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTransferIds as $id) {
            try {
                $this->db->query("DELETE FROM car_transfer_requests WHERE id = ?", [$id]);
            } catch (\Throwable $e) {
                // Ignore cleanup errors
            }
        }
        $this->createdTransferIds = [];
        parent::tearDown();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Insert a car_transfer_requests row replicating the endpoint's INSERT.
     * Tracked for tearDown cleanup. Returns the new transfer request ID.
     */
    private function createTransferRequest(int $carId, int $requesterId, array $overrides = []): int
    {
        $defaults = [
            'existing_car_id'       => $carId,
            'requested_by_user_id'  => $requesterId,
            'security_token'        => bin2hex(random_bytes(32)),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'S4|SE|FHC',
            'submitted_series'      => 'S4',
            'submitted_variant'     => 'SE',
            'submitted_year'        => '1973',
            'submitted_type'        => 'FHC',
            'submitted_chassis'     => 'TEST001',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'ENG001',
            'submitted_comments'    => 'Test transfer request',
            'submitted_email'       => 'requester@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'Requester',
            'submitted_city'        => 'Portland',
            'submitted_state'       => 'Oregon',
            'submitted_country'     => 'United States',
            'created_by'            => $requesterId,
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
            throw new \RuntimeException("createTransferRequest: INSERT failed");
        }

        $this->createdTransferIds[] = $id;
        return $id;
    }

    // =========================================================================
    // Tests
    // =========================================================================

    /**
     * A valid initiation creates a car_transfer_requests row in the database
     * with the correct car ID, requester ID, and 'pending' status.
     *
     * Pins the INSERT path in transfer-request.php:130–160.
     */
    public function testValidRequestCreatesTransferRow(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $transferId = $this->createTransferRequest($carId, $requesterId);

        $row = $this->db->query(
            "SELECT existing_car_id, requested_by_user_id, status FROM car_transfer_requests WHERE id = ?",
            [$transferId]
        )->first();

        $this->assertNotNull($row, 'Transfer request row must exist after creation');
        $this->assertSame((string) $carId, (string) $row->existing_car_id);
        $this->assertSame((string) $requesterId, (string) $row->requested_by_user_id);
        $this->assertSame('pending', $row->status, "New transfer requests must default to 'pending'");
    }

    /**
     * A duplicate pending request for the same car by the same user is detected
     * by the guard query in transfer-request.php:111–118.
     *
     * Pins that the guard SELECT with status='pending' returns > 0 when a
     * duplicate exists, so the endpoint can reject it.
     */
    public function testDuplicateRequestForSameCarIsRejected(): void
    {
        $ownerId     = $this->createTestUser();
        $requesterId = $this->createTestUser();
        $carId       = $this->createTestCar($ownerId);

        $this->createTransferRequest($carId, $requesterId);

        // Replicate the endpoint's duplicate-check guard
        $duplicateCheck = $this->db->query(
            'SELECT id FROM car_transfer_requests WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = "pending"',
            [$carId, $requesterId]
        );

        $this->assertGreaterThan(0, $duplicateCheck->count(),
            'Guard query must find the existing pending request so the endpoint can reject the duplicate'
        );
    }

    /**
     * A request for a car the user already owns is detected by the ownership
     * check in transfer-request.php:106–108.
     *
     * Pins that cars.user_id matches the requesting user's ID when they are
     * the current owner — the endpoint guards on this before inserting.
     */
    public function testRequestForOwnedCarIsRejectedByOwnershipCheck(): void
    {
        $ownerId = $this->createTestUser();
        $carId   = $this->createTestCar($ownerId);

        // Replicate the endpoint's car lookup
        $carRow = $this->db->query(
            'SELECT id, user_id FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotNull($carRow);

        // Replicate the endpoint's ownership guard: $existingCar->user_id == $user->data()->id
        $ownerAlreadyOwnsCar = ((int) $carRow->user_id === $ownerId);
        $this->assertTrue($ownerAlreadyOwnsCar,
            'Owner must be detected as already owning the car so the endpoint can reject the self-transfer'
        );
    }

    /**
     * A request for a non-existent car (bogus chassis or year) is detected by
     * the car lookup in transfer-request.php:94–101.
     *
     * Pins that the SELECT returns 0 rows when no car matches, causing the
     * endpoint to throw "No car found with this chassis number".
     */
    public function testInvalidCarChassisReturnsNoResults(): void
    {
        $nonExistentChassis = 'GHOST_' . uniqid();

        $result = $this->db->query(
            'SELECT id, user_id FROM cars WHERE year = ? AND type = ? AND chassis = ?',
            ['1999', 'FHC', $nonExistentChassis]
        );

        $this->assertSame(0, $result->count(),
            'Car lookup must return 0 rows for a non-existent chassis so the endpoint can reject the request'
        );
    }
}
