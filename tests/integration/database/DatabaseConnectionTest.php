<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

/**
 * Database Connection Verification Test
 *
 * Simple test to verify that the database connection is working
 * and that basic queries can be executed.
 */
final class DatabaseConnectionTest extends IntegrationTestCase
{
    /**
     * Test that database connection is available
     */
    public function testDatabaseConnectionIsAvailable(): void
    {
        $this->requireDatabase();
        
        // If we get here, database is connected
        $this->assertTrue($this->databaseConnected, "Database should be connected");
    }

    /**
     * Test that we can execute a simple query
     */
    public function testCanExecuteSimpleQuery(): void
    {
        $this->requireDatabase();
        
        $result = $this->db->query("SELECT 1 as test_value");
        $this->assertNotNull($result, "Query should return a result");
        
        $row = $result->first();
        $this->assertNotNull($row, "Result should have at least one row");
        $this->assertEquals(1, $row->test_value, "Query should return test value of 1");
    }

    /**
     * Test that we can query actual tables
     */
    public function testCanQueryUserTable(): void
    {
        $this->requireDatabase();
        
        $result = $this->db->query("SELECT COUNT(*) as user_count FROM users");
        $this->assertNotNull($result, "Should be able to query users table");
        
        $row = $result->first();
        $this->assertNotNull($row, "Query result should have rows");
        $this->assertGreaterThanOrEqual(0, $row->user_count, "User count should be >= 0");
        
        echo "\n✅ Database has " . $row->user_count . " users\n";
    }

    /**
     * Test that we can query cars table
     */
    public function testCanQueryCarsTable(): void
    {
        $this->requireDatabase();
        
        $result = $this->db->query("SELECT COUNT(*) as car_count FROM cars");
        $this->assertNotNull($result, "Should be able to query cars table");
        
        $row = $result->first();
        $this->assertNotNull($row, "Query result should have rows");
        $this->assertGreaterThanOrEqual(0, $row->car_count, "Car count should be >= 0");
        
        echo "\n✅ Database has " . $row->car_count . " cars\n";
    }

    /**
     * Test that we can retrieve a user record
     */
    public function testCanRetrieveUserRecord(): void
    {
        $this->requireDatabase();

        $result = $this->db->query("SELECT id, username, email FROM users LIMIT 1");

        $this->assertGreaterThan(0, $result->count(), "Should have users in database");

        $user = $result->first();
        $this->assertNotNull($user->id, "User should have an ID");
        $this->assertNotNull($user->username, "User should have a username");

        echo "\n✅ Retrieved user: {$user->username} (ID: {$user->id})\n";
    }

    /**
     * Test that we can retrieve a car record
     */
    public function testCanRetrieveCarRecord(): void
    {
        $this->requireDatabase();

        $result = $this->db->query("SELECT id, year, model, chassis FROM cars LIMIT 1");

        $this->assertGreaterThan(0, $result->count(), "Should have cars in database");

        $car = $result->first();
        $this->assertNotNull($car->id, "Car should have an ID");

        echo "\n✅ Retrieved car: {$car->year} {$car->model} (ID: {$car->id})\n";
    }
}
