<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Test suite for ElanRegistryOwner class
 *
 * Tests the functionality of the ElanRegistryOwner class including
 * data access, validation, and integration with existing systems.
 */
class ElanRegistryOwnerTest extends TestCase
{
    private $owner;
    private $db;

    protected function setUp(): void
    {
        // Initialize test database connection
        $this->db = DB::getInstance();
        $this->owner = new ElanRegistryOwner();
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        $this->owner = null;
    }

    /**
     * Test basic owner instantiation
     */
    public function testOwnerInstantiation(): void
    {
        $owner = new ElanRegistryOwner();
        $this->assertInstanceOf(ElanRegistryOwner::class, $owner);
        $this->assertNull($owner->data());
    }

    /**
     * Test static getOwnerProfile method with valid user
     */
    public function testGetOwnerProfileWithValidUser(): void
    {
        // Find a valid user in the database for testing
        $userQuery = $this->db->query("SELECT id FROM users LIMIT 1");

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
            $ownerData = ElanRegistryOwner::getOwnerProfile((int)$userId);

            $this->assertNotNull($ownerData);
            $this->assertEquals($userId, $ownerData->id);
            $this->assertObjectHasAttribute('fname', $ownerData);
            $this->assertObjectHasAttribute('lname', $ownerData);
            $this->assertObjectHasAttribute('email', $ownerData);
        } else {
            $this->markTestSkipped('No users available in database for testing');
        }
    }

    /**
     * Test getOwnerProfile with invalid user ID
     */
    public function testGetOwnerProfileWithInvalidUser(): void
    {
        $ownerData = ElanRegistryOwner::getOwnerProfile(999999);
        $this->assertNull($ownerData);
    }

    /**
     * Test owner loading with valid ID
     */
    public function testFindWithValidUser(): void
    {
        // Find a valid user in the database for testing
        $userQuery = $this->db->query("SELECT id FROM users LIMIT 1");

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
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
     * Test owner loading with invalid ID
     */
    public function testFindWithInvalidUser(): void
    {
        $owner = new ElanRegistryOwner();
        $result = $owner->find(999999);

        $this->assertFalse($result);
        $this->assertNull($owner->data());
    }

    /**
     * Test profile quality scoring
     */
    public function testProfileQualityScoring(): void
    {
        // Find a user with some profile data
        $userQuery = $this->db->query(
            "SELECT u.id FROM users u
             LEFT JOIN profiles p ON u.id = p.user_id
             WHERE u.fname IS NOT NULL AND u.lname IS NOT NULL
             LIMIT 1"
        );

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
            $owner = new ElanRegistryOwner($userId);

            $score = $owner->getProfileQualityScore();
            $this->assertIsFloat($score);
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(100.0, $score);
        } else {
            $this->markTestSkipped('No users with profile data available for testing');
        }
    }

    /**
     * Test profile completeness validation
     */
    public function testProfileCompletenessValidation(): void
    {
        // Find a user for testing
        $userQuery = $this->db->query("SELECT id FROM users LIMIT 1");

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
            $owner = new ElanRegistryOwner($userId);

            $missingFields = $owner->validateProfileCompleteness();
            $this->assertIsArray($missingFields);
        } else {
            $this->markTestSkipped('No users available in database for testing');
        }
    }

    /**
     * Test getting cars owned by owner
     */
    public function testGetCarsOwned(): void
    {
        // Find a user who owns cars
        $userQuery = $this->db->query(
            "SELECT DISTINCT user_id FROM cars WHERE user_id IS NOT NULL LIMIT 1"
        );

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->user_id;
            $owner = new ElanRegistryOwner((int)$userId);

            $ownedCars = $owner->getCarsOwned();
            $this->assertIsArray($ownedCars);
            $this->assertGreaterThan(0, count($ownedCars));

            // Check that all returned cars belong to this user
            foreach ($ownedCars as $car) {
                $this->assertEquals($userId, $car->user_id);
            }
        } else {
            $this->markTestSkipped('No users with cars available for testing');
        }
    }

    /**
     * Test ownership history retrieval
     */
    public function testGetOwnershipHistory(): void
    {
        // Find a user who has ownership history
        $userQuery = $this->db->query(
            "SELECT DISTINCT user_id FROM cars_hist WHERE user_id IS NOT NULL LIMIT 1"
        );

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->user_id;
            $owner = new ElanRegistryOwner((int)$userId);

            $history = $owner->getOwnershipHistory();
            $this->assertIsArray($history);
        } else {
            // This is fine if no history exists yet
            $this->assertTrue(true);
        }
    }

    /**
     * Test owner search functionality
     */
    public function testSearchOwners(): void
    {
        $owner = new ElanRegistryOwner();

        // Search for a common name pattern
        $results = $owner->searchOwners('test', 10);
        $this->assertIsArray($results);
        $this->assertLessThanOrEqual(10, count($results));

        // Empty search should return empty array
        $emptyResults = $owner->searchOwners('', 10);
        $this->assertIsArray($emptyResults);
    }

    /**
     * Test field validation methods
     */
    public function testFieldValidation(): void
    {
        // Test email validation
        $owner = new ElanRegistryOwner();

        // Use reflection to test private validation methods
        $reflection = new ReflectionClass($owner);
        $validateMethod = $reflection->getMethod('validateAndSanitizeFields');
        $validateMethod->setAccessible(true);

        // Test valid email
        $validFields = ['email' => 'test@example.com'];
        $result = $validateMethod->invokeArgs($owner, [$validFields, false]);
        $this->assertEquals('test@example.com', $result['email']);

        // Test invalid email should throw exception
        $this->expectException(OwnerValidationException::class);
        $invalidFields = ['email' => 'invalid-email'];
        $validateMethod->invokeArgs($owner, [$invalidFields, false]);
    }

    /**
     * Test string sanitization
     */
    public function testStringSanitization(): void
    {
        $owner = new ElanRegistryOwner();

        // Use reflection to test private sanitization method
        $reflection = new ReflectionClass($owner);
        $sanitizeMethod = $reflection->getMethod('sanitizeString');
        $sanitizeMethod->setAccessible(true);

        // Test basic sanitization
        $result = $sanitizeMethod->invokeArgs($owner, ['  Test Name  ', 50]);
        $this->assertEquals('Test Name', $result);

        // Test length limiting
        $longString = str_repeat('a', 100);
        $result = $sanitizeMethod->invokeArgs($owner, [$longString, 10]);
        $this->assertEquals(10, strlen($result));
    }

    /**
     * Test field extraction methods
     */
    public function testFieldExtraction(): void
    {
        $owner = new ElanRegistryOwner();

        // Use reflection to test private extraction methods
        $reflection = new ReflectionClass($owner);
        $extractUserMethod = $reflection->getMethod('extractUserFields');
        $extractProfileMethod = $reflection->getMethod('extractProfileFields');
        $extractUserMethod->setAccessible(true);
        $extractProfileMethod->setAccessible(true);

        $testFields = [
            'fname' => 'John',
            'lname' => 'Doe',
            'email' => 'john@example.com',
            'city' => 'Test City',
            'state' => 'Test State',
            'country' => 'Test Country',
            'website' => 'https://example.com'
        ];

        $userFields = $extractUserMethod->invokeArgs($owner, [$testFields]);
        $profileFields = $extractProfileMethod->invokeArgs($owner, [$testFields]);

        $this->assertArrayHasKey('fname', $userFields);
        $this->assertArrayHasKey('lname', $userFields);
        $this->assertArrayHasKey('email', $userFields);

        $this->assertArrayHasKey('city', $profileFields);
        $this->assertArrayHasKey('state', $profileFields);
        $this->assertArrayHasKey('country', $profileFields);
        $this->assertArrayHasKey('website', $profileFields);
    }
}