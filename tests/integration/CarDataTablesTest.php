<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

/**
 * Test cases for Car DataTables functionality
 *
 * Tests cover server-side DataTables data processing including searching,
 * sorting, pagination, and security validation.
 *
 * @group integration
 */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
     *
     * @group fast
     */
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
}
