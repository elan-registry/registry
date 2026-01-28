<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Test cases for Car transfer functionality
 *
 * Tests cover car ownership transfer operations with user validation,
 * transaction handling, relationship updates, and profile data copying.
 *
 * @group integration
 */
final class CarTransferTest extends IntegrationTestCase
{
    private $testCarId;
    private $testUserId;
    private $targetUserId;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Set up authenticated user context for transfer operations
        global $user;
        $user = new User();
        $user->find(1);  // Load user ID 1

        // Manually set the private $_isLoggedIn property to true using reflection
        $reflection = new ReflectionClass($user);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setAccessible(true);
        $isLoggedInProperty->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->testUserId = 1;
        $this->targetUserId = 10;  // Use existing user ID (FredHansen)
        $this->db = DB::getInstance();

        // Create unique test car for this test
        try {
            $this->testCarId = $this->createTestCar($this->testUserId, [
                'chassis' => 'TR' . uniqid()
            ]);
        } catch (RuntimeException $e) {
            $this->markTestSkipped('Could not create test car: ' . $e->getMessage());
        }

        // Ensure car_user relationship exists for test car
        $this->db->insert('car_user', [
            'car_id' => $this->testCarId,
            'userid' => $this->testUserId
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up any test data if needed
    }

    /**
     * Test successful car transfer to valid user
     *
     * @group fast
     */
    public function testTransferCarSuccessWithValidUser(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER');

        $this->assertTrue($result);

        // Reload car data from database to verify transfer
        $transferredCar = new Car($this->testCarId);
        $this->assertEquals($this->targetUserId, $transferredCar->data()->user_id);
    }

    /**
     * Test car transfer fails with invalid user ID
     *
     * @group fast
     */
    public function testTransferCarFailsWithInvalidUser(): void
    {
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $car->transfer(99999, 'Test transfer', 'NEWOWNER');
    }

    /**
     * Test car transfer fails when car does not exist
     *
     * @group fast
     */
    public function testTransferCarFailsWhenCarNotExists(): void
    {
        $this->expectException(Exception::class);

        $car = new Car(99999);
        $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER');
    }

    /**
     * Test car transfer updates car_user relationship table
     *
     * @group fast
     */
    public function testTransferUpdatesCarUserRelationship(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        // Verify original relationship
        $relationQuery = $this->db->query(
            "SELECT * FROM car_user WHERE car_id = ? AND userid = ?",
            [$carId, $this->testUserId]
        );
        $this->assertTrue($relationQuery->count() > 0);

        $result = $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify relationship was updated
        $relationQuery = $this->db->query(
            "SELECT * FROM car_user WHERE car_id = ? AND userid = ?",
            [$carId, $this->targetUserId]
        );
        $this->assertTrue($relationQuery->count() > 0);
    }

    /**
     * Test car transfer creates history record
     *
     * @group fast
     */
    public function testTransferCreatesHistoryRecord(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $result = $car->transfer($this->targetUserId, 'Test transfer history', 'NEWOWNER');

        $this->assertTrue($result);

        // Check that history record was created with NEWOWNER operation
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'NEWOWNER'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * Test car transfer copies user profile data
     *
     * @group fast
     */
    public function testTransferCopiesUserProfileData(): void
    {
        $car = new Car($this->testCarId);

        // Get target user's profile data
        $targetUser = getUserWithProfile($this->targetUserId);
        $this->assertNotNull($targetUser);

        $result = $car->transfer($this->targetUserId, 'Test transfer profile', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify that car now has target user's profile data
        $updatedCar = new Car($car->data()->id);
        $this->assertEquals($targetUser->fname ?? '', $updatedCar->data()->fname);
        $this->assertEquals($targetUser->lname ?? '', $updatedCar->data()->lname);
        $this->assertEquals($targetUser->email ?? '', $updatedCar->data()->email);
    }

    /**
     * Test car transfer transaction rollback on failure
     *
     * @group fast
     */
    public function testTransferTransactionRollbackOnFailure(): void
    {
        // Test that invalid user causes transfer to fail completely
        $this->expectException(Exception::class);

        $car = new Car($this->testCarId);
        $originalUserId = $car->data()->user_id;

        try {
            $car->transfer(99999, 'Test transfer', 'NEWOWNER');
        } catch (Exception $e) {
            // After failed transfer, car should still have original owner
            $carReloaded = new Car($car->data()->id);
            $this->assertEquals($originalUserId, $carReloaded->data()->user_id);
            throw $e;
        }
    }

    /**
     * Test car transfer requires authenticated user
     *
     * @group fast
     */
    public function testTransferRequiresAuthenticatedUser(): void
    {
        $this->expectException(CarPermissionException::class);

        $car = new Car($this->testCarId);

        // Mock: Unset global user to simulate no authentication
        global $user;
        $originalUser = $user ?? null;
        unset($GLOBALS['user']);

        try {
            $car->transfer($this->targetUserId, 'Test transfer', 'NEWOWNER');
        } finally {
            // Restore user
            if ($originalUser) {
                $GLOBALS['user'] = $originalUser;
            }
        }
    }

    /**
     * Test car transfer with TRANSFER operation type
     *
     * @group fast
     */
    public function testTransferCarWithTransferOperationType(): void
    {
        $car = new Car($this->testCarId);
        $carId = $car->data()->id;

        $result = $car->transfer($this->targetUserId, 'Test transfer', 'TRANSFER');

        $this->assertTrue($result);

        // Check that history record was created with TRANSFER operation
        $historyQuery = $this->db->query(
            "SELECT * FROM cars_hist WHERE car_id = ? AND operation = 'TRANSFER'",
            [$carId]
        );
        $this->assertTrue($historyQuery->count() > 0);
    }

    /**
     * Test car transfer updates location data if available
     *
     * @group fast
     */
    public function testTransferUpdatesLocationData(): void
    {
        $car = new Car($this->testCarId);

        $result = $car->transfer($this->targetUserId, 'Test transfer location', 'NEWOWNER');

        $this->assertTrue($result);

        // Verify that car now has target user's location data
        $targetUser = getUserWithProfile($this->targetUserId);
        $updatedCar = new Car($car->data()->id);

        $this->assertEquals($targetUser->city ?? '', $updatedCar->data()->city);
        $this->assertEquals($targetUser->state ?? '', $updatedCar->data()->state);
        $this->assertEquals($targetUser->country ?? '', $updatedCar->data()->country);
    }
}
