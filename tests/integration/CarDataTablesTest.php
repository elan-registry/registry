<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('integration')]
final class CarDataTablesTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

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
        $this->assertLessThanOrEqual($result['recordsTotal'], $result['recordsFiltered']);
    }

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
            $this->assertIsArray($result['data']);
        }
    }

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
        $this->assertLessThanOrEqual(5, count($result['data']));
    }

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

        // Invalid columns are skipped, not rejected.
        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
    }

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

        $result = $car->getDataTablesData($request, 'cars');

        $tableCheck = $this->db->query("SHOW TABLES LIKE 'cars'");
        $this->assertGreaterThan(0, $tableCheck->count());
    }

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
     * @param list<array<string, mixed>> $columns Column definitions (defaults to id column).
     *                                            Values are usually strings ('data', 'searchable',
     *                                            'orderable') but 'search' holds a nested
     *                                            array{value: string}, hence `mixed` not `string`.
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

    #[Group('fast')]
    public function testNegativeLengthParameter(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car();
        $car->getDataTablesData($this->buildDataTablesRequest(['length' => -1]), 'cars');
    }

    #[Group('fast')]
    public function testZeroLengthParameter(): void
    {
        $this->expectException(CarValidationException::class);

        $car = new Car();
        $car->getDataTablesData($this->buildDataTablesRequest(['length' => 0]), 'cars');
    }

    #[Group('fast')]
    public function testNonIntegerStartIsCastSafely(): void
    {
        $car = new Car();

        // 0 is what a non-numeric string casts to.
        $request = $this->buildDataTablesRequest(['start' => (int) 'abc']);

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('draw', $result);
        $this->assertArrayHasKey('recordsTotal', $result);
        $this->assertArrayHasKey('recordsFiltered', $result);
        $this->assertArrayHasKey('data', $result);
    }

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
     * Pins the explicit SELECT list that keeps owner PII out of the response (#1501).
     */
    #[Group('fast')]
    public function testCarDataTablesResponseExcludesPII(): void
    {
        $uniqueSuffix = substr(uniqid(), -8);
        $userId = $this->createTestUser([
            'email' => "pii_test_{$uniqueSuffix}@example.com",
            'lname' => "PIITestLname{$uniqueSuffix}",
        ]);
        $this->createTestCar($userId, [
            'email' => "pii_test_{$uniqueSuffix}@example.com",
            'lname' => "PIITestLname{$uniqueSuffix}",
            'fname' => "PIIFirst",
            'lat'   => 51.5074,
            'lon'   => -0.1278,
        ]);

        $car     = new Car();
        $request = $this->buildDataTablesRequest(
            ['length' => 100],
            [['data' => 'id', 'searchable' => 'true', 'orderable' => 'true']]
        );

        $result = $car->getDataTablesData($request, 'cars');

        $this->assertArrayHasKey('data', $result);
        $this->assertNotEmpty($result['data'], 'Data must contain the test car');

        foreach ($result['data'] as $row) {
            $rowArray = (array) $row;
            $this->assertArrayNotHasKey('email', $rowArray,
                'DataTables response must not expose email (PII)');
            $this->assertArrayNotHasKey('lname', $rowArray,
                'DataTables response must not expose lname (PII)');
            $this->assertArrayNotHasKey('vericode', $rowArray,
                'DataTables response must not expose vericode (internal field)');
            $this->assertArrayNotHasKey('last_verified', $rowArray,
                'DataTables response must not expose last_verified (internal field)');
            $this->assertArrayNotHasKey('user_id', $rowArray,
                'DataTables response must not expose user_id (#1501)');
            $this->assertArrayNotHasKey('lat', $rowArray,
                'DataTables response must not expose lat (#1501)');
            $this->assertArrayNotHasKey('lon', $rowArray,
                'DataTables response must not expose lon (#1501)');
        }
    }

    // Per-column search (#907)

    /**
     * A broken AND concatenation in $combinedWhere would silently return wrong rows.
     */
    #[Group('fast')]
    public function testPerColumnSeriesSearchFiltersResults(): void
    {
        $userId = $this->createTestUser();
        $this->createTestCar($userId, ['series' => 'S4']);
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
     * Pins the $searchWhere . ' ' . $columnWhere concatenation.
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
        $this->assertSame(1, count($result['data']),
            'Exactly one car must survive the combined filter (S4 + unique color)');

        foreach ($result['data'] as $row) {
            $this->assertSame('S4', $row->series,
                'Only the S4 car must survive the combined filter');
        }
    }

    /**
     * #1501: getHistory() backs app/api/cars/history.php, which any member can call.
     * Checks return values, so a SELECT * refactor is caught.
     */
    #[Group('fast')]
    public function testGetHistoryExcludesPIIFromReturnedRows(): void
    {
        $uniqueSuffix = substr(uniqid(), -8);
        $userId = $this->createTestUser([
            'email' => "hist_pii_{$uniqueSuffix}@example.com",
            'lname'  => "HistPII{$uniqueSuffix}",
        ]);
        $carId = $this->createTestCar($userId, ['chassis' => 'H' . substr($uniqueSuffix, -8)]);

        $this->db->insert('cars_hist', [
            'operation' => 'TEST',
            'car_id'    => $carId,
            'model'     => 'Elan',
            'series'    => 'S4',
            'variant'   => 'SE',
            'type'      => 'FHC',
            'chassis'   => 'H' . substr($uniqueSuffix, -8),
            'email'     => "hist_pii_{$uniqueSuffix}@example.com",
            'lname'     => "HistPII{$uniqueSuffix}",
            'user_id'   => $userId,
            'lat'       => 51.5074,
            'lon'       => -0.1278,
        ]);

        $repo    = new CarRepository($this->db);
        $history = $repo->getHistory($carId);

        $this->assertNotEmpty($history, 'History must contain the seeded row');

        foreach ($history as $row) {
            $rowArray = (array) $row;
            $this->assertArrayNotHasKey('email', $rowArray,
                'getHistory() rows must not expose email (PII)');
            $this->assertArrayNotHasKey('lname', $rowArray,
                'getHistory() rows must not expose lname (PII)');
            $this->assertArrayNotHasKey('user_id', $rowArray,
                'getHistory() rows must not expose user_id (#1501)');
            $this->assertArrayNotHasKey('lat', $rowArray,
                'getHistory() rows must not expose lat (#1501)');
            $this->assertArrayNotHasKey('lon', $rowArray,
                'getHistory() rows must not expose lon (#1501)');
        }
    }

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
