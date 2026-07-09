<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Base class for all integration tests
 *
 * Provides:
 * - Database connection validation
 * - Test fixture creation (users, cars)
 * - Skip logic when database unavailable
 * - Access to real UserSpice framework functions
 *
 * All integration tests should extend this class instead of TestCase directly.
 *
 * Example:
 * ```php
 * class MyIntegrationTest extends IntegrationTestCase
 * {
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->requireDatabase(); // Skip test if DB unavailable
 *
 *         // Create test fixtures
 *         $userId = $this->createTestUser();
 *         $carId = $this->createTestCar($userId);
 *     }
 * }
 * ```
 */
abstract class IntegrationTestCase extends TestCase
{
    protected $db;
    protected $databaseConnected = false;

    /** @var int[] Car IDs created during this test, cleaned up in tearDown */
    private array $createdCarIds = [];

    /** @var int[] User IDs created during this test, cleaned up in tearDown */
    private array $createdUserIds = [];

    /**
     * Set up test environment
     * Initializes database connection
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createdCarIds = [];
        $this->createdUserIds = [];

        // Get real DB instance (loaded by bootstrap-integration.php)
        try {
            $this->db = DB::getInstance();

            // Verify database connection with simple query
            $result = $this->db->query("SELECT 1");
            if ($result !== null && $result->count() > 0) {
                $this->databaseConnected = true;
            }
        } catch (RuntimeException $e) {
            $this->databaseConnected = false;
            // Don't fail yet - let requireDatabase() handle it
        }
    }

    /**
     * Clean up all test-created cars and users
     */
    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // Delete cars first (depend on users via car_user)
            foreach ($this->createdCarIds as $carId) {
                try {
                    $this->db->query("DELETE FROM car_transfer_requests WHERE existing_car_id = ?", [$carId]);
                    $this->db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
                    $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);
                    $this->db->query("DELETE FROM car_user_hist WHERE car_id = ?", [$carId]);
                    $this->db->delete('cars', ['id', '=', $carId]);
                } catch (RuntimeException $e) {
                    // Ignore cleanup errors
                }
            }

            // Then delete users
            foreach ($this->createdUserIds as $userId) {
                try {
                    $this->db->delete('users', ['id', '=', $userId]);
                } catch (RuntimeException $e) {
                    // Ignore cleanup errors
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Skip test if database not available
     *
     * Call this at the start of setUp() in any test that requires database access.
     * If database is not available, the test will be skipped with a message.
     */
    protected function requireDatabase(): void
    {
        if (!$this->databaseConnected) {
            $this->markTestSkipped('Database connection not available for integration testing');
        }
    }

    /**
     * Create a test user in database
     *
     * @param array $data Override default user data
     * @return int The created user ID
     * @throws RuntimeException If user creation fails
     */
    protected function createTestUser(array $data = []): int
    {
        $this->requireDatabase();

        // Generate unique username and email
        $uniqueSuffix = uniqid();

        $defaults = [
            'username' => "testuser_{$uniqueSuffix}",
            'password' => password_hash('testpass123', PASSWORD_BCRYPT),
            'email' => "test_{$uniqueSuffix}@example.com",
            'fname' => 'Test',
            'lname' => 'User',
            'active' => 1,
            'join_date' => date('Y-m-d H:i:s')
        ];

        $userData = array_merge($defaults, $data);

        // Insert into database
        $insertResult = $this->db->insert('users', $userData);
        if (!$insertResult) {
            throw new RuntimeException("Failed to create test user: {$this->db->errorString()}");
        }

        $userId = (int) $this->db->lastId();
        if (!$userId) {
            throw new RuntimeException("Failed to get inserted user ID");
        }

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    /**
     * Create a test car in database
     *
     * @param int $userId The owner user ID
     * @param array $data Override default car data
     * @return int The created car ID
     * @throws RuntimeException If car creation fails
     */
    protected function createTestCar(int $userId, array $data = []): int
    {
        $this->requireDatabase();

        // Verify user exists
        $userCheck = $this->db->query("SELECT id FROM users WHERE id = ?", [$userId])->first();
        if (!$userCheck) {
            throw new RuntimeException("User ID {$userId} does not exist");
        }

        // Generate unique chassis number
        $uniqueSuffix = uniqid();

        $defaults = [
            'user_id' => $userId,
            'year' => '1973',
            'model' => 'Elan S4',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'T' . substr($uniqueSuffix, -10), // varchar(15) limit
            'color' => 'Red',
            'ctime' => date('Y-m-d H:i:s')
        ];

        $carData = array_merge($defaults, $data);

        // Insert into database
        $insertResult = $this->db->insert('cars', $carData);
        if (!$insertResult) {
            throw new RuntimeException("Failed to create test car: {$this->db->errorString()}");
        }

        $carId = (int) $this->db->lastId();
        if (!$carId) {
            throw new RuntimeException("Failed to get inserted car ID");
        }

        // Insert into car_user junction table
        $junctionResult = $this->db->insert('car_user', ['userid' => $userId, 'car_id' => $carId]);
        if (!$junctionResult) {
            throw new RuntimeException("Failed to create car_user record: {$this->db->errorString()}");
        }

        $this->createdCarIds[] = $carId;

        // Purge any stale cars_hist rows left from a previous test run that
        // used the same car ID (possible if the cars table was ever truncated,
        // which resets AUTO_INCREMENT without clearing history).
        $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);

        return $carId;
    }

    /**
     * Delete a test user from database
     *
     * @param int $userId The user ID to delete
     * @return bool Success status
     */
    protected function deleteTestUser(int $userId): bool
    {
        if (!$this->databaseConnected) {
            return false;
        }

        try {
            $this->db->delete('users', ['id', '=', $userId]);
            // Remove from tracking so tearDown doesn't double-delete
            $this->createdUserIds = array_values(array_diff($this->createdUserIds, [$userId]));
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Delete a test car from database
     *
     * @param int $carId The car ID to delete
     * @return bool Success status
     */
    protected function deleteTestCar(int $carId): bool
    {
        if (!$this->databaseConnected) {
            return false;
        }

        try {
            $this->db->query("DELETE FROM car_user WHERE car_id = ?", [$carId]);
            $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);
            $this->db->delete('cars', ['id', '=', $carId]);
            // Remove from tracking so tearDown doesn't double-delete
            $this->createdCarIds = array_values(array_diff($this->createdCarIds, [$carId]));
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Register a car ID for cleanup in tearDown
     *
     * Call this when a test creates a car via Car::create() directly instead of
     * through createTestCar(), so the car is cleaned up after the test.
     *
     * @param int $carId The car ID to track
     */
    protected function trackCarId(int $carId): void
    {
        $this->createdCarIds[] = $carId;
    }

    protected function untrackCarId(int $carId): void
    {
        $this->createdCarIds = array_values(array_diff($this->createdCarIds, [$carId]));
    }

    /**
     * Check if database is currently connected
     *
     * @return bool True if database connection is active
     */
    protected function isDatabaseConnected(): bool
    {
        return $this->databaseConnected;
    }
}
