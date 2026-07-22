<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\Car;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration 20260710120000_change_cars_year_and_drop_modifiedby
 *
 * Verifies the post-migration state of:
 * - cars.year and cars_hist.year changed from varchar(4) NOT NULL to SMALLINT UNSIGNED NULL
 * - ModifiedBy column removed from both cars and cars_hist
 * - All three cars triggers (cars_insert, cars_update, cars_delete) rebuilt without ModifiedBy
 * - Trigger INSERT/UPDATE/DELETE behaviour including the @disable_triggers guard on cars_update
 *
 * These are schema-level integration tests: they query information_schema to verify
 * column types and trigger definitions, and exercise the triggers via real DML.
 */
#[Group('integration')]
#[Group('migration')]
final class CarsYearSmallintMigrationTest extends IntegrationTestCase
{
    private int $testUserId = 1;

    /** Car IDs created by individual tests that need early cleanup (e.g. DELETE tests). */
    private array $localCarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Verify the migration has been applied by checking that cars.year is SMALLINT.
        // If the column is still VARCHAR the migration has not run — skip the suite rather
        // than failing with misleading assertion errors.
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

        // Mirror the authenticated-user context required by Car::update() / Car::delete().
        global $user;
        $user = new User();
        $user->find($this->testUserId);

        $reflection     = new ReflectionClass($user);
        $isLoggedInProp = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProp->setValue($user, true);

        $GLOBALS['user'] = $user;

