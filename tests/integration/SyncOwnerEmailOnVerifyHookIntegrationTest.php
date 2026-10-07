<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;

/**
 * #1958: the DB-backed sync that the sync_owner_email_on_verify hook relies on.
 * The hook's own control flow: tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php.
 * The "Integration" suffix avoids a "Cannot redeclare class" fatal when a
 * cross-suite --filter loads both files.
 *
 * @see usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 */
#[Group('integration')]
#[Group('owner')]
final class SyncOwnerEmailOnVerifyHookIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM fix_script_runs WHERE script_name = ?', [basename(__FILE__)]);
        }

        parent::tearDown();
    }

    /**
     * Real connection, except the owner-scoped `UPDATE cars ... WHERE id = ?
     * AND user_id = ?` reports a DB error, so updateCarForOwner() throws
     * CarDatabaseException.
     */
    private function dbFailingUpdateCarForOwner(): DatabaseInterface
    {
        return new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                $fails = str_starts_with($sql, 'UPDATE cars SET')
                    && str_ends_with($sql, 'WHERE id = ? AND user_id = ?');

                return $fails ? $this->simulateFailure('simulated deadlock') : parent::query($sql, $params);
            }
        };
    }

    /** #1958: a changed users.email syncs to every owned car. */
    public function testConfirmedEmailChangeSyncsToAllOwnedCars(): void
    {
        $userId = $this->createTestUser(['email' => 'old-address@example.com']);
        $carId1 = $this->createTestCar($userId, ['email' => 'old-address@example.com']);
        $carId2 = $this->createTestCar($userId, ['email' => 'old-address@example.com']);

        // Mirrors users/verify.php: users.email is set before the verifySuccess hooks fire.
        $this->db->query("UPDATE users SET email = ? WHERE id = ?", ['new-address@example.com', $userId]);

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue($result->isCompleteSuccess(), 'The sync must succeed for both owned cars');
        $this->assertSame([$carId1, $carId2], $result->updated);

        foreach ([$carId1, $carId2] as $carId) {
            $car = $this->db->query("SELECT email FROM cars WHERE id = ?", [$carId])->first();
            $this->assertNotNull($car);
            $this->assertSame(
                'new-address@example.com',
                $car->email,
                "cars.email for car {$carId} must reflect the confirmed new address"
            );
        }
    }

    /**
     * The hook's first catch clause (OwnerDatabaseException | CarDatabaseException)
     * matches what syncOwnerFieldsToCars() throws against a real database.
     */
    public function testSyncFailureThrowsCarDatabaseExceptionCatchableAsTheHookExpects(): void
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, [
            'chassis' => 'VERIFYHK1',
            'email'   => 'old-address@example.com',
        ]);

        $db = $this->dbFailingUpdateCarForOwner();
        $owner = $this->ownerWithLoadedData($db, [
            'id'      => $userId,
            'fname'   => 'Test',
            'lname'   => 'User',
            'email'   => 'new-address@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => '',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (\ElanRegistry\Exceptions\OwnerDatabaseException | CarDatabaseException $e) {
            $thrown = $e;
        }

        $this->assertNotNull(
            $thrown,
            'syncOwnerFieldsToCars() must throw a type caught by the hook\'s '
            . 'OwnerDatabaseException | CarDatabaseException clause — otherwise '
            . 'the hook\'s first catch is unreachable dead code'
        );
        $this->assertInstanceOf(
            CarDatabaseException::class,
            $thrown,
            'A genuine per-car UPDATE failure must surface as CarDatabaseException specifically'
        );

        // The per-car transaction rolled back before the exception propagated.
        $car = $this->db->query("SELECT email FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame(
            'old-address@example.com',
            $car->email,
            'cars.email must remain unchanged when the underlying UPDATE fails'
        );
    }

    /**
     * The hook's \Throwable catch is reachable: a \TypeError propagates through
     * syncOwnerFieldsToCars(). The real DB layer only warns on bad bind params,
     * so a stub throws the \TypeError.
     */
    public function testMalformedOwnerDataThrowsThrowableCatchableAsTheHookExpects(): void
    {
        $db = new class implements DatabaseInterface {
            // Unused; satisfies DatabaseInterface's @phpstan-impure contract.
            private int $calls = 0;

            public function query(string $sql, array $params = []): self
            {
                $this->calls++;
                throw new \TypeError('simulated: bindValue() received a non-scalar value');
            }
            public function get(string $table, array $where): self
            {
                $this->calls++;
                return $this;
            }
            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                $this->calls++;
                return true;
            }
            public function update(string $table, array|int $id, array $fields): bool
            {
                $this->calls++;
                return true;
            }
            public function delete(string $table, array|int $where): self
            {
                $this->calls++;
                return $this;
            }
            public function error(): bool
            {
                $this->calls++;
                return false;
            }
            public function errorString(): string
            {
                $this->calls++;
                return '';
            }
            public function errorInfo(): array
            {
                $this->calls++;
                return [];
            }
            public function count(): int
            {
                $this->calls++;
                return 0;
            }
            public function first(bool $assoc = false): array
            {
                $this->calls++;
                return [];
            }
            public function results(bool $assoc = false): array
            {
                $this->calls++;
                return [];
            }
            public function lastId(): int
            {
                $this->calls++;
                return 0;
            }
            public function beginTransaction(): bool
            {
                $this->calls++;
                return true;
            }
            public function commit(): bool
            {
                $this->calls++;
                return true;
            }
            public function rollBack(): bool
            {
                $this->calls++;
                return true;
            }
            public function inTransaction(): bool
            {
                $this->calls++;
                return false;
            }
        };

        $owner = $this->ownerWithLoadedData($db, [
            'id'      => 1,
            'fname'   => 'Test',
            'lname'   => 'User',
            'email'   => 'new-address@example.com',
            'city'    => 'Portland',
            'state'   => 'Oregon',
            'country' => 'United States',
            'lat'     => null,
            'lon'     => null,
            'website' => '',
        ]);

        $thrown = null;
        try {
            $owner->syncOwnerFieldsToCars();
        } catch (\ElanRegistry\Exceptions\OwnerDatabaseException | CarDatabaseException $e) {
            $this->fail(
                'Expected a bare \\Throwable (\\TypeError) to propagate untouched, not '
                . 'be wrapped as one of the application exceptions — got ' . get_class($e)
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            \TypeError::class,
            $thrown,
            'A bare \\TypeError from the DB layer must propagate to the caller uncaught by '
            . 'syncOwnerFieldsToCars()/getCarsOwned() — it is exactly the class of failure the '
            . "hook's second catch clause (\\Throwable, not \\Exception) exists for, since "
            . "PHP's Error hierarchy does not extend Exception"
        );
    }

    /**
     * #1958: a DB-row id is a string. Under strict_types, passing it uncast to
     * admin_script_record_completion(int $userId) throws TypeError.
     */
    public function testAdminScriptRecordCompletionRejectsUncastStringUserIdFromDbRow(): void
    {
        require_once __DIR__ . '/../../app/admin/includes/fix-script-core.php';

        $userId = $this->createTestUser();
        $row = $this->db->query('SELECT id FROM users WHERE id = ?', [$userId])->first();
        $this->assertIsString($row->id, 'A DB row property must be a string here for this regression test to be meaningful');

        $thrown = null;
        try {
            /** @phpstan-ignore-next-line argument.type — deliberately passing the unfixed shape to prove it throws */
            admin_script_record_completion(__FILE__, $row->id);
        } catch (\TypeError $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'An uncast string id must throw TypeError under strict_types — this is the bug #1958 shipped once');

        admin_script_record_completion(__FILE__, (int) $row->id);
    }

    // #1890: clearBouncedForUser() / carIdsWithBouncedFlagButNoAddress() against
    // real MySQL: LOWER() semantics, the cars_update trigger, and bounce-clear
    // (autocommit) followed by the sync's own transactions in one request.

    /**
     * Only the car whose bounced address differs (case-insensitively) from the
     * confirmed email is cleared. email_suppressed is never written.
     */
    public function testOnlyTheMatchingBouncedCarClearsAndGetsAHistoryRow(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com']);

        $repo = new CarRepository($this->db);

        $staleCarId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR1']);
        $repo->updateEmailBounced($staleCarId, true, 'OLD-ADDRESS@EXAMPLE.COM');
        $repo->updateEmailSuppressed($staleCarId, true);

        $currentCarId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR2']);
        // Differs only in case, so only LOWER() shows it already matches.
        $repo->updateEmailBounced($currentCarId, true, 'NEW-Address@Example.com');
        $repo->updateEmailSuppressed($currentCarId, true);

        // Clear cars_hist rows from setup so the "exactly one new row" check is clean.
        $this->db->query('DELETE FROM cars_hist WHERE car_id IN (?, ?)', [$staleCarId, $currentCarId]);

        $cleared = $repo->clearBouncedForUser($userId, 'new-address@example.com');

        $this->assertSame(1, $cleared, 'Only the car bounced under a different (case-insensitively) address must clear');

        $staleCar = $this->db->query(
            'SELECT email_bounced, email_bounced_address, email_suppressed FROM cars WHERE id = ?',
            [$staleCarId]
        )->first();
        $this->assertSame(0, (int) $staleCar->email_bounced, 'The stale-address car must have its bounce flag cleared');
        $this->assertNull($staleCar->email_bounced_address);
        $this->assertSame(
            1,
            (int) $staleCar->email_suppressed,
            'email_suppressed must be untouched by the bounce-clear UPDATE, which only sets email_bounced/email_bounced_address'
        );

        $currentCar = $this->db->query(
            'SELECT email_bounced, email_bounced_address, email_suppressed FROM cars WHERE id = ?',
            [$currentCarId]
        )->first();
        $this->assertSame(
            1,
            (int) $currentCar->email_bounced,
            'The car already bounced under the current (case-insensitively matching) address must be left untouched'
        );
        $this->assertSame('NEW-Address@Example.com', $currentCar->email_bounced_address);
        $this->assertSame(
            1,
            (int) $currentCar->email_suppressed,
            'email_suppressed must remain untouched on the car the UPDATE skips entirely'
        );

        $histRows = $this->db->query(
            'SELECT car_id FROM cars_hist WHERE car_id IN (?, ?) ORDER BY car_id',
            [$staleCarId, $currentCarId]
        )->results();
        $this->assertCount(1, $histRows, 'cars_update trigger must fire exactly once, for the changed row only');
        $this->assertSame($staleCarId, (int) $histRows[0]->car_id);
    }

    /**
     * A NULL email_bounced_address with email_bounced=1 (legacy anomaly) is
     * found and cleared; only real MySQL proves the "IS NULL" branch.
     */
    public function testCarWithNullBouncedAddressIsFoundByIntegrityCheckAndClears(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com']);
        $repo = new CarRepository($this->db);

        $carId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR3']);
        // updateEmailBounced() forbids this combination; only legacy data has it.
        $this->db->query(
            'UPDATE cars SET email_bounced = 1, email_bounced_address = NULL WHERE id = ?',
            [$carId]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed the integrity-anomaly row: ' . $this->db->errorString());
        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$carId]);

        $integrityCarIds = $repo->carIdsWithBouncedFlagButNoAddress($userId);
        $this->assertSame([$carId], $integrityCarIds, 'The integrity-check SELECT must find the anomalous row beforehand');

        $cleared = $repo->clearBouncedForUser($userId, 'new-address@example.com');
        $this->assertSame(1, $cleared, 'A NULL-address anomaly row must still clear via the real UPDATE');

        $car = $this->db->query('SELECT email_bounced, email_bounced_address FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(0, (int) $car->email_bounced);
        $this->assertNull($car->email_bounced_address);

        $histRows = $this->db->query('SELECT car_id FROM cars_hist WHERE car_id = ?', [$carId])->results();
        $this->assertCount(1, $histRows, 'The clearing UPDATE must produce exactly one cars_hist row');
    }

    /** Bounce-clear (autocommit) followed by the sync's own transactions must not conflict. */
    public function testSyncOwnerFieldsToCarsSucceedsImmediatelyAfterBounceClearUpdate(): void
    {
        $userId = $this->createTestUser(['email' => 'old-address@example.com']);
        $repo = new CarRepository($this->db);

        $carId = $this->createTestCar($userId, [
            'chassis' => 'BOUNCECLR4',
            'email'   => 'old-address@example.com',
        ]);
        $repo->updateEmailBounced($carId, true, 'old-address@example.com');
        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$carId]);

        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['new-address@example.com', $userId]);

        $cleared = $repo->clearBouncedForUser($userId, 'new-address@example.com');
        $this->assertSame(1, $cleared, 'Precondition: the bounce-clear UPDATE must have run and cleared the car');

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue(
            $result->isCompleteSuccess(),
            'syncOwnerFieldsToCars() must succeed when called immediately after the bounce-clear UPDATE — '
            . 'no transaction conflict between the two independent operations'
        );

        $car = $this->db->query('SELECT email, email_bounced FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame('new-address@example.com', $car->email, 'The sync must still propagate the new email');
        $this->assertSame(0, (int) $car->email_bounced, 'The bounce-clear result must not be undone by the subsequent sync');
    }
}
