<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * IntegrationTestCase's loginAsTestUser()/restoreGlobalUser() snapshot logic.
 * A regression reintroduces the cross-test session leak fixed in #1572.
 * setUp()/tearDown() save and restore $GLOBALS['user'], because these tests
 * change it themselves.
 */
#[Group('integration')]
final class IntegrationTestCaseAuthHelperTest extends IntegrationTestCase
{
    /** Car ID no fixture ever creates — only ever handed to the cleanup probe below. */
    private const UNUSED_CAR_ID = PHP_INT_MAX;

    /** Value of $GLOBALS['user'] before this test ran (meaningless if $ambientUserWasSet is false). */
    private mixed $ambientUser = null;

    /** Whether $GLOBALS['user'] existed at all before this test ran. */
    private bool $ambientUserWasSet = false;

    /**
     * Set by test_tearDownRestoresGlobalBeforeRunningCleanup() only: whether the ambient
     * session was already back in $GLOBALS['user'] when tearDown()'s cleanup phase ran.
     * Null means the probe was never armed, so tearDown() has nothing to assert.
     */
    private ?bool $globalWasRestoredDuringCleanup = null;

    /** Real DB handle, stashed while the cleanup probe stands in for it. */
    private mixed $realDb = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Snapshot BEFORE requireDatabase(): tearDown() runs even after a skip, and
        // without a snapshot it would unset the ambient session for the whole process.
        $this->ambientUserWasSet = array_key_exists('user', $GLOBALS);
        $this->ambientUser = $GLOBALS['user'] ?? null;

        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->realDb !== null) {
                $this->db = $this->realDb;
                $this->realDb = null;
            }

            // Restore after parent::tearDown(), since the helper's own restore runs there
            // and would otherwise put this test's sentinel back into the global.
            if ($this->ambientUserWasSet) {
                $GLOBALS['user'] = $this->ambientUser;
            } else {
                unset($GLOBALS['user']);
            }
        }

        if ($this->globalWasRestoredDuringCleanup !== null) {
            $this->assertTrue(
                $this->globalWasRestoredDuringCleanup,
                'tearDown() must restore $GLOBALS[\'user\'] before it starts deleting fixtures'
            );
        }
    }

    /**
     * A second loginAsTestUser() call must not re-snapshot: restore returns the session
     * that was ambient before the *first* login, not the intermediate one.
     */
    public function test_secondLoginDoesNotResnapshot_restoreReturnsPreFirstLoginSession(): void
    {
        $ambientSentinel = new User();
        $GLOBALS['user'] = $ambientSentinel;

        $firstUserId  = $this->createTestUser();
        $secondUserId = $this->createTestUser();

        $firstSession = $this->loginAsTestUser($firstUserId);
        $this->assertSame($firstSession, $GLOBALS['user'], 'First login must publish to the global');

        $secondSession = $this->loginAsTestUser($secondUserId);
        $this->assertNotSame($firstSession, $secondSession, 'Each login must build a fresh User instance');
        $this->assertSame($secondSession, $GLOBALS['user'], 'Second login must publish to the global');

        $this->restoreGlobalUser();

        $this->assertSame(
            $ambientSentinel,
            $GLOBALS['user'],
            'Restore must return the session ambient before the FIRST login, not the intermediate one'
        );
    }

    /**
     * restoreGlobalUser() is idempotent: once it has restored, a second call must leave
     * $GLOBALS['user'] alone rather than re-applying a stale snapshot.
     */
    public function test_secondRestoreIsNoOp(): void
    {
        $ambientSentinel = new User();
        $GLOBALS['user'] = $ambientSentinel;

        $this->loginAsTestUser($this->createTestUser());
        $this->restoreGlobalUser();
        $this->assertSame($ambientSentinel, $GLOBALS['user'], 'First restore must put the ambient session back');

        // Simulate whatever runs next (another test, or tearDown) owning the global now.
        $laterSentinel = new User();
        $GLOBALS['user'] = $laterSentinel;

        $this->restoreGlobalUser();

        $this->assertSame(
            $laterSentinel,
            $GLOBALS['user'],
            'A second restore must be a no-op, not clobber the current session with a stale snapshot'
        );
    }

    /**
     * With no ambient session, restore must remove the key, not set it to null:
     * array_key_exists() would otherwise see a different state.
     */
    public function test_restoreUnsetsGlobalWhenNothingWasAmbient(): void
    {
        // Keep the "no ambient session" window narrow: fixture helpers may read the global.
        $userId = $this->createTestUser();

        // bootstrap-integration.php seeds a user, so unset it here.
        unset($GLOBALS['user']);

        $this->loginAsTestUser($userId);
        $this->assertArrayHasKey('user', $GLOBALS, 'Login must publish to the global');

        $this->restoreGlobalUser();

        $this->assertFalse(
            array_key_exists('user', $GLOBALS),
            'Restore must unset $GLOBALS[\'user\'] entirely when nothing was ambient, not set it to null'
        );
    }

    /** An unknown user ID must fail loudly, not publish a hollow session. */
    public function test_loginAsTestUserThrowsWhenUserIdDoesNotExist(): void
    {
        $missingUserId = $this->firstUnusedUserId();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("loginAsTestUser(): no users row with id {$missingUserId}");

        $this->loginAsTestUser($missingUserId);
    }

    /**
     * tearDown() must restore the session before it deletes fixtures. Cleanup
     * swallows its own errors, so a probe DB handle records $GLOBALS['user']
     * when cleanup starts and then fails the delete.
     */
    public function test_tearDownRestoresGlobalBeforeRunningCleanup(): void
    {
        $ambientSentinel = new User();
        $GLOBALS['user'] = $ambientSentinel;

        $userId  = $this->createTestUser();
        $session = $this->loginAsTestUser($userId);
        $this->assertSame($session, $GLOBALS['user'], 'Login must publish to the global');

        // The probe intercepts tearDown()'s cleanup, so delete through the real handle.
        $this->deleteTestUser($userId);

        $this->trackCarId(self::UNUSED_CAR_ID);

        $recordGlobal = function () use ($ambientSentinel): void {
            $this->globalWasRestoredDuringCleanup = (($GLOBALS['user'] ?? null) === $ambientSentinel);
        };

        $this->realDb = $this->db;
        $this->db     = new class ($recordGlobal) {
            public function __construct(private Closure $recordGlobal)
            {
            }

            public function query(string $sql, array $params = []): never
            {
                $this->recordAndFail();
            }

            public function delete(string $table, array $where): never
            {
                $this->recordAndFail();
            }

            private function recordAndFail(): never
            {
                ($this->recordGlobal)();
                throw new RuntimeException('intentional cleanup failure (tearDown ordering probe)');
            }
        };
    }

    /**
     * Lowest `users.id` guaranteed not to exist, with enough headroom that a concurrent
     * fixture insert cannot claim it mid-test.
     */
    private function firstUnusedUserId(): int
    {
        $result = $this->db->query('SELECT COALESCE(MAX(id), 0) + 1000000 AS unused_id FROM users');
        if ($result->error()) {
            throw new RuntimeException("Could not determine an unused user ID: {$result->errorString()}");
        }

        return (int) $result->first()->unused_id;
    }
}
