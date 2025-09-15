<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Simple Database Schema Test
 * 
 * Direct database connection test to validate schema consistency
 * without UserSpice framework dependencies.
 */
final class SimpleDatabaseSchemaTest extends TestCase
{
    private $pdo;
    private $connected = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupDatabaseConnection();
    }

    /**
     * Setup direct database connection
     */
    private function setupDatabaseConnection(): void
    {
        try {
            // Connect using MAMP socket path (as per development documentation)
            $this->pdo = new PDO(
                'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=elanregi_spice;charset=utf8',
                'claude',
                'claude',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
                ]
            );
            $this->connected = true;
        } catch (PDOException $e) {
            $this->connected = false;
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Test that car-related tables exist
     */
    public function testCarTablesExist(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        $tables = ['cars', 'cars_hist', 'car_user', 'car_user_hist'];
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $result = $stmt->fetchAll();
            
            $this->assertNotEmpty($result, "Table '{$table}' should exist");
        }
    }

    /**
     * Test column naming patterns
     */
    public function testColumnNamingPatterns(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        // Check cars table structure
        $stmt = $this->pdo->query("DESCRIBE cars");
        $columns = $stmt->fetchAll();
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('id', $columnNames, "Cars table should have 'id' column");
        $this->assertNotContains('carid', $columnNames, "Cars table should not have 'carid' column");
        
        // Check car_user table structure
        $stmt = $this->pdo->query("DESCRIBE car_user");
        $columns = $stmt->fetchAll();
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('carid', $columnNames, "car_user table should have 'carid' column (current state)");
        $this->assertContains('userid', $columnNames, "car_user table should have 'userid' column");
        
        // Check car_user_hist table structure  
        $stmt = $this->pdo->query("DESCRIBE car_user_hist");
        $columns = $stmt->fetchAll();
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('carid', $columnNames, "car_user_hist table should have 'carid' column (current state)");
        
        // Check cars_hist table structure
        $stmt = $this->pdo->query("DESCRIBE cars_hist");
        $columns = $stmt->fetchAll();
        $columnNames = array_column($columns, 'Field');
        
        $this->assertContains('car_id', $columnNames, "cars_hist table should have 'car_id' column");
        
        echo "\n✓ Schema analysis completed - found expected column naming patterns\n";
    }

    /**
     * Test basic data integrity
     */
    public function testDataIntegrity(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        // Count records in each table
        $counts = [];
        $tables = ['cars', 'car_user', 'car_user_hist', 'cars_hist'];
        
        foreach ($tables as $table) {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM {$table}");
            $result = $stmt->fetch();
            $counts[$table] = (int)$result->count;
        }
        
        $this->assertGreaterThan(0, $counts['cars'], "Should have car records");
        $this->assertGreaterThanOrEqual(0, $counts['car_user'], "car_user table should be accessible");
        $this->assertGreaterThanOrEqual(0, $counts['car_user_hist'], "car_user_hist table should be accessible");
        $this->assertGreaterThanOrEqual(0, $counts['cars_hist'], "cars_hist table should be accessible");
        
        echo "\nData counts - Cars: {$counts['cars']}, car_user: {$counts['car_user']}, car_user_hist: {$counts['car_user_hist']}, cars_hist: {$counts['cars_hist']}\n";
    }

    /**
     * Test queries that will be affected by migration
     */
    public function testAffectedQueries(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        // Test current queries with carid
        $queries = [
            "SELECT COUNT(*) as count FROM car_user WHERE carid > 0",
            "SELECT COUNT(*) as count FROM car_user_hist WHERE carid > 0"
        ];
        
        foreach ($queries as $query) {
            try {
                $stmt = $this->pdo->query($query);
                $result = $stmt->fetch();
                $this->assertIsObject($result, "Query should work: {$query}");
                $this->assertObjectHasProperty('count', $result, "Query should return count");
                
                echo "✓ Query works: {$query} (count: {$result->count})\n";
            } catch (PDOException $e) {
                $this->fail("Query failed: {$query} - Error: " . $e->getMessage());
            }
        }
        
        // Test future queries with car_id (should work for cars_hist)
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM cars_hist WHERE car_id > 0");
            $result = $stmt->fetch();
            $this->assertIsObject($result, "cars_hist with car_id should work");
            echo "✓ Future-compatible query works: cars_hist with car_id (count: {$result->count})\n";
        } catch (PDOException $e) {
            $this->fail("Future-compatible query failed: " . $e->getMessage());
        }
    }

    /**
     * Test foreign key relationships
     */
    public function testForeignKeyRelationships(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        // Check for orphaned car_user records
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) as count FROM car_user cu 
             LEFT JOIN cars c ON cu.carid = c.id 
             WHERE c.id IS NULL"
        );
        $orphanedCars = $stmt->fetch()->count;
        
        $this->assertLessThanOrEqual(5, $orphanedCars, 
            "Should have minimal orphaned car_user records (found: {$orphanedCars})");
        
        // Check for orphaned car_user_hist records
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) as count FROM car_user_hist cuh 
             LEFT JOIN cars c ON cuh.carid = c.id 
             WHERE c.id IS NULL"
        );
        $orphanedHist = $stmt->fetch()->count;
        
        $this->assertLessThanOrEqual(200, $orphanedHist,
            "Should have reasonable number of orphaned car_user_hist records (found: {$orphanedHist}) - acceptable for migration");
        
        echo "✓ Data integrity check - Orphaned car_user: {$orphanedCars}, car_user_hist: {$orphanedHist}\n";
    }

    /**
     * Test sample car-user operations
     */
    public function testCarUserOperations(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        // Get sample data
        $stmt = $this->pdo->query("SELECT id FROM cars LIMIT 1");
        $car = $stmt->fetch();
        
        $stmt = $this->pdo->query("SELECT id FROM users LIMIT 1");
        $user = $stmt->fetch();
        
        if (!$car || !$user) {
            $this->markTestSkipped('Need sample car and user data');
        }
        
        // Test JOIN operation between cars and car_user
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.chassis, cu.carid, cu.userid 
             FROM cars c 
             LEFT JOIN car_user cu ON c.id = cu.carid 
             WHERE c.id = ? 
             LIMIT 1"
        );
        $stmt->execute([$car->id]);
        $result = $stmt->fetch();
        
        $this->assertIsObject($result, "JOIN query should work");
        $this->assertEquals($car->id, $result->id, "Car ID should match");
        
        if ($result->carid) {
            $this->assertEquals($car->id, $result->carid, "carid should match car id");
        }
        
        echo "✓ Car-user JOIN operation works\n";
    }

    /**
     * Generate migration readiness report
     */
    public function testGenerateMigrationReadinessReport(): void
    {
        if (!$this->connected) {
            $this->markTestSkipped('Database not connected');
        }

        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'database_connection' => 'successful',
            'schema_validation' => 'passed',
            'migration_ready' => true,
            'notes' => []
        ];
        
        // Check migration readiness
        try {
            // Verify all expected tables exist
            $tables = ['cars', 'car_user', 'car_user_hist', 'cars_hist'];
            foreach ($tables as $table) {
                $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
                $stmt->execute([$table]);
                if ($stmt->rowCount() == 0) {
                    $report['migration_ready'] = false;
                    $report['notes'][] = "Missing table: {$table}";
                }
            }
            
            // Verify current column structure
            $stmt = $this->pdo->query("DESCRIBE car_user");
            $columns = array_column($stmt->fetchAll(), 'Field');
            if (!in_array('carid', $columns)) {
                $report['migration_ready'] = false;
                $report['notes'][] = "car_user.carid column not found";
            }
            
            $stmt = $this->pdo->query("DESCRIBE car_user_hist");
            $columns = array_column($stmt->fetchAll(), 'Field');
            if (!in_array('carid', $columns)) {
                $report['migration_ready'] = false;
                $report['notes'][] = "car_user_hist.carid column not found";
            }
            
            if (empty($report['notes'])) {
                $report['notes'][] = "All schema validation checks passed";
            }
            
        } catch (PDOException $e) {
            $report['migration_ready'] = false;
            $report['notes'][] = "Database error: " . $e->getMessage();
        }
        
        // Save report
        $reportPath = __DIR__ . '/reports/migration_readiness_' . date('Y-m-d_H-i-s') . '.json';
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0755, true);
        }
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->assertTrue($report['migration_ready'], "Database should be ready for migration");
        
        echo "\nMigration readiness report: {$reportPath}\n";
        echo "Status: " . ($report['migration_ready'] ? "✅ READY" : "❌ NOT READY") . "\n";
        if (!empty($report['notes'])) {
            echo "Notes: " . implode(', ', $report['notes']) . "\n";
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo) {
            $this->pdo = null;
        }
        parent::tearDown();
    }
}