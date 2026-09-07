<?php

declare(strict_types=1);

use ElanRegistry\DatabaseInterface;
use ElanRegistry\Database\DbAdapter;
use ElanRegistry\Owner;
use PHPUnit\Framework\TestCase;

/**
 * Base class for all integration tests
 *
 * Provides:
 * - Database connection validation
 * - Test fixture creation (users, cars)
 * - Skip logic when database unavailable
 * - Access to real UserSpice framework functions
 *
 * All integration tests should extend this class instead of TestCase directly.
 *
 * Example:
 * ```php
 * class MyIntegrationTest extends IntegrationTestCase
 * {
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->requireDatabase(); // Skip test if DB unavailable
 *
 *         // Create test fixtures
 *         $userId = $this->createTestUser();
 *         $carId = $this->createTestCar($userId);
 *     }
 * }
 * ```
 */
abstract class IntegrationTestCase extends TestCase
{
    protected $db;
    protected $databaseConnected = false;

    /** @var int[] Car IDs created during this test, cleaned up in tearDown */
    private array $createdCarIds = [];

    /** @var int[] User IDs created during this test, cleaned up in tearDown */
    private array $createdUserIds = [];

    /** @var User|null Snapshot of $GLOBALS['user'] from the first loginAsTestUser() call of a test. */
    private ?User $savedGlobalUser = null;

    /** Whether $savedGlobalUser is awaiting restoration (disambiguates "nothing saved" from "saved null"). */
    private bool $globalUserRestorePending = false;

    /**
     * Set up test environment
     * Initializes database connection
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->createdCarIds = [];
        $this->createdUserIds = [];

        // Get real DB instance (loaded by bootstrap-integration.php), wrapped in the
        // DbAdapter so it satisfies the DatabaseInterface type hints that production
        // collaborators (CarRepository, StatisticsDataService, the account-cleanup
        // helpers, ...) now declare. The adapter delegates 1:1 to the wrapped \DB, so
        // tests still exercise genuine database behaviour.
        try {
            $this->db = new DbAdapter(DB::getInstance());

            // Verify database connection with simple query. The adapter always returns
            // itself from query() (a failed statement is reported by error(), never by a
            // null return), so a usable connection is one that actually yields the row.
            $result = $this->db->query("SELECT 1");
            if (!$result->error() && $result->count() > 0) {
                $this->databaseConnected = true;
            }
        } catch (RuntimeException $e) {
            $this->databaseConnected = false;
            // Don't fail yet - let requireDatabase() handle it
        }
    }

    /**
     * Clean up all test-created cars and users
     */
    protected function tearDown(): void
    {
        $this->restoreGlobalUser();

        if ($this->databaseConnected) {
            // Defense-in-depth: the whole integration suite shares one DB connection
            // (phpunit-integration.xml's processIsolation="false"), so a test that opens
            // a transaction and then fails an assertion before its own rollback runs
            // (see CarMergeTest.php's testCarRepositoryTransactionRollbackPreservesCarAndOwnerAssignment
            // for a real instance of this bug, fixed via try/finally) leaks that open
            // transaction into every subsequent test in the process, corrupting their
            // reads/writes in ways that look unrelated to the actual leaking test. Every
            // individual transaction-using test should already guard itself with
            // try/finally; this is a second, process-wide safety net so a future
            // unguarded beginTransaction() fails loudly here instead of silently
            // poisoning whichever test happens to run next.
            // instanceof guard: a few tests (e.g. IntegrationTestCaseAuthHelperTest)
            // deliberately swap $this->db for a bare test stub that only implements
            // query()/delete(), to probe tearDown()'s own cleanup-ordering behavior —
            // this check must not assume $this->db is always a full DbAdapter.
            if ($this->db instanceof DbAdapter && $this->db->inTransaction()) {
                fwrite(STDERR, "WARNING: tearDown() found an open transaction left by this test — rolling back to avoid poisoning subsequent tests.\n");
                $this->db->rollBack();
            }

            foreach ($this->createdCarIds as $carId) {
                try {
                    $this->db->query("DELETE FROM car_transfer_requests WHERE existing_car_id = ?", [$carId]);
                    $this->deleteCarRows($carId);
                } catch (RuntimeException $e) {
                    // Don't fail the test over cleanup, but a silent swallow here means the
                    // fixture row survives into the next test run with no trace of why —
                    // log it so a polluted test schema is diagnosable.
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for car ID {$carId}: {$e->getMessage()}\n");
                }
            }

            // Then delete users
            foreach ($this->createdUserIds as $userId) {
                try {
                    $this->db->delete('users', ['id', '=', $userId]);
                } catch (RuntimeException $e) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for user ID {$userId}: {$e->getMessage()}\n");
                }
            }
        }

