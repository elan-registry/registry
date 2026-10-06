<?php

declare(strict_types=1);

namespace Tests\Unit\Classes;

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Exceptions\OwnerValidationException;
use ElanRegistry\Owner;
use ElanRegistry\OwnerSyncResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

/**
 * Unit tests for {@see Owner::updateProfileAndSync()} (#1891) — the thin
 * wrapper that chains update() and syncOwnerFieldsToCars() into one call.
 *
 * This is the wrapper's own wiring: does it call update() first, propagate
 * update()'s own exceptions (and convert any other \Throwable from before
 * the commit into one of them) before sync ever runs, and return sync()'s
 * result (including a non-complete-success result) rather than swallowing
 * it. The wrapper adds no validation of its own — Owner::update()'s existing
 * validateAndSanitizeFields() already rejects malformed input, and that
 * rejection is pinned at the unit level by tests/unit/OwnerValidationTest.php
 * and at this call site specifically below, because validateAndSanitizeFields()
 * is private and type coercion bugs can differ by the exact PHP types a caller
 * passes in.
 *
 * No real database: a hand-built {@see FakeDatabase} subclass answers the
 * handful of queries update() and syncOwnerFieldsToCars() issue against a
 * fixed, in-memory single- or multi-car owner. The live-DB chaining (real
 * users/profiles/cars rows) is covered once, thinly, by
 * tests/integration/OwnerUpdateProfileAndSyncTest.php — this file is pure
 * wiring and does not need a second live-DB test of the same thing.
 *
 * @author Jim Boone
 */
#[Group('unit')]
final class OwnerUpdateProfileAndSyncTest extends TestCase
{
    public const OWNER_ID = 42;

