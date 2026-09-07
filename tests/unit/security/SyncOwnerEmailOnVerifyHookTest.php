<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * (issue #1958), exercising the hook FILE directly rather than reproducing
 * its logic.
 *
 * The hook is a plain included script with no enclosing function, but it has
 * no dependency on users/verify.php itself — it reads a `global $verify` and
 * calls Owner. Both are substitutable here: `$verify` is a minimal stub
 * exposing the two members the hook touches (`exists()` and `data()->id`),
 * and `Owner`'s DB handle is supplied through the `dbi()` seam its
 * constructor falls back to, defined once below. So the file can simply be
 * `require`d, exactly as tests/unit/security/TurnstileTest.php does for the
 * sibling hook login_form_turnstile.php.
 *
 * What only this tier can cover — the hook's own control flow, which the
 * integration suite's direct Owner calls cannot reach:
 *
 *   * the run-once-per-request guard. users/verify.php fires verifySuccess
 *     from three sites, two of which (295 and 315) run on the SAME request
 *     for every real confirmation, because the confirm branch falls through
 *     with $verify_success = TRUE instead of exit()ing. Without the guard the
 *     sync — and its per-car cars_hist trigger rows — happens twice.
 *   * which LogCategories constant each failure branch uses.
 *   * that a partial OwnerSyncResult is logged at all: per-car failures come
 *     back through the return value, never as an exception, so the catch
 *     blocks cannot see them. A car skipped for no longer being owned is not
 *     such a failure — it lands in `skipped`, which leaves isCompleteSuccess()
 *     true, so it must not reach the partial-sync log branch (#1954).
 *   * that a missing/non-existent $verify returns silently.
 *
 * @see usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php
 * @see tests/integration/SyncOwnerEmailOnVerifyHookTest.php
 */
#[Group('fast')]
#[Group('unit')]
final class SyncOwnerEmailOnVerifyHookTest extends TestCase
{
    private const HOOK = __DIR__
        . '/../../../usersc/plugins/hooker/hooks/sync_owner_email_on_verify.php';

    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries, $verify;
        $mockLogEntries = [];
        $verify = null;
        // Always a real instance so dbi() and carLookupCount() never face null;
        // each test replaces it with one scripted for the branch it pins.
        $GLOBALS['hookTestDb'] = new HookTestDb();

        // Clear any guard flags left by a previous test in this process — the
        // guard is deliberately per-request state, and each test is a request.
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, '__elanregistry_verify_email_synced_')) {
                unset($GLOBALS[$key]);
            }
        }
    }

    /**
     * The hook is `require`d (not `require_once`) so a single test can fire it
     * twice, reproducing verify.php's two same-request hook fires.
     */
    private function fireHook(): void
    {
        require self::HOOK;
    }

    /** A stand-in for the User instance verify.php leaves in $verify. */
    private function fakeVerify(int $userId, bool $exists = true): object
    {
        return new class ($userId, $exists) {
            public function __construct(private int $userId, private bool $exists)
            {
            }
            public function exists(): bool
            {
                return $this->exists;
            }
            public function data(): object
            {
                return (object) ['id' => $this->userId];
            }
        };
    }

    /**
     * Asserts the sync reached the database (or did not) by counting the car
     * SELECT that Owner::getCarsOwned() issues.
     */
    private function carLookupCount(): int
    {
        return $GLOBALS['hookTestDb']->carLookups;
    }

    public function testMissingVerifyGlobalSyncsNothingAndLogsNothing(): void
    {
        global $mockLogEntries, $verify;
        $verify = null;
        $GLOBALS['hookTestDb'] = new HookTestDb();

        $this->fireHook();

        $this->assertSame(0, $this->carLookupCount(), 'No sync may be attempted without a $verify');
        $this->assertSame([], $mockLogEntries);
    }

    public function testNonExistentVerifyUserSyncsNothingAndLogsNothing(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(42, exists: false);
        $GLOBALS['hookTestDb'] = new HookTestDb();

        $this->fireHook();

        $this->assertSame(0, $this->carLookupCount());
        $this->assertSame([], $mockLogEntries);
    }

    /**
     * The #1958 double-fire regression guard. verify.php's confirm path runs
     * the verifySuccess hook twice in one request (lines 295 and 315); the
     * sync must still execute exactly once.
     */
    public function testHookFiredTwiceInOneRequestSyncsOnlyOnce(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $this->fireHook();
        $this->fireHook();

        $this->assertSame(
            1,
            $this->carLookupCount(),
            'The hook fires twice per confirmation (verify.php lines 295 and 315); without the '
            . 'run-once guard the sync — and its per-car cars_hist trigger rows — is duplicated'
        );
    }

    /** A single fire must actually reach the sync — proving the guard is not a blanket block. */
    public function testSingleFireRunsTheSync(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $this->fireHook();

        $this->assertSame(1, $this->carLookupCount());
        $this->assertSame(
            [],
            array_filter(
                $mockLogEntries,
                static fn(array $e): bool => str_contains($e['message'], 'sync_owner_email_on_verify:')
            ),
            'A fully successful sync must log nothing from the hook itself'
        );
    }

    /** The guard keys on user id, so a different user is still allowed to sync. */
    public function testGuardIsScopedToTheVerifiedUserId(): void
    {
        global $verify;
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]]);

        $verify = $this->fakeVerify(7);
        $this->fireHook();
        $verify = $this->fakeVerify(8);
        $this->fireHook();

        $this->assertSame(2, $this->carLookupCount());
    }

    /**
     * Per-car failures are returned in OwnerSyncResult, never thrown — so the
     * catch blocks cannot see them. Before this branch existed they vanished
     * entirely, since the hook shows the user nothing.
     */
    public function testPartialSyncResultIsLoggedUnderOwnerErrors(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        // failUpdate makes CarRepository::updateCarForOwner() throw a plain
        // \RuntimeException, which syncOwnerFieldsToCars()'s per-car \Throwable
        // handler records as a failed car rather than propagating.
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]], failUpdate: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_OWNER_ERRORS, $entry['category']);
        $this->assertStringContainsString('partial sync for user 7', $entry['message']);
        $this->assertStringContainsString('0 of 1 car(s) updated', $entry['message']);
        $this->assertStringContainsString('Car 100 could not be updated.', $entry['message']);
    }

    /**
     * An infrastructure fault (deadlock, lock-wait timeout) surfaces as
     * OwnerDatabaseException from getCarsOwned() and must log under
     * DATABASE_ERROR without interrupting verify.php's render.
     */
    public function testDatabaseExceptionIsLoggedUnderDatabaseError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(failCarLookup: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $entry['category']);
        $this->assertStringContainsString('car owner-field sync failed for user 7', $entry['message']);
    }

    /**
     * A TypeError is not a database fault, so the \Throwable branch must log
     * under SYSTEM_ERROR — matching usersc/user_settings.php's precedent.
     * PHP's Error hierarchy does not extend Exception, which is why that catch
     * must be \Throwable rather than \Exception.
     */
    public function testThrowableIsLoggedUnderSystemErrorNotDatabaseError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(throwTypeErrorOnCarLookup: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(
            LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
            $entry['category'],
            'A TypeError is a system fault, not a database fault'
        );
        $this->assertStringContainsString('unexpected TypeError', $entry['message']);
    }

    /**
     * Isolates the hook's own log line from any Owner-internal logging.
     *
     * @param array<int, array{user_id: mixed, category: string, message: string}> $entries
     * @return array{user_id: mixed, category: string, message: string}
     */
    private function soleHookLogEntry(array $entries): array
    {
        $hookEntries = array_values(array_filter(
            $entries,
            static fn(array $e): bool => str_contains($e['message'], 'sync_owner_email_on_verify:')
        ));

        $this->assertCount(1, $hookEntries, 'The hook must log exactly one line for this outcome');

        return $hookEntries[0];
    }
}

