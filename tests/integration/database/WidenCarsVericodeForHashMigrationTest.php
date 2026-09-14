<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for migration 20260913205636_widen_cars_vericode_for_hash.
 *
 * Verifies the post-migration schema (cars.vericode is varchar(64), indexed)
 * and the two behaviors a prior review round found broken and fixed:
 *
 * - The migration's guarded UPDATE (`vericode = NULL, mtime = mtime`) must
 *   suppress the cars_update trigger via @disable_triggers, mirroring
 *   CarVerificationColumnsHistTest::testMigrationBackfillGuardSuppressesUpdateHistory()
 *   and CarsYearSmallintMigrationTest::test_disableTriggersGuard_suppressesUpdateHistory()
 *   for other migrations' guarded UPDATEs.
 * - The same UPDATE must preserve cars.mtime (via `mtime = mtime`), since
 *   mtime is ON UPDATE CURRENT_TIMESTAMP and would otherwise be silently
 *   bumped to the migration's run time — see
 *   20260905172137_convert_car_timestamps_to_datetime.php's BACKFILL_SQL/
 *   NULL_REPAIR_SQL for the precedent this migration follows.
 *
 * This does not invoke Phinx's up()/down() directly (that would mutate the
 * test schema mid-suite); instead it replicates the exact guarded UPDATE
 * statement against a fixture row, the same approach
 * CarVerificationColumnsHistTest uses for its own migration-guard test.
 */
#[Group('integration')]
#[Group('migration')]
final class WidenCarsVericodeForHashMigrationTest extends IntegrationTestCase
{
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $vericodeColumn = $this->db->query("SHOW COLUMNS FROM cars LIKE 'vericode'")->first();
        if (!$vericodeColumn || strtolower((string) $vericodeColumn->Type) !== 'varchar(64)') {
            $this->markTestSkipped(
                'Migration 20260913205636 has not been applied — cars.vericode is not varchar(64). '
                . 'Run: composer migrate'
            );
        }