    /**
     * A FakeDatabase that answers update()'s and syncOwnerFieldsToCars()'s
     * queries for a fixed owner with $carCount owned cars, honoring exactly
     * one injected failure point.
     *
     * - `UPDATE users` / `UPDATE profiles` (via ->update()): always succeeds,
     *   unless $failUserUpdate/$failProfileUpdate is set.
     * - find()'s post-commit reload SELECT: returns the owner row with the
     *   fields that were just written (so this double doubles as "today's
     *   current values" for ownerContactFields()), unless $failReload is set,
     *   in which case it returns zero rows (simulating update()'s own
     *   documented non-fatal reload failure — Owner::updateProfileAndSync()'s
     *   own `_data` reset guards against this leaving stale pre-update values
     *   for sync).
     * - getCarsOwned()'s SELECT: returns $carCount synthetic car rows.
     * - updateCarForOwner()'s UPDATE: reports 1 row changed (so every car is
     *   treated as actually changed, never the ambiguous-zero branch).
     * - insertHistory()'s INSERT into cars_hist: succeeds, unless
     *   $failHistoryForCarId matches that car's id — the exact technique
     *   tests/integration/OwnerSyncOwnerFieldsToCarsTest.php uses at the
     *   integration tier (dbFailingHistoryInsert()), reproduced here without
     *   a live connection.
     */
    /**
     * @return FakeDatabase&object{queriesSeen: list<string>}
     */
    private function makeDatabase(
        int $carCount,
        bool $failUserUpdate = false,
        bool $failProfileUpdate = false,
        ?int $failHistoryForCarId = null,
        bool $failReload = false,
        bool $failCommit = false
    ) {
        return new class (
            $carCount,
            $failUserUpdate,
            $failProfileUpdate,
            $failHistoryForCarId,
            $failReload,
            $failCommit
        ) extends FakeDatabase {
            private string $lastSql = '';
            private bool $inTransaction = false;

            /** @var list<string> Every SQL string passed to query(), in call order. */
            public array $queriesSeen = [];

            public function __construct(
                private int $carCount,
                private bool $failUserUpdate,
                private bool $failProfileUpdate,
                private ?int $failHistoryForCarId,
                private bool $failReload,
                private bool $failCommit
            ) {
            }

            public function query(string $sql, array $params = []): self
            {
                $this->lastSql = $sql;
                $this->queriesSeen[] = $sql;
                return $this;
            }

            // Owner::commit()/beginTransaction() only call through to the
            // database when inTransaction() reports the matching state — the
            // base FakeDatabase always returns false here, which would make
            // $failCommit unreachable. Track it for real so the state this
            // test cares about (whether a later syncOwnerFieldsToCars() call
            // still sees an open transaction) stays correct too.
            public function beginTransaction(): bool
            {
                $this->inTransaction = true;
                return true;
            }

            // DatabaseInterface::inTransaction() declares @phpstan-impure; this
            // double tracks real state via a property, which PHPStan's purity
            // check reads as a pure getter even though the interface requires
            // the override.
            // @phpstan-ignore impureMethod.pure
            public function inTransaction(): bool
            {
                return $this->inTransaction;
            }

            public function commit(): bool
            {
                if ($this->failCommit) {
                    // Simulates a PDOException from a failed COMMIT (lost
                    // connection, deadlock) — not one of Owner's own exception
                    // types, so updateProfileAndSync() must convert it rather
                    // than let it reach a caller's catch ladder unconverted.
                    throw new \PDOException('simulated commit failure');
                }
                $this->inTransaction = false;
                return true;
            }

            public function rollBack(): bool
            {
                $this->inTransaction = false;
                return true;
            }

            public function update(string $table, array|int $id, array $fields): bool
            {
                if ($table === 'users' && $this->failUserUpdate) {
                    return false;
                }
                if ($table === 'profiles' && $this->failProfileUpdate) {
                    return false;
                }
                // updateCarForOwner()'s raw "UPDATE cars SET ..." goes through
                // query(), not here — this ->update() is only ever users/profiles.
                return true;
            }

            public function insert(string $table, array $fields = [], bool $update = false): bool
            {
                if ($table === 'cars_hist') {
                    $carId = (int) ($fields['car_id'] ?? 0);
                    return $this->failHistoryForCarId !== $carId;
                }
                return true;
            }

            public function count(): int
            {
                if (str_starts_with($this->lastSql, 'SELECT u.*')) {
                    // find()'s reload (real SQL: "SELECT u.*, p.city, ... FROM
                    // users u LEFT JOIN profiles p ...") — exactly one row,
                    // unless $failReload simulates the reload finding nothing.
                    return $this->failReload ? 0 : 1;
                }
                if (str_starts_with($this->lastSql, 'SELECT c.* FROM cars')) {
                    return $this->carCount;
                }
                if (str_starts_with($this->lastSql, 'UPDATE cars SET')) {
                    // Every per-car UPDATE reports exactly one row changed.
                    return 1;
                }
                return 0;
            }

            private ?object $ownerRow = null;

            /** @var list<object> */
            private array $carRows = [];

            public function first(bool $assoc = false): object
            {
                $this->ownerRow ??= (object) [
                    'id'      => OwnerUpdateProfileAndSyncTest::OWNER_ID,
                    'fname'   => 'Synced',
                    'lname'   => 'Owner',
                    'email'   => 'synced@example.com',
                    'city'    => 'Portland',
                    'state'   => 'Oregon',
                    'country' => 'United States',
                    'lat'     => 45.5,
                    'lon'     => -122.6,
                    'website' => 'https://example.com',
                ];
                return $this->ownerRow;
            }

            /** @return list<object> */
            public function results(bool $assoc = false): array
            {
                if ($this->carRows === []) {
                    for ($i = 1; $i <= $this->carCount; $i++) {
                        $this->carRows[] = (object) [
                            'id'            => $i,
                            'model'         => 'Elan',
                            'series'        => 'S4',
                            'variant'       => 'SE',
                            'type'          => 'FHC',
                            'chassis'       => 'T100' . $i,
                            'year'          => 1970,
                            'color'         => 'Red',
                            'engine'        => '',
                            'purchasedate'  => null,
                        ];
                    }
                }
                return $this->carRows;
            }
        };
    }

    /**
     * Build an Owner with its private $_data already populated (mirrors
     * OwnerContactRefresherTest's established reflection technique), so the
     * constructor's own find() query is bypassed and the double only has to
     * answer update()'s and sync()'s queries.
     */
    private function ownerLoadedWithId(DatabaseInterface $db, int $id = self::OWNER_ID): Owner
    {
        $owner = new Owner(null, $db);
        $ref = new \ReflectionClass(Owner::class);
        $dataProp = $ref->getProperty('_data');
        $dataProp->setValue($owner, (object) ['id' => $id]);
        return $owner;
    }