/**
 * Minimal scriptable DatabaseInterface for driving Owner through the hook.
 *
 * Owner::find() is bypassed (the hook's Owner is loaded via the users/profiles
 * SELECT this fake answers), and only the handful of calls
 * syncOwnerFieldsToCars() actually makes are modelled. Each constructor flag
 * selects one failure mode so a single test can pin one hook branch.
 */
final class HookTestDb implements DatabaseInterface
{
    public int $carLookups = 0;

    // Unused; satisfies DatabaseInterface's @phpstan-impure contract. carLookups is what tests assert on.
    private int $calls = 0;
    private int $count = 0;
    /** @var array<int, object> */
    private array $rows = [];
    private bool $error = false;

    /** @param array<int, object> $cars */
    public function __construct(
        private array $cars = [],
        private bool $failCarLookup = false,
        private bool $failUpdate = false,
        private bool $throwTypeErrorOnCarLookup = false
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->error = false;

        if (str_contains($sql, 'FROM users u')) {
            // Owner::find() — the owner profile bundle.
            $this->rows = [(object) [
                'id' => $params[0], 'fname' => 'Test', 'lname' => 'Owner',
                'email' => 'new-address@example.com', 'city' => 'Portland',
                'state' => 'Oregon', 'country' => 'United States',
                'lat' => null, 'lon' => null, 'website' => '',
            ]];
            $this->count = 1;
            return $this;
        }

        if (str_contains($sql, 'FROM cars c WHERE c.user_id')) {
            $this->carLookups++;

            if ($this->throwTypeErrorOnCarLookup) {
                throw new \TypeError('simulated: bindValue() received a non-scalar value');
            }
            if ($this->failCarLookup) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->rows = $this->cars;
            $this->count = count($this->cars);
            return $this;
        }

        if (str_starts_with($sql, 'UPDATE cars SET')) {
            if ($this->failUpdate) {
                // A plain Exception, so syncOwnerFieldsToCars()'s per-car
                // \Throwable handler records a failed car instead of propagating.
                throw new \RuntimeException('simulated per-car update failure');
            }

            // One row changed, so the caller skips the carBelongsToOwner()
            // ambiguity check and goes straight to the history insert.
            $this->count = 1;
            $this->rows = [];
            return $this;
        }

        $this->count = 0;
        $this->rows = [];
        return $this;
    }

