<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
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
 * the hook file itself. That file's class name would otherwise collide with
 * this one's — normal suite runs scope to one testsuite config and never hit
 * it, but a cross-suite --filter that autoloads both fatals with "Cannot
 * redeclare class" — so this file carries the Integration suffix.
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

    // =========================================================================
    // Issue #1890: CarRepository::clearBouncedForUser() /
    // carIdsWithBouncedFlagButNoAddress() against a real database.
    //
    // These exercise exactly what a mocked unit test cannot: MySQL's actual
    // LOWER() comparison semantics, the cars_update AFTER UPDATE trigger
    // firing (a cars_hist row per changed car), and that the bounce-clear
    // UPDATE and syncOwnerFieldsToCars()'s own per-car UPDATEs can run back
    // to back in one request without a transaction conflict — the specific
    // risk class called out in the plan's "Database & Security
    // Considerations" section (bounce-clear is a bare autocommit UPDATE with
    // no beginTransaction() of its own).
    // =========================================================================

    /**
     * Two real cars for the same user, bounced under two different
     * addresses. Confirming the new users.email only matches one of them
     * proves the real UPDATE's WHERE clause (including MySQL's LOWER()
     * comparison) selects the correct row and leaves the other alone — a
     * mocked unit test can only pin the SQL text, not that MySQL evaluates
     * it as intended.
     *
     * The "current" car's bounced address is seeded as a case-DIFFERENT but
     * otherwise identical string to the confirmed email
     * ('NEW-Address@Example.com' vs. 'new-address@example.com') — not merely
     * a different address as the stale car is. This is what makes the
     * "must be left untouched" assertion below actually exercise the
     * LOWER() comparison: removing LOWER() from clearBouncedForUser()'s SQL
     * would break specifically this assertion (the car would then no longer
     * match the confirmed email case-sensitively... but the point is
     * clearBouncedForUser()'s WHERE clause matches on the ADDRESS DIFFERING,
     * so a case-only difference must NOT count as differing — proving the
     * comparison is genuinely case-insensitive, not merely coincidentally
     * passing because the two strings happen to already match byte-for-byte).
     *
     * Also verifies `email_suppressed` (set on both cars beforehand) is left
     * untouched by the UPDATE on both cars — proving the bounce-clear UPDATE
     * genuinely never writes that column, not just that its SQL text omits it.
     */
    public function testOnlyTheMatchingBouncedCarClearsAndGetsAHistoryRow(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com']);

        $repo = new CarRepository($this->db);

        $staleCarId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR1']);
        $repo->updateEmailBounced($staleCarId, true, 'OLD-ADDRESS@EXAMPLE.COM');
        $repo->updateEmailSuppressed($staleCarId, true);

        $currentCarId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR2']);
        // Case-DIFFERENT from, but otherwise identical to, the confirmed
        // email — so only the LOWER() comparison can tell this car's
        // recorded address already matches.
        $repo->updateEmailBounced($currentCarId, true, 'NEW-Address@Example.com');
        $repo->updateEmailSuppressed($currentCarId, true);

        // Clear cars_hist rows written by the calls above so this test's own
        // assertion below (exactly one new row) isn't polluted by them.
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

        // The cars_update trigger must have fired exactly once, for the
        // one car actually changed by the UPDATE — not for the untouched one.
        $histRows = $this->db->query(
            'SELECT car_id FROM cars_hist WHERE car_id IN (?, ?) ORDER BY car_id',
            [$staleCarId, $currentCarId]
        )->results();
        $this->assertCount(1, $histRows, 'cars_update trigger must fire exactly once, for the changed row only');
        $this->assertSame($staleCarId, (int) $histRows[0]->car_id);
    }

    /**
     * A car with email_bounced=1 and a NULL email_bounced_address (the
     * pre-existing data-integrity anomaly the plan describes) must be found
     * by the integrity-check SELECT beforehand, and then clears via the real
     * UPDATE — clearBouncedForUser()'s WHERE clause explicitly ORs on
     * "address IS NULL", which a unit test can pin as SQL text but not prove
     * MySQL evaluates as true for a genuinely NULL column.
     */
    public function testCarWithNullBouncedAddressIsFoundByIntegrityCheckAndClears(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com']);
        $repo = new CarRepository($this->db);

        $carId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR3']);
        // Write the anomaly directly — updateEmailBounced() itself forbids
        // this combination, so it can only exist from data predating that
        // guard (per the plan's docblock rationale for clearBouncedForUser()).
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

    /**
     * The specific transaction-ordering risk the plan calls out: bounce-clear
     * runs first (a bare autocommit UPDATE with no explicit beginTransaction()
     * of its own), then syncOwnerFieldsToCars() (which manages its own
     * per-car transactions) runs immediately after, in the same request. This
     * confirms neither call interferes with the other — the sync must still
     * fully succeed when called right after a real bounce-clear UPDATE.
     */
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

        // Mirrors the real confirm-by-link write and the hook's own ordering:
        // users.email changes first, then bounce-clear, then the sync.
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
