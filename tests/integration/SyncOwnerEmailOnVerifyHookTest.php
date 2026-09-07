<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for #1958 (confirmed email change via verify.php wasn't
 * syncing to cars.email). Validates the DB-backed sync mechanics the hook
 * (usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php) depends on:
 * a real database, a user whose email has just changed, syncOwnerFieldsToCars()
 * updates every owned car, and the exceptions it can throw match the hook's
 * catch clauses. Does not duplicate OwnerSyncOwnerFieldsToCarsTest.php
 * (#1873, general nine-field behavior) — this pins the specific sequence
 * #1958's hook relies on.
 *
 * The hook FILE's own control flow (run-once-per-request guard, partial-
 * failure logging, log category per catch branch) is covered directly in
 * tests/unit/security/SyncOwnerEmailOnVerifyHookTest.php, which `require`s
 * the hook file itself.
 *
 * Manual verification of the real confirm-by-link flow (clicking an actual
 * emailed link) is not automatable — no Playwright pattern in this repo
 * retrieves a Mailtrap-captured confirmation link — see
 * docs/development/DEPLOYMENT.md's "Hooker Hook Registration" section for
 * the manual verification steps.
 *
 * @see usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * @see usersc/classes/Owner.php Owner::syncOwnerFieldsToCars()
 * @see tests/integration/OwnerSyncOwnerFieldsToCarsTest.php
 * @see tests/integration/OwnerSyncOwnerFieldsToCarsFailureTest.php
 */
#[Group('integration')]
#[Group('owner')]
final class SyncOwnerEmailOnVerifyHookTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // Cleans up the row inserted by
            // testAdminScriptRecordCompletionRejectsUncastStringUserIdFromDbRow().
            $this->db->query('DELETE FROM fix_script_runs WHERE script_name = ?', [basename(__FILE__)]);
        }

        parent::tearDown();
    }

    /**
     * A DatabaseInterface proxy backed by the real connection, except that
     * query() reports a database error for the specific
     * `UPDATE cars SET ... WHERE id = ? AND user_id = ?` call issued by
     * CarRepository::updateCarForOwner() — forcing that call to throw
     * CarDatabaseException, exactly as a genuine deadlock or constraint
     * violation would. Mirrors
     * OwnerSyncOwnerFieldsToCarsFailureTest::dbFailingUpdateCarForOwner()
     * exactly; duplicated here (rather than shared) because that class is
     * `final` with a private helper, and this suite deliberately covers the
     * hook's own catch semantics rather than extending that class's fixture.
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
     * Core positive-path test for #1958: construct a real Owner for a user
     * whose users.email has just changed, call syncOwnerFieldsToCars(), and
     * confirm cars.email reflects the new address for every car the owner
     * has. Uses two cars to also confirm the sync is not scoped to a single
     * car.
     */
    public function testConfirmedEmailChangeSyncsToAllOwnedCars(): void
    {
        $userId = $this->createTestUser(['email' => 'old-address@example.com']);
        $carId1 = $this->createTestCar($userId, ['email' => 'old-address@example.com']);
        $carId2 = $this->createTestCar($userId, ['email' => 'old-address@example.com']);

        // Mirrors users/verify.php's own confirm-by-link write: users.email
        // is updated to the previously-staged email_new value immediately
        // before the verifySuccess hooks fire.
        $this->db->query("UPDATE users SET email = ? WHERE id = ?", ['new-address@example.com', $userId]);

        // This is the hook's entire body, reduced to its essential sequence
        // (see class docblock) — $userId here stands in for
        // (int) $verify->data()->id in the real hook.
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
     * Confirms the negative path the hook's catch block exists for: when
     * syncOwnerFieldsToCars() throws (a genuine per-car UPDATE failure, not
     * the row-count ambiguity — see OwnerSyncOwnerFieldsToCarsFailureTest's
     * class docblock), it throws CarDatabaseException, which the hook's
     * first catch clause (OwnerDatabaseException | CarDatabaseException)
     * names explicitly. This proves that catch clause is reachable and
     * matches what syncOwnerFieldsToCars() can actually throw against a real
     * database.
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
            // Exactly the combined catch clause the hook uses.
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

        // Confirm the car's email was NOT actually changed — the per-car
        // transaction rolled back before the exception propagated.
        $car = $this->db->query("SELECT email FROM cars WHERE id = ?", [$carId])->first();
        $this->assertNotNull($car);
        $this->assertSame(
            'old-address@example.com',
            $car->email,
            'cars.email must remain unchanged when the underlying UPDATE fails'
        );
    }

    /**
     * Confirms the hook's second catch clause (\Throwable, not \Exception —
     * see the hook's own comment for why) is reachable: a bare \TypeError
     * from the DB layer propagates uncaught through syncOwnerFieldsToCars().
     * The real DB layer coerces non-scalar bind params with a warning rather
     * than throwing (confirmed by direct experimentation), so this uses a
     * minimal DatabaseInterface stub that throws \TypeError directly instead.
     */
    public function testMalformedOwnerDataThrowsThrowableCatchableAsTheHookExpects(): void
    {
        $db = new class implements DatabaseInterface {
            // Unused; satisfies DatabaseInterface's @phpstan-impure contract.
            private int $calls = 0;

            public function query(string $sql, array $params = []): self
            {
                $this->calls++;
                // Simulates a TypeError-family Error surfacing from deep in the
                // DB layer when handed a malformed, untyped value — the class
                // of failure the hook's own comment calls out by name.
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
     * Regression test for the bug fixed in #1958's second commit: script 26
     * originally passed $user->data()->id (a string, straight off a DB row)
     * to admin_script_record_completion()'s `int $userId` parameter uncast.
     * Under this file's declare(strict_types=1), that throws TypeError rather
     * than coercing — confirmed here with a real DB-fetched id, not a
     * hand-typed string literal, since the whole point is that a DB row's
     * property is a string even when it looks like an integer.
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

        // The actual fix: casting avoids the TypeError.
        admin_script_record_completion(__FILE__, (int) $row->id);
    }
}