        parent::tearDown();
    }

    /**
     * Skip test if database not available
     *
     * Call this at the start of setUp() in any test that requires database access.
     * If database is not available, the test will be skipped with a message.
     */
    protected function requireDatabase(): void
    {
        if (!$this->databaseConnected) {
            $this->markTestSkipped('Database connection not available for integration testing');
        }
    }

    /**
     * Authenticate a test user as the ambient session for the current test.
     *
     * UserSpice's User class has no public API to mark an instance logged-in without a
     * real HTTP request/session round-trip, so this bypasses login() by setting the
     * private $_isLoggedIn flag directly via reflection. setAccessible() is intentionally
     * omitted on the ReflectionProperty call below — it has been a no-op since PHP 8.1.
     *
     * Sets $GLOBALS['user'] (the `global $user` alias inside this method binds the same
     * storage slot, so both reads observe the authenticated session). The previous
     * $GLOBALS['user'] is snapshotted on first use only, so calling this more than once
     * in a test still restores the value ambient before *this test's* first call.
     *
     * IntegrationTestCase::tearDown() restores automatically; call restoreGlobalUser()
     * directly if a test must not leak the fake session past a single test method.
     *
     * @param int $userId A user ID already persisted to `users` (e.g. via createTestUser()).
     * @return User The authenticated instance, already the ambient session.
     */
    protected function loginAsTestUser(int $userId): User
    {
        $loggedInUser = new User();
        if (!$loggedInUser->find($userId)) {
            throw new RuntimeException("loginAsTestUser(): no users row with id {$userId}");
        }

        $reflection = new ReflectionClass($loggedInUser);
        $isLoggedInProperty = $reflection->getProperty('_isLoggedIn');
        $isLoggedInProperty->setValue($loggedInUser, true);

        if (!$this->globalUserRestorePending) {
            $this->savedGlobalUser = $GLOBALS['user'] ?? null;
            $this->globalUserRestorePending = true;
        }

        global $user;
        $user = $loggedInUser;
        $GLOBALS['user'] = $loggedInUser;

        return $loggedInUser;
    }

    /**
     * Restore $GLOBALS['user'] (and the `global $user` alias) to whatever was ambient
     * before the first loginAsTestUser() call of this test, or unset both if nothing was
     * ambient. Idempotent — safe to call when no loginAsTestUser() call is pending, so
     * setUp()-only callers (auto-restored by tearDown()) and inline mid-test-method
     * callers (explicit finally-block call, whose later tearDown() invocation becomes a
     * harmless no-op) both work correctly.
     */
    protected function restoreGlobalUser(): void
    {
        if (!$this->globalUserRestorePending) {
            return;
        }

        global $user;
        if ($this->savedGlobalUser !== null) {
            $user = $this->savedGlobalUser;
            $GLOBALS['user'] = $this->savedGlobalUser;
        } else {
            // Defensive, not dead: tests/bootstrap-integration.php seeds a non-null
            // $GLOBALS['user'] once per process, so in practice the snapshot is never
            // null — unless an earlier test unset the global itself. No unset($user)
            // here: unsetting a global-bound local never unsets the actual superglobal,
            // so it would just be a no-op.
            unset($GLOBALS['user']);
        }

        $this->savedGlobalUser = null;
        $this->globalUserRestorePending = false;
    }

    /**
     * Create a test user in database
     *
     * @param array $data Override default user data
     * @return int The created user ID
     * @throws RuntimeException If user creation fails
     */
    protected function createTestUser(array $data = []): int
    {
        $this->requireDatabase();

        // Generate unique username and email
        $uniqueSuffix = uniqid();

        $defaults = [
            'username' => "testuser_{$uniqueSuffix}",
            'password' => password_hash('testpass123', PASSWORD_BCRYPT),
            'email' => "test_{$uniqueSuffix}@example.com",
            'fname' => 'Test',
            'lname' => 'User',
            'active' => 1,
            'join_date' => date('Y-m-d H:i:s')
        ];

        $userData = array_merge($defaults, $data);

        // Insert into database
        $insertResult = $this->db->insert('users', $userData);
        if (!$insertResult) {
            throw new RuntimeException("Failed to create test user: {$this->db->errorString()}");
        }

        $userId = (int) $this->db->lastId();
        if (!$userId) {
            throw new RuntimeException("Failed to get inserted user ID");
        }

        $this->createdUserIds[] = $userId;

        return $userId;
    }

    /**
     * Create a test car in database
     *
     * @param int $userId The owner user ID
     * @param array $data Override default car data
     * @return int The created car ID
     * @throws RuntimeException If car creation fails
     */
    protected function createTestCar(int $userId, array $data = []): int
    {
        $this->requireDatabase();

        // Verify user exists
        $userCheck = $this->db->query("SELECT id FROM users WHERE id = ?", [$userId])->first();
        if (!$userCheck) {
            throw new RuntimeException("User ID {$userId} does not exist");
        }

        // Generate unique chassis number
        $uniqueSuffix = uniqid();

        $defaults = [
            'user_id' => $userId,
            'year' => 1973,
            'model' => 'Elan S4',
            'series' => 'S4',
            'variant' => 'SE',
            'type' => 'FHC',
            'chassis' => 'T' . substr($uniqueSuffix, -10), // varchar(15) limit
            'color' => 'Red',
            'ctime' => date('Y-m-d H:i:s')
        ];

        $carData = array_merge($defaults, $data);

        // Insert into database
        $insertResult = $this->db->insert('cars', $carData);
        if (!$insertResult) {
            throw new RuntimeException("Failed to create test car: {$this->db->errorString()}");
        }

        $carId = (int) $this->db->lastId();
        if (!$carId) {
            throw new RuntimeException("Failed to get inserted car ID");
        }

        $this->createdCarIds[] = $carId;

        // Purge any stale cars_hist rows left from a previous test run that
        // used the same car ID (possible if the cars table was ever truncated,
        // which resets AUTO_INCREMENT without clearing history).
        $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);

        return $carId;
    }

    /**
     * Delete a test user from database
     *
     * @param int $userId The user ID to delete
     * @return bool Success status
     */
    protected function deleteTestUser(int $userId): bool
    {
        if (!$this->databaseConnected) {
            return false;
        }

        try {
            $this->db->delete('users', ['id', '=', $userId]);
            // Remove from tracking so tearDown doesn't double-delete
            $this->createdUserIds = array_values(array_diff($this->createdUserIds, [$userId]));
            return true;
        } catch (RuntimeException $e) {
            fwrite(STDERR, "NOTE: deleteTestUser() failed for user ID {$userId}: {$e->getMessage()}\n");
            return false;
        }
    }

    /**
     * Delete a test car from database
     *
     * @param int $carId The car ID to delete
     * @return bool Success status
     */
    protected function deleteTestCar(int $carId): bool
    {
        if (!$this->databaseConnected) {
            return false;
        }

        try {
            $this->deleteCarWithHistory($carId);
            // Remove from tracking so tearDown doesn't double-delete
            $this->createdCarIds = array_values(array_diff($this->createdCarIds, [$carId]));
            return true;
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            // PHPUnit\Framework\Exception extends RuntimeException, so the catch below would
            // otherwise swallow deleteCarWithHistory()'s self-verification failures and log
            // them away instead of failing the test — defeating the point of verifying at all.
            throw $e;
        } catch (RuntimeException $e) {
            fwrite(STDERR, "NOTE: deleteTestCar() failed for car ID {$carId}: {$e->getMessage()}\n");
            return false;
        }
    }

    /**
     * Delete a car's `cars` and `cars_hist` rows, in the order required to avoid
     * orphaning the cars_delete trigger's own history row (#1503, #1551): cars
     * must go first, then cars_hist.
     *
     * @param int $carId The car ID to delete
     */
    private function deleteCarRows(int $carId): void
    {
        $this->db->delete('cars', ['id', '=', $carId]);
        $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);
    }

    /**
     * Delete a car's `cars` and `cars_hist` rows (see deleteCarRows()) and self-verify
     * both are actually gone afterward. DB::query() never throws on an execute-time
     * failure (see countMatchingLogs() below), so the verification queries check
     * error() explicitly — otherwise a failed verification SELECT would return zero
     * rows and the assertion would pass vacuously, "confirming" a deletion that may
     * not have happened.
     *
     * @param int $carId The car ID to delete
     */
    protected function deleteCarWithHistory(int $carId): void
    {
        $this->deleteCarRows($carId);

        $carsRemaining = $this->db->query("SELECT id FROM cars WHERE id = ?", [$carId]);
        if ($carsRemaining->error()) {
            throw new RuntimeException("Verification query failed for cars row {$carId}: {$carsRemaining->errorString()}");
        }
        $this->assertSame(0, $carsRemaining->count(), "cars row {$carId} must be deleted");

        $histRemaining = $this->db->query("SELECT id FROM cars_hist WHERE car_id = ?", [$carId]);
        if ($histRemaining->error()) {
            throw new RuntimeException("Verification query failed for cars_hist rows (car {$carId}): {$histRemaining->errorString()}");
        }
        $this->assertSame(0, $histRemaining->count(), "cars_hist rows for car {$carId} must be deleted");
    }

    /**
     * Register a car ID for cleanup in tearDown
     *
     * Call this when a test creates a car via Car::create() directly instead of
     * through createTestCar(), so the car is cleaned up after the test.
     *
     * @param int $carId The car ID to track
     */
    protected function trackCarId(int $carId): void
    {
        $this->createdCarIds[] = $carId;
    }

    protected function untrackCarId(int $carId): void
    {
        $this->createdCarIds = array_values(array_diff($this->createdCarIds, [$carId]));
    }

    /**
     * Check if database is currently connected
     *
     * @return bool True if database connection is active
     */
    protected function isDatabaseConnected(): bool
    {
        return $this->databaseConnected;
    }

    /**
     * Count rows in the logs table matching a logtype and lognote LIKE pattern.
     *
     * DB::query() never throws on an execute-time failure (see DB class conventions
     * in TESTING_STRATEGY.md) — it just leaves no rows, which count(0)/first([])
     * would otherwise make indistinguishable from a real "zero matches" result.
     * Checking error() explicitly means a broken query fails loudly instead of a
     * before/after count assertion passing vacuously on 0 === 0.
     *
     * @param string $logtype Exact logtype value (e.g. 'CarActions')
     * @param string $lognote LIKE pattern for lognote (e.g. 'Car update failed%')
     * @throws RuntimeException If the underlying query fails
     */
    protected function countMatchingLogs(string $logtype, string $lognote): int
    {
        $result = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE logtype = ? AND lognote LIKE ?',
            [$logtype, $lognote]
        );

        if ($result->error()) {
            throw new RuntimeException("countMatchingLogs() query failed for logtype='{$logtype}': {$result->errorString()}");
        }

        return (int) $result->first()->cnt;
    }

    /**
     * Build an Owner backed by the given DatabaseInterface (typically a test
     * proxy), with $_data populated via Reflection so no real find() query is
     * needed against it.
     *
     * @param array<string, mixed> $data Owner data fields (id, fname, email, etc.)
     */
    protected function ownerWithLoadedData(DatabaseInterface $db, array $data): Owner
    {
        $owner = new Owner(null, $db);

        $ref = new \ReflectionClass(Owner::class);
        $dataProp = $ref->getProperty('_data');
        $dataProp->setValue($owner, (object) $data);

        return $owner;
    }

    /**
     * Loads the testable, function_exists()-guarded query functions
     * (findOwnerFieldDriftSummary(), findOrphanedOwnerCarCount(),
     * findOwnerIdsWithDrift(), findDriftedCarDetails(), their private helpers,
     * and the RECONCILE_* constants they share) from
     * `app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php`, without
     * executing the rest of that file (top-level securePage() gate, AJAX
     * request handling, HTML template render) — none of which a test can
     * safely trigger outside a real HTTP request.
     *
     * Shared by ReconcileOwnerFieldsAnalyzeTest and
     * ReconcileOwnerFieldsExecuteTest (both need the same functions), following
     * FixPagePermissionsAnalyzeRunTest::loadAnalyzePermissions()'s precedent
     * for script #21. The extracted slice is written to a real temp file and
     * require()'d — not eval()'d — so it is subject to normal PHP file
     * compilation/opcache semantics like any other included file, and the
     * function_exists() guard makes calling this from both test classes in the
     * same PHPUnit process safe.
     */
    protected function loadOwnerFieldDriftFunctions(): void
    {
        if (function_exists('findOwnerFieldDriftSummary')) {
            return;
        }

        $scriptPath = __DIR__ . '/../../app/admin/scripts/maintenance/26-Reconcile-Owner-Fields.php';

        $source = file_get_contents($scriptPath);
        if ($source === false) {
            throw new \RuntimeException('Could not read ' . $scriptPath);
        }

        // Isolate the slice from the RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS
        // constant (the first line of the testable, function_exists()-guarded
        // region) up to (but not including) the "AJAX handlers" comment that
        // starts the securePage()/CSRF/isAdmin()-gated request handling this
        // must never execute.
        $startMarker = 'const RECONCILE_MAX_CONSECUTIVE_OWNER_ERRORS';
        $endMarker = '// AJAX handlers';

        $startPos = strpos($source, $startMarker);
        $endPos = strpos($source, $endMarker);

        if ($startPos === false || $endPos === false || $endPos <= $startPos) {
            throw new \RuntimeException(
                'Could not locate the drift-detection functions in ' . $scriptPath
                . ' — the script may have been restructured; update loadOwnerFieldDriftFunctions() to match.'
            );
        }

        $functionSource = substr($source, $startPos, $endPos - $startPos);

        $tempFile = tempnam(sys_get_temp_dir(), 'reconcileOwnerFields_');
        if ($tempFile === false) {
            throw new \RuntimeException('Could not create temp file for drift-function extraction');
        }

        $preamble = "<?php\n"
            . "declare(strict_types=1);\n"
            . "use ElanRegistry\\DatabaseInterface;\n";

        $written = file_put_contents($tempFile, $preamble . $functionSource);
        if ($written === false) {
            unlink($tempFile);
            throw new \RuntimeException('Could not write extracted drift-function source to temp file');
        }

        try {
            require $tempFile;
        } finally {
            unlink($tempFile);
        }
    }
}
