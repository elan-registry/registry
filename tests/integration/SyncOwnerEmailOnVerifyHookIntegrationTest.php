<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // #1890, #2101: the hook's bounce-clear against real MySQL. The bounce
    // lives on the owner's profile. These tests include the real hook file, so
    // the repository clear, the transaction and the flag-log row run as in
    // production.

    /**
     * Run the real hook file the way users/verify.php does.
     *
     * The hook reads the global $verify and guards against a second run in the
     * same request with a $GLOBALS key, so the key is reset here.
     */
    private function runVerifySuccessHook(int $userId): void
    {
        $GLOBALS['verify'] = new class ($userId) {
            public function __construct(private int $userId)
            {
            }

            public function exists(): bool
            {
                return true;
            }

            public function data(): object
            {
                return (object) ['id' => $this->userId];
            }
        };
        unset($GLOBALS['__elanregistry_verify_email_synced_' . $userId]);

        try {
            (static function (): void {
                include __DIR__ . '/../../usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php';
            })();
        } finally {
            unset($GLOBALS['verify'], $GLOBALS['__elanregistry_verify_email_synced_' . $userId]);
        }
    }

    /**
     * The three cases that clear the bounce: a different address, a NULL
     * address and an empty address.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function clearingBouncedAddressProvider(): array
    {
        return [
            'different address' => ['old-address@example.com'],
            'NULL address'      => [null],
            'empty address'     => [''],
        ];
    }

    #[DataProvider('clearingBouncedAddressProvider')]
    public function testHookClearsTheProfileBounceAndWritesOneEmailChangeLogRow(?string $bouncedAddress): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $this->setOwnerBounced($userId, 'placeholder@example.com', '2026-03-04 05:06:07');
        $this->db->query('UPDATE profiles SET email_bounced_address = ? WHERE user_id = ?', [$bouncedAddress, $userId]);
        $this->setOwnerOptedOut($userId);
        $this->createTestCar($userId, ['email' => 'new-address@example.com']);

        $before = $this->getOwnerEmailFlags($userId);
        $this->assertSame('1', (string) $before['email_bounced'], 'Test setup: the bounce must be set');
        $this->assertSame($bouncedAddress, $before['email_bounced_address'], 'Test setup: the stored address');
        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100));

        $this->runVerifySuccessHook($userId);

        $after = $this->getOwnerEmailFlags($userId);
        $this->assertSame('0', (string) $after['email_bounced'], 'The bounce must be cleared');
        $this->assertNull($after['email_bounced_address']);
        $this->assertNull($after['email_bounced_at']);
        $this->assertNotNull($after['email_bounce_cleared_at'], 'The clear time must be recorded');
        $this->assertSame('1', (string) $after['email_opted_out'], 'A bounce clear must not touch the opt-out');

        $changes = (new CarRepository($this->db))->recentFlagChanges($userId, 100);
        $this->assertCount(1, $changes, 'One clear writes exactly one flag-log row');
        $this->assertSame('email_bounced', $changes[0]['flag']);
        $this->assertSame(1, $changes[0]['old']);
        $this->assertSame(0, $changes[0]['new']);
        $this->assertSame('email_change', $changes[0]['source']);
        $this->assertSame($userId, $changes[0]['acting_user_id']);
    }

    /**
     * The matching rule is strtolower(trim()): a bounce on the address that
     * the owner confirmed stays, whatever its case or surrounding whitespace.
     * Both sides differ in case, so the rule must apply to both.
     */
    public function testHookKeepsTheBounceWhenTheAddressMatchesUnderTheMatchingRule(): void
    {
        $userId = $this->createTestUser(['email' => 'New-Address@Example.COM'], true);
        $this->setOwnerBounced($userId, "  new-ADDRESS@example.com \t", '2026-03-04 05:06:07');
        $before = $this->getOwnerEmailFlags($userId);

        $this->runVerifySuccessHook($userId);

        $this->assertSame($before, $this->getOwnerEmailFlags($userId), 'A bounce on the same address must not change');
        $this->assertSame(
            [],
            (new CarRepository($this->db))->recentFlagChanges($userId, 100),
            'A kept bounce writes no flag-log row'
        );
    }

    public function testHookLeavesAnOwnerWithNoBounceUnchangedAndWritesNoLogRow(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $this->setOwnerBlocked($userId, 'blocked@example.com', '2026-02-03 04:05:06', 'spam');
        $before = $this->getOwnerEmailFlags($userId);

        $this->runVerifySuccessHook($userId);

        $this->assertSame($before, $this->getOwnerEmailFlags($userId), 'A block must stay after an email confirmation');
        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100));
    }

    public function testHookForOwnerWithNoProfileClearsNothingAndDoesNotThrow(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com']);
        $carId = $this->createTestCar($userId, ['email' => 'old-address@example.com']);

        $this->runVerifySuccessHook($userId);

        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100));
        $this->assertSame(
            'new-address@example.com',
            $this->db->query('SELECT email FROM cars WHERE id = ?', [$carId])->first()->email,
            'The owner-field sync after the bounce-clear still runs'
        );
    }

    /**
     * carIdsWithBouncedFlagButNoAddress() keeps its name until #2356. It
     * reports the owner's cars only when the profile bounce has no address.
     */
    public function testIntegrityCheckReportsNothingForAnOwnerWithNoBounce(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $this->createTestCar($userId, ['chassis' => 'BOUNCECLR3']);

        $this->assertSame([], (new CarRepository($this->db))->carIdsWithBouncedFlagButNoAddress($userId));
    }

    public function testIntegrityCheckReportsNothingForABounceWithAnAddress(): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $this->createTestCar($userId, ['chassis' => 'BOUNCECLR3']);
        $this->setOwnerBounced($userId, 'old-address@example.com');

        $this->assertSame([], (new CarRepository($this->db))->carIdsWithBouncedFlagButNoAddress($userId));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function missingAddressProvider(): array
    {
        return [
            'NULL address'  => [null],
            'empty address' => [''],
        ];
    }

    #[DataProvider('missingAddressProvider')]
    public function testIntegrityCheckReportsTheOwnersCarsForABounceWithNoAddress(?string $address): void
    {
        $userId = $this->createTestUser(['email' => 'new-address@example.com'], true);
        $carId1 = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR3']);
        $carId2 = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR4']);
        $this->setOwnerBounced($userId, 'placeholder@example.com');
        $this->db->query('UPDATE profiles SET email_bounced_address = ? WHERE user_id = ?', [$address, $userId]);

        $found = (new CarRepository($this->db))->carIdsWithBouncedFlagButNoAddress($userId);

        sort($found);
        $expected = [$carId1, $carId2];
        sort($expected);
        $this->assertSame($expected, $found, 'A bounce with no address reports the owner\'s cars');
    }

    /** Bounce-clear (a transaction) followed by the sync's own transactions must not conflict. */
    public function testSyncOwnerFieldsToCarsSucceedsImmediatelyAfterBounceClear(): void
    {
        $userId = $this->createTestUser(['email' => 'old-address@example.com'], true);
        $repo = new CarRepository($this->db);

        $carId = $this->createTestCar($userId, [
            'chassis' => 'BOUNCECLR5',
            'email'   => 'old-address@example.com',
        ]);
        $this->setOwnerBounced($userId, 'old-address@example.com');

        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['new-address@example.com', $userId]);

        $repo->beginTransaction();
        $cleared = $repo->clearBouncedForUser($userId, 'new-address@example.com');
        $repo->commit();
        $this->assertSame(1, $cleared, 'Precondition: the bounce-clear must have run and cleared the profile');

        $owner = new Owner($userId);
        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue(
            $result->isCompleteSuccess(),
            'syncOwnerFieldsToCars() must succeed when called immediately after the bounce clear — '
            . 'no transaction conflict between the two independent operations'
        );

        $car = $this->db->query('SELECT email FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame('new-address@example.com', $car->email, 'The sync must still propagate the new email');
        $this->assertSame(
            '0',
            (string) $this->getOwnerEmailFlags($userId)['email_bounced'],
            'The bounce-clear result must not be undone by the subsequent sync'
        );
    }
}
