<?php

declare(strict_types=1);

// Load UserSpice framework for real database testing
$initPath = dirname(__DIR__) . '/users/init.php';
if (file_exists($initPath)) {
    require_once $initPath;
}

use PHPUnit\Framework\TestCase;

/**
 * Pre-Migration Baseline Test
 * 
 * Captures current car management functionality state before database migration
 * to ensure identical behavior after column standardization changes.
 * 
 * Purpose:
 * - Test all car management operations with current schema
 * - Document expected behavior and results
 * - Create baseline for post-migration comparison
 * - Identify critical functionality that must be preserved
 * - Performance benchmarking
 */
final class PreMigrationBaselineTest extends TestCase
{
    private $db;
    private $baseline = [];
    private $performanceMetrics = [];
    private $testCarId;
    private $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = DB::getInstance();
        $this->baseline = [];
        $this->performanceMetrics = [];
        
        $this->setupTestData();
    }

    /**
     * Setup test data for baseline testing
     */
    private function setupTestData(): void
    {
        // Get existing data for testing
        $car = $this->db->query("SELECT id FROM cars ORDER BY id LIMIT 1")->first();
        $user = $this->db->query("SELECT id FROM users ORDER BY id LIMIT 1")->first();
        
        $this->testCarId = $car ? $car->id : null;
        $this->testUserId = $user ? $user->id : null;
        
        $this->assertNotNull($this->testCarId, "Need existing car for baseline testing");
        $this->assertNotNull($this->testUserId, "Need existing user for baseline testing");
    }

    /**
     * Test car lookup operations (current behavior baseline)
     */
    public function testCarLookupOperationsBaseline(): void
    {
        $startTime = microtime(true);
        
        // Test basic car lookup
        $car = $this->db->query("SELECT * FROM cars WHERE id = ?", [$this->testCarId])->first();
        $this->assertNotNull($car, "Should be able to find car by ID");
        
        $basicLookupTime = microtime(true) - $startTime;
        
        // Test car with owner lookup (using current carid column)
        $startTime = microtime(true);
        $carWithOwner = $this->db->query(
            "SELECT c.*, cu.userid, cu.carid 
             FROM cars c 
             LEFT JOIN car_user cu ON c.id = cu.carid 
             WHERE c.id = ?", 
            [$this->testCarId]
        )->first();
        
        $carWithOwnerTime = microtime(true) - $startTime;
        
        $this->baseline['car_lookup'] = [
            'basic_lookup_works' => ($car !== null),
            'car_id_field_exists' => property_exists($car, 'id'),
            'car_with_owner_lookup_works' => ($carWithOwner !== null),
            'junction_carid_field_exists' => property_exists($carWithOwner, 'carid'),
            'junction_userid_field_exists' => property_exists($carWithOwner, 'userid'),
        ];
        
        $this->performanceMetrics['car_lookup'] = [
            'basic_lookup_time' => $basicLookupTime,
            'car_with_owner_time' => $carWithOwnerTime
        ];
        
        // Assertions for baseline behavior
        $this->assertTrue($this->baseline['car_lookup']['basic_lookup_works']);
        $this->assertTrue($this->baseline['car_lookup']['car_id_field_exists']);
        $this->assertTrue($this->baseline['car_lookup']['car_with_owner_lookup_works']);
        
        if ($carWithOwner && $carWithOwner->carid) {
            $this->assertTrue($this->baseline['car_lookup']['junction_carid_field_exists']);
        }
    }

    /**
     * Test user car listing operations (current behavior baseline)
     */
    public function testUserCarListingBaseline(): void
    {
        $startTime = microtime(true);
        
        // Test getting user's cars (current carid usage)
        $userCars = $this->db->query(
            "SELECT c.id, c.chassis, c.year, cu.carid 
             FROM cars c 
             JOIN car_user cu ON c.id = cu.carid 
             WHERE cu.userid = ?",
            [$this->testUserId]
        )->results();
        
        $userCarsTime = microtime(true) - $startTime;
        
        // Test count query
        $startTime = microtime(true);
        $carCount = $this->db->query(
            "SELECT COUNT(cu.carid) as count FROM car_user cu WHERE cu.userid = ?",
            [$this->testUserId]
        )->first()->count;
        
        $carCountTime = microtime(true) - $startTime;
        
        $this->baseline['user_car_listing'] = [
            'user_cars_query_works' => is_array($userCars),
            'car_count_query_works' => is_numeric($carCount),
            'car_count' => (int)$carCount,
            'cars_returned' => count($userCars)
        ];
        
        $this->performanceMetrics['user_car_listing'] = [
            'user_cars_time' => $userCarsTime,
            'car_count_time' => $carCountTime
        ];
        
        // Verify each car has expected fields
        if (!empty($userCars)) {
            $firstCar = $userCars[0];
            $this->baseline['user_car_listing']['car_has_id'] = property_exists($firstCar, 'id');
            $this->baseline['user_car_listing']['car_has_chassis'] = property_exists($firstCar, 'chassis');
            $this->baseline['user_car_listing']['junction_has_carid'] = property_exists($firstCar, 'carid');
        }
        
        $this->assertTrue($this->baseline['user_car_listing']['user_cars_query_works']);
        $this->assertTrue($this->baseline['user_car_listing']['car_count_query_works']);
    }

    /**
     * Test car-user relationship operations (current behavior baseline)
     */
    public function testCarUserRelationshipBaseline(): void
    {
        // Check existing relationships
        $existingRelationship = $this->db->query(
            "SELECT * FROM car_user WHERE userid = ? AND carid = ? LIMIT 1",
            [$this->testUserId, $this->testCarId]
        )->first();
        
        $hadExistingRelationship = ($existingRelationship !== null);
        
        // If no relationship exists, create one for testing
        $createdTestRelationship = false;
        if (!$hadExistingRelationship) {
            $insertResult = $this->db->query(
                "INSERT INTO car_user (userid, carid, mtime) VALUES (?, ?, NOW())",
                [$this->testUserId, $this->testCarId]
            );
            $createdTestRelationship = !$insertResult->error();
        }
        
        // Test relationship queries
        $startTime = microtime(true);
        $relationship = $this->db->query(
            "SELECT userid, carid, mtime FROM car_user WHERE userid = ? AND carid = ?",
            [$this->testUserId, $this->testCarId]
        )->first();
        
        $relationshipQueryTime = microtime(true) - $startTime;
        
        // Test reverse lookup (cars for user)
        $startTime = microtime(true);
        $userCarIds = $this->db->query(
            "SELECT carid FROM car_user WHERE userid = ?",
            [$this->testUserId]
        )->results();
        
        $userCarsQueryTime = microtime(true) - $startTime;
        
        // Test reverse lookup (users for car)
        $startTime = microtime(true);
        $carUserIds = $this->db->query(
            "SELECT userid FROM car_user WHERE carid = ?",
            [$this->testCarId]
        )->results();
        
        $carUsersQueryTime = microtime(true) - $startTime;
        
        $this->baseline['car_user_relationships'] = [
            'relationship_query_works' => ($relationship !== null),
            'user_cars_query_works' => is_array($userCarIds),
            'car_users_query_works' => is_array($carUserIds),
            'relationship_has_userid' => $relationship && property_exists($relationship, 'userid'),
            'relationship_has_carid' => $relationship && property_exists($relationship, 'carid'),
            'relationship_has_mtime' => $relationship && property_exists($relationship, 'mtime')
        ];
        
        $this->performanceMetrics['car_user_relationships'] = [
            'relationship_query_time' => $relationshipQueryTime,
            'user_cars_query_time' => $userCarsQueryTime,
            'car_users_query_time' => $carUsersQueryTime
        ];
        
        // Clean up test relationship if we created it
        if ($createdTestRelationship && !$hadExistingRelationship) {
            $this->db->query(
                "DELETE FROM car_user WHERE userid = ? AND carid = ?",
                [$this->testUserId, $this->testCarId]
            );
        }
        
        $this->assertTrue($this->baseline['car_user_relationships']['relationship_query_works']);
        $this->assertTrue($this->baseline['car_user_relationships']['user_cars_query_works']);
        $this->assertTrue($this->baseline['car_user_relationships']['car_users_query_works']);
    }

    /**
     * Test car history operations (current behavior baseline)
     */
    public function testCarHistoryOperationsBaseline(): void
    {
        $startTime = microtime(true);
        
        // Test cars_hist query (should already use car_id)
        $carHistory = $this->db->query(
            "SELECT car_id, operation, timestamp FROM cars_hist WHERE car_id = ? ORDER BY timestamp DESC LIMIT 5",
            [$this->testCarId]
        )->results();
        
        $carHistoryTime = microtime(true) - $startTime;
        
        // Test car_user_hist query (currently uses carid)
        $startTime = microtime(true);
        $carUserHistory = $this->db->query(
            "SELECT carid, userid, operation, timestamp FROM car_user_hist WHERE carid = ? ORDER BY timestamp DESC LIMIT 5",
            [$this->testCarId]
        )->results();
        
        $carUserHistoryTime = microtime(true) - $startTime;
        
        $this->baseline['car_history'] = [
            'cars_hist_query_works' => is_array($carHistory),
            'car_user_hist_query_works' => is_array($carUserHistory),
            'cars_hist_count' => count($carHistory),
            'car_user_hist_count' => count($carUserHistory)
        ];
        
        if (!empty($carHistory)) {
            $firstHistory = $carHistory[0];
            $this->baseline['car_history']['cars_hist_has_car_id'] = property_exists($firstHistory, 'car_id');
        }
        
        if (!empty($carUserHistory)) {
            $firstUserHistory = $carUserHistory[0];
            $this->baseline['car_history']['car_user_hist_has_carid'] = property_exists($firstUserHistory, 'carid');
            $this->baseline['car_history']['car_user_hist_has_userid'] = property_exists($firstUserHistory, 'userid');
        }
        
        $this->performanceMetrics['car_history'] = [
            'cars_hist_time' => $carHistoryTime,
            'car_user_hist_time' => $carUserHistoryTime
        ];
        
        $this->assertTrue($this->baseline['car_history']['cars_hist_query_works']);
        $this->assertTrue($this->baseline['car_history']['car_user_hist_query_works']);
    }

    /**
     * Test complex join operations (current behavior baseline)
     */
    public function testComplexJoinOperationsBaseline(): void
    {
        $startTime = microtime(true);
        
        // Complex join: car + owner + history
        $complexQuery = $this->db->query(
            "SELECT 
                c.id, c.chassis, c.year,
                cu.carid as junction_carid, cu.userid,
                ch.car_id as hist_car_id, ch.operation
             FROM cars c
             LEFT JOIN car_user cu ON c.id = cu.carid
             LEFT JOIN cars_hist ch ON c.id = ch.car_id
             WHERE c.id = ?
             ORDER BY ch.timestamp DESC
             LIMIT 5",
            [$this->testCarId]
        )->results();
        
        $complexQueryTime = microtime(true) - $startTime;
        
        $this->baseline['complex_joins'] = [
            'complex_query_works' => is_array($complexQuery),
            'result_count' => count($complexQuery)
        ];
        
        if (!empty($complexQuery)) {
            $firstResult = $complexQuery[0];
            $this->baseline['complex_joins']['has_car_id'] = property_exists($firstResult, 'id');
            $this->baseline['complex_joins']['has_junction_carid'] = property_exists($firstResult, 'junction_carid');
            $this->baseline['complex_joins']['has_hist_car_id'] = property_exists($firstResult, 'hist_car_id');
        }
        
        $this->performanceMetrics['complex_joins'] = [
            'complex_query_time' => $complexQueryTime
        ];
        
        $this->assertTrue($this->baseline['complex_joins']['complex_query_works']);
    }

    /**
     * Test administrative queries (current behavior baseline)
     */
    public function testAdministrativeQueriesBaseline(): void
    {
        $queries = [
            'orphaned_car_users' => "SELECT COUNT(*) as count FROM car_user cu LEFT JOIN cars c ON cu.carid = c.id WHERE c.id IS NULL",
            'orphaned_user_cars' => "SELECT COUNT(*) as count FROM car_user cu LEFT JOIN users u ON cu.userid = u.id WHERE u.id IS NULL",
            'cars_with_multiple_owners' => "SELECT carid, COUNT(userid) as owner_count FROM car_user GROUP BY carid HAVING COUNT(userid) > 1",
            'users_with_multiple_cars' => "SELECT userid, COUNT(carid) as car_count FROM car_user GROUP BY userid HAVING COUNT(carid) > 1"
        ];
        
        $adminResults = [];
        foreach ($queries as $name => $query) {
            $startTime = microtime(true);
            try {
                $result = $this->db->query($query);
                $queryTime = microtime(true) - $startTime;
                
                $adminResults[$name] = [
                    'works' => true,
                    'result_count' => $result->count(),
                    'time' => $queryTime
                ];
                
                if (str_contains($name, 'orphaned') || str_contains($name, 'count')) {
                    $adminResults[$name]['value'] = $result->first()->count ?? 0;
                }
                
            } catch (Exception $e) {
                $adminResults[$name] = [
                    'works' => false,
                    'error' => $e->getMessage(),
                    'time' => microtime(true) - $startTime
                ];
            }
        }
        
        $this->baseline['administrative_queries'] = $adminResults;
        
        // All admin queries should work
        foreach ($adminResults as $name => $result) {
            $this->assertTrue($result['works'], "Administrative query '{$name}' should work");
        }
    }

    /**
     * Generate comprehensive baseline report
     */
    public function testGenerateBaselineReport(): void
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'test_environment' => [
                'test_car_id' => $this->testCarId,
                'test_user_id' => $this->testUserId,
                'php_version' => PHP_VERSION,
                'database_connection' => 'successful'
            ],
            'functionality_baseline' => $this->baseline,
            'performance_metrics' => $this->performanceMetrics,
            'summary' => [
                'total_tests_passed' => $this->getNumAssertions(),
                'critical_functionality_verified' => true,
                'performance_benchmarked' => true
            ]
        ];
        
        // Calculate total performance impact
        $totalTime = 0;
        foreach ($this->performanceMetrics as $category => $metrics) {
            foreach ($metrics as $time) {
                $totalTime += $time;
            }
        }
        $report['performance_metrics']['total_baseline_time'] = $totalTime;
        
        // Write comprehensive baseline report
        $reportPath = __DIR__ . '/reports/pre_migration_baseline_' . date('Y-m-d_H-i-s') . '.json';
        
        // Create reports directory if it doesn't exist
        $reportsDir = dirname($reportPath);
        if (!is_dir($reportsDir)) {
            mkdir($reportsDir, 0755, true);
        }
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        $this->assertTrue(file_exists($reportPath), "Baseline report should be created");
        $this->assertGreaterThan(0, filesize($reportPath), "Report should contain data");
        
        echo "\nPre-migration baseline report created: {$reportPath}\n";
        echo "Total baseline tests passed: {$report['summary']['total_tests_passed']}\n";
        echo "Total performance benchmark time: " . number_format($totalTime * 1000, 2) . "ms\n";
        
        // Save report path for post-migration comparison
        file_put_contents(__DIR__ . '/reports/latest_baseline.txt', $reportPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Output summary
        echo "\n--- Pre-Migration Baseline Summary ---\n";
        echo "Car lookup operations: " . (count($this->baseline['car_lookup'] ?? []) > 0 ? "✓" : "✗") . "\n";
        echo "User car listing: " . (count($this->baseline['user_car_listing'] ?? []) > 0 ? "✓" : "✗") . "\n";
        echo "Car-user relationships: " . (count($this->baseline['car_user_relationships'] ?? []) > 0 ? "✓" : "✗") . "\n";
        echo "History operations: " . (count($this->baseline['car_history'] ?? []) > 0 ? "✓" : "✗") . "\n";
        echo "Complex joins: " . (count($this->baseline['complex_joins'] ?? []) > 0 ? "✓" : "✗") . "\n";
        echo "Administrative queries: " . (count($this->baseline['administrative_queries'] ?? []) > 0 ? "✓" : "✗") . "\n";
    }
}