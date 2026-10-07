<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #1958: runs the hook file itself, with a stub $verify and a fake DB
 * behind dbi(). Only this tier reaches the hook's own control flow: the
 * run-once guard (verify.php fires verifySuccess twice on one confirmation
 * request), the log category of each failure branch, and the logging of a
 * partial OwnerSyncResult, which is returned and never thrown.
 *
 * @see tests/integration/SyncOwnerEmailOnVerifyHookIntegrationTest.php
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
        $GLOBALS['hookTestDb'] = new HookTestDb();

        // The run-once guard is per-request state; each test is a request.
        foreach (array_keys($GLOBALS) as $key) {
            if (str_starts_with((string) $key, '__elanregistry_verify_email_synced_')) {
                unset($GLOBALS[$key]);
            }
        }
    }

    /**
     * Not require_once, so one test can fire the hook twice as verify.php does.
     */
    private function fireHook(): void
    {
        require self::HOOK;
    }

    /**
     * The hook must not read $verify->data()->email: verify.php builds $verify
     * before the email-change UPDATE, so that address is the old one. Tests
     * pass a wrong email here to prove the hook ignores it.
     */
    private function fakeVerify(int $userId, bool $exists = true, string $email = 'unused-old-email@example.com'): object
    {
        return new class ($userId, $exists, $email) {
            public function __construct(private int $userId, private bool $exists, private string $email)
            {
            }
            public function exists(): bool
            {
                return $this->exists;
            }
            public function data(): object
            {
                return (object) ['id' => $this->userId, 'email' => $this->email];
            }
        };
    }

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
            'Without the run-once guard the sync and its cars_hist rows are duplicated'
        );
    }

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
     * Per-car failures are returned, never thrown, so no catch block sees them.
     */
    public function testPartialSyncResultIsLoggedUnderOwnerErrors(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(cars: [(object) ['id' => 100]], failUpdate: true);

        $this->fireHook();

        $entry = $this->soleHookLogEntry($mockLogEntries);
        $this->assertSame(LogCategories::LOG_CATEGORY_OWNER_ERRORS, $entry['category']);
        $this->assertStringContainsString('partial sync for user 7', $entry['message']);
        $this->assertStringContainsString('0 of 1 car(s) updated', $entry['message']);
        $this->assertStringContainsString('Car 100 could not be updated.', $entry['message']);
    }

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
     * Error does not extend Exception, so the hook must catch \Throwable.
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

    /**
     * @param array<int, array{user_id: mixed, category: string, message: string}> $entries
     * @return array<int, array{user_id: mixed, category: string, message: string}>
     */
    private function bounceClearLogEntries(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear')
                || str_contains($e['message'], 'cleared bounce flag')
                || str_contains($e['message'], 'had email_bounced=1')
        ));
    }

    // Bounce-clear block (#1890)

    public function testBounceClearedOnCarWithNonMatchingBouncedAddress(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1
        );

        $this->fireHook();

        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls, 'The bounce-clear UPDATE must be issued exactly once');

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(1, $entries, 'Exactly one bounce-clear log line must fire when rows were cleared');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $entries[0]['category']);
        $this->assertStringContainsString('cleared bounce flag on 1 car(s)', $entries[0]['message']);
        $this->assertStringContainsString('for user 7', $entries[0]['message']);
    }

    /**
     * $verify carries the old address, which equals the car's bounced address;
     * only the fresh Owner lookup returns the new, confirmed email.
     */
    public function testBounceClearUsesFreshOwnerLookupNotVerifysStaleCachedEmail(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7, email: 'old-address@example.com');
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            ownerEmail: 'new-address@example.com',
            bounceClearAffectedRows: 1
        );

        $this->fireHook();

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The bounce-clear UPDATE must be issued exactly once'
        );
        $this->assertSame(
            ['new-address@example.com'],
            $GLOBALS['hookTestDb']->bounceClearEmails,
            'clearBouncedForUser() must use the fresh Owner-lookup email, not the stale $verify email'
        );
    }

    /**
     * An empty email would make every bounced car with no address match the WHERE clause.
     */
    public function testEmptyOwnerLookupEmailSkipsBounceClearAndLogsSystemError(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            ownerNotFound: true
        );

        $this->fireHook();

        $this->assertSame(
            0,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'clearBouncedForUser() must never be called with an unreadable/empty current email'
        );

        $entry = null;
        foreach ($mockLogEntries as $candidate) {
            if (str_contains($candidate['message'], 'skipping bounce-clear for user 7')) {
                $entry = $candidate;
                break;
            }
        }
        $this->assertNotNull($entry, 'The skip must be logged');
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $entry['category']);
    }

    public function testNoOpWhenBouncedAddressAlreadyMatchesConfirmedEmail(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 0
        );

        $this->fireHook();

        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls);
        $this->assertSame(
            [],
            $this->bounceClearLogEntries($mockLogEntries),
            'A no-op bounce-clear (already matching address) must log nothing'
        );
    }

    public function testIntegrityAnomalyIsLoggedAndStillCleared(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            integrityCarIds: [200, 201],
            bounceClearAffectedRows: 2
        );

        $this->fireHook();

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(2, $entries, 'Both the anomaly line and the cleared-count line must fire');

        $anomalyEntry = null;
        $clearedEntry = null;
        foreach ($entries as $entry) {
            if (str_contains($entry['message'], 'had email_bounced=1')) {
                $anomalyEntry = $entry;
            } elseif (str_contains($entry['message'], 'cleared bounce flag')) {
                $clearedEntry = $entry;
            }
        }

        $this->assertNotNull($anomalyEntry, 'The integrity-anomaly line must be present');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $anomalyEntry['category']);
        $this->assertStringContainsString('200,201', $anomalyEntry['message']);
        $this->assertStringContainsString('data-integrity anomaly', $anomalyEntry['message']);

        $this->assertNotNull($clearedEntry, 'The cleared-count line must also be present');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $clearedEntry['category']);
        $this->assertStringContainsString('cleared bounce flag on 2 car(s)', $clearedEntry['message']);
    }

    public function testBounceClearRunsExactlyOnceOnDoubleFire(): void
    {
        global $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1
        );

        $this->fireHook();
        $this->fireHook();

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The shared run-once guard must prevent a second bounce-clear UPDATE on the same request'
        );
    }

    public function testExceptionInBounceClearDoesNotBlockSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            failIntegrityCheckSelect: true
        );

        $this->fireHook();

        $bounceClearFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear failed for user 7')
        ));
        $this->assertCount(1, $bounceClearFailure);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $bounceClearFailure[0]['category']);

        $this->assertSame(0, $GLOBALS['hookTestDb']->bounceClearCalls);

        $this->assertSame(1, $this->carLookupCount(), 'The sync block must still run after the bounce-clear block throws');
    }

    public function testExceptionInSyncBlockDoesNotUndoBounceClearBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            bounceClearAffectedRows: 1,
            failCarLookup: true
        );

        $this->fireHook();

        $this->assertSame(1, $GLOBALS['hookTestDb']->bounceClearCalls);
        $bounceEntries = $this->bounceClearLogEntries($mockLogEntries);
        $this->assertCount(1, $bounceEntries);
        $this->assertStringContainsString('cleared bounce flag on 1 car(s)', $bounceEntries[0]['message']);

        $syncFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'car owner-field sync failed for user 7')
        ));
        $this->assertCount(1, $syncFailure);
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $syncFailure[0]['category']);
    }

    public function testBounceClearUpdateDatabaseErrorIsLoggedAndDoesNotBlockSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            integrityCarIds: [200],
            failBounceClearUpdate: true
        );

        $this->fireHook();

        $entries = $this->bounceClearLogEntries($mockLogEntries);
        $anomalyEntry = null;
        $clearedEntry = null;
        foreach ($entries as $entry) {
            if (str_contains($entry['message'], 'had email_bounced=1')) {
                $anomalyEntry = $entry;
            } elseif (str_contains($entry['message'], 'cleared bounce flag')) {
                $clearedEntry = $entry;
            }
        }
        $this->assertNotNull($anomalyEntry, 'The integrity-anomaly line runs before the failing UPDATE and must still fire');
        $this->assertSame(LogCategories::LOG_CATEGORY_EMAIL_BOUNCED, $anomalyEntry['category']);
        $this->assertNull($clearedEntry, 'The cleared-count line must not fire when the UPDATE itself failed');

        $bounceClearFailure = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'bounce-clear failed for user 7')
        ));
        $this->assertCount(1, $bounceClearFailure, 'The bounce-clear UPDATE failure must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_DATABASE_ERROR, $bounceClearFailure[0]['category']);

        $this->assertSame(
            1,
            $GLOBALS['hookTestDb']->bounceClearCalls,
            'The bounce-clear UPDATE must have been attempted exactly once'
        );

        $this->assertSame(
            1,
            $this->carLookupCount(),
            'The sync block must still run after the bounce-clear block throws on the UPDATE itself'
        );
    }

    /**
     * #1890: the hook is a bare include with no caller try/catch, so an
     * exception from new Owner() would fatal verify.php's render.
     */
    public function testOwnerLookupFailureIsCaughtAndDoesNotThrowOrRunSyncBlock(): void
    {
        global $mockLogEntries, $verify;
        $verify = $this->fakeVerify(7);
        $GLOBALS['hookTestDb'] = new HookTestDb(
            cars: [(object) ['id' => 100]],
            failOwnerLookup: true
        );

        $this->fireHook();

        $failureEntries = array_values(array_filter(
            $mockLogEntries,
            static fn(array $e): bool => str_contains($e['message'], 'unexpected')
                && str_contains($e['message'], 'during bounce-clear for user 7')
        ));
        $this->assertCount(1, $failureEntries, 'The Owner construction failure must be logged exactly once');
        $this->assertSame(LogCategories::LOG_CATEGORY_SYSTEM_ERROR, $failureEntries[0]['category']);
        $this->assertStringContainsString('OwnerDatabaseException', $failureEntries[0]['message']);

        $this->assertSame(0, $GLOBALS['hookTestDb']->bounceClearCalls);
        $this->assertSame(
            0,
            $this->carLookupCount(),
            'The sync block must never run when the Owner lookup itself failed'
        );
    }
}

