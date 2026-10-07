<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Migration 20260913205636_widen_cars_vericode_for_hash: schema, and the
 * guarded UPDATE (no cars_hist rows, mtime kept). Replicates the UPDATE on a
 * fixture row instead of calling up()/down(), which would change the shared schema.
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
     * Wide enough for a 64-char HMAC-SHA256 hex digest.
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
     * findByVerificationCode() looks up by vericode.
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
     * Without @disable_triggers, the UPDATE writes a bogus 'UPDATE' cars_hist row
     * per car (~1080 in production).
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
     * cars.mtime is ON UPDATE CURRENT_TIMESTAMP; without `mtime = mtime` every
     * touched car would look modified at migration time.
     */
    #[Group('fast')]
    public function testMigrationUpdateGuardPreservesMtime(): void
    {
        $legacyPlaintextCode = str_repeat('b', 32);
        $pastMtime = date('Y-m-d H:i:s', strtotime('-30 days'));

        $carId = $this->createTestCar($this->testUserId, ['vericode' => $legacyPlaintextCode]);

        // createTestCar() set mtime to now; backdate it (guarded) to get a distinguishable value.
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
     * Proves `mtime = mtime` is needed: an unguarded UPDATE does bump mtime.
     */
    #[Group('fast')]
    public function testUnguardedUpdateOmittingMtimeDoesBumpIt(): void
    {
        $pastMtime = date('Y-m-d H:i:s', strtotime('-30 days'));
        $carId = $this->createTestCar($this->testUserId, ['vericode' => str_repeat('c', 32)]);

        $this->db->query('SET @disable_triggers = 1');
        $this->db->query('UPDATE cars SET mtime = ? WHERE id = ?', [$pastMtime, $carId]);
        $this->db->query('SET @disable_triggers = NULL');

        // Negative case: no mtime in SET and no trigger guard.
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
