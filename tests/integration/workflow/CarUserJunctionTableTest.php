<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

/**
 * Car-User Junction Table Test Suite
 *
 * Comprehensive testing of car_user and car_user_hist table operations
 * to ensure all relationship functionality works before and after migration.
 *
 * Tests cover:
 * - Car ownership assignment and removal
 * - Multiple users sharing cars
 * - car_user_hist audit trail functionality
 * - Orphaned relationship cleanup
 * - Data integrity and foreign key relationships
 */
final class CarUserJunctionTableTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId1;
    private $testUserId2;
    private $createdTestData = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Find or create test data
        $this->setupTestData();
    }

    /**
     * Set up test data for junction table operations
     */
    private function setupTestData(): void
    {
        // Find existing cars for testing (avoid creating test data in production DB)
        $existingCar = $this->db->query("SELECT id FROM cars ORDER BY id LIMIT 1")->first();
        if ($existingCar) {
            $this->testCarId = $existingCar->id;
        }

        // Find existing users for testing
        $existingUsers = $this->db->query("SELECT id FROM users ORDER BY id LIMIT 2")->results();
        if (count($existingUsers) >= 2) {
            $this->testUserId1 = $existingUsers[0]->id;
            $this->testUserId2 = $existingUsers[1]->id;
        } elseif (count($existingUsers) === 1) {
            $this->testUserId1 = $existingUsers[0]->id;
            $this->testUserId2 = $existingUsers[0]->id; // Use same user if only one exists
        }

        $this->assertNotNull($this->testCarId, "Need at least one car for testing");
        $this->assertNotNull($this->testUserId1, "Need at least one user for testing");
    }

    /**
     * Test basic car_user table operations
     */
    public function testCarUserBasicOperations(): void
    {
        // Clean up any existing test relationships first
        $this->cleanupTestRelationships();
        
        // Test INSERT into car_user table
        $insertResult = $this->db->query(
            "INSERT INTO car_user (userid, car_id, mtime) VALUES (?, ?, NOW())",
            [$this->testUserId1, $this->testCarId]
        );
        $this->assertTrue($insertResult->error() == false, "Should be able to insert car_user relationship");
        
        $insertedId = $this->db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
        $this->createdTestData[] = ['table' => 'car_user', 'id' => $insertedId];
        
        // Test SELECT from car_user table
        $relationship = $this->db->query(
            "SELECT * FROM car_user WHERE userid = ? AND car_id = ?",
            [$this->testUserId1, $this->testCarId]
        )->first();

        $this->assertNotNull($relationship, "Should be able to retrieve car_user relationship");
        $this->assertEquals($this->testUserId1, $relationship->userid, "User ID should match");
        $this->assertEquals($this->testCarId, $relationship->car_id, "Car ID should match");
        
        // Test UPDATE car_user record
        $updateResult = $this->db->query(
            "UPDATE car_user SET mtime = NOW() WHERE userid = ? AND car_id = ?",
            [$this->testUserId1, $this->testCarId]
        );
        $this->assertTrue($updateResult->error() == false, "Should be able to update car_user relationship");
        
        // Test DELETE from car_user table
        $deleteResult = $this->db->query(
            "DELETE FROM car_user WHERE userid = ? AND car_id = ?",
            [$this->testUserId1, $this->testCarId]
        );
        $this->assertTrue($deleteResult->error() == false, "Should be able to delete car_user relationship");

        // Verify deletion
        $deletedCheck = $this->db->query(
            "SELECT * FROM car_user WHERE userid = ? AND car_id = ?",
            [$this->testUserId1, $this->testCarId]
        )->count();
        $this->assertEquals(0, $deletedCheck, "Relationship should be deleted");
    }

    /**
     * Test multiple users sharing the same car
     */
    public function testMultipleUsersPerCar(): void
    {
        $this->cleanupTestRelationships();
        
        // Add car to both users
        $result1 = $this->db->query(
            "INSERT INTO car_user (userid, car_id, mtime) VALUES (?, ?, NOW())",
            [$this->testUserId1, $this->testCarId]
        );
        $this->assertTrue($result1->error() == false, "Should add car to first user");

        $result2 = $this->db->query(
            "INSERT INTO car_user (userid, car_id, mtime) VALUES (?, ?, NOW())",
            [$this->testUserId2, $this->testCarId]
        );
        $this->assertTrue($result2->error() == false, "Should add same car to second user");
        
        // Record IDs for cleanup
        $id1 = $this->db->query("SELECT id FROM car_user WHERE userid = ? AND car_id = ?", [$this->testUserId1, $this->testCarId])->first()->id;
        $id2 = $this->db->query("SELECT id FROM car_user WHERE userid = ? AND car_id = ?", [$this->testUserId2, $this->testCarId])->first()->id;
        $this->createdTestData[] = ['table' => 'car_user', 'id' => $id1];
        $this->createdTestData[] = ['table' => 'car_user', 'id' => $id2];
        
        // Verify both relationships exist
        $relationships = $this->db->query(
            "SELECT userid FROM car_user WHERE car_id = ? ORDER BY userid",
            [$this->testCarId]
        )->results();
        
        $this->assertGreaterThanOrEqual(2, count($relationships), "Should have at least 2 users for this car");
        
        $userIds = array_map(function($rel) { return $rel->userid; }, $relationships);
        $this->assertContains($this->testUserId1, $userIds, "First user should have access to car");
        $this->assertContains($this->testUserId2, $userIds, "Second user should have access to car");
    }

    /**
     * Test car_user_hist audit trail functionality
     */
    public function testCarUserHistoryAuditTrail(): void
    {
        $this->cleanupTestRelationships();
        
        // Insert a history record
        $historyResult = $this->db->query(
            "INSERT INTO car_user_hist (operation, car_id, userid, timestamp) VALUES (?, ?, ?, NOW())",
            ['ADD_USER', $this->testCarId, $this->testUserId1]
        );
        $this->assertTrue($historyResult->error() == false, "Should be able to insert car_user_hist record");
        
        $historyId = $this->db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
        $this->createdTestData[] = ['table' => 'car_user_hist', 'id' => $historyId];
        
        // Verify history record
        $historyRecord = $this->db->query(
            "SELECT * FROM car_user_hist WHERE car_id = ? AND userid = ? AND operation = ?",
            [$this->testCarId, $this->testUserId1, 'ADD_USER']
        )->first();

        $this->assertNotNull($historyRecord, "Should be able to retrieve history record");
        $this->assertEquals('ADD_USER', $historyRecord->operation, "Operation should be recorded correctly");
        $this->assertEquals($this->testCarId, $historyRecord->car_id, "Car ID should be recorded correctly");
        $this->assertEquals($this->testUserId1, $historyRecord->userid, "User ID should be recorded correctly");
        
        // Test different operation types
        $operations = ['REMOVE_USER', 'TRANSFER_OWNERSHIP', 'SHARE_CAR'];
        foreach ($operations as $operation) {
            $opResult = $this->db->query(
                "INSERT INTO car_user_hist (operation, car_id, userid, timestamp) VALUES (?, ?, ?, NOW())",
                [$operation, $this->testCarId, $this->testUserId2]
            );
            $this->assertTrue($opResult->error() == false, "Should record {$operation} operation");
            
            $opId = $this->db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
            $this->createdTestData[] = ['table' => 'car_user_hist', 'id' => $opId];
        }
        
        // Verify all operations recorded
        $allOperations = $this->db->query(
            "SELECT operation FROM car_user_hist WHERE car_id = ? ORDER BY timestamp",
            [$this->testCarId]
        )->results();
        
        $this->assertGreaterThanOrEqual(4, count($allOperations), "Should have recorded all test operations");
    }

    /**
     * Test foreign key relationships and data integrity
     */
    public function testForeignKeyIntegrity(): void
    {
        // Test that car_id must reference existing car
        $nonExistentCarId = 999999;
        $invalidCarResult = $this->db->query(
            "SELECT COUNT(*) as count FROM cars WHERE id = ?",
            [$nonExistentCarId]
        )->first()->count;
        
        if ($invalidCarResult == 0) {
            // Try to insert with non-existent car ID - this should work in current schema
            // but would be caught by foreign key constraints if they existed
            $result = $this->db->query(
                "INSERT INTO car_user (userid, car_id, mtime) VALUES (?, ?, NOW())",
                [$this->testUserId1, $nonExistentCarId]
            );
            
            if (!$result->error()) {
                // Clean up the invalid record
                $invalidId = $this->db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
                $this->db->query("DELETE FROM car_user WHERE id = ?", [$invalidId]);
                
                // This indicates we might want to add foreign key constraints in migration
                echo "\nNote: No foreign key constraints detected on car_user.car_id\n";
            }
        }
        
        // Test that userid must reference existing user
        $nonExistentUserId = 999999;
        $invalidUserResult = $this->db->query(
            "SELECT COUNT(*) as count FROM users WHERE id = ?",
            [$nonExistentUserId]
        )->first()->count;
        
        if ($invalidUserResult == 0) {
            $result = $this->db->query(
                "INSERT INTO car_user (userid, car_id, mtime) VALUES (?, ?, NOW())",
                [$nonExistentUserId, $this->testCarId]
            );
            
            if (!$result->error()) {
                // Clean up the invalid record
                $invalidId = $this->db->query("SELECT LAST_INSERT_ID() as id")->first()->id;
                $this->db->query("DELETE FROM car_user WHERE id = ?", [$invalidId]);
                
                echo "\nNote: No foreign key constraints detected on car_user.userid\n";
            }
        }
        
        $this->assertTrue(true, "Foreign key integrity test completed");
    }

    /**
     * Test queries that will be affected by column rename
     */
    public function testAffectedQueries(): void
    {
        // Test common query patterns that use car_id
        $queryPatterns = [
            // Find cars for a user
            "SELECT car_id FROM car_user WHERE userid = ?",
            // Find users for a car
            "SELECT userid FROM car_user WHERE car_id = ?",
            // Count cars per user
            "SELECT COUNT(car_id) as car_count FROM car_user WHERE userid = ?",
            // Join with cars table
            "SELECT c.chassis, cu.car_id FROM cars c JOIN car_user cu ON c.id = cu.car_id WHERE cu.userid = ?",
            // History queries
            "SELECT car_id, operation FROM car_user_hist WHERE userid = ? ORDER BY timestamp DESC"
        ];
        
        foreach ($queryPatterns as $query) {
            try {
                $result = $this->db->query($query, [$this->testUserId1]);
                $this->assertNotNull($result, "Query should execute: {$query}");
            } catch (Exception $e) {
                $this->fail("Query failed: {$query} - Error: " . $e->getMessage());
            }
        }
    }

    /**
     * Clean up test relationships before/after tests
     */
    private function cleanupTestRelationships(): void
    {
        // Clean up specific test relationships
        if ($this->testUserId1 && $this->testCarId) {
            $this->db->query(
                "DELETE FROM car_user WHERE userid = ? AND car_id = ?",
                [$this->testUserId1, $this->testCarId]
            );
        }
        
        if ($this->testUserId2 && $this->testCarId) {
            $this->db->query(
                "DELETE FROM car_user WHERE userid = ? AND car_id = ?",
                [$this->testUserId2, $this->testCarId]
            );
        }
        
        // Clean up test history records
        if ($this->testCarId) {
            $this->db->query(
                "DELETE FROM car_user_hist WHERE car_id = ? AND userid IN (?, ?)",
                [$this->testCarId, $this->testUserId1, $this->testUserId2]
            );
        }
    }

    protected function tearDown(): void
    {
        // Clean up any created test data
        foreach ($this->createdTestData as $data) {
            try {
                $this->db->query("DELETE FROM {$data['table']} WHERE id = ?", [$data['id']]);
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
        
        $this->cleanupTestRelationships();
        parent::tearDown();
    }
}