    public function get(string $table, array $where): self
    {
        return $this;
    }
    public function insert(string $table, array $fields = [], bool $update = false): bool
    {
        return true;
    }
    public function update(string $table, array|int $id, array $fields): bool
    {
        return true;
    }
    public function delete(string $table, array|int $where): self
    {
        return $this;
    }
    public function error(): bool
    {
        $this->calls++;
        return $this->error;
    }
    public function errorString(): string
    {
        $this->calls++;
        return $this->error ? 'simulated deadlock' : '';
    }
    public function errorInfo(): array
    {
        $this->calls++;
        return [];
    }
    public function count(): int
    {
        $this->calls++;
        return $this->count;
    }
    public function first(bool $assoc = false): array|object
    {
        $this->calls++;
        return $this->rows[0] ?? [];
    }
    public function results(bool $assoc = false): array
    {
        $this->calls++;
        return $this->rows;
    }
    public function lastId(): int
    {
        $this->calls++;
        return 0;
    }
    public function beginTransaction(): bool
    {
        return true;
    }
    public function commit(): bool
    {
        return true;
    }
    public function rollBack(): bool
    {
        return true;
    }
    public function inTransaction(): bool
    {
        $this->calls++;
        return false;
    }
}

// The seam the hook's `new Owner($userId)` falls back to when no DB is passed.
// Defined here rather than in bootstrap-unit.php because this is the only unit
// test that needs it; guarded so it cannot collide if that ever changes.
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'] ?? new HookTestDb();
    }
}
