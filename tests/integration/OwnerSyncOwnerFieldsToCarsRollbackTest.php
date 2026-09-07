<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for Owner::syncOwnerFieldsToCars()'s transactional
 * rollback when the history insert fails (#1873).
 *
 * Each car is written inside its own transaction. When insertHistory() fails
 * after a successful UPDATE, the whole per-car transaction must roll back:
 * the car row must be left at its ORIGINAL values, no OWNER_SYNC row must
 * exist, the car must be reported in `failed`, and the failure must be
 * logged. This supersedes the old syncLocationToCars()-era
 * testInsertHistoryFailureStillCountsCarAsUpdatedAndLogsSeparately(), which
 * asserted the OPPOSITE — that the update persisted despite the history
 * failure — encoding the pre-transaction semantics this issue replaces.
 *
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerSyncOwnerFieldsToCarsRollbackTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * A DatabaseInterface proxy backed by the real connection for every
     * method except insert(), which always returns false — forcing
     * CarRepository::insertHistory() (a thin wrapper over
     * DatabaseInterface::insert()) to fail while the real UPDATE, the
     * transaction control methods, and getCarsOwned()'s own read keep
     * working against the real connection.
     */
    private function dbFailingHistoryInsert(): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real) implements DatabaseInterface {
            public function __construct(private DatabaseInterface $real)
            {
            }
            public function query(string $sql, array $params = []): self
            {
                $this->real->query($sql, $params);
                return $this;
            }
            public function get(string $table, array $where): self|false
            {
                $result = $this->real->get($table, $where);
                return $result === false ? false : $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                return false;
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
                return $this->real->error();
            }
            public function errorString(): string
            {
                return $this->real->errorString();
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
     * insertHistory() failing after a successful UPDATE must roll back the
     * whole per-car transaction: the car row reverts to its ORIGINAL values,
     * no OWNER_SYNC row is written, the car is reported in `failed`, and the
     * failure is logged (inverted from the pre-#1873 behavior, which counted
     * the car as updated despite the history failure).
     */
    public function testHistoryInsertFailureRollsBackCarUpdateAndReportsFailed(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'city' => 'OriginalCity',
            'lat'  => null,
            'lon'  => null,
        ]);

        $logPattern = "syncOwnerFieldsToCars: failed to insert history record for car ID {$carId}%";
        $before = $this->countMatchingLogs('OwnerActions', $logPattern);

        $db = $this->dbFailingHistoryInsert();
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Synced',
            'lname'   => 'Owner',
            'email'   => 'synced@example.com',
            'city'    => 'NewCity',
            'state'   => 'NewState',
            'country' => 'New Country',
            'lat'     => '45.5231',
            'lon'     => '-122.6765',
            'website' => 'https://example.com',
        ]);

        $result = $owner->syncOwnerFieldsToCars();

        $this->assertSame([], $result->updated, 'A car whose history insert fails must not appear in updated');
        $this->assertSame([$carId], $result->failed, 'A car whose history insert fails must appear in failed');
        $this->assertFalse($result->isCompleteSuccess());

        // The UPDATE must have been rolled back — original values persist.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'The car UPDATE must be rolled back when the history insert fails');
        $this->assertNull($car->lat, 'The car UPDATE must be rolled back when the history insert fails');

        // No OWNER_SYNC row — the whole transaction, insert included, rolled back.
        $histCount = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        )->first()->cnt;
        $this->assertSame(0, (int) $histCount, 'No OWNER_SYNC history row must exist when its own insert fails and the transaction rolls back');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'The history-insert failure must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }
}
