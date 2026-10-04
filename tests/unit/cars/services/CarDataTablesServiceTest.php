<?php

declare(strict_types=1);

use ElanRegistry\Car\CarDataTablesService;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\CarValidationException;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for CarDataTablesService service class
 */
#[Group('fast')]
final class CarDataTablesServiceTest extends TestCase
{
    private CarDataTablesService $service;

    protected function setUp(): void
    {
        // Column validation and the request-validation guards run before any query,
        // so an unconfigured double is enough for those tests.
        $this->service = new CarDataTablesService($this->createStub(DatabaseInterface::class));
    }

    /**
     * Invoke the private validateColumnName() method via reflection.
     */
    private function invokeValidateColumnName(string $columnName, string $tableName): string|false
    {
        $method = new ReflectionMethod(CarDataTablesService::class, 'validateColumnName');
        return $method->invoke($this->service, $columnName, $tableName);
    }

    // ============================================================
    // validateColumnName tests
    // ============================================================

    public function testValidateColumnNameAcceptsValidCarsColumn(): void
    {
        $result = $this->invokeValidateColumnName('chassis', 'cars');
        $this->assertEquals('chassis', $result);
    }

    public function testValidateColumnNameAcceptsValidFactoryColumn(): void
    {
        $result = $this->invokeValidateColumnName('serial', 'elan_factory_info');
        $this->assertEquals('serial', $result);
    }

