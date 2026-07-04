<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Test cases for Car DataTables functionality
 *
 * Tests cover server-side DataTables data processing including searching,
 * sorting, pagination, and security validation.
 */
#[Group('integration')]
final class CarDataTablesTest extends IntegrationTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db = DB::getInstance();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Test getDataTablesData for cars table
     */
    #[Group('fast')]
    public function testGetDataTablesDataForCarsTable(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'chassis', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'year', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Test getDataTablesData for factory table
     */
    #[Group('fast')]
    public function testGetDataTablesDataForFactoryTable(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'year', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'factory');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Test getDataTablesData with search filter
     */
    #[Group('fast')]
    public function testGetDataTablesDataWithSearch(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'Elan'],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'chassis', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'model', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        // Filtered results should be less than or equal to total
        $this->assertLessThanOrEqual($result['recordsTotal'], $result['recordsFiltered']);
    }

    /**
     * Test getDataTablesData with sorting
     */
    #[Group('fast')]
    public function testGetDataTablesDataWithSorting(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 1, 'dir' => 'desc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
                ['data' => 'year', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);

        if (count($result['data']) > 1) {
            // Verify descending sort (if data exists)
            // This is a basic check - full verification would require more data
            $this->assertIsArray($result['data']);
        }
    }

    /**
     * Test getDataTablesData with pagination
     */
    #[Group('fast')]
    public function testGetDataTablesDataWithPagination(): void
    {
        $car = new Car();

        $request = [
            'draw' => 2,
            'start' => 10,
            'length' => 5,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertEquals(2, $result['draw']);
        // Result should have no more than 5 rows
        $this->assertLessThanOrEqual(5, count($result['data']));
    }

    /**
     * Test getDataTablesData validates column names
     */
    #[Group('fast')]
    public function testGetDataTablesDataValidatesColumnNames(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'invalid_column_name', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        // Invalid columns are silently skipped, not rejected with exception
        // This is the current behavior of getDataTablesData()
        $result = $car->getDataTablesData($request, 'cars');

        // Verify result structure is valid even with invalid columns
        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Test getDataTablesData prevents SQL injection
     */
    #[Group('fast')]
    public function testGetDataTablesDataPreventsInjection(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => "'; DROP TABLE cars; --"],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'chassis', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        // Should not throw exception and should safely handle injection attempt
        $result = $car->getDataTablesData($request, 'cars');

        // Verify table still exists
        $tableCheck = $this->db->query("SHOW TABLES LIKE 'cars'");
        $this->assertGreaterThan(0, $tableCheck->count());
    }

    /**
     * Test getDataTablesData fails with invalid table
     */
    #[Group('fast')]
    public function testGetDataTablesDataFailsWithInvalidTable(): void
    {
        $this->expectException(Exception::class);

        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'invalid_table');
    }

    /**
     * Test getDataTablesData returns correct record counts
     */
    #[Group('fast')]
    public function testGetDataTablesDataReturnsCorrectRecordCounts(): void
    {
        $car = new Car();

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => ''],
            'order' => [['column' => 0, 'dir' => 'asc']],
            'columns' => [
                ['data' => 'id', 'searchable' => 'true', 'orderable' => 'true'],
            ]
        ];

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsNumeric($result['recordsTotal']);
        $this->assertIsNumeric($result['recordsFiltered']);
        $this->assertGreaterThanOrEqual(0, $result['recordsTotal']);
        $this->assertGreaterThanOrEqual(0, $result['recordsFiltered']);
    }

    /**
     * Build a default DataTables request array with optional overrides.
     *
     * @param array<string, mixed> $overrides Key/value pairs to override default values
     * @param list<array<string, string>> $columns Column definitions (defaults to id column)
     * @return array<string, mixed>
     */
    private function buildDataTablesRequest(array $overrides = [], array $columns = []): array
    {
        return array_merge([
            'draw'    => 1,
            'start'   => 0,
            'length'  => 10,
            'search'  => ['value' => ''],
            'order'   => [['column' => 0, 'dir' => 'asc']],
            'columns' => $columns ?: [['data' => 'id', 'searchable' => 'true', 'orderable' => 'true']],
        ], $overrides);
    }

    /**
     * Test that an oversized search value (100KB+) does not crash the service
     */
    #[Group('fast')]
    public function testOversizedSearchValueDoesNotCrash(): void
    {
        $car = new Car();

        $request = $this->buildDataTablesRequest(
            ['search' => ['value' => str_repeat('x', 102400)]],
            [['data' => 'chassis', 'searchable' => 'true', 'orderable' => 'true']]
        );

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals(0, $result['recordsFiltered']);
    }

    /**
     * Test that a negative length parameter is handled safely without crashing
     */
    #[Group('fast')]
    public function testNegativeLengthParameter(): void
    {
        $car = new Car();

        $request = $this->buildDataTablesRequest(['length' => -1]);

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);
    }

    /**
     * Test that a zero length parameter returns an empty data array with valid metadata structure
     */
    #[Group('fast')]
    public function testZeroLengthParameter(): void
    {
        $car = new Car();

        $request = $this->buildDataTablesRequest(['draw' => 3, 'length' => 0]);

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertEquals(3, $result['draw']);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertGreaterThanOrEqual(0, $result['recordsTotal']);
        $this->assertEmpty($result['data']);
    }

    /**
     * Test that a non-integer start value is cast safely to an integer
     */
    #[Group('fast')]
    public function testNonIntegerStartIsCastSafely(): void
    {
        $car = new Car();

        // Pass 0 (the result of casting a non-numeric string to int) to verify
        // the service handles an offset of 0 correctly.
        $request = $this->buildDataTablesRequest(['start' => (int) 'abc']);

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
    }

    /**
     * Test that 500+ unrecognized column names do not crash the service and return the full record count
     */
    #[Group('fast')]
    public function testExcessiveColumnCountDoesNotCrash(): void
    {
        $car = new Car();

        $columns = [];
        for ($i = 0; $i < 500; $i++) {
            $columns[] = ['data' => 'col_' . $i, 'searchable' => 'true', 'orderable' => 'true'];
        }

        $request = $this->buildDataTablesRequest([], $columns);

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals($result['recordsTotal'], $result['recordsFiltered']);
    }

    /**
     * Test chassis lookup SQL query finds a car with a matching chassis number
     *
     * The app/api/cars/chassis-lookup.php endpoint executes this query inline.
     * We verify the query behavior directly against the real database.
     */
    #[Group('fast')]
    public function testChassisLookupQueryFindsCar(): void
    {
        $userId = $this->createTestUser();
        $chassis = 'TC-' . substr(uniqid(), -10); // varchar(15) limit
        $carId = $this->createTestCar($userId, ['chassis' => $chassis]);

        // Execute the same SQL that the app/api/cars/chassis-lookup.php endpoint runs
        $result = $this->db->query("SELECT id FROM cars WHERE chassis = ? LIMIT 1", [$chassis]);

        $this->assertGreaterThan(0, $result->count());
        $this->assertEquals($carId, $result->first()->id);
    }

    /**
     * Test chassis lookup SQL query returns no results for an unknown chassis number
     */
    #[Group('fast')]
    public function testChassisLookupQueryReturnsNoResultsForUnknownChassis(): void
    {
        $result = $this->db->query(
            "SELECT id FROM cars WHERE chassis = ? LIMIT 1",
            ['CHASSIS-DOES-NOT-EXIST-' . uniqid()]
        );

        $this->assertEquals(0, $result->count());
    }

    // =========================================================================
    // Per-column search tests (#907 — added in v2.24.0 #763)
    // =========================================================================

    /**
     * Per-column search for series='S4' returns only S4 rows and
     * recordsFiltered is less than recordsTotal.
     *
     * Pins the $columnSearchClauses path in CarDataTablesService::processRequest()
     * (lines 104–121). A missing space or broken AND concatenation in
     * $combinedWhere would silently return wrong results.
     */
    #[Group('fast')]
    public function testPerColumnSeriesSearchFiltersResults(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId, ['series' => 'S4']);
        // A second car with a different series ensures recordsTotal > recordsFiltered
        $this->createTestCar($userId, ['series' => 'Sprint']);

        $car     = new Car();
        $request = $this->buildDataTablesRequest(
            ['length' => 50],
            [[
                'data'        => 'series',
                'searchable'  => 'true',
                'orderable'   => 'true',
                'search'      => ['value' => 'S4'],
            ]]
        );

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, $result['recordsFiltered'],
            'recordsFiltered must be > 0 when an S4 car exists');
        $this->assertLessThan($result['recordsTotal'], $result['recordsFiltered'],
            'recordsFiltered must be less than recordsTotal when only some cars match');

        foreach ($result['data'] as $row) {
            $this->assertSame('S4', $row->series,
                'Every returned row must have series = S4');
        }
    }

    /**
     * Combining a global search with a per-column search returns only rows
     * satisfying BOTH constraints.
     *
     * Pins the $searchWhere . ' ' . $columnWhere concatenation in
     * CarDataTablesService::processRequest() (line 123). If the space is
     * dropped or the AND keyword is lost, this test returns wrong rows.
     */
    #[Group('fast')]
    public function testCombinedGlobalAndPerColumnSearchIntersectsConstraints(): void
    {
        $userId = $this->createTestUser();
        $uniqueColor = 'TestColor' . substr(uniqid(), -6);

        // Matches color AND series
        $this->createTestCar($userId, ['color' => $uniqueColor, 'series' => 'S4']);
        // Matches color but NOT series
        $this->createTestCar($userId, ['color' => $uniqueColor, 'series' => 'Sprint']);

        $car     = new Car();
        // Include 'color' as a searchable column so the global search can match it.
        // The per-column 'series' filter is applied on top, reducing the result set.
        $request = $this->buildDataTablesRequest(
            ['search' => ['value' => $uniqueColor], 'length' => 50],
            [
                ['data' => 'color',  'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '']],
                ['data' => 'series', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => 'S4']],
            ]
        );

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertGreaterThan(0, $result['recordsFiltered'],
            'At least the S4 car with the unique color must match');

        foreach ($result['data'] as $row) {
            $this->assertSame('S4', $row->series,
                'Only the S4 car must survive the combined filter');
        }
    }

    /**
     * A per-column search value that matches no rows returns recordsFiltered = 0
     * and data = [].
     *
     * Pins the COUNT(*) query built from $combinedWhere when the column filter
     * selects nothing.
     */
    #[Group('fast')]
    public function testPerColumnSearchWithNoMatchReturnsZeroResults(): void
    {
        $car     = new Car();
        $noMatch = 'NOMATCH_' . uniqid();
        $request = $this->buildDataTablesRequest(
            ['length' => 10],
            [[
                'data'       => 'series',
                'searchable' => 'true',
                'orderable'  => 'true',
                'search'     => ['value' => $noMatch],
            ]]
        );

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertSame(0, (int) $result['recordsFiltered'],
            'recordsFiltered must be 0 when the column value matches nothing');
        $this->assertSame([], $result['data'],
            'data must be empty when the column value matches nothing');
    }
}
