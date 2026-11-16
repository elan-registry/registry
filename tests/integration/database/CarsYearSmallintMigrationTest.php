<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Post-migration state of 20260710120000_change_cars_year_and_drop_modifiedby.
 */
#[Group('integration')]
#[Group('migration')]
final class CarsYearSmallintMigrationTest extends IntegrationTestCase
{
    private int $testUserId;

    /** Car IDs created by individual tests that need early cleanup (e.g. DELETE tests). */
    private array $localCarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Skip, not fail, when the migration has not run: the failures would mislead.
        $yearType = $this->db->query(
            "SELECT COLUMN_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'year'
             LIMIT 1"
        )->first();

        if (!$yearType || stripos((string) $yearType->COLUMN_TYPE, 'smallint') === false) {
            $this->markTestSkipped(
                'Migration 20260710120000 has not been applied — cars.year is not SMALLINT UNSIGNED. ' .
                'Run: composer migrate'
            );
        }

        $this->testUserId = $this->createTestUser();

        $this->loginAsTestUser($this->testUserId);

        $this->localCarIds = [];
    }

    protected function tearDown(): void
    {
        try {
            // #1551: the cars_delete trigger's own hist row still needs removing.
            foreach ($this->localCarIds as $carId) {
                $this->deleteCarWithHistory($carId);
                $this->untrackCarId($carId);
            }
        } finally {
            // Base cleanup must run even if deleteCarWithHistory() throws.
            parent::tearDown();
        }
    }

    // -------------------------------------------------------------------------
    // Schema checks
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function auditedTableProvider(): array
    {
        return [
            'cars'      => ['cars'],
            'cars_hist' => ['cars_hist'],
        ];
    }

    /** Fails before any query runs if a provider row names a table outside this migration. */
    private function assertMigratedTable(string $table): void
    {
        $this->assertContains($table, ['cars', 'cars_hist'], 'Test setup: unknown table');
    }

    #[Group('integration')]
    #[Group('migration')]
    #[DataProvider('auditedTableProvider')]
    public function test_schema_year_isSmallintUnsignedNullable(string $table): void
    {
        $this->assertMigratedTable($table);
        $row = $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = 'year'
             LIMIT 1",
            [$table]
        )->first();

        $this->assertNotNull($row, "Column {$table}.year must exist");
        $this->assertStringContainsStringIgnoringCase(
            'smallint',
            (string) $row->COLUMN_TYPE,
            "{$table}.year must be SMALLINT after migration"
        );
        $this->assertStringContainsStringIgnoringCase(
            'unsigned',
            (string) $row->COLUMN_TYPE,
            "{$table}.year must be UNSIGNED after migration"
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            "{$table}.year must be nullable after migration"
        );
    }

    #[Group('integration')]
    #[Group('migration')]
    #[DataProvider('auditedTableProvider')]
    public function test_schema_modifiedBy_isAbsent(string $table): void
    {
        $this->assertMigratedTable($table);
        $result = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = ?
               AND COLUMN_NAME  = 'ModifiedBy'
             LIMIT 1",
            [$table]
        );

        $this->assertSame(
            0,
            $result->count(),
            "Column {$table}.ModifiedBy must not exist after migration"
        );
    }

    // -------------------------------------------------------------------------
    // Trigger behaviour: INSERT
    // -------------------------------------------------------------------------

    /**
     * Raw INSERT, not createTestCar(): that helper purges the new car's hist rows.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_insertTrigger_createsHistRowWithIntegerYear(): void
    {
        $chassis = 'MIGT' . substr(uniqid(), -8);

        $this->db->query(
            "INSERT INTO cars (year, model, series, variant, type, chassis, mtime, user_id)
             VALUES (1966, 'Elan S3', 'S3', 'FHC', '36', ?, NOW(), ?)",
            [$chassis, $this->testUserId]
        );

        $carId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $carId, 'Direct INSERT must return a valid car ID');

        $this->trackCarId($carId);

        $histQuery = $this->db->query(
            "SELECT operation, year
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'INSERT'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        );

        $this->assertGreaterThan(
            0,
            $histQuery->count(),
            'cars_insert trigger must create a cars_hist row with operation=INSERT'
        );

        $histRow = $histQuery->first();
        $this->assertSame(
            'INSERT',
            $histRow->operation
        );
        $this->assertSame(
            1966,
            (int) $histRow->year,
            'cars_hist.year must store the integer year value inserted into cars'
        );

        $modifiedByCheck = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars_hist'
               AND COLUMN_NAME  = 'ModifiedBy'
             LIMIT 1"
        );

        $this->assertSame(
            0,
            $modifiedByCheck->count(),
            'cars_hist must not have a ModifiedBy column after migration'
        );
    }

    // -------------------------------------------------------------------------
    // Trigger behaviour: UPDATE
    // -------------------------------------------------------------------------

    /**
     * cars_update captures OLD values except NEW.chassis_override; this checks year only.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_updateTrigger_capturesOldYearInHistory(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['year' => 1969]);

        $car = new Car($carId);
        $result = $car->update([
            'id'    => $carId,
            'token' => Token::generate(),
            'year'  => 1970,
        ]);

        $this->assertTrue($result, 'Car::update() must return true on success');

        $histQuery = $this->db->query(
            "SELECT year
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'UPDATE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        );

        $this->assertGreaterThan(
            0,
            $histQuery->count(),
            'cars_update trigger must create a cars_hist row with operation=UPDATE'
        );

        $histRow = $histQuery->first();
        $this->assertSame(
            1969,
            (int) $histRow->year,
            'cars_hist must capture OLD.year (pre-update value) in the UPDATE trigger row'
        );

        $carsRow = $this->db->query(
            'SELECT year FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertSame(
            1970,
            (int) $carsRow->year,
            'cars.year must reflect the updated value after Car::update()'
        );
    }

    // -------------------------------------------------------------------------
    // Trigger behaviour: @disable_triggers guard
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function test_disableTriggersGuard_suppressesUpdateHistory(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['year' => 1971]);

        $beforeCount = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $this->db->query("SET @disable_triggers = 1");
        $this->db->query(
            "UPDATE cars SET year = 1972, mtime = NOW() WHERE id = ?",
            [$carId]
        );
        $this->db->query("SET @disable_triggers = NULL");

        $afterCount = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $this->assertSame(
            (int) $beforeCount,
            (int) $afterCount,
            '@disable_triggers guard must prevent the cars_update trigger from firing'
        );

        // The UPDATE itself must still run; only the trigger is suppressed.
        $carsRow = $this->db->query(
            'SELECT year FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertSame(
            1972,
            (int) $carsRow->year,
            'Raw UPDATE must still modify cars.year even when the trigger is disabled'
        );
    }

    // -------------------------------------------------------------------------
    // Trigger behaviour: DELETE
    // -------------------------------------------------------------------------

    #[Group('integration')]
    #[Group('migration')]
    public function test_deleteTrigger_createsHistRowWithOperation(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['year' => 1973]);

        $this->localCarIds[] = $carId;

        $car    = new Car($carId);
        $result = $car->delete('Migration test deletion', $this->testUserId);

        $this->assertTrue($result, 'Car::delete() must return true on success');

        $carsResult = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$carId]
        );

        $this->assertSame(
            0,
            $carsResult->count(),
            'cars row must not exist after Car::delete()'
        );

        $histQuery = $this->db->query(
            "SELECT operation, year
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'DELETE'
             ORDER BY timestamp DESC
             LIMIT 1",
            [$carId]
        );

        $this->assertGreaterThan(
            0,
            $histQuery->count(),
            'cars_delete trigger must create a cars_hist row with operation=DELETE'
        );

        $histRow = $histQuery->first();
        $this->assertSame(
            'DELETE',
            $histRow->operation
        );
        $this->assertSame(
            1973,
            (int) $histRow->year,
            'cars_hist.year must capture the integer year at the time of deletion'
        );
    }
}
