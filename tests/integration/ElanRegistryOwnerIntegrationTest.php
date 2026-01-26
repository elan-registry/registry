<?php
declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Integration tests for ElanRegistryOwner class
 *
 * These tests require the full application bootstrap and real database connection.
 * They test ElanRegistryOwner functionality with actual database data and global functions.
 */
class ElanRegistryOwnerIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * Test static getOwnerProfile method with valid user
     */
    public function testGetOwnerProfileWithValidUser(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $ownerData = ElanRegistryOwner::getOwnerProfile((int)$userId);

        $this->assertNotNull($ownerData);
        $this->assertEquals($userId, $ownerData->id);
        $this->assertObjectHasProperty('fname', $ownerData);
        $this->assertObjectHasProperty('lname', $ownerData);
        $this->assertObjectHasProperty('email', $ownerData);
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $owner = new ElanRegistryOwner();
        $result = $owner->find((int)$userId);

        $this->assertTrue($result);
        $this->assertNotNull($owner->data());
        $this->assertEquals($userId, $owner->data()->id);
    }

    /**
     * Test getting cars owned by owner
     */
    public function testGetCarsOwned(): void
    {
        // Use user ID 1 for testing
        $userId = 1;
        $owner = new ElanRegistryOwner((int)$userId);

        $ownedCars = $owner->getCarsOwned();
        $this->assertIsArray($ownedCars);
        $this->assertGreaterThan(0, count($ownedCars));

        // Check that all returned cars belong to this user
        foreach ($ownedCars as $carData) {
            $this->assertEquals($userId, $carData->user_id);
        }
    }
}
