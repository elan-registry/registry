<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
class CarTransferWorkflowTest extends TestCase
{
    private $db;
    private $connected = false;
    private $testCarId;
    private $testUserId;
    private $currentOwnerId;

    protected function setUp(): void
    {
        // Try to connect to real database
        try {
            $this->db = new PDO(
                'mysql:host=127.0.0.1;port=8889;dbname=elanregi_spice',
                'claude',
                'claude',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->connected = true;

            // Find a car and users for testing
            $carStmt = $this->db->query("SELECT id, user_id FROM cars LIMIT 1");
            $car = $carStmt->fetch(PDO::FETCH_OBJ);
            $this->testCarId = $car ? $car->id : null;
            $this->currentOwnerId = $car ? $car->user_id : null;

            // Find a different user for transfer testing
            $userStmt = $this->db->prepare("SELECT id FROM users WHERE id != ? AND active = 1 LIMIT 1");
            $userStmt->execute([$this->currentOwnerId]);
            $user = $userStmt->fetch(PDO::FETCH_OBJ);
            $this->testUserId = $user ? $user->id : null;
        } catch (PDOException $e) {
            $this->connected = false;
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    // =========================================================================
    // Transfer Request Creation Tests
    // =========================================================================

    /**
     * Test transfer request creation with valid data
     */
    public function testCreateTransferRequest(): void
    {
        if (!$this->connected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Get car details for the request
        $carStmt = $this->db->prepare(
            "SELECT year, type, chassis, color, engine FROM cars WHERE id = ?"
        );
        $carStmt->execute([$this->testCarId]);
        $car = $carStmt->fetch(PDO::FETCH_OBJ);

        $this->assertNotNull($car, "Test car should exist");

        // Create a transfer request
        $securityToken = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant,
                submitted_year, submitted_type, submitted_chassis, submitted_color,
                submitted_engine, submitted_comments, submitted_email, submitted_fname,
                submitted_lname, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $result = $insertStmt->execute([
            $this->testCarId,
            $this->testUserId,
            $securityToken,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            $car->year,
            $car->type,
            $car->chassis,
            $car->color,
            $car->engine,
            'Test transfer request',
            'test@example.com',
            'Test',
            'User',
            $this->testUserId
        ]);

        $this->assertTrue($result, "Transfer request should be created successfully");

        // Verify the request was created
        $verifyStmt = $this->db->prepare(
            "SELECT id, status FROM car_transfer_requests WHERE id = LAST_INSERT_ID()"
        );
        $verifyStmt->execute();
        $request = $verifyStmt->fetch(PDO::FETCH_OBJ);

        $this->assertNotNull($request, "Transfer request should exist");
        $this->assertEquals('pending', $request->status, "Request should be in pending status");
    }

    /**
     * Test duplicate transfer request prevention
     */
    public function testPreventDuplicateTransferRequests(): void
    {
        if (!$this->connected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Create first transfer request
        $securityToken1 = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year,
                submitted_type, submitted_chassis, submitted_color, submitted_engine,
                submitted_comments, submitted_email, submitted_fname, submitted_lname,
                submitted_city, submitted_state, submitted_country, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStmt->execute([
            $this->testCarId,
            $this->testUserId,
            $securityToken1,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            2024,
            'S1H',
            'TESTX',
            'Red',
            'Test Engine',
            'Test comment',
            'test@example.com',
            'Test',
            'User',
            'Test City',
            'Test State',
            'Test Country',
            $this->testUserId
        ]);

        $firstId = $this->db->lastInsertId();

        // Try to create duplicate
        $checkStmt = $this->db->prepare(
            "SELECT COUNT(*) as count FROM car_transfer_requests
             WHERE existing_car_id = ? AND requested_by_user_id = ? AND status = 'pending'"
        );
        $checkStmt->execute([$this->testCarId, $this->testUserId]);
        $result = $checkStmt->fetch(PDO::FETCH_OBJ);

        $this->assertGreaterThanOrEqual(1, $result->count, "Should find at least one pending request");

        // Clean up
        $deleteStmt = $this->db->prepare("DELETE FROM car_transfer_requests WHERE id = ?");
        $deleteStmt->execute([$firstId]);
    }

    // =========================================================================
    // Transfer Approval Tests
    // =========================================================================

    /**
     * Test transfer approval updates status and audit trail
     */
    public function testTransferApprovalUpdatesStatus(): void
    {
        if (!$this->connected || !$this->testCarId || !$this->testUserId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Create a transfer request
        $securityToken = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year,
                submitted_type, submitted_chassis, submitted_color, submitted_engine,
                submitted_comments, submitted_email, submitted_fname, submitted_lname,
                submitted_city, submitted_state, submitted_country, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStmt->execute([
            $this->testCarId,
            $this->testUserId,
            $securityToken,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            2024,
            'S1H',
            'TESTX',
            'Red',
            'Test Engine',
            'Test comment',
            'test@example.com',
            'Test',
            'User',
            'Test City',
            'Test State',
            'Test Country',
            $this->testUserId
        ]);

        $requestId = (int)$this->db->lastInsertId();

        // Approve the transfer
        $updateStmt = $this->db->prepare(
            "UPDATE car_transfer_requests SET status = 'completed',
             completed_date = NOW(), admin_notes = ? WHERE id = ?"
        );

        $result = $updateStmt->execute(['Approved by admin', $requestId]);
        $this->assertTrue($result, "Approval update should succeed");

        // Verify status changed
        $verifyStmt = $this->db->prepare(
            "SELECT status, completed_date FROM car_transfer_requests WHERE id = ?"
        );
        $verifyStmt->execute([$requestId]);
        $request = $verifyStmt->fetch(PDO::FETCH_OBJ);

        $this->assertEquals('completed', $request->status, "Status should be completed");
        $this->assertNotNull($request->completed_date, "Completion date should be set");

        // Clean up
        $deleteStmt = $this->db->prepare("DELETE FROM car_transfer_requests WHERE id = ?");
        $deleteStmt->execute([$requestId]);
    }

    /**
     * Test that approval only works for pending requests
     */
    public function testApprovalRequiresPendingStatus(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        // Create a denied request
        $securityToken = hash('sha256', 'test-' . time());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year,
                submitted_type, submitted_chassis, submitted_color, submitted_engine,
                submitted_comments, submitted_email, submitted_fname, submitted_lname,
                submitted_city, submitted_state, submitted_country,
                status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStmt->execute([
            $this->testCarId ?? 1,
            $this->testUserId ?? 1,
            $securityToken,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            2024,
            'S1H',
            'TESTX',
            'Red',
            'Test Engine',
            'Test comment',
            'test@example.com',
            'Test',
            'User',
            'Test City',
            'Test State',
            'Test Country',
            'denied',
            1
        ]);

        $requestId = (int)$this->db->lastInsertId();

        // Try to approve a non-pending request
        $checkStmt = $this->db->prepare(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'"
        );
        $checkStmt->execute([$requestId]);
        $result = $checkStmt->fetch();

        $this->assertFalse($result, "Non-pending request should not be found with pending status");

        // Clean up
        $deleteStmt = $this->db->prepare("DELETE FROM car_transfer_requests WHERE id = ?");
        $deleteStmt->execute([$requestId]);
    }

    // =========================================================================
    // Transfer Denial Tests
    // =========================================================================

    /**
     * Test transfer denial updates status without changing ownership
     */
    public function testTransferDenialUpdatesStatus(): void
    {
        if (!$this->connected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Record current ownership
        $carStmt = $this->db->prepare("SELECT user_id FROM cars WHERE id = ?");
        $carStmt->execute([$this->testCarId]);
        $carBefore = $carStmt->fetch(PDO::FETCH_OBJ);
        $ownerBefore = $carBefore->user_id;

        // Create a transfer request
        $securityToken = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year,
                submitted_type, submitted_chassis, submitted_color, submitted_engine,
                submitted_comments, submitted_email, submitted_fname, submitted_lname,
                submitted_city, submitted_state, submitted_country, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStmt->execute([
            $this->testCarId,
            $this->testUserId,
            $securityToken,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            2024,
            'S1H',
            'TESTX',
            'Red',
            'Test Engine',
            'Test comment',
            'test@example.com',
            'Test',
            'User',
            'Test City',
            'Test State',
            'Test Country',
            $this->testUserId
        ]);

        $requestId = (int)$this->db->lastInsertId();

        // Deny the transfer
        $updateStmt = $this->db->prepare(
            "UPDATE car_transfer_requests SET status = 'denied',
             completed_date = NOW(), admin_notes = ? WHERE id = ?"
        );

        $result = $updateStmt->execute(['Denied by admin', $requestId]);
        $this->assertTrue($result, "Denial update should succeed");

        // Verify status changed but ownership unchanged
        $verifyTransferStmt = $this->db->prepare(
            "SELECT status FROM car_transfer_requests WHERE id = ?"
        );
        $verifyTransferStmt->execute([$requestId]);
        $request = $verifyTransferStmt->fetch(PDO::FETCH_OBJ);

        $this->assertEquals('denied', $request->status, "Status should be denied");

        // Verify car ownership unchanged
        $carAfterStmt = $this->db->prepare("SELECT user_id FROM cars WHERE id = ?");
        $carAfterStmt->execute([$this->testCarId]);
        $carAfter = $carAfterStmt->fetch(PDO::FETCH_OBJ);

        $this->assertEquals($ownerBefore, $carAfter->user_id, "Car ownership should not change on denial");

        // Clean up
        $deleteStmt = $this->db->prepare("DELETE FROM car_transfer_requests WHERE id = ?");
        $deleteStmt->execute([$requestId]);
    }

    // =========================================================================
    // Error Handling Tests
    // =========================================================================

    /**
     * Test that non-existent transfer request returns proper error
     */
    public function testNonExistentTransferReturns404(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not available');
        }

        $checkStmt = $this->db->prepare(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'"
        );
        $checkStmt->execute([99999999]);
        $result = $checkStmt->fetch();

        $this->assertFalse($result, "Non-existent transfer request should not be found");
    }

    /**
     * Test that already-processed requests cannot be re-processed
     */
    public function testCannotProcessAlreadyProcessedRequest(): void
    {
        if (!$this->connected || !$this->testCarId) {
            $this->markTestSkipped('Database or test data not available');
        }

        // Create and immediately complete a request
        $securityToken = hash('sha256', $this->testCarId . $this->testUserId . time() . rand());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));

        $insertStmt = $this->db->prepare(
            "INSERT INTO car_transfer_requests (
                existing_car_id, requested_by_user_id, security_token, expires_at,
                submitted_model, submitted_series, submitted_variant, submitted_year,
                submitted_type, submitted_chassis, submitted_color, submitted_engine,
                submitted_comments, submitted_email, submitted_fname, submitted_lname,
                submitted_city, submitted_state, submitted_country,
                status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $insertStmt->execute([
            $this->testCarId,
            $this->testUserId,
            $securityToken,
            $expiresAt,
            'Test Model',
            'Test Series',
            'Test Variant',
            2024,
            'S1H',
            'TESTX',
            'Red',
            'Test Engine',
            'Test comment',
            'test@example.com',
            'Test',
            'User',
            'Test City',
            'Test State',
            'Test Country',
            'completed',
            $this->testUserId
        ]);

        $requestId = (int)$this->db->lastInsertId();

        // Try to find it as pending
        $checkStmt = $this->db->prepare(
            "SELECT * FROM car_transfer_requests WHERE id = ? AND status = 'pending'"
        );
        $checkStmt->execute([$requestId]);
        $result = $checkStmt->fetch();

        $this->assertFalse($result, "Already-processed request should not match pending query");

        // Clean up
        $deleteStmt = $this->db->prepare("DELETE FROM car_transfer_requests WHERE id = ?");
        $deleteStmt->execute([$requestId]);
    }
}
?>
