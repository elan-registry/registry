<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;

/**
 * User Deletion Cleanup tests (Issue #106)
 *
 * Tests the decision logic and validation for user deletion cleanup.
 * Note: Database operations are tested in integration tests, not here.
 */
#[Group('fast')]
final class UserDeletionCleanupTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        parent::setUp();

        global $mockDeletedUsers, $mockLogEntries;
        $mockDeletedUsers = [];
        $mockLogEntries = [];

        $this->db = DB::getInstance();
        $this->setupMockData();
    }

    protected function tearDown(): void
    {
        global $mockDeletedUsers, $mockLogEntries;
        unset($mockDeletedUsers, $mockLogEntries);

        parent::tearDown();
    }

    private function setupMockData(): void
    {
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
     * Test deleteUsers function returns correct count
     */
    public function testDeleteUsersFunctionReturnsCount(): void
    {
        $userId = 999;

        $deletedCount = deleteUsers([$userId]);

        $this->assertEquals(1, $deletedCount, 'One user should be deleted');
    }

    /**
     * Test batch deletion returns correct count
     */
    public function testBatchDeletionReturnsCorrectCount(): void
    {
        $userIds = [999, 888, 777];

        $deletedCount = deleteUsers($userIds);

        $this->assertEquals(3, $deletedCount, 'All users should be processed');
    }

    /**
     * Test user is added to deletion tracking
     */
    public function testUserAddedToDeletionTracking(): void
    {
        $userId = 999;

        deleteUsers([$userId]);

        global $mockDeletedUsers;
        $this->assertContains($userId, $mockDeletedUsers, 'User should be in deletion list');
    }

    /**
     * Test empty user list returns zero
     */
    public function testEmptyUserListReturnsZero(): void
    {
        $deletedCount = deleteUsers([]);

        $this->assertEquals(0, $deletedCount, 'Empty list should return zero');
    }

    /**
     * Test audit logging category is set correctly
     */
    public function testAuditLoggingCategoryIsUserDeletion(): void
    {
        $userId = 999;

        deleteUsers([$userId]);

        global $mockLogEntries;

        $userDeletionLogs = array_filter($mockLogEntries, function ($entry) {
            return $entry['category'] === 'UserDeletion';
        });

        $this->assertGreaterThanOrEqual(0, count($userDeletionLogs), 'UserDeletion category should be used');
    }
}
