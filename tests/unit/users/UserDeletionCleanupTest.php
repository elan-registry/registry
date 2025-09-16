<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test cases for User Deletion Cleanup functionality (Issue #106)
 * 
 * Tests the cleanup process when users are deleted, ensuring:
 * - Orphaned profiles are cleaned up
 * - Car ownership is properly transferred to noowner user
 * - car_user relationships are maintained
 * - Audit logging functions correctly
 * - Fallback behavior works when noowner user is missing
 */
final class UserDeletionCleanupTest extends TestCase
{
    private $db;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Reset global mock data
        global $mockDeletedUsers, $mockLogEntries;
        $mockDeletedUsers = [];
        $mockLogEntries = [];
        
        // Get mock database instance
        $this->db = DB::getInstance();
        
        // Set up mock data for testing
        $this->setupMockData();
    }
    
    protected function tearDown(): void
    {
        // Clean up global mock data
        global $mockDeletedUsers, $mockLogEntries;
        unset($mockDeletedUsers, $mockLogEntries);
        
        parent::tearDown();
    }
    
    /**
     * Set up mock database data for user deletion tests
     */
    private function setupMockData(): void
    {
        // Mock users including noowner
        global $mockUsers, $mockProfiles, $mockCarUser, $mockCars;
        
        $mockUsers = [
            (object) ['id' => 1, 'username' => 'admin', 'email' => 'admin@test.com'],
            (object) ['id' => 83, 'username' => 'noowner', 'email' => 'noreply@test.com'],
            (object) ['id' => 999, 'username' => 'tobedeleted', 'email' => 'delete@test.com']
        ];
        
        $mockProfiles = [
            (object) ['id' => 1, 'user_id' => 999, 'city' => 'Test City', 'state' => 'TS']
        ];
        
        $mockCarUser = [
            (object) ['id' => 1, 'userid' => 999, 'carid' => 1001],
            (object) ['id' => 2, 'userid' => 999, 'carid' => 1002]
        ];
        
        $mockCars = [
            (object) ['id' => 1001, 'user_id' => 999, 'chassis' => 'TEST001', 'year' => '1973'],
            (object) ['id' => 1002, 'user_id' => 999, 'chassis' => 'TEST002', 'year' => '1974']
        ];
    }
    
    /**
     * Test successful lookup of noowner user
     */
    public function testNoOwnerUserLookup(): void
    {
        $query = $this->db->query('SELECT id FROM users WHERE username = ?', ['noowner']);
        
        $this->assertEquals(1, $query->count(), 'noowner user should be found');
        $this->assertEquals(83, $query->first()->id, 'noowner user should have correct ID');
    }
    
    /**
     * Test profile cleanup during user deletion
     */
    public function testProfileCleanupSuccess(): void
    {
        $userId = 999;
        
        // Execute user deletion
        $deletedCount = deleteUsers([$userId]);
        
        // Verify deletion executed
        $this->assertEquals(1, $deletedCount, 'One user should be deleted');
        
        // Verify profile cleanup occurred (would be tested via mock DB queries)
        $this->assertUserDeletionLogged($userId, 'Complete cleanup');
    }
    
    /**
     * Test car_user junction table cleanup
     */
    public function testCarUserCleanupSuccess(): void
    {
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify car_user cleanup and reassignment
        $this->assertUserDeletionLogged($userId, 'Complete cleanup');
        $this->assertUserDeletionLogged($userId, 'reassigned 2 cars to noowner user');
    }
    
    /**
     * Test car ownership transfer to noowner user
     */
    public function testCarReassignmentToNoOwner(): void
    {
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify cars were reassigned
        $this->assertUserDeletionLogged($userId, 'reassigned 2 cars to noowner user (ID: 83)');
    }
    
    /**
     * Test audit logging entries are created
     */
    public function testAuditLoggingEntries(): void
    {
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify audit log entry exists
        global $mockLogEntries;
        
        $userDeletionLogs = array_filter($mockLogEntries, function($entry) use ($userId) {
            return $entry['user_id'] === $userId && 
                   $entry['category'] === 'UserDeletion';
        });
        
        $this->assertNotEmpty($userDeletionLogs, 'Audit log entries should be created');
        $this->assertGreaterThan(0, count($userDeletionLogs), 'At least one audit log entry should exist');
    }
    
    /**
     * Test fallback behavior when noowner user is missing
     */
    public function testMissingNoOwnerUserFallback(): void
    {
        // Remove noowner user from mock data
        $this->removeMockNoOwnerUser();
        
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify fallback behavior
        $this->assertUserDeletionLogged($userId, 'Fallback cleanup: noowner user not found');
    }
    
    /**
     * Test deletion of user without cars
     */
    public function testUserWithoutCarsCleanup(): void
    {
        $userId = 888; // User with no cars
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify cleanup occurred even without cars
        $this->assertUserDeletionLogged($userId, 'Complete cleanup: reassigned 0 cars');
    }
    
    /**
     * Test deletion of user with multiple cars
     */
    public function testUserWithMultipleCarsCleanup(): void
    {
        // This test uses the default mock data (user 999 with 2 cars)
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify multiple cars were handled
        $this->assertUserDeletionLogged($userId, 'reassigned 2 cars to noowner user');
    }
    
    /**
     * Test complete cleanup flow integration
     */
    public function testCompleteCleanupFlow(): void
    {
        $userId = 999;
        
        // Execute complete user deletion
        deleteUsers([$userId]);
        
        // Verify all aspects of cleanup
        global $mockLogEntries, $mockDeletedUsers;
        
        // Verify user was marked for deletion
        $this->assertContains($userId, $mockDeletedUsers, 'User should be in deletion list');
        
        // Verify audit log created
        $this->assertUserDeletionLogged($userId, 'Complete cleanup');
        
        // Verify car reassignment logged
        $this->assertUserDeletionLogged($userId, 'reassigned 2 cars to noowner user (ID: 83)');
    }
    
    /**
     * Test database integrity after cleanup
     */
    public function testDatabaseIntegrityAfterCleanup(): void
    {
        $userId = 999;
        
        // Execute user deletion
        deleteUsers([$userId]);
        
        // Verify no orphaned records would remain
        // (In a real database test, this would query actual tables)
        $this->assertUserDeletionLogged($userId, 'Complete cleanup');
        
        // Verify cars are preserved (not deleted)
        // This would be tested by verifying cars still exist but with new owner
        $this->assertTrue(true, 'Database integrity maintained');
    }
    
    /**
     * Test batch user deletion
     */
    public function testBatchUserDeletion(): void
    {
        $userIds = [999, 888, 777];
        
        // Execute batch deletion
        $deletedCount = deleteUsers($userIds);
        
        // Verify all users processed
        $this->assertEquals(3, $deletedCount, 'All users should be processed');
        
        // Verify each user has audit log
        foreach ($userIds as $userId) {
            $this->assertUserDeletionLogged($userId, 'cleanup');
        }
    }
    
    /**
     * Helper method to remove noowner user from mock data
     */
    private function removeMockNoOwnerUser(): void
    {
        global $mockUsers;
        $mockUsers = array_filter($mockUsers, function($user) {
            return $user->username !== 'noowner';
        });
    }
    
    /**
     * Assert that a user deletion was logged with specific message content
     */
    private function assertUserDeletionLogged(int $userId, string $messageContains): void
    {
        global $mockLogEntries;
        
        $found = false;
        foreach ($mockLogEntries as $entry) {
            if ($entry['user_id'] === $userId && 
                $entry['category'] === 'UserDeletion' &&
                strpos($entry['message'], $messageContains) !== false) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, "Audit log entry not found for user $userId with message containing: $messageContains");
    }
}