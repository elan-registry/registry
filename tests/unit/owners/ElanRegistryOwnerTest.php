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
    private $realPdo;
    private $connected = false;

    protected function setUp(): void
    {
        // Try to connect to real database for integration testing
        try {
            $this->realPdo = new PDO(
                'mysql:host=127.0.0.1;port=8889;dbname=elanregi_spice',
                'claude',
                'claude',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $this->connected = true;

            // Create a DB wrapper that uses real PDO
            $this->db = $this->createDbWrapper($this->realPdo);
        } catch (PDOException $e) {
            $this->connected = false;
            // Fall back to mock DB
            $this->db = DB::getInstance();
        }

        $this->owner = new ElanRegistryOwner(null, $this->db);
    }

    /**
     * Create a DB wrapper that mimics the DB interface but uses real PDO
     *
     * @param PDO $pdo Database connection
     * @return object DB wrapper instance
     */
    private function createDbWrapper(PDO $pdo): object
    {
        return new class($pdo) {
            /** @var PDO */
            private $pdo;

            /**
             * @param PDO $pdo
             */
            public function __construct(PDO $pdo)
            {
                $this->pdo = $pdo;
            }

            /**
             * Execute a query and return results
             *
             * @param string $sql SQL query
             * @param array<mixed> $params Query parameters
             * @return object Query result object
             */
            public function query(string $sql, array $params = []): object
            {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $results = $stmt->fetchAll(PDO::FETCH_OBJ);

                return new class($results) {
                    /** @var array<object> */
                    private $results;

                    /**
                     * @param array<object> $results
                     */
                    public function __construct(array $results)
                    {
                        $this->results = $results;
                    }

                    /**
                     * Get count of results
                     *
                     * @return int
                     */
                    public function count(): int
                    {
                        return count($this->results);
                    }

                    /**
                     * Get first result
                     *
                     * @return object|null
                     */
                    public function first(): ?object
                    {
                        return $this->results[0] ?? null;
                    }

                    /**
                     * Get all results
                     *
                     * @return array<object>
                     */
                    public function results(): array
                    {
                        return $this->results;
                    }
                };
            }
        };
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        $this->owner = null;
        $this->realPdo = null;
    }

    /**
     * Helper method to execute queries
     */
    private function query(string $sql, array $params = []): object
    {
        return $this->db->query($sql, $params);
    }

    /**
     * Test basic owner instantiation
     */
    public function testOwnerInstantiation(): void
    {
        $owner = new ElanRegistryOwner(null, $this->db);
        $this->assertInstanceOf(ElanRegistryOwner::class, $owner);
        $this->assertNull($owner->data());
    }

    /**
     * Test static getOwnerProfile method with valid user
     *
     * @group integration
     * NOTE: This test has been moved to ElanRegistryOwnerIntegrationTest
     * as it requires full application bootstrap and global functions.
     */
    public function testGetOwnerProfileWithValidUser(): void
    {
        $this->markTestSkipped('Moved to integration test suite - see ElanRegistryOwnerIntegrationTest');
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
     *
     * @group integration
     * NOTE: This test has been moved to ElanRegistryOwnerIntegrationTest
     * as it requires full application bootstrap.
     */
    public function testFindWithValidUser(): void
    {
        $this->markTestSkipped('Moved to integration test suite - see ElanRegistryOwnerIntegrationTest');
    }

    /**
     * Test owner loading with invalid ID
     */
    public function testFindWithInvalidUser(): void
    {
        $owner = new ElanRegistryOwner(null, $this->db);
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
        $userQuery = $this->query(
            "SELECT u.id FROM users u
             LEFT JOIN profiles p ON u.id = p.user_id
             WHERE u.fname IS NOT NULL AND u.lname IS NOT NULL
             LIMIT 1"
        );

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
            $owner = new ElanRegistryOwner($userId, $this->db);

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
        $userQuery = $this->query("SELECT id FROM users LIMIT 1");

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->id;
            $owner = new ElanRegistryOwner($userId, $this->db);

            $missingFields = $owner->validateProfileCompleteness();
            $this->assertIsArray($missingFields);
        } else {
            $this->markTestSkipped('No users available in database for testing');
        }
    }

    /**
     * Test getting cars owned by owner
     *
     * @group integration
     * NOTE: This test has been moved to ElanRegistryOwnerIntegrationTest
     * as it requires full application bootstrap.
     */
    public function testGetCarsOwned(): void
    {
        $this->markTestSkipped('Moved to integration test suite - see ElanRegistryOwnerIntegrationTest');
    }

    /**
     * Test ownership history retrieval
     */
    public function testGetOwnershipHistory(): void
    {
        // Find a user who has ownership history
        $userQuery = $this->query(
            "SELECT DISTINCT user_id FROM cars_hist WHERE user_id IS NOT NULL LIMIT 1"
        );

        if ($userQuery->count() > 0) {
            $userId = $userQuery->first()->user_id;
            $owner = new ElanRegistryOwner((int)$userId, $this->db);

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
        $owner = new ElanRegistryOwner(null, $this->db);

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
        $owner = new ElanRegistryOwner(null, $this->db);

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
        $owner = new ElanRegistryOwner(null, $this->db);

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
        $owner = new ElanRegistryOwner(null, $this->db);

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