        $this->localCarIds = [];
    }

    protected function tearDown(): void
    {
        // Clean up any cars that were deleted mid-test (so IntegrationTestCase's tearDown
        // doesn't try to DELETE them again, which would be a no-op but could hide bugs).
        foreach ($this->localCarIds as $carId) {
            $this->untrackCarId($carId);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Schema checks
    // -------------------------------------------------------------------------

    /**
     * cars.year must be smallint unsigned nullable after the migration.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_schema_carsYear_isSmallintUnsignedNullable(): void
    {
        $row = $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'year'
             LIMIT 1"
        )->first();

        $this->assertNotNull($row, 'Column cars.year must exist');
        $this->assertStringContainsStringIgnoringCase(
            'smallint',
            (string) $row->COLUMN_TYPE,
            'cars.year must be SMALLINT after migration'
        );
        $this->assertStringContainsStringIgnoringCase(
            'unsigned',
            (string) $row->COLUMN_TYPE,
            'cars.year must be UNSIGNED after migration'
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            'cars.year must be nullable after migration'
        );
    }

    /**
     * cars_hist.year must be smallint unsigned nullable after the migration.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_schema_carsHistYear_isSmallintUnsignedNullable(): void
    {
        $row = $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars_hist'
               AND COLUMN_NAME  = 'year'
             LIMIT 1"
        )->first();

        $this->assertNotNull($row, 'Column cars_hist.year must exist');
        $this->assertStringContainsStringIgnoringCase(
            'smallint',
            (string) $row->COLUMN_TYPE,
            'cars_hist.year must be SMALLINT after migration'
        );
        $this->assertStringContainsStringIgnoringCase(
            'unsigned',
            (string) $row->COLUMN_TYPE,
            'cars_hist.year must be UNSIGNED after migration'
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            'cars_hist.year must be nullable after migration'
        );
    }

    /**
     * The ModifiedBy column must not exist on cars after the migration.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_schema_carsModifiedBy_isAbsent(): void
    {
        $result = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'ModifiedBy'
             LIMIT 1"
        );

        $this->assertSame(
            0,
            $result->count(),
            'Column cars.ModifiedBy must not exist after migration'
        );
    }

    /**
     * The ModifiedBy column must not exist on cars_hist after the migration.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_schema_carsHistModifiedBy_isAbsent(): void
    {
        $result = $this->db->query(
            "SELECT 1
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars_hist'
               AND COLUMN_NAME  = 'ModifiedBy'
             LIMIT 1"
        );

        $this->assertSame(
            0,
            $result->count(),
            'Column cars_hist.ModifiedBy must not exist after migration'
        );
    }

    /**
     * All three cars triggers must exist after the migration.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_schema_allThreeCarsTriggersExist(): void
    {
        $triggers = $this->db->query(
            "SELECT TRIGGER_NAME
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'cars'
             ORDER BY TRIGGER_NAME"
        )->results();

        $this->assertNotNull($triggers, 'information_schema.TRIGGERS query must return results');

        $triggerNames = array_map(
            static fn(object $t): string => $t->TRIGGER_NAME,
            $triggers
        );

        $this->assertContains('cars_delete', $triggerNames, 'cars_delete trigger must exist');
        $this->assertContains('cars_insert', $triggerNames, 'cars_insert trigger must exist');
        $this->assertContains('cars_update', $triggerNames, 'cars_update trigger must exist');
    }

    /**
     * No trigger's ACTION_STATEMENT must reference ModifiedBy.
     *
     * The migration rebuilds all three triggers without the ModifiedBy column; any
     * remaining reference would cause DML errors on cars (since the column was dropped).
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_triggerBodies_containNoModifiedByReferences(): void
    {
        $triggers = $this->db->query(
            "SELECT TRIGGER_NAME, ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'cars'
             ORDER BY TRIGGER_NAME"
        )->results();

        $this->assertNotNull($triggers, 'information_schema.TRIGGERS query must return results');
        $this->assertNotEmpty($triggers, 'At least one trigger must exist on the cars table');

        foreach ($triggers as $trigger) {
            $this->assertStringNotContainsStringIgnoringCase(
                'ModifiedBy',
                (string) $trigger->ACTION_STATEMENT,
                "Trigger {$trigger->TRIGGER_NAME} must not reference ModifiedBy after migration"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Trigger behaviour: INSERT
    // -------------------------------------------------------------------------

    /**
     * Inserting a car must produce one cars_hist row with operation='INSERT' and
     * the correct integer year value. cars_hist must also not have a ModifiedBy column.
     *
     * NOTE: createTestCar() purges stale cars_hist rows for new car IDs after the
     * INSERT (to avoid pollution from recycled AUTO_INCREMENT values). To observe
     * the INSERT trigger output we must do a direct raw INSERT on the cars table —
     * the trigger fires synchronously before createTestCar()'s cleanup DELETE.
     * We track the car ID manually for tearDown cleanup.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_insertTrigger_createsHistRowWithIntegerYear(): void
    {
        $chassis = 'MIGT' . substr(uniqid(), -8);

        // Insert directly so we can read cars_hist before any cleanup sweep.
        $this->db->query(
            "INSERT INTO cars (year, model, series, variant, type, chassis, mtime, user_id)
             VALUES (1966, 'Elan S3', 'S3', 'FHC', '36', ?, NOW(), ?)",
            [$chassis, $this->testUserId]
        );

        $carId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $carId, 'Direct INSERT must return a valid car ID');

        // Register with IntegrationTestCase so tearDown cleans up.
        $this->trackCarId($carId);

        // The cars_insert trigger must have fired and written to cars_hist.
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

        // Confirm ModifiedBy is absent from cars_hist at the schema level (once is enough,
        // but guard it here as a belt-and-suspenders check inside the trigger test).
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
     * Updating a car's year must produce one cars_hist row with operation='UPDATE'
     * containing OLD.year (the pre-update value).
     *
     * The cars_update trigger is intentionally asymmetric: it captures OLD values
     * for most columns but uses NEW.chassis_override. This test exercises the year
     * path only and verifies the OLD-year snapshot in history.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_updateTrigger_capturesOldYearInHistory(): void
    {
        // Create a car with a known year so we can assert the OLD value in history.
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

        // Verify the cars row itself has the NEW year.
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

    /**
     * Wrapping an UPDATE in SET @disable_triggers = 1 / SET @disable_triggers = NULL
     * must prevent the cars_update trigger from inserting a cars_hist row.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_disableTriggersGuard_suppressesUpdateHistory(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['year' => 1971]);

        // Count UPDATE history rows before the guarded DML.
        $beforeCount = $this->db->query(
            "SELECT COUNT(*) AS cnt
             FROM cars_hist
             WHERE car_id   = ?
               AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        // Execute a raw UPDATE wrapped in the disable-triggers guard.
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

        // Confirm cars.year was actually changed (i.e. the UPDATE ran, only the trigger was suppressed).
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

    /**
     * Deleting a car must produce a cars_hist row with operation='DELETE' containing
     * the car's year at the time of deletion.
     *
     * The test deletes via Car::delete() (which calls the application layer)
     * and then inspects cars_hist directly.
     */
    #[Group('integration')]
    #[Group('migration')]
    public function test_deleteTrigger_createsHistRowWithOperation(): void
    {
        $carId = $this->createTestCar($this->testUserId, ['year' => 1973]);

        // Track locally so tearDown doesn't try to clean up an already-deleted car.
        $this->localCarIds[] = $carId;

        $car    = new Car($carId);
        $result = $car->delete('Migration test deletion', Token::generate(), $this->testUserId);

        $this->assertTrue($result, 'Car::delete() must return true on success');

        // cars row must be gone.
        $carsResult = $this->db->query(
            'SELECT id FROM cars WHERE id = ?',
            [$carId]
        );

        $this->assertSame(
            0,
            $carsResult->count(),
            'cars row must not exist after Car::delete()'
        );

        // cars_hist must have a DELETE row from the trigger.
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
