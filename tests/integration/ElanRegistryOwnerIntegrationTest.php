<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for ElanRegistryOwner class
 *
 * These tests require the full application bootstrap and real database connection.
 * They test ElanRegistryOwner functionality with actual database data and global functions.
 */
class ElanRegistryOwnerIntegrationTest extends TestCase
{
    private $db;
    private $connected = false;

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
        } catch (PDOException $e) {
            $this->connected = false;
        }
    }

    protected function tearDown(): void
    {
        $this->db = null;
    }

    /**
     * Test static getOwnerProfile method with valid user
     */
    public function testGetOwnerProfileWithValidUser(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database connection not available');
        }

        // Find a valid user in the database for testing
        $stmt = $this->db->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_OBJ);

        if ($user) {
            $userId = $user->id;
            $ownerData = ElanRegistryOwner::getOwnerProfile((int)$userId);

            $this->assertNotNull($ownerData);
            $this->assertEquals($userId, $ownerData->id);
            $this->assertObjectHasProperty('fname', $ownerData);
            $this->assertObjectHasProperty('lname', $ownerData);
            $this->assertObjectHasProperty('email', $ownerData);
        } else {
            $this->markTestSkipped('No users available in database for testing');
        }
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database connection not available');
        }

        // Find a valid user in the database for testing
        $stmt = $this->db->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch(PDO::FETCH_OBJ);

        if ($user) {
            $userId = $user->id;
            $owner = new ElanRegistryOwner();
            $result = $owner->find((int)$userId);

            $this->assertTrue($result);
            $this->assertNotNull($owner->data());
            $this->assertEquals($userId, $owner->data()->id);
        } else {
            $this->markTestSkipped('No users available in database for testing');
        }
    }

    /**
     * Test getting cars owned by owner
     */
    public function testGetCarsOwned(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database connection not available');
        }

        // Find a user who owns cars
        $stmt = $this->db->query(
            "SELECT DISTINCT user_id FROM cars WHERE user_id IS NOT NULL LIMIT 1"
        );
        $car = $stmt->fetch(PDO::FETCH_OBJ);

        if ($car) {
            $userId = $car->user_id;
            $owner = new ElanRegistryOwner((int)$userId);

            $ownedCars = $owner->getCarsOwned();
            $this->assertIsArray($ownedCars);
            $this->assertGreaterThan(0, count($ownedCars));

            // Check that all returned cars belong to this user
            foreach ($ownedCars as $carData) {
                $this->assertEquals($userId, $carData->user_id);
            }
        } else {
            $this->markTestSkipped('No users with cars available for testing');
        }
    }
}