    // -------------------------------------------------------------------
    // Success / partial-sync / validation-failure wiring
    // -------------------------------------------------------------------

    public function testSuccessReturnsACompleteSuccessOwnerSyncResult(): void
    {
        $db = $this->makeDatabase(carCount: 2);
        $owner = $this->ownerLoadedWithId($db);

        $result = $owner->updateProfileAndSync(['fname' => 'Synced', 'lname' => 'Owner']);

        $this->assertTrue($result->isCompleteSuccess());
        $this->assertSame([1, 2], $result->updated);
        $this->assertSame(0, $result->failedCount());
    }

    public function testPartialSyncReturnsRatherThanThrowsAnIncompleteOwnerSyncResult(): void
    {
        // Car 2's history insert fails — its UPDATE must roll back and it
        // must land in $result->failed, while car 1 still succeeds, and the
        // call must return normally rather than throw.
        $db = $this->makeDatabase(carCount: 2, failHistoryForCarId: 2);
        $owner = $this->ownerLoadedWithId($db);

        $result = $owner->updateProfileAndSync(['fname' => 'Synced']);

        $this->assertFalse($result->isCompleteSuccess());
        $this->assertSame([1], $result->updated);
        $this->assertSame([2], $result->failed);
    }

    public function testValidationFailureThrowsBeforeSyncIsEverCalled(): void
    {
        // An out-of-range lat is rejected by validateAndSanitizeFields()
        // before any DB write. Asserting the exception type alone would still
        // pass if update() and syncOwnerFieldsToCars() ran in the other order
        // (sync first, then a throwing update()) — so this checks the actual
        // queries the double saw, not just that something was thrown.
        $db = $this->makeDatabase(carCount: 2);
        $owner = $this->ownerLoadedWithId($db);

        try {
            $owner->updateProfileAndSync(['lat' => '91']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            // Caught below, outside the try, so the assertions on $db run
            // even if a different exception type would otherwise mask them.
        }

        $this->assertSame(
            [],
            array_filter($db->queriesSeen, static fn (string $sql): bool => str_starts_with($sql, 'SELECT c.* FROM cars')),
            'syncOwnerFieldsToCars() must not read the car list when update() rejects the fields first'
        );
    }

    /**
     * Regression guard: updateProfileAndSync() must restore $_data to its
     * pre-call snapshot when update() throws before committing, not leave it
     * null. A caller that writes a field outside $ownerFields on its own
     * (usersc/user_settings.php's direct website-clear write, since
     * Owner::update() drops empty values) and then retries
     * syncOwnerFieldsToCars() on the SAME already-loaded Owner after this
     * throw must not hit syncOwnerFieldsToCars()'s "not loaded" guard — this
     * Owner really is still loaded; nothing about the rejected fields
     * invalidates that.
     */
    public function testValidationFailureRestoresDataSoARetriedSyncStillWorks(): void
    {
        $db = $this->makeDatabase(carCount: 2);
        $owner = $this->ownerLoadedWithId($db);

        try {
            $owner->updateProfileAndSync(['lat' => '91']);
            $this->fail('Expected OwnerValidationException was not thrown');
        } catch (OwnerValidationException $e) {
            // Caught below, outside the try, so the retry below still runs
            // even if a different exception type would otherwise mask it.
        }

        // Mirrors usersc/user_settings.php's retry: the same $owner, still
        // loaded, syncs a field written outside the failed updateProfileAndSync()
        // call. Before the fix, $_data stayed null and this threw
        // OwnerDatabaseException("not loaded") instead of succeeding.
        $result = $owner->syncOwnerFieldsToCars();

        $this->assertTrue($result->isCompleteSuccess());
        $this->assertSame([1, 2], $result->updated);
    }

    /**
     * Regression guard: a \Throwable from update() that is not one of its own
     * documented exception types (OwnerValidationException/OwnerUpdateException)
     * must still be reported as "nothing was written" rather than reach a
     * caller's catch ladder unconverted and be read as a post-commit sync
     * failure, which usersc/user_settings.php reports as "Owner details saved,
     * but...". A failed COMMIT (simulated here as a PDOException, matching
     * what a real lost-connection/deadlock failure throws) is exactly such a
     * \Throwable: it is neither of update()'s own exception types.
     */
    public function testThrowableFromUpdateBeforeCommitIsConvertedNotPassedThrough(): void
    {
        $db = $this->makeDatabase(carCount: 2, failCommit: true);
        $owner = $this->ownerLoadedWithId($db);

        $this->expectException(\ElanRegistry\Exceptions\OwnerUpdateException::class);
        $owner->updateProfileAndSync(['fname' => 'Synced']);
    }

    /**
     * Regression guard: a failed post-commit reload must not sync stale
     * pre-update values onto the owner's cars while reporting success.
     *
     * update()'s own post-commit find() failure is deliberately non-fatal
     * there (the write already succeeded, so a reload failure is logged, not
     * thrown). Without a reset, updateProfileAndSync() would leave $_data
     * holding its pre-update values when the reload failed, and
     * syncOwnerFieldsToCars() would read ownerContactFields() from that stale
     * $_data — copying the OLD name/location/website onto every owned car
     * and returning a complete-success OwnerSyncResult. Clearing $_data (not
     * $_carsOwned, which caches only which cars the owner has) before
     * update() runs means a failed reload leaves $_data null, which
     * syncOwnerFieldsToCars()'s own "not loaded" guard turns into a thrown
     * OwnerDatabaseException instead.
     */
    public function testReloadFailureAfterCommitThrowsRatherThanSyncsStaleData(): void
    {
        $db = $this->makeDatabase(carCount: 2, failReload: true);
        $owner = $this->ownerLoadedWithId($db);

        $this->expectException(\ElanRegistry\Exceptions\OwnerDatabaseException::class);
        $this->expectExceptionMessage('failed to load');
        $owner->updateProfileAndSync(['fname' => 'Synced']);
    }

    // -------------------------------------------------------------------
    // Wrong-typed values arriving at this call site (#1891 plan item 1.4)
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function wrongTypedFieldsProvider(): array
    {
        return [
            // is_numeric() on an array or a non-numeric string returns false
            // cleanly, so these are rejected by validateAndSanitizeFields()'s
            // own check, with no type coercion involved.
            'lat non-numeric string' => [['lat' => 'north']],
            'lon non-numeric string' => [['lon' => 'east']],
            'lat as array'           => [['lat' => ['a', 'b']]],
            'lon as array'           => [['lon' => ['a', 'b']]],
            // fname/lname/city/state/country/website/email/password each got an
            // explicit is_string() guard (#1891 fix) so a wrong-typed value
            // raises OwnerValidationException before reaching the string-typed
            // helper (InputSanitizer::normalize(), trim(), strlen(), etc.) that
            // would otherwise take a non-string argument and throw an uncaught
            // TypeError under strict_types=1.
            'fname as array'    => [['fname' => ['Al', 'Ice']]],
            'website as array'  => [['website' => ['https://example.com']]],
        ];
    }

    /**
     * Each of these wrong-typed values is rejected cleanly by
     * validateAndSanitizeFields()'s own type check (is_numeric() for lat/lon,
     * is_string() for the rest — #1891 fix), before any string-typed helper runs.
     */
    #[DataProvider('wrongTypedFieldsProvider')]
    public function testWrongTypedValueRaisesValidationExceptionNotTypeError(array $fields): void
    {
        $db = $this->makeDatabase(carCount: 1);
        $owner = $this->ownerLoadedWithId($db);

        try {
            $owner->updateProfileAndSync($fields);
            $this->fail('Expected OwnerValidationException was not thrown for: ' . json_encode($fields));
        } catch (\Throwable $e) {
            $this->assertInstanceOf(
                OwnerValidationException::class,
                $e,
                'updateProfileAndSync() must raise OwnerValidationException, not a '
                . get_class($e) . ', for ' . json_encode($fields) . ': ' . $e->getMessage()
            );
        }
    }
}
