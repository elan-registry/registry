<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationSandboxTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Sandbox round trip for 20260915000000_add_cars_verification_attempts_columns (#2167).
 *
 * The cars_insert/cars_update/cars_delete triggers name the two new columns.
 * down() must restore the old trigger bodies before it drops the columns, or
 * every later write to `cars` fails. up() must put the columns back into the
 * trigger bodies, or cars_hist loses their values.
 */
#[Group('integration')]
#[Group('migration')]
final class AddCarsVerificationAttemptsColumnsSandboxTest extends MigrationSandboxTestCase
{
    private const MIGRATION = '20260915000000_add_cars_verification_attempts_columns.php';

    protected static function targetVersion(): int
    {
        return 20260915000000;
    }

    /**
     * The steps change sandbox state, so they run in one method in a fixed order.
     */
    public function testDownThenUpKeepsCarsAuditTriggersWorking(): void
    {
        // 1. Baseline: each DML statement writes one cars_hist row.
        $this->assertDmlWritesHistory('before down()');

        // 2. down() drops the columns that the current triggers name.
        $this->runDown(self::MIGRATION);
        $this->assertNull($this->columnInfo('cars', 'verification_attempts'), 'down() must drop cars.verification_attempts');
        $this->assertNull($this->columnInfo('cars', 'verification_attempts_since'), 'down() must drop cars.verification_attempts_since');

        // 3. The DML must still succeed and write history.
        $this->assertDmlWritesHistory('after down()');

        // 4. The three triggers exist.
        $this->assertSame(
            ['cars_delete', 'cars_insert', 'cars_update'],
            $this->sandbox()->triggerNames('cars'),
            'down() must leave the three cars audit triggers in place'
        );

        // 5. up() adds the columns again.
        $this->runUp(self::MIGRATION);
        $this->assertNotNull($this->columnInfo('cars', 'verification_attempts'), 'up() must add cars.verification_attempts');
        $this->assertNotNull($this->columnInfo('cars', 'verification_attempts_since'), 'up() must add cars.verification_attempts_since');

        // 6. The DML succeeds and the new column values reach cars_hist.
        $carId = $this->insertCar(7, '2026-01-02 03:04:05');
        $this->assertSame(
            [['INSERT', 7, '2026-01-02 03:04:05']],
            $this->historyAttempts($carId),
            'cars_insert must copy the new columns into cars_hist'
        );

        $this->pdo()->prepare(
            'UPDATE cars SET verification_attempts = ?, verification_attempts_since = ? WHERE id = ?'
        )->execute([9, '2026-02-03 04:05:06', $carId]);
        $this->assertSame(
            [['INSERT', 7, '2026-01-02 03:04:05'], ['UPDATE', 7, '2026-01-02 03:04:05']],
            $this->historyAttempts($carId),
            'cars_update must copy the OLD values of the new columns into cars_hist'
        );

        $this->pdo()->prepare('DELETE FROM cars WHERE id = ?')->execute([$carId]);
        $this->assertSame(
            [
                ['INSERT', 7, '2026-01-02 03:04:05'],
                ['UPDATE', 7, '2026-01-02 03:04:05'],
                ['DELETE', 9, '2026-02-03 04:05:06'],
            ],
            $this->historyAttempts($carId),
            'cars_delete must copy the new columns into cars_hist'
        );
    }

    /**
     * Run INSERT, UPDATE and DELETE on one car and check that each writes its cars_hist row.
     */
    private function assertDmlWritesHistory(string $stage): void
    {
        $carId = $this->insertCar(null, null);
        $this->pdo()->prepare('UPDATE cars SET color = ? WHERE id = ?')->execute(['Red', $carId]);
        $this->pdo()->prepare('DELETE FROM cars WHERE id = ?')->execute([$carId]);

        $statement = $this->pdo()->prepare('SELECT operation FROM cars_hist WHERE car_id = ? ORDER BY id');
        $statement->execute([$carId]);

        $this->assertSame(
            ['INSERT', 'UPDATE', 'DELETE'],
            $statement->fetchAll(\PDO::FETCH_COLUMN),
            "INSERT, UPDATE and DELETE on cars must each write a cars_hist row {$stage}"
        );
    }

    /**
     * Insert a minimal car. With null arguments, the verification columns are left out.
     */
    private function insertCar(?int $attempts, ?string $since): int
    {
        $columns = 'model, series, variant, type, chassis';
        $placeholders = '?, ?, ?, ?, ?';
        $values = ['Elan', 'S3', 'SE', 'DHC', '45/' . random_int(1000, 9999)];

        if ($attempts !== null) {
            $columns .= ', verification_attempts, verification_attempts_since';
            $placeholders .= ', ?, ?';
            $values[] = $attempts;
            $values[] = $since;
        }

        $this->pdo()->prepare("INSERT INTO cars ({$columns}) VALUES ({$placeholders})")->execute($values);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * The cars_hist rows of one car, as [operation, verification_attempts, verification_attempts_since].
     *
     * @return list<array{0: string, 1: int, 2: ?string}>
     */
    private function historyAttempts(int $carId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT operation, verification_attempts, verification_attempts_since
               FROM cars_hist WHERE car_id = ? ORDER BY id'
        );
        $statement->execute([$carId]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            $rows[] = [(string) $row[0], (int) $row[1], $row[2] === null ? null : (string) $row[2]];
        }

        return $rows;
    }
}
