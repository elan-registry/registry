<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Exceptions\OwnerDatabaseException;
use ElanRegistry\LogCategories;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for Owner::syncOwnerFieldsToCars()'s per-car failure
 * branches (#1873, renamed/rewritten from #1618's syncLocationToCars()
 * suite for the new transactional semantics).
 *
 * OwnerSyncOwnerFieldsToCarsTest.php covers the happy path,
 * OwnerReadMethodsDatabaseFailureTest covers getCarsOwned() throwing before
 * the loop even starts, OwnerSyncOwnerFieldsToCarsOwnershipScopingTest
 * covers the zero-row-UPDATE-but-still-owned-elsewhere race, and
 * OwnerSyncOwnerFieldsToCarsRollbackTest covers a failing history insert
 * rolling back an otherwise-successful UPDATE. This file covers what
 * happens when the UPDATE itself fails outright (a genuine database error,
 * not the row-count ambiguity) — CarRepository::updateCarForOwner() throws
 * CarDatabaseException in that case, and the per-car try/catch in
 * syncOwnerFieldsToCars() must catch it, roll back, record the car in
 * `failed`, and log — without aborting the rest of the sync.
 *
 * IMPORTANT — this is NOT the same failure mode the old
 * testInsertHistoryFailureStillCountsCarAsUpdatedAndLogsSeparately() covered.
 * That method asserted the update PERSISTED despite a history-insert
 * failure — correct under the old bare-loop, no-transaction implementation,
 * but exactly backwards under the new per-car-transaction design, where a
 * failed history insert must roll back the update too. That scenario now
 * lives in OwnerSyncOwnerFieldsToCarsRollbackTest.php; it is intentionally
 * not duplicated here.
 *
 * Owner constructs its own internal CarRepository with no injection point,
 * but Owner and CarRepository share the same DatabaseInterface instance —
 * so a proxy that fails query() for the specific per-car UPDATE statement
 * can force that one write to error while getCarsOwned()'s own read keeps
 * working against the real connection.
 *
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerSyncOwnerFieldsToCarsFailureTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * A DatabaseInterface proxy backed by the real connection, except that
     * query() reports a database error for the specific
     * `UPDATE cars SET ... WHERE id = ? AND user_id = ?` call issued by
     * CarRepository::updateCarForOwner() — forcing that call to throw
     * CarDatabaseException, exactly as a genuine deadlock or constraint
     * violation would. Every other query (including getCarsOwned()'s own
     * read) passes through untouched.
     */
    private function dbFailingUpdateCarForOwner(): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real) implements DatabaseInterface {
            private bool $lastCallFailed = false;

            public function __construct(private DatabaseInterface $real)
            {
            }

            public function query(string $sql, array $params = []): self
            {
                $this->lastCallFailed = str_starts_with($sql, 'UPDATE cars SET')
                    && str_ends_with($sql, 'WHERE id = ? AND user_id = ?');

                if (!$this->lastCallFailed) {
                    $this->real->query($sql, $params);
                }

                return $this;
            }
            public function get(string $table, array $where): self|false
            {
                $result = $this->real->get($table, $where);
                return $result === false ? false : $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                return $this->real->insert($table, $fields, $update);
            }
            public function update(string $table, array|int $id, array $fields): bool
            {
                return $this->real->update($table, $id, $fields);
            }
            public function delete(string $table, array|int $where): self|false
            {
                $result = $this->real->delete($table, $where);
                return $result === false ? false : $this;
            }
            public function error(): bool
            {
                return $this->lastCallFailed || $this->real->error();
            }
            public function errorString(): string
            {
                return $this->lastCallFailed ? 'simulated deadlock' : $this->real->errorString();
            }
            public function errorInfo(): array
            {
                return $this->real->errorInfo();
            }
            public function count(): int
            {
                return $this->real->count();
            }
            public function first(bool $assoc = false): array|object
            {
                return $this->real->first($assoc);
            }
            public function results(bool $assoc = false): array
            {
                return $this->real->results($assoc);
            }
            public function lastId(): int
            {
                return $this->real->lastId();
            }
            public function beginTransaction(): bool
            {
                return $this->real->beginTransaction();
            }
            public function commit(): bool
            {
                return $this->real->commit();
            }
            public function rollBack(): bool
            {
                return $this->real->rollBack();
            }
            public function inTransaction(): bool
            {
                return $this->real->inTransaction();
            }
        };
    }

    /**
     * A DatabaseInterface proxy backed by the real connection, except that the
     * `UPDATE cars SET ... WHERE id = ? AND user_id = ?` call for one specific
     * car ID reports a database error, exactly as dbFailingUpdateCarForOwner()
     * does for "the" (single) UPDATE. This variant targets ONE car among
     * several, so cars processed before it commit for real and the failure is
     * reached only after some partial progress has already landed — the
     * scenario Gap B needs (a later car fails after earlier ones committed).
     */
    private function dbFailingUpdateForSpecificCar(int $targetCarId): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real, $targetCarId) implements DatabaseInterface {
            private bool $lastCallFailed = false;

            public function __construct(private DatabaseInterface $real, private int $targetCarId)
            {
            }

            public function query(string $sql, array $params = []): self
            {
                $isTargetUpdate = str_starts_with($sql, 'UPDATE cars SET')
                    && str_ends_with($sql, 'WHERE id = ? AND user_id = ?')
                    && (int) ($params[count($params) - 2] ?? null) === $this->targetCarId;

                $this->lastCallFailed = $isTargetUpdate;

                if (!$this->lastCallFailed) {
                    $this->real->query($sql, $params);
                }

                return $this;
            }
            public function get(string $table, array $where): self|false
            {
                $result = $this->real->get($table, $where);
                return $result === false ? false : $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                return $this->real->insert($table, $fields, $update);
            }
            public function update(string $table, array|int $id, array $fields): bool
            {
                return $this->real->update($table, $id, $fields);
            }
            public function delete(string $table, array|int $where): self|false
            {
                $result = $this->real->delete($table, $where);
                return $result === false ? false : $this;
            }
            public function error(): bool
            {
                return $this->lastCallFailed || $this->real->error();
            }
            public function errorString(): string
            {
                return $this->lastCallFailed ? 'simulated deadlock on target car' : $this->real->errorString();
            }
            public function errorInfo(): array
            {
                return $this->real->errorInfo();
            }
            public function count(): int
            {
                return $this->real->count();
            }
            public function first(bool $assoc = false): array|object
            {
                return $this->real->first($assoc);
            }
            public function results(bool $assoc = false): array
            {
                return $this->real->results($assoc);
            }
            public function lastId(): int
            {
                return $this->real->lastId();
            }
            public function beginTransaction(): bool
            {
                return $this->real->beginTransaction();
            }
            public function commit(): bool
            {
                return $this->real->commit();
            }
            public function rollBack(): bool
            {
                return $this->real->rollBack();
            }
            public function inTransaction(): bool
            {
                return $this->real->inTransaction();
            }
        };
    }

    /**
     * Count cars_hist rows for a car scoped to operation='OWNER_SYNC' — never
     * all cars_hist rows, since the cars_update trigger writes its own
     * operation='UPDATE' row for every change and would otherwise be
     * indistinguishable from the application's own history write.
     */
    private function countOwnerSyncHistoryRows(int $carId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        );

        return (int) $result->first()->cnt;
    }

    /**
     * A genuine UPDATE failure (not a 0-row-matched ambiguity) is an
     * infrastructure failure, not a per-car outcome: it must roll the car's
     * transaction back and propagate as CarDatabaseException rather than be
     * recorded in `failed`. Reporting a DB outage as N individual "car could
     * not be updated" results hides the actual fault from the caller.
     */
    public function testUpdateQueryFailureRollsBackAndPropagates(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'chassis' => 'SYNCFAIL1',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        $db = $this->dbFailingUpdateCarForOwner();
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'A DB-level UPDATE failure must propagate, not be recorded as a per-car failure');
        $this->assertStringContainsString(
            'simulated deadlock',
            $thrown->getMessage(),
            'The propagating exception must carry the underlying MySQL error string'
        );

        // Confirm the car's values were NOT actually changed in the real DB:
        // the per-car transaction is rolled back before the exception propagates.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'Car city must remain unchanged when the UPDATE query fails');
        $this->assertNull($car->lat, 'Car lat must remain unchanged when the UPDATE query fails');
    }

    /**
     * Gap A (#1873 round-two review): the outer-transaction guard at the top
     * of syncOwnerFieldsToCars() has no test pinning it. CarRepository's
     * beginTransaction()/commit()/rollback() are nesting-aware no-ops when an
     * outer transaction is already open on the shared connection — so if this
     * guard were ever deleted, a per-car rollback inside an outer transaction
     * would silently do nothing, committing a car row without its audit row
     * once the outer transaction later commits. That is the exact bug #1873
     * exists to fix, inverted. This test proves the guard actually fires, and
     * that it fires BEFORE any work — no car row touched, no OWNER_SYNC
     * history row written — by opening a real outer transaction on the
     * connection Owner holds before calling syncOwnerFieldsToCars().
     */
    public function testSyncInsideOuterTransactionThrowsBeforeAnyWork(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'chassis' => 'OUTERTXN1',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        $owner = $this->ownerWithLoadedData($this->db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $this->assertFalse(
            $this->db->inTransaction(),
            'Precondition: the shared connection must not already be inside a transaction'
        );

        $thrown = null;
        $this->db->beginTransaction();
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (OwnerDatabaseException $e) {
            $thrown = $e;
        } finally {
            // Unconditionally roll back the transaction opened above so a
            // failing assertion below cannot leave the suite's shared
            // connection stuck inside a transaction and cascade failures
            // into unrelated tests. syncOwnerFieldsToCars() never commits or
            // rolls back this outer transaction itself (it only throws), so
            // it is always still open here.
            $this->db->rollBack();
        }

        $this->assertNotNull(
            $thrown,
            'syncOwnerFieldsToCars() must throw OwnerDatabaseException when called inside an outer transaction'
        );
        $this->assertStringContainsString(
            'outer transaction',
            $thrown->getMessage(),
            'The exception message must name the outer-transaction problem'
        );

        // Guard must fire before any per-car work — the car must be untouched.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'The guard must fire before any car row is modified');
        $this->assertNull($car->lat, 'The guard must fire before any car row is modified');

        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($carId),
            'The guard must fire before any OWNER_SYNC history row is written'
        );
    }

    /**
     * Gap B (#1873 round-two review): the one branch where diagnosability was
     * genuinely fragile. CarRepository::updateCarForOwner() throws
     * CarDatabaseException on a genuine UPDATE failure from inside the per-car
     * transaction. It deliberately writes no log row of its own — one written
     * there would be destroyed by the rollback before the exception escapes
     * (InnoDB `logs` table; a row inserted in a transaction does not survive
     * ROLLBACK). The propagating catch in syncOwnerFieldsToCars() therefore logs
     * AFTER the rollback, recording the partial state (which cars already
     * committed, and where the abort happened).
     *
     * This test forces the failure on the LATER of two cars, so one car has
     * already committed by the time the failure hits, and asserts:
     *  - CarDatabaseException propagates to the caller
     *  - a log row under LOG_CATEGORY_DATABASE_ERROR survives (proving the
     *    post-rollback logging placement actually works, not just that a
     *    logger() call exists in the source)
     *  - that surviving log names both the aborted car ID and the
     *    already-committed car ID
     *  - the already-committed car's synced values are genuinely present in
     *    the DB, proving the partial state the log describes is real
     */
    public function testUpdateQueryFailureOnLaterCarLogsPartialStateAfterRollback(): void
    {
        $userId = $this->createTestUser();
        $committedCarId = $this->createTestCar($userId, [
            'chassis' => 'PARTIAL01',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);
        $abortedCarId = $this->createTestCar($userId, [
            'chassis' => 'PARTIAL02',
            'city'    => 'OriginalCity',
            'lat'     => null,
        ]);

        // getCarsOwned() orders by model, year — both test cars share the
        // default model/year, so insertion order (committedCarId first) is
        // the tie-break MySQL uses in practice for otherwise-equal sort keys
        // on a single-table scan. Assert that ordering explicitly so the
        // "later car" premise is verified rather than assumed.
        $ownedIds = array_map(
            static fn ($c) => (int) $c->id,
            (new \ElanRegistry\Owner($userId))->getCarsOwned()
        );
        $this->assertSame(
            [$committedCarId, $abortedCarId],
            $ownedIds,
            'Precondition: committedCarId must be processed before abortedCarId'
        );

        $logCountBefore = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"
        );

        $db = $this->dbFailingUpdateForSpecificCar($abortedCarId);
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'CarDatabaseException must propagate when a later car\'s UPDATE fails'
        );

        $logCountAfter = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_DATABASE_ERROR,
            "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"
        );
        $this->assertSame(
            $logCountBefore + 1,
            $logCountAfter,
            'A log row recording the abort must survive the per-car rollback — the row is '
            . 'written after rollback() returns, not inside the failed transaction'
        );

        $survivingLog = $this->db->query(
            "SELECT lognote FROM logs WHERE logtype = ? AND lognote LIKE ? ORDER BY id DESC LIMIT 1",
            [LogCategories::LOG_CATEGORY_DATABASE_ERROR, "syncOwnerFieldsToCars: aborted at car ID {$abortedCarId}%"]
        )->first();
        $this->assertNotNull($survivingLog, 'The surviving log row must be readable back from the DB');
        $this->assertStringContainsString(
            (string) $abortedCarId,
            $survivingLog->lognote,
            'The surviving log must name the car ID where the abort happened'
        );
        $this->assertStringContainsString(
            (string) $committedCarId,
            $survivingLog->lognote,
            'The surviving log must name the already-committed car ID(s), proving the partial '
            . 'state is recorded, not just the failure point'
        );

        // Prove the partial state the log describes is real: the earlier car
        // really did commit its synced values before the later car aborted.
        $committedCar = $this->db->query(
            "SELECT city, lat FROM cars WHERE id = ?",
            [$committedCarId]
        )->first();
        $this->assertNotNull($committedCar);
        $this->assertSame(
            'NewCity',
            $committedCar->city,
            'The already-committed car must genuinely hold the synced value in the DB'
        );
        $this->assertSame(
            '45.5231',
            $committedCar->lat,
            'The already-committed car must genuinely hold the synced value in the DB'
        );
        $this->assertSame(
            1,
            $this->countOwnerSyncHistoryRows($committedCarId),
            'The already-committed car must have its OWNER_SYNC history row too — the commit was whole'
        );

        // And the aborted car must show no trace of the attempted update.
        $abortedCar = $this->db->query(
            "SELECT city, lat FROM cars WHERE id = ?",
            [$abortedCarId]
        )->first();
        $this->assertNotNull($abortedCar);
        $this->assertSame('OriginalCity', $abortedCar->city, 'The aborted car must remain unchanged');
        $this->assertNull($abortedCar->lat, 'The aborted car must remain unchanged');
        $this->assertSame(
            0,
            $this->countOwnerSyncHistoryRows($abortedCarId),
            'The aborted car must have no OWNER_SYNC history row — its transaction was rolled back'
        );
    }

    /**
     * An Owner constructed with a user ID whose row no longer exists (find()
     * runs, queries the database, and returns false, so $this->_data stays
     * null) must throw OwnerDatabaseException from syncOwnerFieldsToCars()
     * rather than silently returning an empty, complete-success
     * OwnerSyncResult. Silently succeeding here would hide a genuine
     * precondition failure — the caller asked to sync a nonexistent owner —
     * behind a result indistinguishable from "owner has zero cars".
     *
     * The user is created then deleted so find() actually executes its query
     * and returns false for a real, once-valid ID — not merely skipped via
     * the constructor's `if ($id)` guard, which a userId of 0 or null would
     * trigger without ever calling find() at all.
     */
    public function testSyncOnNeverLoadedOwnerThrowsOwnerDatabaseException(): void
    {
        $userId = $this->createTestUser();
        $this->db->delete('users', ['id', '=', $userId]);

        $owner = new Owner($userId);
        $this->assertNull($owner->data(), 'Precondition: Owner must have failed to load');

        $this->expectException(OwnerDatabaseException::class);
        $this->expectExceptionMessage('called on an Owner that failed to load');

        $owner->syncOwnerFieldsToCars();
    }
}
