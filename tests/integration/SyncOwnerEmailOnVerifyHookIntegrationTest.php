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

    // #1890, #2101, #2356: the hook's flag clear against real MySQL. The flags
    // live on the owner's profile. These tests include the real hook file, so
    // the repository clear, the transaction and the flag-log rows run as in
    // production. users/verify.php writes users.email before the hook runs, so
    // a test simulates a confirmed change by leaving email_address_seen at the
    // old address.

    private const OLD_ADDRESS = 'old-address@example.com';
    private const NEW_ADDRESS = 'new-address@example.com';
    private const OLD_SINCE   = '2020-01-02 03:04:05';

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
     * An owner whose users.email is the new address and whose profile still
     * records the old one as the address last seen: the state after
     * users/verify.php wrote the confirmed address, before the hook runs.
     */
    private function createOwnerWithConfirmedChange(): int
    {
        $userId = $this->createTestUser(['email' => self::NEW_ADDRESS], true);
        $this->setEmailAddressSinceSeen($userId, self::OLD_ADDRESS, self::OLD_SINCE);

        return $userId;
    }

    /**
     * The three stored-address states of a flag that a confirmed change
     * clears: a different address, a NULL address and an empty address.
     *
     * @return array<string, array{0: ?string}>
     */
    public static function clearingFlagAddressProvider(): array
    {
        return [
            'different address' => [self::OLD_ADDRESS],
            'NULL address'      => [null],
            'empty address'     => [''],
        ];
    }

    #[DataProvider('clearingFlagAddressProvider')]
    public function testHookClearsTheProfileBounceAndWritesOneEmailChangeLogRow(?string $bouncedAddress): void
    {
        $userId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBounced($userId, 'placeholder@example.com', '2026-03-04 05:06:07');
        $this->db->query('UPDATE profiles SET email_bounced_address = ? WHERE user_id = ?', [$bouncedAddress, $userId]);
        $this->setOwnerOptedOut($userId);
        $this->createTestCar($userId, ['email' => self::NEW_ADDRESS]);

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
        $this->assertSame('1', (string) $after['email_opted_out'], 'A confirmed change must not touch the opt-out');
        $this->assertSame(self::NEW_ADDRESS, $after['email_address_seen'], 'The hook records the confirmed address');
        $this->assertNotSame(self::OLD_SINCE, $after['email_address_since'], 'The hook records the time of the change');

        $changes = (new CarRepository($this->db))->recentFlagChanges($userId, 100);
        $this->assertCount(1, $changes, 'One clear writes exactly one flag-log row');
        $this->assertSame('email_bounced', $changes[0]['flag']);
        $this->assertSame(1, $changes[0]['old']);
        $this->assertSame(0, $changes[0]['new']);
        $this->assertSame('email_change', $changes[0]['source']);
        $this->assertSame($userId, $changes[0]['acting_user_id']);
    }

    /** A confirmed change to a different address clears the block with its address, date and cause (#2356). */
    public function testHookClearsTheProfileBlockAndWritesOneEmailChangeLogRow(): void
    {
        $userId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerBlocked($userId, self::OLD_ADDRESS, '2026-02-03 04:05:06', 'spam');

        $this->runVerifySuccessHook($userId);

        $after = $this->getOwnerEmailFlags($userId);
        $this->assertSame('0', (string) $after['email_blocked'], 'The block must be cleared');
        $this->assertNull($after['email_blocked_address']);
        $this->assertNull($after['email_blocked_at']);
        $this->assertNull($after['email_blocked_cause']);

        $changes = (new CarRepository($this->db))->recentFlagChanges($userId, 100);
        $this->assertCount(1, $changes);
        $this->assertSame('email_blocked', $changes[0]['flag']);
        $this->assertSame(1, $changes[0]['old']);
        $this->assertSame(0, $changes[0]['new']);
        $this->assertSame('email_change', $changes[0]['source']);
    }

    /** The opt-out stays, and a log row is written for no flag but the two that changed. */
    public function testHookKeepsTheOptOutAfterAConfirmedChange(): void
    {
        $userId = $this->createOwnerWithConfirmedChange();
        $this->setOwnerOptedOut($userId, true, '2026-03-04 05:06:07');

        $this->runVerifySuccessHook($userId);

        $after = $this->getOwnerEmailFlags($userId);
        $this->assertSame('1', (string) $after['email_opted_out']);
        $this->assertSame('2026-03-04 05:06:07', $after['email_opted_out_at']);
        $this->assertSame(self::NEW_ADDRESS, $after['email_address_seen'], 'The change itself is recorded');
        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100), 'No flag changed, so no log row');
    }

    /**
     * The match is strtolower(trim()): a re-verify of the address already
     * recorded is no change, whatever its case or surrounding whitespace. The
     * bounce stays and no log row is written.
     */
    public function testHookKeepsTheBounceWhenTheAddressIsTheOneAlreadyRecorded(): void
    {
        $userId = $this->createTestUser(['email' => 'New-Address@Example.COM'], true);
        $this->setOwnerBounced($userId, "  new-ADDRESS@example.com \t", '2026-03-04 05:06:07');
        $this->setEmailAddressSinceSeen($userId, self::NEW_ADDRESS, self::OLD_SINCE);
        $before = $this->getOwnerEmailFlags($userId);

        $this->runVerifySuccessHook($userId);

        $this->assertSame($before, $this->getOwnerEmailFlags($userId), 'A re-verify of the same address must change nothing');
        $this->assertSame(
            [],
            (new CarRepository($this->db))->recentFlagChanges($userId, 100),
            'A kept bounce writes no flag-log row'
        );
    }

    public function testHookLeavesAnOwnerWithNoFlagUnchangedAndWritesNoLogRow(): void
    {
        $userId = $this->createTestUser(['email' => self::NEW_ADDRESS], true);
        $before = $this->getOwnerEmailFlags($userId);

        $this->runVerifySuccessHook($userId);

        $this->assertSame($before, $this->getOwnerEmailFlags($userId));
        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100));
    }

    public function testHookForOwnerWithNoProfileClearsNothingAndDoesNotThrow(): void
    {
        $userId = $this->createTestUser(['email' => self::NEW_ADDRESS]);
        $carId = $this->createTestCar($userId, ['email' => self::OLD_ADDRESS]);

        $this->runVerifySuccessHook($userId);

        $this->assertSame([], (new CarRepository($this->db))->recentFlagChanges($userId, 100));
        $this->assertSame(
            self::NEW_ADDRESS,
            $this->db->query('SELECT email FROM cars WHERE id = ?', [$carId])->first()->email,
            'The owner-field sync after the flag clear still runs'
        );
    }

    /**
     * The flag clear (a transaction) followed by the sync's own transactions
     * must not conflict. The hook runs both.
     */
    public function testHookSyncsTheCarsAfterTheFlagClear(): void
    {
        $userId = $this->createTestUser(['email' => self::OLD_ADDRESS], true);
        $carId = $this->createTestCar($userId, ['chassis' => 'BOUNCECLR5', 'email' => self::OLD_ADDRESS]);
        $this->setOwnerBounced($userId, self::OLD_ADDRESS);
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', [self::NEW_ADDRESS, $userId]);

        $this->runVerifySuccessHook($userId);

        $car = $this->db->query('SELECT email FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(self::NEW_ADDRESS, $car->email, 'The sync must still propagate the new email');
        $flags = $this->getOwnerEmailFlags($userId);
        $this->assertSame('0', (string) $flags['email_bounced'], 'The sync must not undo the clear');
        $this->assertSame(self::NEW_ADDRESS, $flags['email_address_seen']);
    }
}
