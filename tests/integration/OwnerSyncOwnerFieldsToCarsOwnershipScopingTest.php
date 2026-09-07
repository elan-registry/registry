<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for Owner::syncOwnerFieldsToCars()'s ownership-scoping
 * guard (#1873).
 *
 * Scenario: a car present in the getCarsOwned() snapshot but no longer
 * owned by this user at write time (e.g. transferred to another owner
 * between the snapshot read and the per-car write) must NOT be overwritten,
 * must be reported in the result's `skipped` list (not `failed` — this is
 * expected behavior, not an error), and must be logged.
 *
 * Reproduced deterministically rather than via real timing: the car is
 * reassigned to another user for real, up front. A DatabaseInterface proxy
 * then makes getCarsOwned()'s own SELECT report that car anyway — as if the
 * reassignment had happened just after the snapshot was taken — while every
 * other query (the per-car UPDATE and the carBelongsToOwner() ownership
 * check) hits the real database and sees the car's actual current owner.
 * That reproduces exactly what updateCarForOwner() and carBelongsToOwner()
 * would see during a genuine mid-sync transfer.
 *
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 * @see usersc/classes/Owner.php Owner::carBelongsToOwner()
 */
#[Group('integration')]
#[Group('owner')]
final class OwnerSyncOwnerFieldsToCarsOwnershipScopingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    /**
     * A DatabaseInterface proxy backed by the real connection, except that
     * the FIRST call to getCarsOwned()'s own
     * `SELECT c.* FROM cars c WHERE c.user_id = ?` query for $ownerId has one
     * extra row — for $staleCarId, a car that has genuinely already been
     * reassigned away from $ownerId in the real database — appended to its
     * result set. Only the first matching call is affected (tracked via
     * $injectedStaleRow), so a second, unrelated read of the same shape later
     * in the test would not also get the stale row.
     */
    private function dbWithStaleSnapshotIncluding(int $ownerId, int $staleCarId): DatabaseInterface
    {
        $real = $this->db;
        return new class ($real, $ownerId, $staleCarId) implements DatabaseInterface {
            private bool $pendingInjection = false;

            public function __construct(
                private DatabaseInterface $real,
                private int $ownerId,
                private int $staleCarId
            ) {
            }

            public function query(string $sql, array $params = []): self
            {
                $this->real->query($sql, $params);

                $this->pendingInjection = (
                    str_starts_with($sql, 'SELECT c.* FROM cars c WHERE c.user_id = ?')
                    && ($params[0] ?? null) === $this->ownerId
                );

                return $this;
            }

            public function count(): int
            {
                return $this->real->count() + ($this->pendingInjection ? 1 : 0);
            }

            public function results(bool $assoc = false): array
            {
                $rows = $this->real->results($assoc);
                if ($this->pendingInjection) {
                    $this->pendingInjection = false;
                    $staleRow = $this->real->query("SELECT * FROM cars WHERE id = ?", [$this->staleCarId])->first();
                    $rows[] = $staleRow;
                }
                return $rows;
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
            public function first(bool $assoc = false): array|object
            {
                return $this->real->first($assoc);
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

    public function testCarNoLongerOwnedIsNotOverwrittenAndSkippedAndLogged(): void
    {
        $userId = $this->createTestUser();
        $otherUserId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'city' => 'OriginalCity',
            'lat'  => null,
            'lon'  => null,
        ]);

        // The car has genuinely already been transferred away from $userId by
        // the time syncOwnerFieldsToCars() runs — only the getCarsOwned()
        // snapshot below is stale.
        $this->db->query("UPDATE cars SET user_id = ? WHERE id = ?", [$otherUserId, $carId]);

        $logPattern = "syncOwnerFieldsToCars: car ID {$carId} is no longer owned by user {$userId}%";
        $before = $this->countMatchingLogs('OwnerActions', $logPattern);

        $db = $this->dbWithStaleSnapshotIncluding($userId, $carId);
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

        $this->assertSame([], $result->updated, 'The reassigned car must not appear in updated');
        $this->assertSame([$carId], $result->skipped, 'The reassigned car must appear in skipped, not failed');
        $this->assertSame([], $result->failed, 'A mid-sync ownership change is not a failure');
        $this->assertTrue($result->isCompleteSuccess(), 'A skip-only result must read as complete success');

        // The car's real, current values must NOT have been overwritten with
        // this (former) owner's data.
        $car = $this->db->query("SELECT city, lat FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame('OriginalCity', $car->city, 'A car no longer owned by this user must not be overwritten');
        $this->assertNull($car->lat, 'A car no longer owned by this user must not be overwritten');

        // No OWNER_SYNC history row for a car whose write was rolled back.
        $histCount = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'OWNER_SYNC'",
            [$carId]
        )->first()->cnt;
        $this->assertSame(0, (int) $histCount, 'No OWNER_SYNC history row must be written for a car that left this owner');

        $after = $this->countMatchingLogs('OwnerActions', $logPattern);
        $this->assertSame($before + 1, $after, 'Losing ownership mid-sync must be logged under LOG_CATEGORY_OWNER_ACTIONS');
    }
}
