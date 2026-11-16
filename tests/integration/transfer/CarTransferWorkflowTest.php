<?php

declare(strict_types=1);

require_once __DIR__ . '/TransferIntegrationTestCase.php';

use ElanRegistry\Transfer\TransferStatus;
use PHPUnit\Framework\Attributes\Group;

/**
 * Car transfer approval gate: CarTransferRepository::updateStatus() rejects a
 * request that is no longer pending, and the outer rollback restores a claim.
 */
#[Group('integration')]
#[Group('transfer')]
final class CarTransferWorkflowTest extends TransferIntegrationTestCase
{
    private int $testCarId;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $currentOwnerId = $this->createTestUser();
        $this->testCarId = $this->createTestCar($currentOwnerId);
        $this->testUserId = $this->createTestUser(); // distinct transfer-target user
    }

    // =========================================================================
    // TOCTOU Gate Tests (issue #1175)
    // =========================================================================

    /**
     * updateStatus() returning false is the duplicate signal process-transfer-approve.php uses.
     */
    public function testUpdateStatusReturnsFalseForAlreadyProcessedRequest(): void
    {
        // Already approved by another admin.
        $requestId = $this->createTransferRequest($this->testCarId, $this->testUserId, [
            'status'         => 'completed',
            'completed_date' => date('Y-m-d H:i:s'),
            'admin_notes'    => 'First admin',
        ]);

        $result = $this->repo->updateStatus($requestId, TransferStatus::Completed, 'Second admin');

        $this->assertFalse($result, 'updateStatus() must return false when the row is already in a terminal status (TOCTOU gate)');

        $row = $this->repo->findById($requestId);
        $this->assertNotNull($row, 'Row should still exist after the rejected claim');
        $this->assertSame('First admin', $row->admin_notes, 'The rejected claim must not overwrite admin_notes');
        $this->assertSame('completed', $row->status, 'The rejected claim must not change status');
    }

    /**
     * #1175: if the car transfer fails after the claim, the rollback must leave the request 'pending'.
     */
    public function testStatusClaimRollsBackIfTransferFails(): void
    {
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
}
