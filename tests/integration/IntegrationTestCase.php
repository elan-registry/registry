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
    /** @var list<string> Connection variables that invokeEndpoint() exports to its subprocess */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    protected $db;
    protected $databaseConnected = false;

    /** @var int[] Car IDs created during this test, cleaned up in tearDown */
    private array $createdCarIds = [];

    /** @var int[] User IDs created during this test, cleaned up in tearDown */
    private array $createdUserIds = [];

    /** @var int[] user_permission_matches row IDs created by grantTestPermission(), cleaned up in tearDown */
    private array $createdPermissionMatchIds = [];

    /** @var array<string, int> us_rate_limits action => highest id before the test, see trackNewRateLimitRows() */
    private array $rateLimitIdFloors = [];

    /** @var array<string, string|false>|null getenv() values from before the first invokeEndpoint() call of this test, or null if none ran */
    private ?array $originalDbEnv = null;

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
        $this->createdPermissionMatchIds = [];
        $this->rateLimitIdFloors = [];

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

        // putenv() changes the whole PHPUnit process, so a value that
        // invokeEndpoint() exported must not reach a later test.
        if ($this->originalDbEnv !== null) {
            foreach ($this->originalDbEnv as $var => $value) {
                putenv($value === false ? $var : "{$var}={$value}");
            }
            $this->originalDbEnv = null;
        }

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

            foreach ($this->createdPermissionMatchIds as $id) {
                try {
                    $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$id]);
                    if ($this->db->error()) {
                        fwrite(STDERR, "NOTE: tearDown() cleanup failed for user_permission_matches ID {$id}: {$this->db->errorString()}\n");
                    }
                } catch (RuntimeException $e) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for user_permission_matches ID {$id}: {$e->getMessage()}\n");
                }
            }
            foreach ($this->rateLimitIdFloors as $action => $floor) {
                try {
                    $this->db->query('DELETE FROM us_rate_limits WHERE id > ? AND action = ?', [$floor, $action]);
                    if ($this->db->error()) {
                        fwrite(STDERR, "NOTE: tearDown() cleanup failed for us_rate_limits action '{$action}': {$this->db->errorString()}\n");
                    }
                } catch (RuntimeException $e) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for us_rate_limits action '{$action}': {$e->getMessage()}\n");
                }
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

            // Events belong to the user (#2358), so they go before the users.
            // A row with car_id NULL has no car to find it by.
            try {
                $this->deleteEmailEventsForUserIds($this->createdUserIds);
            } catch (RuntimeException $e) {
                fwrite(STDERR, "NOTE: tearDown() cleanup of er_email_events failed: {$e->getMessage()}\n");
            }

            // Then delete users
            foreach ($this->createdUserIds as $userId) {
                try {
                    // profiles has no FK cascade to users, so its row must be
                    // removed explicitly or it outlives the user it described.
                    $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
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
     * POST to a real endpoint file in a `php -r` subprocess and capture its response.
     *
     * A subprocess is necessary because ApiResponse::send() calls exit, and a
     * securePage() refusal exits through Redirect::to() or die(). Either would
     * end the PHPUnit process. The subprocess runs the real UserSpice bootstrap,
     * so Token::check(), hasPerm(), and securePage() run unchanged. The login
     * uses the same reflection as loginAsTestUser().
     *
     * `raw` holds the stdout and stderr bytes of the subprocess (2>&1), not
     * trimmed, so two responses can be compared byte for byte. PHP error text
     * on stderr is in `raw` too. `httpCode` is null when no status code was set,
     * when the subprocess stopped before the endpoint ran, or when other output
     * follows the status marker. In the last case `raw` keeps the marker and
     * the output after it, so assertNoPhpErrorText() can see that output.
     * `status` is null when the body is not JSON or has no bool `success` value.
     *
     * `flash` holds the UserSpice session flash messages that the endpoint set
     * with usError(), usSuccess(), usWarning(), or usMessage(), as read from
     * the session when the subprocess ends. This includes a refusal that
     * redirects. UserSpice stores them in the session keys valErr, valSuc, and
     * genMsg. `error` is valErr, `success` is valSuc, and `message` is genMsg
     * (usWarning() and usMessage() both write genMsg). Each list is empty when
     * the endpoint set none. The text is the sanitized text that UserSpice
     * stored. When the HTTP marker matched, the helper removes the flash payload
     * from `raw`. When it did not match, `raw` keeps the payload and `flash`
     * stays empty.
     *
     * The subprocess sets $post values after the CSRF value. Thus a 'csrf' key
     * in $post overrides $csrfMode.
     *
     * @param string $endpointFile Repo-relative path (e.g. 'app/api/contact/send-owner-email.php'), or an absolute path inside this repository
     * @param array<string, scalar|null|array<mixed>> $post $_POST fields; values pass through var_export(), so nested arrays of scalars and null are allowed
     * @param int|null $loggedInUserId User to log in inside the subprocess; null = logged out
     * @param string $csrfMode 'valid' (Token::generate()), 'invalid' (a well-formed token that does not match the session token), or 'omit' (no csrf key)
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     * @throws InvalidArgumentException If $csrfMode is unknown, $endpointFile is not a file in this repository, or a $post value is not a scalar, null, or an array of those
     * @throws RuntimeException If shell_exec() cannot run the subprocess, or the HTTP marker matched and the flash payload is missing or malformed
     */
    protected function invokeEndpoint(string $endpointFile, array $post, ?int $loggedInUserId, string $csrfMode = 'valid'): array
    {
        // Resolve the root too, so the prefix check works in a symlinked checkout.
        $projectRoot = (string) realpath(dirname(__DIR__, 2));

        // Resolve first: a '..' segment could pass the prefix check and still point outside the repo.
        $candidate = str_starts_with($endpointFile, '/') ? $endpointFile : $projectRoot . '/' . $endpointFile;
        $absoluteFile = realpath($candidate);
        if ($absoluteFile === false || !str_starts_with($absoluteFile, $projectRoot . '/') || !is_file($absoluteFile)) {
            throw new InvalidArgumentException("invokeEndpoint(): '{$endpointFile}' is not a file in this repository");
        }

        $this->assertExportablePostValues($post);

        $csrfSnippet = match ($csrfMode) {
            'valid'   => '$_POST["csrf"] = \Token::generate();',
            // A session token must exist and the bad one must be well formed, or
            // Token::check() refuses it before the hash_equals() comparison.
            'invalid' => '\Token::generate(); $_POST["csrf"] = str_repeat("0", 64);',
            'omit'    => '',
            default   => throw new InvalidArgumentException(
                "invokeEndpoint(): unknown csrfMode '{$csrfMode}' (use 'valid', 'invalid', or 'omit')"
            ),
        };

        $this->exposeTestDatabaseToSubprocess();

        // A missing user must stop the run, as in loginAsTestUser(). A silent
        // logged-out request could pass a test that expects a refusal.
        $loginSnippet = $loggedInUserId === null
            ? ''
            : sprintf(
                '$loggedInUser = new User(); ' .
                'if (!$loggedInUser->find(%d)) { fwrite(STDERR, "invokeEndpoint: no users row %d\n"); exit(97); } ' .
                '$reflection = new ReflectionClass($loggedInUser); ' .
                '$prop = $reflection->getProperty("_isLoggedIn"); $prop->setValue($loggedInUser, true); ' .
                'global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser;',
                $loggedInUserId,
                $loggedInUserId
            );

        $postSnippet = '';
        foreach ($post as $key => $value) {
            $postSnippet .= sprintf('$_POST[%s] = %s; ', var_export($key, true), var_export($value, true));
        }

        // Shutdown functions also run after exit and die(), so every path
        // through the endpoint prints the marker.
        // The same function prints the session flash messages first, as
        // base64 JSON, so message text cannot collide with the markers.
        $statusSnippet = 'register_shutdown_function(static function (): void { ' .
            '$flash = ["error" => [], "success" => [], "message" => []]; ' .
            'if (class_exists("Config") && isset($_SESSION) && is_array($_SESSION)) { ' .
            'foreach (["error" => "valErr", "success" => "valSuc", "message" => "genMsg"] as $name => $suffix) { ' .
            '$stored = $_SESSION[\Config::get("session/session_name") . $suffix] ?? []; ' .
            '$flash[$name] = array_values(array_map("strval", is_array($stored) ? $stored : ($stored === "" ? [] : [$stored]))); } } ' .
            'echo "___FLASH:" . base64_encode(json_encode($flash, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)); ' .
            '$code = http_response_code(); echo "___HTTP:" . ($code === false ? "none" : $code); });';

        $script = sprintf(
            'require %s; ' .
            '\Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; ' .
            // Under CLI, Server::get('PHP_SELF') ignores $_SERVER['PHP_SELF'] and
            // derives the web path from SCRIPT_FILENAME, which `php -r` leaves
            // empty (giving '/'). Set SCRIPT_FILENAME so securePage($php_self)
            // sees the real page.
            '$_SERVER["SCRIPT_FILENAME"] = %s; ' .
            '$_SERVER["REQUEST_METHOD"] = "POST"; ' .
            'chdir(%s); ' .
            'require_once %s; ' .
            '%s %s %s %s ' .
            'require %s;',
            var_export($projectRoot . '/vendor/autoload.php', true),
            var_export($projectRoot, true),
            var_export($projectRoot, true),
            var_export($absoluteFile, true),
            var_export(dirname($absoluteFile), true),
            var_export($projectRoot . '/users/init.php', true),
            $csrfSnippet,
            $loginSnippet,
            $postSnippet,
            $statusSnippet,
            var_export($absoluteFile, true)
        );

        // php.ini can hide errors or print them to stdout only. These flags send
        // all PHP error text to stderr, so raw always holds it.
        $output = shell_exec(
            'php -d display_errors=stderr -d error_reporting=-1 -r ' . escapeshellarg($script) . ' 2>&1; echo "___EXIT:$?"'
        );
        if (!is_string($output)) {
            throw new RuntimeException('invokeEndpoint(): shell_exec failed to run the subprocess for ' . $endpointFile);
        }

        // Parse from the end: the endpoint output itself can contain any bytes.
        $exitCode = -1;
        $beforeExit = $output;
        if (preg_match('/___EXIT:(\d+)\s*\z/', $output, $exitMatch, PREG_OFFSET_CAPTURE) === 1) {
            $exitCode = (int) $exitMatch[1][0];
            $beforeExit = substr($output, 0, $exitMatch[0][1]);
        }

        // Accept the marker only at the very end. Output after it (for example
        // from a shutdown function that the endpoint registered) stays in raw.
        $httpCode = null;
        $raw = $beforeExit;
        $httpPos = strrpos($beforeExit, '___HTTP:');
        if ($httpPos !== false) {
            $afterMarker = substr($beforeExit, $httpPos + strlen('___HTTP:'));
            if (preg_match('/\A(\d{3}|none)\s*\z/', $afterMarker, $httpMatch) === 1) {
                $raw = substr($beforeExit, 0, $httpPos);
                $httpCode = $httpMatch[1] === 'none' ? null : (int) $httpMatch[1];
            }
        }

        // The flash payload sits right before the HTTP marker. Strip it only
        // when the HTTP marker matched, so raw stays complete in the odd case.
        $flash = ['error' => [], 'success' => [], 'message' => []];
        if ($httpCode !== null || $raw !== $beforeExit) {
            if (preg_match('/___FLASH:([A-Za-z0-9+\/=]*)\z/', $raw, $flashMatch, PREG_OFFSET_CAPTURE) !== 1) {
                throw new RuntimeException("invokeEndpoint(): the flash payload is missing from the output of {$endpointFile}");
            }
            $flashData = json_decode((string) base64_decode($flashMatch[1][0], true), true);
            if (!is_array($flashData)) {
                throw new RuntimeException("invokeEndpoint(): the flash payload of {$endpointFile} does not decode to an array");
            }
            foreach (array_keys($flash) as $name) {
                $flash[$name] = array_values(array_map('strval', (array) ($flashData[$name] ?? [])));
            }
            $raw = substr($raw, 0, $flashMatch[0][1]);
        }

        $decoded = json_decode(trim($raw), true);
        $json = is_array($decoded) ? $decoded : null;

        return [
            'status'   => is_bool($json['success'] ?? null) ? $json['success'] : null,
            'message'  => $json !== null ? (string) ($json['message'] ?? '') : '',
            'httpCode' => $httpCode,
            'exitCode' => $exitCode,
            'raw'      => $raw,
            'json'     => $json,
            'flash'    => $flash,
        ];
    }

    /**
     * Reject $post values that var_export() cannot turn into valid PHP source.
     * An object exports as a __set_state() call that the subprocess cannot run.
     *
     * @param array<mixed> $values The $post array, or a nested array inside it
     * @param string $path Key path of $values, for the error message
     * @throws InvalidArgumentException If a value is not a scalar, null, or an array of those
     */
    private function assertExportablePostValues(array $values, string $path = ''): void
    {
        foreach ($values as $key => $value) {
            $keyPath = $path === '' ? (string) $key : "{$path}[{$key}]";
            if (is_array($value)) {
                $this->assertExportablePostValues($value, $keyPath);
            } elseif (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(
                    "invokeEndpoint(): \$post['{$keyPath}'] is " . get_debug_type($value) . '. Use a scalar, null, or an array of those.'
                );
            }
        }
    }

    /**
     * Fail the test if PHP error text leaked into an endpoint response. A
     * notice or warning can print before valid JSON and still leave the
     * status code and decoded body looking correct.
     *
     * @param string $raw The `raw` output from invokeEndpoint()
     */
    protected function assertNoPhpErrorText(string $raw): void
    {
        foreach (['Fatal error', 'Parse error', 'Uncaught', 'TypeError', 'Warning:', 'Notice:', 'Deprecated:'] as $errorText) {
            $this->assertStringNotContainsString($errorText, $raw);
        }
    }

    /**
     * Grant a permission through a real user_permission_matches row, removed in
     * tearDown(). securePage() and hasPerm() read this table, so endpoint tests
     * need a real row, not a mock.
     *
     * Not named grantPermission(): some test classes declare a private method
     * with that name, and a protected one here would be a fatal error in them.
     *
     * @param int $userId User to grant the permission to
     * @param int $permissionId Permission ID (1 = User, 2 = Admin, 3 = Editor)
     */
    protected function grantTestPermission(int $userId, int $permissionId): void
    {
        $this->db->insert('user_permission_matches', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ]);
        $this->assertFalse($this->db->error(), 'Failed to grant permission for test setup: ' . $this->db->errorString());
        $matchId = (int) $this->db->lastId();
        $this->assertGreaterThan(0, $matchId, 'Failed to read the user_permission_matches row ID for test setup');
        $this->createdPermissionMatchIds[] = $matchId;
    }

    /**
     * Mark us_rate_limits rows that a later endpoint call writes for $action as
     * this test's own, so tearDown() deletes them. No fixture owns those rows,
     * and left in place they push later runs toward the limit. Call it before
     * the request that writes the rows.
     *
     * @param string $action The RateLimit action name the endpoint uses
     */
    protected function trackNewRateLimitRows(string $action): void
    {
        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM us_rate_limits')->first();
        $this->assertFalse($this->db->error(), 'Failed to read us_rate_limits MAX(id) for test setup: ' . $this->db->errorString());
        $this->rateLimitIdFloors[$action] = (int) ($max->max_id ?? 0);
    }

    /**
     * Pass the test DB credentials to the subprocess so it uses the test
     * schema, not the real .env. The subprocess also reloads .env.test.local
     * so users/init.php connects to the test schema.
     *
     * Private: several test classes declare a private method with this name,
     * and a protected one here would be a fatal error in those classes.
     */
    private function exposeTestDatabaseToSubprocess(): void
    {
        // Snapshot once per test, so tearDown() restores the values from before the first call.
        if ($this->originalDbEnv === null) {
            $this->originalDbEnv = [];
            foreach (self::DB_ENV_VARS as $var) {
                $this->originalDbEnv[$var] = getenv($var);
            }
        }

        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    /**
     * Create a test user in database
     *
     * Creates only the `users` row by default. Pass $withProfile to also get
     * the matching `profiles` row.
     *
     * Real owners are created through Owner::create(), which inserts users and
     * profiles together in one transaction, so a user without a profile is a
     * shape no real car owner is in (verified against production-shaped data:
     * zero car owners lack a profiles row). Tests exercising anything that
     * reads or writes profiles — such as the owner e-mail flags
     * (`email_opted_out`, `email_blocked`, `email_bounced`) — therefore need
     * the profile row or they test a state that cannot occur.
     *
     * It is opt-in rather than the default only because many existing tests
     * insert their own profiles row with the specific city/state/country
     * values they assert on; creating one here unconditionally would give
     * those tests two rows. `profiles.user_id` is UNIQUE (#2101), so the
     * second insert would fail. Never pass $withProfile and also insert a
     * profile row. To set the e-mail flags, call the setOwner* helpers, which
     * UPDATE this one row.
     *
     * @param array $data Override default user data
     * @param bool $withProfile Also insert a bare `profiles` row for the user
     * @return int The created user ID
     * @throws RuntimeException If user or profile creation fails
     */
    protected function createTestUser(array $data = [], bool $withProfile = false): int
    {
        $this->requireDatabase();

        // Generate unique username and email
        $uniqueSuffix = uniqid();

        $defaults = [
            'username' => "testuser_{$uniqueSuffix}",
            // Minimum bcrypt cost: no test verifies this hash, and the default cost
            // made every fixture user cost ~0.36s of pure key stretching.
            'password' => password_hash('testpass123', PASSWORD_BCRYPT, ['cost' => 4]),
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

        if ($withProfile) {
            // bio/city/state/country are NOT NULL with no default, so every
            // one must be supplied even though this fixture has no meaningful
            // values for them.
            $profileInserted = $this->db->insert('profiles', [
                'user_id' => $userId,
                'bio'     => '',
                'city'    => '',
                'state'   => '',
                'country' => '',
                // A real profile always has these two, set when the owner
                // e-mail address was first recorded (#2101).
                'email_address_since' => date('Y-m-d H:i:s'),
                'email_address_seen'  => (string) $userData['email'],
            ]);
            if (!$profileInserted) {
                throw new RuntimeException(
                    "Failed to create test profile for user {$userId}: {$this->db->errorString()}"
                );
            }
        }

        return $userId;
    }

    /**
     * Set the owner opt-out flag on the user's one profile row.
     *
     * Needs a profile created with createTestUser($data, true). UPDATEs the
     * row, because `profiles.user_id` is UNIQUE.
     *
     * @param int $userId Owner user ID
     * @param bool $optedOut New value of `email_opted_out`
     * @param string|null $at Value for `email_opted_out_at` (Y-m-d H:i:s), or null
     */
    protected function setOwnerOptedOut(int $userId, bool $optedOut = true, ?string $at = null): void
    {
        $this->updateOwnerProfile($userId, [
            'email_opted_out'    => $optedOut ? 1 : 0,
            'email_opted_out_at' => $optedOut ? ($at ?? date('Y-m-d H:i:s')) : null,
        ]);
    }

    /**
     * Set the owner "blocked" flag (a complaint, an unsubscribe, or a blocklist entry).
     *
     * @param int $userId Owner user ID
     * @param string $address Value for `email_blocked_address`
     * @param string|null $at Value for `email_blocked_at` (Y-m-d H:i:s), or null for now
     * @param string|null $cause Value for `email_blocked_cause`: `spam`, `unsubscribed`, `blocklist`, or null when unknown
     */
    protected function setOwnerBlocked(
        int $userId,
        string $address,
        ?string $at = null,
        ?string $cause = 'spam'
    ): void {
        $this->updateOwnerProfile($userId, [
            'email_blocked'         => 1,
            'email_blocked_address' => $address,
            'email_blocked_at'      => $at ?? date('Y-m-d H:i:s'),
            'email_blocked_cause'   => $cause,
        ]);
    }

    /**
     * Set the owner "bounced" flag.
     *
     * @param int $userId Owner user ID
     * @param string $address Value for `email_bounced_address`
     * @param string|null $at Value for `email_bounced_at` (Y-m-d H:i:s), or null for now
     */
    protected function setOwnerBounced(int $userId, string $address, ?string $at = null): void
    {
        $this->updateOwnerProfile($userId, [
            'email_bounced'         => 1,
            'email_bounced_address' => $address,
            'email_bounced_at'      => $at ?? date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Clear all three owner flags and their detail columns.
     *
     * @param int $userId Owner user ID
     */
    protected function clearOwnerEmailFlags(int $userId): void
    {
        $this->updateOwnerProfile($userId, [
            'email_opted_out'         => 0,
            'email_opted_out_at'      => null,
            'email_blocked'           => 0,
            'email_blocked_address'   => null,
            'email_blocked_at'        => null,
            'email_blocked_cause'     => null,
            'email_bounced'           => 0,
            'email_bounced_address'   => null,
            'email_bounced_at'        => null,
            'email_bounce_cleared_at' => null,
        ]);
    }

    /**
     * Read the owner flag columns of the user's profile row.
     *
     * @param int $userId Owner user ID
     * @return array<string, mixed> Column name to raw value (strings or null, as PDO returns them)
     */
    protected function getOwnerEmailFlags(int $userId): array
    {
        $row = $this->db->query(
            'SELECT email_opted_out, email_opted_out_at, email_blocked, email_blocked_address,
                    email_blocked_at, email_blocked_cause, email_bounced, email_bounced_address,
                    email_bounced_at, email_bounce_cleared_at, email_address_since, email_address_seen
               FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();
        $this->assertIsObject($row, "User {$userId} must have a profiles row (createTestUser(\$data, true))");

        return (array) $row;
    }

    /**
     * Write `users.email` as given, with no normalization and no profile write.
     *
     * Simulates an edit through the upstream admin user form
     * (users/views/_admin_user.php). That form writes `users.email` and sets
     * neither `email_address_since` nor `email_address_seen`, and it runs no
     * hook. The value may hold a tab, a newline or other whitespace.
     *
     * @param int $userId Owner user ID
     * @param string $email Exact value to store
     */
    protected function setUserEmailRaw(int $userId, string $email): void
    {
        $result = $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
        $this->assertFalse($result->error(), 'Test setup: users.email update failed: ' . $result->errorString());
        $stored = $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first();
        $this->assertIsObject($stored, "Test setup: user {$userId} must exist");
        $this->assertSame($email, $stored->email, 'Test setup: users.email must hold the exact raw value');
    }

    /**
     * Set `email_address_since` and `email_address_seen` on the user's profile row.
     *
     * createTestUser($data, true) sets `seen` to the user's email. Use this
     * helper to set another state.
     *
     * @param int $userId Owner user ID
     * @param string|null $seen Value for `email_address_seen`
     * @param string|null $since Value for `email_address_since` (Y-m-d H:i:s), or null for the column default
     */
    protected function setEmailAddressSinceSeen(int $userId, ?string $seen, ?string $since = null): void
    {
        $columns = ['email_address_seen' => $seen];
        if ($since !== null) {
            $columns['email_address_since'] = $since;
        }
        $this->updateOwnerProfile($userId, $columns);
    }

    /**
     * UPDATE the user's one profile row and fail the test if no row matched.
     *
     * @param int $userId Owner user ID
     * @param array<string, mixed> $columns Column name to new value
     */
    private function updateOwnerProfile(int $userId, array $columns): void
    {
        $this->assertNotEmpty($columns, 'Test setup: no profile columns to update');
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $result = $this->db->query(
            "UPDATE profiles SET {$set} WHERE user_id = ?",
            [...array_values($columns), $userId]
        );
        $this->assertFalse($result->error(), 'Test setup: profile update failed: ' . $result->errorString());
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt,
            "Test setup: user {$userId} must have exactly one profiles row (createTestUser(\$data, true))"
        );
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
     * Evaluate a datetime expression on the MySQL server and return the result.
     *
     * Freshness fixtures must come from MySQL's clock, not PHP's.
     * `CarRepository::freshnessSql()` compares against MySQL's `NOW()`, and the
     * two clocks can differ. On the reference dev box PHP is UTC and MySQL is
     * Pacific, 7 hours apart, which would swamp a 60-second boundary offset.
     *
     * @param string $expression A trusted SQL datetime expression, e.g. 'NOW() - INTERVAL 30 DAY'
     * @return string The value as MySQL formats it
     */
    protected function mysqlDatetime(string $expression): string
    {
        $row = $this->db->query("SELECT {$expression} AS t")->first();

        return (string) $row->t;
    }

    /**
     * Random address in 2001:db8::/32 (RFC 3849) for a RateLimit 'ip'
     * identifier. Since UserSpice 6.1.6, RateLimit::getRealIP() uses
     * ClientIP, which returns 127.0.0.1 under the CLI SAPI, so tests without
     * an explicit IP share one bucket. The 254 TEST-NET-3 addresses let two
     * tests in one run collide. The IPv6 range makes a collision unlikely.
     */
    protected function fakeDocumentationIp(): string
    {
        return '2001:db8::' . dechex(random_int(1, 0xffff)) . ':' . dechex(random_int(1, 0xffff));
    }

    /**
     * Seed a car's owner_last_updated to a known value, so a test can later
     * assert whether an operation changed it. Pins the #1929 freshness
     * contract's precondition.
     *
     * @param int $carId The car ID to update
     * @param string $when The value to store (e.g. an old timestamp string)
     */
    protected function seedOwnerLastUpdated(int $carId, string $when): void
    {
        $result = $this->db->query('UPDATE cars SET owner_last_updated = ? WHERE id = ?', [$when, $carId]);
        $this->assertFalse($result->error(), 'Test setup: seeding owner_last_updated must succeed: ' . $result->errorString());
        // Read back, so a seed that wrote nothing cannot leave the creation-time
        // default ("now") in place and let a reset test pass without a reset.
        $this->assertSame(
            $when,
            $this->getOwnerLastUpdated($carId),
            'Test setup: owner_last_updated must read back as seeded (pass a Y-m-d H:i:s value)'
        );
    }

    /**
     * Read a car's current owner_last_updated. Pins the #1929 freshness
     * contract's read side.
     *
     * @param int $carId The car ID to read
     * @return string The stored owner_last_updated value
     */
    protected function getOwnerLastUpdated(int $carId): string
    {
        $row = $this->db->query('SELECT owner_last_updated FROM cars WHERE id = ?', [$carId])->first();
        $this->assertIsObject($row, "Car {$carId} must exist");

        return (string) $row->owner_last_updated;
    }

    /**
     * Count a car's cars_hist audit rows, so a test can assert an operation
     * wrote exactly one row. Pins the #1929 freshness contract's one-UPDATE
     * guarantee (image and owner_last_updated change together).
     *
     * @param int $carId The car ID to count history rows for
     * @return int The number of cars_hist rows for this car
     */
    protected function countCarsHistRows(int $carId): int
    {
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ?', [$carId])->first();
        $this->assertIsObject($row, "Expected a cars_hist count row for car {$carId}");

        return (int) $row->cnt;
    }

    /**
     * Ids of every car findVerificationEligible() returns, in one unbounded call.
     * A fixed window is not enough: a new fixture car sorts with the
     * never-verified cars, so on a large test schema it can fall outside the window.
     *
     * @return list<int>
     */
    protected function allVerificationEligibleCarIds(): array
    {
        $rows = (new \ElanRegistry\Car\CarRepository($this->db))->findVerificationEligible(PHP_INT_MAX, 0);

        return array_map(static fn (object $row): int => (int) $row->id, $rows);
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
            $this->deleteEmailEventsForUserIds([$userId]);
            $this->db->query("DELETE FROM profiles WHERE user_id = ?", [$userId]);
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
     * must go first, then cars_hist. er_email_events has no foreign key to cars,
     * so its rows are deleted first, as CarAdministrationService does.
     *
     * @param int $carId The car ID to delete
     */
    private function deleteCarRows(int $carId): void
    {
        // Only the `sent` rows carry a car. The owner's other rows go with the
        // owner (see deleteEmailEventsForUserIds()), because a car delete
        // keeps them with the user (#2358).
        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$carId]);
        $this->db->delete('cars', ['id', '=', $carId]);
        $this->db->query("DELETE FROM cars_hist WHERE car_id = ?", [$carId]);
    }

    /**
     * Delete the `er_email_events` rows of some users, whatever their `car_id`.
     *
     * An event row belongs to a user. A car delete sets its `car_id` to NULL, so a
     * cleanup by car ID alone leaves such rows in the shared schema.
     *
     * @param array<int> $userIds User IDs. An empty list issues no query.
     * @throws RuntimeException If the delete fails
     */
    protected function deleteEmailEventsForUserIds(array $userIds): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }

        $this->db->query(
            'DELETE FROM er_email_events WHERE user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')',
            $userIds
        );
        if ($this->db->error()) {
            throw new RuntimeException('Failed to delete er_email_events rows: ' . $this->db->errorString());
        }
    }

    /**
     * Delete a car's `er_email_events`, `cars` and `cars_hist` rows (see deleteCarRows())
     * and self-verify the `cars` and `cars_hist` rows are actually gone afterward. DB::query() never throws on an execute-time
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
     * @param int|null $userId Count only this user's rows, so rows from other tests cannot match
     * @throws RuntimeException If the underlying query fails
     */
    protected function countMatchingLogs(string $logtype, string $lognote, ?int $userId = null): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM logs WHERE logtype = ? AND lognote LIKE ?';
        $params = [$logtype, $lognote];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $result = $this->db->query($sql, $params);

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
     * ReconcileOwnerFieldsExecuteTest. The function_exists() guard makes a call
     * from both test classes in the same PHPUnit process safe.
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
