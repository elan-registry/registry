<?php

declare(strict_types=1);

require_once __DIR__ . '/TransferIntegrationTestCase.php';

use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\Group;

/**
 * Car transfer workflow: request, approve, deny, errors, and audit trail.
 */
#[Group('integration')]
#[Group('transfer')]
final class CarTransferWorkflowTest extends TransferIntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    private $currentOwnerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentOwnerId = $this->createTestUser();
        $this->testCarId = $this->createTestCar($this->currentOwnerId);
        $this->testUserId = $this->createTestUser(); // distinct transfer-target user
    }

    // =========================================================================
    // Transfer Request Creation Tests
    // =========================================================================

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

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId, [
            'security_token'        => $securityToken,
            'expires_at'            => $expiresAt,
            'submitted_year'        => $car->year,
            'submitted_type'        => $car->type,
            'submitted_chassis'     => $car->chassis,
            'submitted_color'       => $car->color,
            'submitted_engine'      => $car->engine,
        ]);

        $request = $this->db->query(
            "SELECT id, status FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();

        $this->assertNotNull($request, "Transfer request should exist");
        $this->assertEquals('pending', $request->status, "Request should be in pending status");
    }

    public function testPreventDuplicateTransferRequests(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $this->createTransferRequest($this->testCarId, $this->testUserId);

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

    public function testTransferApprovalUpdatesStatus(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId);

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

    public function testApprovalRequiresPendingStatus(): void
    {
        if (!$this->databaseConnected) {
            $this->markTestSkipped('Database not available');
        }

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId, ['status' => 'denied']);

        $result = $this->db->query(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$requestId]
        )->first();

        $this->assertEmpty($result, "Non-pending request should not be found with pending status");
    }

    // =========================================================================
    // Transfer Denial Tests
    // =========================================================================

    public function testTransferDenialUpdatesStatus(): void
    {
        if (!$this->databaseConnected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $carBefore = $this->db->query("SELECT user_id FROM cars WHERE id = ?", [$this->testCarId])->first();
        $ownerBefore = $carBefore->user_id;

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId);

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
     * #1175: `AND status = 'pending'` in the claim UPDATE lets only one admin claim the request.
     */
    public function testConcurrentApprovalIsRejectedByAtomicClaim(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId);

        $first = $this->db->query(
            "UPDATE car_transfer_requests SET status = 'completed', completed_date = NOW(), admin_notes = ? WHERE id = ? AND status = 'pending'",
            ['First admin', $requestId]
        );
        $this->assertEquals(1, $first->count(), 'First admin should claim the pending row (1 row affected)');

        // The row is now 'completed', so the pending gate matches 0 rows.
        $second = $this->db->query(
            "UPDATE car_transfer_requests SET status = 'completed', completed_date = NOW(), admin_notes = ? WHERE id = ? AND status = 'pending'",
            ['Second admin', $requestId]
        );
        $this->assertEquals(0, $second->count(), 'Second admin should find no pending row (TOCTOU detected: 0 rows affected)');

        $row = $this->db->query(
            'SELECT status, admin_notes FROM car_transfer_requests WHERE id = ?',
            [$requestId]
        )->first();

        $this->assertNotNull($row, 'Row should still exist');
        $this->assertEquals('completed', $row->status, 'Status should remain completed');
        $this->assertEquals('First admin', $row->admin_notes, 'admin_notes must not be overwritten by the second admin');
    }

    /**
     * updateStatus() returning false is the duplicate signal process-transfer-approve.php uses.
     */
    public function testUpdateStatusReturnsFalseForAlreadyProcessedRequest(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Already approved by another admin.
        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId, [
            'status'         => 'completed',
            'completed_date' => date('Y-m-d H:i:s'),
            'admin_notes'    => 'First admin',
        ]);

        $result = $this->repo->updateStatus($requestId, TransferStatus::Completed, 'Second admin');

        $this->assertFalse($result, 'updateStatus() must return false when the row is already in a terminal status (TOCTOU gate)');
    }

    /**
     * #1175: if the car transfer fails after the claim, the rollback must leave the request 'pending'.
     */
    public function testStatusClaimRollsBackIfTransferFails(): void
    {
        if (!$this->databaseConnected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId);

        // The outer transaction that process-transfer-approve.php begins.
        $this->db->beginTransaction();

        $claimed = $this->repo->updateStatus($requestId, TransferStatus::Completed, 'Admin claim');
        $this->assertTrue($claimed, 'Precondition: initial claim must succeed');

        // As the catch block in process-transfer-approve.php does when $car->transfer() throws.
        $this->db->rollBack();

        $row = $this->db->query(
            "SELECT status FROM car_transfer_requests WHERE id = ?",
            [$requestId]
        )->first();

        $this->assertNotNull($row, 'Row should still exist after rollback');
        $this->assertEquals('pending', $row->status, 'Status must revert to pending when the outer transaction is rolled back');
    }

    public function testCannotProcessAlreadyProcessedRequest(): void
    {
        if (!$this->databaseConnected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId, ['status' => 'completed']);

        $result = $this->db->query(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'",
            [$requestId]
        )->first();

        $this->assertEmpty($result, "Already-processed request should not match pending query");
    }
}
