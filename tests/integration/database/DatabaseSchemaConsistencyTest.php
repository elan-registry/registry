<?php

declare(strict_types=1);

// Direct PDO connection for database testing

use PHPUnit\Framework\TestCase;

/**
 * Database Schema Consistency Test
 * 
 * Validates current column naming patterns and foreign key relationships
 * before implementing database column standardization changes.
 * 
 * Purpose:
 * - Check carid vs car_id usage across all car-related tables
 * - Validate foreign key constraints
 * - Document current schema state for migration baseline
 */
final class DatabaseSchemaConsistencyTest extends TestCase
{
    private $db;
    private $schemaReport = [];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            $this->db = new PDO(
                'mysql:unix_socket=/Applications/MAMP/tmp/mysql/mysql.sock;dbname=elanregi_spice;charset=utf8',
                'claude',
                'claude',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('Database connection failed: ' . $e->getMessage());
        }
        $this->schemaReport = [];
    }

    /**
     * Test that all car-related tables exist and have expected structure
     */
    public function testCarRelatedTablesExist(): void
    {
        $expectedTables = ['cars', 'cars_hist', 'car_user', 'car_user_hist'];
        
        foreach ($expectedTables as $table) {
            $stmt = $this->db->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            $this->assertTrue($stmt->rowCount() > 0, "Table '{$table}' should exist");
        }
    }

    /**
     * Test current column naming patterns in car-related tables
     */
    public function testColumnNamingPatterns(): void
    {
        // Check cars table (should use 'id')
        $carsColumns = $this->getTableColumns('cars');
        $this->assertArrayHasKey('id', $carsColumns, "Cars table should have 'id' column");
        $this->assertArrayNotHasKey('carid', $carsColumns, "Cars table should not have 'carid' column");
        
        // Check car_user table (currently uses 'carid' - this is what we want to change)
        $carUserColumns = $this->getTableColumns('car_user');
        $this->assertArrayHasKey('carid', $carUserColumns, "car_user table currently uses 'carid' column");
        $this->assertArrayNotHasKey('car_id', $carUserColumns, "car_user table should not have 'car_id' column yet");
        
        // Check car_user_hist table (currently uses 'carid')
        $carUserHistColumns = $this->getTableColumns('car_user_hist');
        $this->assertArrayHasKey('carid', $carUserHistColumns, "car_user_hist table currently uses 'carid' column");
        $this->assertArrayNotHasKey('car_id', $carUserHistColumns, "car_user_hist table should not have 'car_id' column yet");
        
        // Check cars_hist table (should already use 'car_id')
        $carsHistColumns = $this->getTableColumns('cars_hist');
        $this->assertArrayHasKey('car_id', $carsHistColumns, "cars_hist table should use 'car_id' column");
        
        // Document findings
        $this->schemaReport['column_patterns'] = [
            'cars' => 'Uses id (standard)',
            'car_user' => 'Uses carid (needs standardization)', 
            'car_user_hist' => 'Uses carid (needs standardization)',
            'cars_hist' => 'Uses car_id (already standardized)'
        ];
    }

    /**
     * Test foreign key relationships and constraints
     */
    public function testForeignKeyRelationships(): void
    {
        // Test that car_user.carid references existing cars
        $orphanedCarUsers = $this->db->query(
            "SELECT cu.id, cu.carid FROM car_user cu 
             LEFT JOIN cars c ON cu.carid = c.id 
             WHERE c.id IS NULL LIMIT 5"
        );
        
        $this->assertLessThanOrEqual(0, $orphanedCarUsers->count(), 
            "Should have no orphaned car_user records");
        
        // Test that car_user_hist.carid references existing cars
        $orphanedCarUserHist = $this->db->query(
            "SELECT cuh.id, cuh.carid FROM car_user_hist cuh 
             LEFT JOIN cars c ON cuh.carid = c.id 
             WHERE c.id IS NULL LIMIT 5"
        );
        
        $this->assertLessThanOrEqual(0, $orphanedCarUserHist->count(),
            "Should have no orphaned car_user_hist records");
        
        // Test that cars_hist.car_id references existing cars
        $orphanedCarsHist = $this->db->query(
            "SELECT ch.id, ch.car_id FROM cars_hist ch 
             LEFT JOIN cars c ON ch.car_id = c.id 
             WHERE c.id IS NULL LIMIT 5"
        );
        
        $this->assertLessThanOrEqual(0, $orphanedCarsHist->count(),
            "Should have no orphaned cars_hist records");
    }

    /**
     * Test data integrity of car-user relationships
     */
    public function testCarUserDataIntegrity(): void
    {
        // Count records in each table
        $carCount = $this->db->query("SELECT COUNT(*) as count FROM cars")->first()->count;
        $carUserCount = $this->db->query("SELECT COUNT(*) as count FROM car_user")->first()->count;
        $carUserHistCount = $this->db->query("SELECT COUNT(*) as count FROM car_user_hist")->first()->count;
        
        $this->assertGreaterThan(0, $carCount, "Should have car records for testing");
        $this->assertGreaterThanOrEqual(0, $carUserCount, "car_user table should exist and be accessible");
        $this->assertGreaterThanOrEqual(0, $carUserHistCount, "car_user_hist table should exist and be accessible");
        
        // Test sample queries that will be affected by column rename
        if ($carUserCount > 0) {
            $sampleCarUser = $this->db->query("SELECT carid, userid FROM car_user LIMIT 1")->first();
            $this->assertIsObject($sampleCarUser, "Should be able to query car_user with 'carid'");
            $this->assertObjectHasProperty('carid', $sampleCarUser, "car_user record should have 'carid' property");
        }

        $this->schemaReport['data_counts'] = [
            'cars' => $carCount,
            'car_user' => $carUserCount,
            'car_user_hist' => $carUserHistCount
        ];
    }

    /**
     * Test current query patterns that reference carid
     */
    public function testCurrentQueryPatterns(): void
    {
        // Test queries that currently work with carid
        $queryTests = [
            "SELECT COUNT(*) as count FROM car_user WHERE carid > 0",
            "SELECT COUNT(*) as count FROM car_user_hist WHERE carid > 0"
        ];
        
        foreach ($queryTests as $query) {
            try {
                $result = $this->db->query($query);
                $this->assertNotNull($result, "Query should execute: {$query}");
            } catch (Exception $e) {
                $this->fail("Query failed: {$query} - Error: " . $e->getMessage());
            }
        }
        
        // Test queries that should work after migration (with car_id)
        $futureQueryTests = [
            "SELECT COUNT(*) as count FROM cars_hist WHERE car_id > 0" // This should already work
        ];
        
        foreach ($futureQueryTests as $query) {
            try {
                $result = $this->db->query($query);
                $this->assertNotNull($result, "Future-compatible query should execute: {$query}");
            } catch (Exception $e) {
                $this->fail("Future-compatible query failed: {$query} - Error: " . $e->getMessage());
            }
        }
    }

    /**
     * Generate schema baseline report for migration reference
     */
    public function testGenerateBaselineReport(): void
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'tables_analyzed' => ['cars', 'car_user', 'car_user_hist', 'cars_hist'],
            'column_patterns' => $this->schemaReport['column_patterns'] ?? [],
            'data_counts' => $this->schemaReport['data_counts'] ?? [],
            'migration_targets' => [
                'car_user.carid' => 'car_user.car_id',
                'car_user_hist.carid' => 'car_user_hist.car_id'
            ]
        ];
        
        // Write report to file for reference
        $reportPath = __DIR__ . '/reports/schema_baseline_' . date('Y-m-d_H-i-s') . '.json';
        
        // Create reports directory if it doesn't exist
        $reportsDir = dirname($reportPath);
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->assertTrue(file_exists($reportPath), "Baseline report should be created");
        $this->assertGreaterThan(0, filesize($reportPath), "Report should contain data");
        
        echo "\nSchema baseline report created: {$reportPath}\n";
    }

    /**
     * Helper method to get table columns
     */
    private function getTableColumns(string $tableName): array
    {
        $result = $this->db->query("DESCRIBE {$tableName}");
        $columns = [];
        
        foreach ($result->results() as $column) {
            $columns[$column->Field] = [
                'type' => $column->Type,
                'null' => $column->Null,
                'key' => $column->Key,
                'default' => $column->Default
            ];
        }
        
        return $columns;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Output summary for debugging
        if (!empty($this->schemaReport)) {
            echo "\n--- Schema Analysis Summary ---\n";
            echo json_encode($this->schemaReport, JSON_PRETTY_PRINT) . "\n";
        }
    }
}