        $this->testUserId = $this->createTestUser();
    }

    // -------------------------------------------------------------------------
    // Schema checks
    // -------------------------------------------------------------------------

    /**
     * cars.vericode must be varchar(64) nullable after the migration — wide
     * enough for a 64-char HMAC-SHA256 hex digest.
     */
    #[Group('fast')]
    public function test_schema_carsVericode_isVarchar64Nullable(): void
    {
        $row = $this->db->query(
            "SELECT COLUMN_TYPE, IS_NULLABLE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'cars'
               AND COLUMN_NAME  = 'vericode'
             LIMIT 1"
        )->first();

        $this->assertNotNull($row, 'Column cars.vericode must exist');
        $this->assertSame(
            'varchar(64)',
            strtolower((string) $row->COLUMN_TYPE),
            'cars.vericode must be varchar(64) after migration'
        );
        $this->assertSame(
            'YES',
            $row->IS_NULLABLE,
            'cars.vericode must remain nullable after migration'
        );
    }

    /**
     * cars.vericode must be indexed after the migration — the lookup path
     * (CarRepository::findByVerificationCode()) is a WHERE vericode = ?.
     */
    #[Group('fast')]
    public function test_schema_carsVericode_isIndexed(): void
    {
        $index = $this->db->query("SHOW INDEX FROM cars WHERE Column_name = 'vericode'")->first();

        $this->assertNotNull($index, 'cars.vericode must have an index after migration');
    }

    // -------------------------------------------------------------------------
    // Guarded UPDATE behavior (the two bugs a prior review round fixed)
    // -------------------------------------------------------------------------

    /**
     * The migration's guarded UPDATE must suppress the cars_update trigger.
     *
     * Without @disable_triggers, nulling every legacy plaintext vericode in
     * one UPDATE would write one spurious 'UPDATE' cars_hist row per matched
     * car — internal bookkeeping misrecorded as an owner edit. At production
     * scale (~1080 rows per the migration's own header comment) that is
     * ~1080 bogus audit entries.
     */
    #[Group('fast')]
    public function testMigrationUpdateGuardSuppressesUpdateHistory(): void
    {
        $legacyPlaintextCode = str_repeat('a', 32);
        $carId = $this->createTestCar($this->testUserId, ['vericode' => $legacyPlaintextCode]);

        $histCountBefore = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        // Exact statement shape from WidenCarsVericodeForHash::up().
        $this->db->query('SET @disable_triggers = 1');
        $this->db->query(
            "UPDATE cars SET vericode = NULL, mtime = mtime"
            . " WHERE id = ? AND vericode IS NOT NULL AND vericode <> ''",
            [$carId]
        );
        $this->db->query('SET @disable_triggers = NULL');

        $histCountAfter = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'UPDATE'",
            [$carId]
        )->first()->cnt;

        $this->assertSame(
            $histCountBefore,
            $histCountAfter,
            'The migration\'s @disable_triggers guard must prevent the cars_update trigger from '
            . 'inserting a spurious cars_hist row — if this regresses, every legacy plaintext '
            . 'vericode nulled by this migration gets a bogus \'UPDATE\' audit entry'
        );

        $carsRow = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNull(
            $carsRow->vericode,
            'The guarded UPDATE must still perform the null-out even though the trigger is suppressed'
        );
    }

    /**
     * The migration's guarded UPDATE must NOT bump cars.mtime.
     *
     * cars.mtime is `ON UPDATE CURRENT_TIMESTAMP` — any UPDATE that omits
     * mtime from its SET clause has that value silently rewritten to the
     * statement's execution time by MySQL itself, not by application code.
     * `mtime = mtime` is the documented suppression (see
     * 20260905172137_convert_car_timestamps_to_datetime.php's BACKFILL_SQL).
     * Without it, every car this migration touches would falsely appear to
     * have been modified at migration-run time, corrupting the admin
     * "recently modified" ordering and the owner-facing last-updated display.
     */
    #[Group('fast')]
    public function testMigrationUpdateGuardPreservesMtime(): void
    {
        $legacyPlaintextCode = str_repeat('b', 32);
        $pastMtime = date('Y-m-d H:i:s', strtotime('-30 days'));

        $carId = $this->createTestCar($this->testUserId, ['vericode' => $legacyPlaintextCode]);

        // Backdate mtime directly — createTestCar()'s INSERT already set it to
        // "now" via ON UPDATE's INSERT-time default, so an explicit UPDATE is
        // needed to establish a distinguishable pre-migration value. This
        // UPDATE itself is guarded for the same reason the migration's is.
        $this->db->query('SET @disable_triggers = 1');
        $this->db->query('UPDATE cars SET mtime = ? WHERE id = ?', [$pastMtime, $carId]);
        $this->db->query('SET @disable_triggers = NULL');

        // Exact statement shape from WidenCarsVericodeForHash::up().
        $this->db->query('SET @disable_triggers = 1');
        $this->db->query(
            "UPDATE cars SET vericode = NULL, mtime = mtime"
            . " WHERE id = ? AND vericode IS NOT NULL AND vericode <> ''",
            [$carId]
        );
        $this->db->query('SET @disable_triggers = NULL');

        $carsRow = $this->db->query('SELECT vericode, mtime FROM cars WHERE id = ?', [$carId])->first();

        $this->assertNull($carsRow->vericode, 'vericode must be nulled by the guarded UPDATE');
        $this->assertSame(
            $pastMtime,
            (string) $carsRow->mtime,
            'cars.mtime must be unchanged by the guarded UPDATE — `mtime = mtime` must suppress '
            . 'the ON UPDATE CURRENT_TIMESTAMP bump, or every touched car\'s real modification '
            . 'date is silently overwritten with the migration\'s run time'
        );
    }

    /**
     * Sanity check: an UNGUARDED UPDATE that omits mtime from its SET clause
     * DOES bump it. This pins the premise the two tests above depend on —
     * that mtime = mtime is load-bearing, not a no-op — by proving the
     * opposite behavior occurs without it.
     */
    #[Group('fast')]
    public function testUnguardedUpdateOmittingMtimeDoesBumpIt(): void
    {
        $pastMtime = date('Y-m-d H:i:s', strtotime('-30 days'));
        $carId = $this->createTestCar($this->testUserId, ['vericode' => str_repeat('c', 32)]);

        $this->db->query('SET @disable_triggers = 1');
        $this->db->query('UPDATE cars SET mtime = ? WHERE id = ?', [$pastMtime, $carId]);
        $this->db->query('SET @disable_triggers = NULL');

        // Deliberately omits mtime from the SET clause and the trigger guard,
        // unlike the migration's actual statement — this is the negative case.
        $this->db->query('UPDATE cars SET vericode = NULL WHERE id = ?', [$carId]);

        $carsRow = $this->db->query('SELECT mtime FROM cars WHERE id = ?', [$carId])->first();

        $this->assertNotSame(
            $pastMtime,
            (string) $carsRow->mtime,
            'An UPDATE that omits mtime from its SET clause must have it bumped by '
            . 'ON UPDATE CURRENT_TIMESTAMP — if this fails, the schema no longer has that '
            . 'clause and the other tests in this file no longer prove anything'
        );
    }

    // -------------------------------------------------------------------------
    // Repository/hashing round-trip against the widened column
    // -------------------------------------------------------------------------

    /**
     * A 64-char HMAC-SHA256 hash must fit and round-trip through the widened
     * column without truncation — the reason this migration exists.
     */
    #[Group('fast')]
    public function testWidenedColumnRoundTripsA64CharHash(): void
    {
        $carId = $this->createTestCar($this->testUserId);
        $repo = new CarRepository($this->db);

        $hash = hashVericode('SOME-VERIFICATION-CODE-' . uniqid());
        $this->assertSame(64, strlen($hash), 'hashVericode() must produce a 64-char digest for this test to be meaningful');

        $this->assertTrue($repo->updateVerificationCode($carId, $hash), 'updateVerificationCode() must succeed');

        $stored = $this->db->query('SELECT vericode FROM cars WHERE id = ?', [$carId])->first();

        $this->assertSame(
            $hash,
            (string) $stored->vericode,
            'A 64-char hash must be stored intact — the whole point of widening the column'
        );
    }
}