    public function testValidateColumnNameRejectsInvalidColumn(): void
    {
        $result = $this->invokeValidateColumnName('password', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsInvalidTable(): void
    {
        $result = $this->invokeValidateColumnName('id', 'users');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsSqlInjection(): void
    {
        $result = $this->invokeValidateColumnName('id; DROP TABLE cars', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsRemovedModifiedByColumn(): void
    {
        $result = $this->invokeValidateColumnName('ModifiedBy', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsEmail(): void
    {
        $result = $this->invokeValidateColumnName('email', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLname(): void
    {
        $result = $this->invokeValidateColumnName('lname', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsVericode(): void
    {
        $result = $this->invokeValidateColumnName('vericode', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLastVerified(): void
    {
        $result = $this->invokeValidateColumnName('last_verified', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsUserId(): void
    {
        $result = $this->invokeValidateColumnName('user_id', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLat(): void
    {
        $result = $this->invokeValidateColumnName('lat', 'cars');
        $this->assertFalse($result);
    }

    public function testValidateColumnNameRejectsLon(): void
    {
        $result = $this->invokeValidateColumnName('lon', 'cars');
        $this->assertFalse($result);
    }

    // ============================================================
    // getDataTablesData tests
    // ============================================================

    public function testGetDataTablesDataThrowsOnInvalidTable(): void
    {
        $this->expectException(CarValidationException::class);

        $request = ['draw' => 1, 'start' => 0, 'length' => 10];
        $this->service->getDataTablesData($request, 'invalid_table');
    }

    // ============================================================
    // getDataTablesData — invalid pagination
    // ============================================================

    /**
     * @return array<string, array{int, int}>
     */
    public static function invalidPaginationProvider(): array
    {
        return [
            'length zero'          => [0,    0],
            'length negative one'  => [0,   -1],
            'length negative two'  => [0,   -2],
            'length negative 99'   => [0,  -99],
            'length over cap'      => [0,  101],
            'length way over cap'  => [0, 9999],
            'negative start'       => [-1,  25],
            'large negative start' => [-100, 25],
        ];
    }

    #[DataProvider('invalidPaginationProvider')]
    public function testGetDataTablesDataThrowsOnInvalidPagination(int $start, int $length): void
    {
        $this->expectException(CarValidationException::class);

        $request = ['draw' => 1, 'start' => $start, 'length' => $length, 'search' => ['value' => ''], 'order' => [], 'columns' => []];
        $this->service->getDataTablesData($request, 'cars');
    }

    // ============================================================
    // getDataTablesData — valid pagination passes validation
    // ============================================================

    /**
     * @return array<string, array{int, int}>
     */
    public static function validPaginationProvider(): array
    {
        return [
            'minimum length (start=0, length=1)'  => [0,    1],
            'first page (start=0, length=25)'      => [0,   25],
            'maximum length (start=50, length=100)' => [50, 100],
        ];
    }

    #[DataProvider('validPaginationProvider')]
    public function testGetDataTablesDataAcceptsValidPagination(int $start, int $length): void
    {
        // getDataTablesData() calls query() twice when search/columns are empty: COUNT(*), then the data SELECT.
        // query() always returns the database instance itself; the simulated result set is
        // expressed through first()/results() on the same double.
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('first')->willReturn((object) ['count' => 5]);
        $db->method('results')->willReturn([]);
        $db->method('error')->willReturn(false);

        $service = new CarDataTablesService($db);

        $request = ['draw' => 1, 'start' => $start, 'length' => $length, 'search' => ['value' => ''], 'order' => [], 'columns' => []];
        $result = $service->getDataTablesData($request, 'cars');

        $this->assertSame(1, $result['draw']);
        $this->assertSame(5, $result['recordsTotal']);
        $this->assertSame(5, $result['recordsFiltered']);
        $this->assertSame([], $result['data']);
    }

    // ============================================================
    // getDataTablesData — filtered-count branch and is_object() guards
    // ============================================================

    public function testGetDataTablesDataExercisesFilteredCountBranch(): void
    {
        // A non-empty search.value plus a searchable column makes $combinedWhere
        // non-empty, entering the filtered-count block that
        // testGetDataTablesDataAcceptsValidPagination() never reaches. first() is
        // called twice here (total count, then filtered count); willReturnOnConsecutiveCalls()
        // sequences the two return values.
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('first')->willReturnOnConsecutiveCalls((object) ['count' => 42], (object) ['count' => 7]);
        $db->method('results')->willReturn([]);
        $db->method('error')->willReturn(false);

        $service = new CarDataTablesService($db);

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'search' => ['value' => 'foo'],
            'order' => [],
            'columns' => [
                ['data' => 'chassis', 'searchable' => 'true'],
            ],
        ];
        $result = $service->getDataTablesData($request, 'cars');

        $this->assertSame(42, $result['recordsTotal']);
        $this->assertSame(7, $result['recordsFiltered']);
    }

    public function testGetDataTablesDataThrowsOnNonObjectTotalCountRow(): void
    {
        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('DataTables total count query returned no rows');

        // first() returning [] (DatabaseInterface's no-rows shape) instead of a
        // stdClass must trip the is_object($countRow) guard.
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('first')->willReturn([]);
        $db->method('error')->willReturn(false);

        $service = new CarDataTablesService($db);

        $request = ['draw' => 1, 'start' => 0, 'length' => 10, 'search' => ['value' => ''], 'order' => [], 'columns' => []];
        $service->getDataTablesData($request, 'cars');
    }

    public function testGetDataTablesDataThrowsOnNonObjectFilteredCountRow(): void
    {
        $this->expectException(CarDatabaseException::class);
        $this->expectExceptionMessage('DataTables filtered count query returned no rows');

        // Total count succeeds (stdClass) so the filtered branch is reached; filtered
        // count then returns [] (DatabaseInterface's no-rows shape), tripping the
        // is_object($filterRow) guard.
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnSelf();
        $db->method('first')->willReturnOnConsecutiveCalls((object) ['count' => 5], []);
        $db->method('error')->willReturn(false);

        $service = new CarDataTablesService($db);

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'foo'],
            'order' => [],
            'columns' => [
                ['data' => 'chassis', 'searchable' => 'true'],
            ],
        ];
        $service->getDataTablesData($request, 'cars');
    }

    // ============================================================
    // getDataTablesData — SELECT list (is_fresh, #1900)
    // ============================================================

    /**
     * Run getDataTablesData() with a sort and a search, and return every SQL
     * string passed to query(), in call order.
     *
     * @return list<string>
     */
    private function captureQueries(string $table, string $column): array
    {
        $queries = [];
        $db = $this->createStub(DatabaseInterface::class);
        $db->method('query')->willReturnCallback(function (string $sql) use (&$queries, $db) {
            $queries[] = $sql;
            return $db;
        });
        $db->method('first')->willReturn((object) ['count' => 5]);
        $db->method('results')->willReturn([]);
        $db->method('error')->willReturn(false);

        $request = [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
            'search' => ['value' => 'foo'],
            'order' => [['column' => 0, 'dir' => 'desc']],
            'columns' => [['data' => $column, 'searchable' => 'true']],
        ];
        (new CarDataTablesService($db))->getDataTablesData($request, $table);

        return $queries;
    }

    public function testCarsSelectAppendsIsFreshExpression(): void
    {
        $queries = $this->captureQueries('cars', 'chassis');

        $this->assertCount(3, $queries);
        [$countSql, $filterSql, $dataSql] = $queries;

        $this->assertStringContainsString(
            ', ' . CarRepository::freshnessSql('cars') . ' AS is_fresh FROM `cars`',
            $dataSql
        );
        $this->assertStringEndsWith('ORDER BY `chassis` DESC LIMIT 0, 10', $dataSql);
        $this->assertSame('SELECT COUNT(*) as count FROM `cars`', $countSql);
        $this->assertStringNotContainsString('is_fresh', $filterSql);
    }

    public function testFactorySelectDoesNotAppendIsFresh(): void
    {
        $dataSql = $this->captureQueries('factory', 'serial')[2];

        $this->assertStringContainsString('AS car_id FROM `elan_factory_info`', $dataSql);
        $this->assertStringNotContainsString('is_fresh', $dataSql);
    }

    public function testIsFreshCannotBeUsedForSortOrFilter(): void
    {
        $this->assertFalse($this->invokeValidateColumnName('is_fresh', 'cars'));
    }

    public function testRequestNamingIsFreshColumnIsNotUsedForSortOrSearch(): void
    {
        // A client that names is_fresh as its sort and search column must get the
        // default ORDER BY and no search condition. With no usable search column the
        // filtered-count query is skipped, so only the COUNT and data queries run.
        $queries = $this->captureQueries('cars', 'is_fresh');

        $this->assertCount(2, $queries);
        $dataSql = $queries[1];
        $this->assertStringEndsWith('ORDER BY id ASC LIMIT 0, 10', $dataSql);
        $this->assertStringNotContainsString('`is_fresh`', $dataSql);
        $this->assertStringNotContainsString('LIKE', $dataSql);
    }
}