/**
 * Answers only the queries the hook issues. Each flag selects one failure mode.
 */
final class HookTestDb implements DatabaseInterface
{
    public int $carLookups = 0;

    public int $bounceClearCalls = 0;

    /** @var list<string> Email bound to each bounce-clear UPDATE */
    public array $bounceClearEmails = [];

    // Unused; satisfies DatabaseInterface's @phpstan-impure contract. carLookups is what tests assert on.
    private int $calls = 0;
    private int $count = 0;
    /** @var array<int, object> */
    private array $rows = [];
    private bool $error = false;

    /**
     * @param array<int, object> $cars
     * @param list<int> $integrityCarIds
     * @param string $ownerEmail Email in the post-update users row, read by Owner::find()
     */
    public function __construct(
        private array $cars = [],
        private bool $failCarLookup = false,
        private bool $failUpdate = false,
        private bool $throwTypeErrorOnCarLookup = false,
        private array $integrityCarIds = [],
        private int $bounceClearAffectedRows = 0,
        private bool $failBounceClearUpdate = false,
        private bool $failIntegrityCheckSelect = false,
        private string $ownerEmail = 'new-address@example.com',
        private bool $ownerNotFound = false,
        private bool $failOwnerLookup = false
    ) {
    }

    public function query(string $sql, array $params = []): self
    {
        $this->error = false;

        if (str_contains($sql, 'FROM users u')) {
            if ($this->failOwnerLookup) {
                $this->error = true;
                $this->rows = [];
                $this->count = 0;
                return $this;
            }

            if ($this->ownerNotFound) {
                $this->rows = [];
                $this->count = 0;
                return $this;
            }

            $this->rows = [(object) [
                'id' => $params[0], 'fname' => 'Test', 'lname' => 'Owner',
                'email' => $this->ownerEmail, 'city' => 'Portland',
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

        // CarRepository::carIdsWithBouncedFlagButNoAddress()
        if (str_starts_with($sql, 'SELECT id FROM cars WHERE')) {
            if ($this->failIntegrityCheckSelect) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->rows = array_map(static fn (int $id): object => (object) ['id' => $id], $this->integrityCarIds);
            $this->count = count($this->integrityCarIds);
            return $this;
        }

        // CarRepository::clearBouncedForUser()
        if (str_starts_with($sql, 'UPDATE cars')
            && str_contains($sql, 'SET email_bounced = 0, email_bounced_address = NULL')
        ) {
            $this->bounceClearCalls++;
            $this->bounceClearEmails[] = (string) ($params[1] ?? '');

            if ($this->failBounceClearUpdate) {
                $this->error = true;
                $this->count = 0;
                $this->rows = [];
                return $this;
            }

            $this->count = $this->bounceClearAffectedRows;
            $this->rows = [];
            return $this;
        }

        if (str_starts_with($sql, 'UPDATE cars SET')) {
            if ($this->failUpdate) {
                // syncOwnerFieldsToCars() records this as a failed car instead of propagating.
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

// The seam new Owner($userId) falls back to. Only this unit test needs it.
if (!function_exists('dbi')) {
    function dbi(): DatabaseInterface
    {
        return $GLOBALS['hookTestDb'] ?? new HookTestDb();
    }
}
