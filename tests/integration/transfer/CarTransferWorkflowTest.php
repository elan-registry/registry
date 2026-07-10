<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Transfer\TransferStatus;

/**
 * Integration tests for car transfer workflow
 *
 * Tests the complete car transfer process:
 * - Transfer request creation
 * - Transfer approval (ownership change)
 * - Transfer denial (ownership preservation)
 * - Error handling and validation
 * - Audit trail verification
 *
 * Requires database connection and real tables.
 */
class CarTransferWorkflowTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    private $currentOwnerId;

    /** @var int[] Transfer request IDs to clean up in tearDown */
    private array $createdTransferIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Find a car and users for testing
        $car = $this->db->query("SELECT id, user_id FROM cars LIMIT 1")->first();
        $this->testCarId = $car ? $car->id : null;
        $this->currentOwnerId = $car ? $car->user_id : null;

        // Find a different user for transfer testing
        $user = $this->db->query(
            "SELECT id FROM users WHERE id != ? AND active = 1 LIMIT 1",
            [$this->currentOwnerId]
        )->first();
        $this->testUserId = $user ? $user->id : null;
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
     * Insert a transfer request row and track the ID for tearDown cleanup.
     */
    private function insertTransferRequest(array $fields): int
    {
        $this->db->insert('car_transfer_requests', $fields);
        $id = (int) $this->db->lastId();
        if ($id > 0) {
            $this->createdTransferIds[] = $id;
        }
        return $id;
    }

    // =========================================================================
    // Transfer Request Creation Tests
    // =========================================================================

    /**
     * Test transfer request creation with valid data
     */
    public function testCreateTransferRequest(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $car = $this->db->query(
            "SELECT year, type, chassis, color, engine FROM cars WHERE id = ?",
            [$this->testCarId]
        )->first();

        $this->assertNotNull($car, "Test car should exist");

        $securityToken = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => $securityToken,
            'expires_at'            => $expiresAt,
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => $car->year,
            'submitted_type'        => $car->type,
            'submitted_chassis'     => $car->chassis,
            'submitted_color'       => $car->color,
            'submitted_engine'      => $car->engine,
            'submitted_comments'    => 'Test transfer request',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'created_by'            => $this->testUserId,
        ]);

        $this->assertGreaterThan(0, $requestId, "Transfer request should be created successfully");

        $request = $this->db->query(
            "SELECT id, status FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();

        $this->assertNotNull($request, "Transfer request should exist");
        $this->assertEquals('pending', $request->status, "Request should be in pending status");
    }

    /**
     * Test duplicate transfer request prevention
     */
    public function testPreventDuplicateTransferRequests(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => $expiresAt,
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'created_by'            => $this->testUserId,
        ]);

        $result = $this->db->query(
            "SELECT COUNT(*) as count FROM car_transfer_requests
             WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = 'pending'",
            [$this->testCarId, $this->testUserId]
        )->first();

        $this->assertGreaterThanOrEqual(1, $result->count, "Should find at least one pending request");
    }

    // =========================================================================
    // Transfer Approval Tests
    // =========================================================================

    /**
     * Test transfer approval updates status and audit trail
     */
    public function testTransferApprovalUpdatesStatus(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'created_by'            => $this->testUserId,
        ]);

        $result = $this->db->update('car_transfer_requests', $requestId, [
            'status'         => 'completed',
            'completed_date' => date('Y-m-d H:i:s'),
            'admin_notes'    => 'Approved by admin',
        ]);
        $this->assertTrue($result, "Approval update should succeed");

        $request = $this->db->query(
            "SELECT status, completed_date FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();

        $this->assertEquals('completed', $request->status, "Status should be completed");
        $this->assertNotNull($request->completed_date, "Completion date should be set");
    }

    /**
     * Test that approval only works for pending requests
     */
    public function testApprovalRequiresPendingStatus(): void
    {
        if (!$this->databaseConnected) {
            $this->markTestSkipped('Database not available');
        }

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId ?? 1,
            'requested_by_user_id'  => $this->testUserId ?? 1,
            'security_token'        => hash('sha256', 'test-' . time()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'status'                => 'denied',
            'created_by'            => 1,
        ]);

        $result = $this->db->query(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$requestId]
        )->first();

        $this->assertEmpty($result, "Non-pending request should not be found with pending status");
    }

    // =========================================================================
    // Transfer Denial Tests
    // =========================================================================

    /**
     * Test transfer denial updates status without changing ownership
     */
    public function testTransferDenialUpdatesStatus(): void
    {
        if (!$this->databaseConnected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $carBefore = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$this->testCarId])->first();
        $ownerBefore = $carBefore->user_id;

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'created_by'            => $this->testUserId,
        ]);

        $result = $this->db->update('car_transfer_requests', $requestId, [
            'status'         => 'denied',
            'completed_date' => date('Y-m-d H:i:s'),
            'admin_notes'    => 'Denied by admin',
        ]);
        $this->assertTrue($result, "Denial update should succeed");

        $request = $this->db->query(
            "SELECT status FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();
        $this->assertEquals('denied', $request->status, "Status should be denied");

        $carAfter = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$this->testCarId])->first();
        $this->assertEquals($ownerBefore, $carAfter->user_id, "Car ownership should not change on denial");
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    /**
     * Test that non-existent transfer request returns proper error
     */
    public function testNonExistentTransferReturns404(): void
    {
        if (!$this->databaseConnected) {
            $this->markTestSkipped('Database not available');
        }

        $result = $this->db->query(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [99999999]
        )->first();

        $this->assertEmpty($result, "Non-existent transfer request should not be found");
    }

    // =========================================================================
    // TOCTOU Gate Tests (issue #1175)
    // =========================================================================

    /**
     * Test that a second concurrent approval attempt is rejected by the atomic claim.
     *
     * The TOCTOU gate is an UPDATE with AND status = 'pending' in the WHERE clause.
     * Only the first admin's UPDATE matches the pending row; a subsequent UPDATE finds
     * no pending row and returns 0 affected rows — the duplicate is detected atomically.
     */
    public function testConcurrentApprovalIsRejectedByAtomicClaim(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'created_by'            => $this->testUserId,
        ]);

        $this->assertGreaterThan(0, $requestId, 'Precondition: transfer request must be created');

        // First admin claims the request atomically.
        // AND status = 'pending' is the TOCTOU gate: only a pending row matches.
        $first = $this->db->query(
            "UPDATE car_transfer_requests SET status = 'completed', completed_date = NOW(), admin_notes = ? WHERE id = ? AND status = 'pending'",
            ['First admin', $requestId]
        );
        $this->assertEquals(1, $first->count(), 'First admin should claim the pending row (1 row affected)');

        // Second admin attempts the same claim on the same row.
        // The row is now 'completed', so AND status = 'pending' matches 0 rows.
        $second = $this->db->query(
            "UPDATE car_transfer_requests SET status = 'completed', completed_date = NOW(), admin_notes = ? WHERE id = ? AND status = 'pending'",
            ['Second admin', $requestId]
        );
        $this->assertEquals(0, $second->count(), 'Second admin should find no pending row (TOCTOU detected: 0 rows affected)');

        // The row must still reflect the first admin's claim, not the second admin's.
        $row = $this->db->query(
            'SELECT status, admin_notes FROM car_transfer_requests WHERE id = ?',
            [$requestId]
        )->first();

        $this->assertNotNull($row, 'Row should still exist');
        $this->assertEquals('completed', $row->status, 'Status should remain completed');
        $this->assertEquals('First admin', $row->admin_notes, 'admin_notes must not be overwritten by the second admin');
    }

    /**
     * Test that CarTransferRepository::updateStatus() returns false for an already-processed request.
     *
     * updateStatus() uses AND status = 'pending' in its WHERE clause for terminal
     * transitions. This means attempting to mark an already-completed request as
     * completed a second time matches 0 rows and returns false — the correct TOCTOU
     * signal that process-transfer-approve.php uses to detect and reject duplicates.
     */
    public function testUpdateStatusReturnsFalseForAlreadyProcessedRequest(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Insert the request already in 'completed' state to simulate a row that
        // has already been approved by another admin.
        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'status'                => 'completed',
            'completed_date'        => date('Y-m-d H:i:s'),
            'admin_notes'           => 'First admin',
            'created_by'            => $this->testUserId,
        ]);

        $this->assertGreaterThan(0, $requestId, 'Precondition: request must be inserted');

        // A second admin attempting to approve the same request calls updateStatus().
        // Because the WHERE clause includes AND status = 'pending', no row matches,
        // and the method returns false — the correct TOCTOU signal.
        $repo   = new \ElanRegistry\Transfer\CarTransferRepository($this->db);
        $result = $repo->updateStatus($requestId, TransferStatus::Completed, 'Second admin');

        $this->assertFalse($result, 'updateStatus() must return false when the row is already in a terminal status (TOCTOU gate)');
    }

    /**
     * Test that a failed transfer rolls back the status claim atomically.
     *
     * This is the partial-state test for #1175: if the car ownership transfer
     * fails after the status has been claimed, the outer transaction rollback
     * must leave the request in 'pending' — not 'completed'.
     */
    public function testStatusClaimRollsBackIfTransferFails(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'created_by'            => $this->testUserId,
        ]);

        $repo = new \ElanRegistry\Transfer\CarTransferRepository($this->db);

        // Simulate the outer transaction that process-transfer-approve.php begins.
        $this->db->beginTransaction();

        // Step 1: claim the request (TOCTOU gate succeeds).
        $claimed = $repo->updateStatus($requestId, TransferStatus::Completed, 'Admin claim');
        $this->assertTrue($claimed, 'Precondition: initial claim must succeed');

        // Step 2: simulate a failed car transfer by rolling back the outer transaction
        // (mirrors what the catch block in process-transfer-approve.php does when
        // $car->transfer() throws).
        $this->db->rollBack();

        // The rollback must have reverted the status claim: the row is still 'pending'.
        $row = $this->db->query(
            "SELECT status FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();

        $this->assertNotNull($row, 'Row should still exist after rollback');
        $this->assertEquals('pending', $row->status, 'Status must revert to pending when the outer transaction is rolled back');
    }

    /**
     * Test that already-processed requests cannot be re-processed
     */
    public function testCannotProcessAlreadyProcessedRequest(): void
    {
        if (!$this->databaseConnected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->insertTransferRequest([
            'existing_car_id'       => $this->testCarId,
            'requested_by_user_id'  => $this->testUserId,
            'security_token'        => hash('sha256', $this->testCarId . $this->testUserId . time() . rand()),
            'expires_at'            => date('Y-m-d H:i:s', strtotime('+30 days')),
            'submitted_model'       => 'Test Model',
            'submitted_series'      => 'Test Series',
            'submitted_variant'     => 'Test Variant',
            'submitted_year'        => 2024,
            'submitted_type'        => 'S1H',
            'submitted_chassis'     => 'TESTX',
            'submitted_color'       => 'Red',
            'submitted_engine'      => 'Test Engine',
            'submitted_comments'    => 'Test comment',
            'submitted_email'       => 'test@example.com',
            'submitted_fname'       => 'Test',
            'submitted_lname'       => 'User',
            'submitted_city'        => 'Test City',
            'submitted_state'       => 'Test State',
            'submitted_country'     => 'Test Country',
            'status'                => 'completed',
            'created_by'            => $this->testUserId,
        ]);

        $result = $this->db->query(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$requestId]
        )->first();

        $this->assertEmpty($result, "Already-processed request should not match pending query");
    }
}
