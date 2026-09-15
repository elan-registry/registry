<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral (real-process, real-DB) tests for app/verify/verify_car.php —
 * the public car verification landing page built by #1881.
 *
 * Follows BrevoWebhookEndpointTest.php's harness pattern exactly, since
 * verify_car.php explicitly follows app/api/webhooks/brevo.php's shape (no
 * securePage(), no session): a router script requires the real endpoint file
 * under PHP's built-in web server (`php -S`), driven with real HTTP requests
 * via curl so genuine $_SERVER['REQUEST_METHOD'], GET/POST superglobals, and
 * response headers/status are all exercised — not simulated by including the
 * file directly with superglobals hand-set.
 *
 * @see docs/plans/issue-1881-verify-car-landing-page.md
 * @see https://github.com/elan-registry/registry/issues/1881
 */
#[Group('integration')]
final class VerifyCarLandingPageTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    /** @var resource|null */
    private static $serverProcess = null;
    private static int $serverPort = 0;
    private static string $projectRoot = '';
    private static string $routerScriptPath = '';

    private int $testUserId = 0;
    private int $testCarId = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 2);

        // Pseudo-random port seeded by PID; phpunit-integration.xml runs with
        // processIsolation="false" (single process) so a collision with
        // BrevoWebhookEndpointTest's own range (20000 + pid%20000, i.e.
        // 20000-39999) is possible in principle — both ranges are wide enough,
        // relative to the number of concurrently-running test classes, that
        // this has not been observed in practice, but the ranges do overlap.
        self::$serverPort = 40000 + (getmypid() % 10000);

        self::$routerScriptPath = self::writeRouterScript();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        if (self::$routerScriptPath !== '' && file_exists(self::$routerScriptPath)) {
            unlink(self::$routerScriptPath);
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->exposeTestDatabaseToEnvironment();
        self::ensureServerRunning();

        // $withProfile: the #1883 opt-out records profiles.email_suppressed on
        // the owner, so the fixture needs the profiles row every real owner has.
        $this->testUserId = $this->createTestUser([], true);
        $this->testCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'year' => 1968,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // Clear rate-limit rows this test may have seeded/generated for the
            // verification_code_attempt action so runs stay independent.
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'verification_code_attempt'");
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Server lifecycle (mirrors BrevoWebhookEndpointTest.php)
    // ------------------------------------------------------------------

    private static function writeRouterScript(): string
    {
        $projectRoot = self::$projectRoot;

        $routerSource = <<<PHP
        <?php
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
        chdir('{$projectRoot}/app/verify');
        require '{$projectRoot}/app/verify/verify_car.php';
        PHP;

        $path = tempnam(sys_get_temp_dir(), 'verify_car_router_') . '.php';
        $written = file_put_contents($path, $routerSource);
        if ($written === false) {
            throw new RuntimeException('Could not write router script for verify_car.php test server');
        }

        return $path;
    }

    private function exposeTestDatabaseToEnvironment(): void
    {
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
    }

    private static function ensureServerRunning(): void
    {
        if (self::$serverProcess !== null) {
            return;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = sprintf(
            'php -S 127.0.0.1:%d -t %s %s',
            self::$serverPort,
            escapeshellarg(self::$projectRoot),
            escapeshellarg(self::$routerScriptPath)
        );

        $process = proc_open($command, $descriptorSpec, $pipes, self::$projectRoot, null);
        if ($process === false) {
            throw new RuntimeException('Could not start PHP built-in server for verify_car.php test');
        }

        self::$serverProcess = $process;

        $deadline = microtime(true) + 3.0;
        $connected = false;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                $connected = true;
                break;
            }
            usleep(50_000);
        }

        if (!$connected) {
            self::stopServer();
            throw new RuntimeException('PHP built-in server did not start listening on port ' . self::$serverPort);
        }
    }

    private static function stopServer(): void
    {
        if (self::$serverProcess === null) {
            return;
        }

        $status = proc_get_status(self::$serverProcess);
        if ($status['running']) {
            proc_terminate(self::$serverProcess, 15);
            usleep(100_000);
        }
        proc_close(self::$serverProcess);
        self::$serverProcess = null;
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    private function get(string $queryString): array
    {
        return $this->request('GET', $queryString, null);
    }

    /**
     * @param array<string, string> $fields
     * @return array{status: int, body: string, location: ?string}
     */
    private function post(string $queryString, array $fields): array
    {
        return $this->request('POST', $queryString, http_build_query($fields));
    }

    /**
     * @return array{status: int, body: string, location: ?string}
     */
    private function request(string $method, string $queryString, ?string $body): array
    {
        $url = sprintf('http://127.0.0.1:%d/app/verify/verify_car.php', self::$serverPort);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("curl request to verify_car.php failed: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $responseBody = substr((string) $response, $headerSize);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'body' => $responseBody, 'location' => $location];
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * Sets a plaintext vericode on the test car (hashed at rest, per
     * CarVerificationManager::setVerificationCode()) and, unless overridden,
     * a vericode_sent_at timestamp well inside the 60-day expiry window.
     * Returns the plaintext code for use in requests.
     */
    private function issueVericode(?string $sentAt = null): string
    {
        $plaintext = bin2hex(random_bytes(16));

        $this->db->query(
            'UPDATE cars SET vericode = ?, vericode_sent_at = ? WHERE id = ?',
            [hashVericode($plaintext), $sentAt ?? date('Y-m-d H:i:s', strtotime('-10 days')), $this->testCarId]
        );
        $this->assertFalse($this->db->error(), 'Test setup: failed to seed vericode fixture');

        return $plaintext;
    }

    /**
     * @return array<string, mixed>
     */
    private function carRow(): array
    {
        $row = $this->db->query('SELECT * FROM cars WHERE id = ?', [$this->testCarId])->first();
        $this->assertNotNull($row, 'Expected to find the test car row');
        return (array) $row;
    }

    private function historyCount(string $operation): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = ?',
            [$this->testCarId, $operation]
        )->first();
        return (int) $row->cnt;
    }

    private function historyCountForCar(int $carId, string $operation): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = ?',
            [$carId, $operation]
        )->first();
        return (int) $row->cnt;
    }

    private function emailSuppressed(int $carId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM cars WHERE id = ?', [$carId])->first();
        $this->assertNotNull($row, "Test setup: car {$carId} disappeared");
        return (int) $row->email_suppressed;
    }

    /** The owner-level opt-out flag (#1883), counterpart to emailSuppressed(). */
    private function profileEmailSuppressed(int $ownerId): int
    {
        $row = $this->db->query('SELECT email_suppressed FROM profiles WHERE user_id = ?', [$ownerId])->first();
        $this->assertNotNull($row, "Test setup: owner {$ownerId} has no profiles row");
        return (int) $row->email_suppressed;
    }

    // ------------------------------------------------------------------
    // GET never mutates
    // ------------------------------------------------------------------

    public function testGetActionVerifyDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code . '&action=verify');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before['last_verified'], $after['last_verified'], 'GET must not set last_verified');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'GET must not touch owner_last_updated');
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('VERIFIED'), 'GET must not write a VERIFIED cars_hist row');
    }

    public function testGetActionSoldDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code . '&action=sold');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'GET must not set solddate');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'GET must not touch owner_last_updated');
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'), 'GET must not write a VERIFIED SOLD cars_hist row');
    }

    public function testGetWithNoActionLandingPageDoesNotMutateCarRow(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame($before, $after, 'Plain landing-page GET must leave every column unchanged');
    }

    // ------------------------------------------------------------------
    // POST action=verify
    // ------------------------------------------------------------------

    public function testPostActionVerifySetsLastVerifiedAndOwnerLastUpdatedAndInsertsHistory(): void
    {
        $code = $this->issueVericode();

        $historyBefore = $this->historyCount('VERIFIED');

        $result = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=verify', (string) $result['location']);

        $after = $this->carRow();
        $this->assertNotNull($after['last_verified'], 'last_verified must be set');
        $this->assertNotNull($after['owner_last_updated'], 'owner_last_updated must be set');

        $this->assertSame(
            $historyBefore + 1,
            $this->historyCount('VERIFIED'),
            'Exactly one VERIFIED cars_hist row must be inserted'
        );
    }

    // ------------------------------------------------------------------
    // POST action=sold
    // ------------------------------------------------------------------

    public function testPostActionSoldWithValidPastDateSetsSubmittedDateNotToday(): void
    {
        $code = $this->issueVericode();
        // Deliberately not today, to prove the submitted date (not date())
        // is what gets written.
        $submittedDate = date('Y-m-d', strtotime('-30 days'));
        $this->assertNotSame(date('Y-m-d'), $submittedDate, 'Test sanity: fixture date must differ from today');

        $historyBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $submittedDate,
        ]);

        $this->assertSame(303, $result['status']);
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=sold', (string) $result['location']);

        $after = $this->carRow();
        $this->assertSame($submittedDate, (string) $after['solddate'], 'solddate must equal the submitted date, not today');
        $this->assertNotNull($after['owner_last_updated']);

        $this->assertSame(
            $historyBefore + 1,
            $this->historyCount('VERIFIED SOLD'),
            'Exactly one VERIFIED SOLD cars_hist row must be inserted'
        );

        $histRow = $this->db->query(
            'SELECT solddate FROM cars_hist WHERE car_id = ? AND operation = ? ORDER BY id DESC LIMIT 1',
            [$this->testCarId, 'VERIFIED SOLD']
        )->first();
        $this->assertSame($submittedDate, (string) $histRow->solddate, 'cars_hist VERIFIED SOLD row must carry the submitted date');
    }

    public function testPostActionSoldWithFutureDateIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();
        $futureDate = date('Y-m-d', strtotime('+5 days'));

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $futureDate,
        ]);

        $this->assertSame(200, $result['status'], 'Rejected submission re-renders the form, no redirect');
        $this->assertStringContainsString('can&#039;t be in the future', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'Future date must not be written');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    public function testPostActionSoldWithPreBuildYearDateIsRejectedWithNoWrite(): void
    {
        // Fixture car has year=1968 (set in setUp()), so Jan 1 1968 is the floor.
        $code = $this->issueVericode();
        $before = $this->carRow();
        $tooEarly = '1967-12-31';

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $tooEarly,
        ]);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString('can&#039;t be before the car was built', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    /**
     * Structured-input requirement: a solddate that is present but not a
     * valid date string at all, not merely out of range. Confirms the page's
     * own documented failure mode (inline validation error, no write, no
     * crash) rather than a PHP fatal from DateTime::createFromFormat()
     * choking on garbage input.
     */
    public function testPostActionSoldWithNonsenseDateStringIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => 'not-a-date',
        ]);

        $this->assertSame(200, $result['status'], 'Malformed date must not crash the page');
        $this->assertStringContainsString('valid date', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    public function testPostActionSoldWithImpossibleCalendarDateIsRejectedWithNoWrite(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => '2026-13-45',
        ]);

        $this->assertSame(200, $result['status'], 'Impossible calendar date must not crash the page');
        $this->assertStringContainsString('valid date', $result['body']);

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate']);
        $this->assertSame(0, $this->historyCount('VERIFIED SOLD'));
    }

    // ------------------------------------------------------------------
    // Already-sold idempotency
    // ------------------------------------------------------------------

    public function testAlreadySoldCarRendersIdempotentStateOnGetNoActionAndActionSold(): void
    {
        $code = $this->issueVericode();
        $this->db->query('UPDATE cars SET solddate = ? WHERE id = ?', ['2020-01-01', $this->testCarId]);

        $noAction = $this->get('vericode=' . $code);
        $this->assertSame(200, $noAction['status']);
        // Landing page disables the Sold button for an already-sold car.
        $this->assertStringContainsString('Already recorded as sold', $noAction['body']);

        $withAction = $this->get('vericode=' . $code . '&action=sold');
        $this->assertSame(200, $withAction['status']);
        $this->assertStringContainsString('already recorded as sold', $withAction['body']);
    }

    public function testAlreadySoldCarPostActionSoldIsStrictNoOp(): void
    {
        $code = $this->issueVericode();
        $originalSoldDate = '2020-01-01';
        $this->db->query('UPDATE cars SET solddate = ? WHERE id = ?', [$originalSoldDate, $this->testCarId]);
        $before = $this->carRow();
        $historyBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => date('Y-m-d', strtotime('-2 days')),
        ]);

        $this->assertSame(200, $result['status'], 'Already-sold POST renders the notice directly, no redirect');

        $after = $this->carRow();
        $this->assertSame($before['solddate'], $after['solddate'], 'solddate must not change on a repeat POST');
        $this->assertSame($before['owner_last_updated'], $after['owner_last_updated'], 'owner_last_updated must not change');
        $this->assertSame(
            $historyBefore,
            $this->historyCount('VERIFIED SOLD'),
            'No second VERIFIED SOLD cars_hist row on a repeat POST'
        );
    }

    // ------------------------------------------------------------------
    // VERIFIED SOLD vs ordinary edit-path UPDATE distinguishability
    // ------------------------------------------------------------------

    /**
     * The `cars_update` DB trigger (see database/migrations/…convert_car_
     * timestamps_to_datetime.php) fires on every UPDATE to `cars`, including
     * the ones verify_car.php itself issues via markSold()/markVerified() —
     * so a single verify POST produces BOTH a trigger-written 'UPDATE' row
     * AND the app's own explicit 'VERIFIED SOLD'/'VERIFIED' row. This test
     * proves the two remain distinguishable by a single query filtering
     * `operation`, per the issue's acceptance criterion — not that only one
     * row type exists, but that the owner-initiated row is never confused
     * with the trigger's generic one.
     */
    public function testVerifiedSoldOperationIsDistinguishableFromOrdinaryUpdateOperation(): void
    {
        $code = $this->issueVericode();
        $soldDate = date('Y-m-d', strtotime('-15 days'));

        $updateCountBefore = $this->historyCount('UPDATE');
        $verifiedSoldCountBefore = $this->historyCount('VERIFIED SOLD');

        $result = $this->post('vericode=' . $code . '&action=sold', [
            'vericode' => $code,
            'solddate' => $soldDate,
        ]);
        $this->assertSame(303, $result['status']);

        // The trigger fired (an ordinary 'UPDATE' row exists from the same
        // markSold() call) ...
        $this->assertGreaterThan(
            $updateCountBefore,
            $this->historyCount('UPDATE'),
            'The cars_update trigger must still fire a generic UPDATE row for this same write'
        );

        // ... and it is a DIFFERENT row from the app's own audit row: a query
        // filtered to operation='VERIFIED SOLD' finds exactly the one owner-
        // initiated row, never conflated with the trigger's 'UPDATE' row.
        $this->assertSame($verifiedSoldCountBefore + 1, $this->historyCount('VERIFIED SOLD'));

        $verifiedSoldRow = $this->db->query(
            "SELECT operation FROM cars_hist WHERE car_id = ? AND operation = 'VERIFIED SOLD' ORDER BY id DESC LIMIT 1",
            [$this->testCarId]
        )->first();
        $this->assertSame('VERIFIED SOLD', $verifiedSoldRow->operation);
        $this->assertNotSame('UPDATE', $verifiedSoldRow->operation);
    }

    // ------------------------------------------------------------------
    // 60-day expiry boundary
    // ------------------------------------------------------------------

    public function testVericodeAtFiftyNineDaysIsStillValid(): void
    {
        $code = $this->issueVericode(date('Y-m-d H:i:s', strtotime('-59 days')));

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);
        $this->assertStringNotContainsString('expired or is no longer valid', $result['body']);
    }

    public function testVericodeAtSixtyOneDaysIsExpired(): void
    {
        $code = $this->issueVericode(date('Y-m-d H:i:s', strtotime('-61 days')));

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
    }

    // ------------------------------------------------------------------
    // Vericode format rejection (before any DB query)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedVericodeProvider(): array
    {
        return [
            'too short' => ['abc123'],
            'too long' => [str_repeat('a', 33)],
            'uppercase hex' => [str_repeat('A', 32)],
            'non-hex characters' => [str_repeat('z', 32)],
            'empty' => [''],
        ];
    }

    #[DataProvider('malformedVericodeProvider')]
    public function testMalformedVericodeIsRejectedBeforeAnyDbQuery(string $badCode): void
    {
        $result = $this->get('vericode=' . urlencode($badCode));

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);
    }

    // ------------------------------------------------------------------
    // Mismatched user id in POST body on action=optout (adversarial)
    // ------------------------------------------------------------------

    /**
     * Issue #1883 AC10: "The owner suppressed is derived server-side from the
     * vericode lookup only — no car or user identifier in the request is
     * trusted; covered by a test posting a valid vericode alongside a
     * mismatched user id and asserting only the vericode's own owner is
     * suppressed."
     *
     * verify_car.php's action=optout POST branch calls
     * Input::raw('vericode') and Input::raw('action') only — it never calls
     * Input::raw() for any user/owner/car identifier (grep the file: the only
     * three Input::raw() call sites are 'vericode', 'action', and 'solddate').
     * So posting a 'user_id' (or any other plausibly-attacker-controlled
     * field name) alongside the vericode has no code path that could read it
     * — $ownerId is resolved once, earlier in the file, purely from
     * $verifyCar->user_id (itself resolved from the vericode lookup at
     * CarRepository::findByVerificationCode()). This test proves that by
     * actively trying to defeat it: it posts owner B's id under several
     * plausible field names next to owner A's valid vericode, then asserts
     * owner A (the vericode's real owner) is suppressed and owner B is not
     * touched at all.
     */
    public function testPostWithMismatchedUserIdInBodySuppressesOnlyTheVericodesOwnOwner(): void
    {
        // Owner A: the fixture from setUp() ($this->testUserId / $this->testCarId).
        $code = $this->issueVericode();

        // Owner B: a second owner with their own car, wholly unrelated to the
        // vericode being posted.
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId, ['chassis' => 'VF' . uniqid()]);

        $this->assertSame(0, $this->emailSuppressed($this->testCarId), 'Test sanity: owner A car must start unsuppressed');
        $this->assertSame(0, $this->emailSuppressed($otherCarId), 'Test sanity: owner B car must start unsuppressed');

        $historyBeforeA = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeB = $this->historyCountForCar($otherCarId, 'EMAIL SUPPRESSED');

        // The vericode belongs to owner A, but the POST body claims owner B's
        // id under every plausible field name an attacker might guess the
        // page reads for the fan-out target. None of these fields are part of
        // the page's real form — this simulates a forged/tampered request.
        $result = $this->post('vericode=' . $code . '&action=optout', [
            'vericode' => $code,
            'user_id' => (string) $otherUserId,
            'owner_id' => (string) $otherUserId,
            'car_id' => (string) $otherCarId,
        ]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=optout', (string) $result['location']);

        $this->assertSame(
            1,
            $this->emailSuppressed($this->testCarId),
            "The vericode's own owner (owner A) must be suppressed"
        );
        $this->assertSame(
            0,
            $this->emailSuppressed($otherCarId),
            'The owner id named in the POST body (owner B) must NOT be suppressed — this is the critical assertion'
        );

        $this->assertSame(
            $historyBeforeA + 1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for owner A\'s car'
        );
        $this->assertSame(
            $historyBeforeB,
            $this->historyCountForCar($otherCarId, 'EMAIL SUPPRESSED'),
            'Owner B\'s car must get zero EMAIL SUPPRESSED cars_hist rows'
        );

        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
        $this->deleteTestUser($otherUserId);
    }

    // ------------------------------------------------------------------
    // Mismatched car id in hidden POST field (adversarial)
    // ------------------------------------------------------------------

    public function testPostWithMismatchedCarIdInHiddenFieldMutatesOnlyTheVericodesOwnCar(): void
    {
        $otherUserId = $this->createTestUser();
        $otherCarId = $this->createTestCar($otherUserId, ['chassis' => 'VF' . uniqid()]);

        $code = $this->issueVericode();
        $otherCarBefore = $this->db->query('SELECT * FROM cars WHERE id = ?', [$otherCarId])->first();

        // The vericode belongs to $this->testCarId, but the POST body claims
        // a different car's id via a field the page never reads for mutation
        // purposes (verify_car.php resolves the car exclusively from the
        // vericode, per its own docblock — this "car_id" field is not even
        // part of its real form, this simulates a forged/tampered request).
        $result = $this->post('vericode=' . $code . '&action=verify', [
            'vericode' => $code,
            'car_id' => (string) $otherCarId,
        ]);

        $this->assertSame(303, $result['status']);

        $mutatedCar = $this->carRow();
        $this->assertNotNull($mutatedCar['last_verified'], "The vericode's own car (testCarId) must be verified");

        $otherCarAfter = $this->db->query('SELECT * FROM cars WHERE id = ?', [$otherCarId])->first();
        $this->assertNull($otherCarAfter->last_verified, 'The car named in the hidden field must NOT be mutated');
        $this->assertEquals(
            $otherCarBefore->owner_last_updated,
            $otherCarAfter->owner_last_updated,
            'The car named in the hidden field must be completely untouched'
        );

        $this->db->query('DELETE FROM cars_hist WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }

    // ------------------------------------------------------------------
    // Cancel link
    // ------------------------------------------------------------------

    public function testCancelGetPerformsNoMutationAndVericodeRemainsUsableAfterward(): void
    {
        $code = $this->issueVericode();

        // Visit the verify-confirm step, then "Cancel" back to the plain
        // landing URL (a GET, not a form submit) per the page's own Cancel
        // link semantics.
        $this->get('vericode=' . $code . '&action=verify');
        $cancelResult = $this->get('vericode=' . $code);

        $this->assertSame(200, $cancelResult['status']);

        $afterCancel = $this->carRow();
        $this->assertNull($afterCancel['last_verified'], 'Cancel must not have verified the car');

        // The vericode must still work: issue a real action against it now.
        $verifyResult = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $verifyResult['status'], 'The vericode must remain usable after a Cancel GET');

        $afterVerify = $this->carRow();
        $this->assertNotNull($afterVerify['last_verified'], 'The still-usable vericode must actually verify the car');
    }

    // ------------------------------------------------------------------
    // Ownership defense-in-depth
    // ------------------------------------------------------------------

    public function testOwnerlessCarWithValidUnexpiredVericodeRendersInvalidLink(): void
    {
        $code = $this->issueVericode();

        // Mirror the "ownerless" condition findVerificationEligible() (and
        // this page's own re-check) uses: no live users row, or reassigned
        // to the 'noowner' GDPR placeholder. Simulate via user_id pointing at
        // a nonexistent user id — cars.user_id has no FK, so this is exactly
        // the deleted-user shape the page's docblock describes.
        $nonexistentUserId = 999999999;
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$nonexistentUserId, $this->testCarId]);

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);

        // No enumeration signal: identical body to a wholly-unknown vericode.
        $unknownResult = $this->get('vericode=' . bin2hex(random_bytes(16)));
        $this->assertSame($result['body'], $unknownResult['body'], 'Ownerless-car and unknown-code responses must be identical (no enumeration signal)');
    }

    public function testCarReassignedToNoownerWithValidUnexpiredVericodeRendersInvalidLink(): void
    {
        $noownerUserId = $this->createTestUser(['username' => 'noowner_' . uniqid()]);
        // Directly force the username to the literal 'noowner' sentinel the
        // page's re-check filters on (CarRepository::findVerificationEligible()'s
        // exclusion, mirrored by verify_car.php's own re-check).
        $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['noowner', $noownerUserId]);

        $code = $this->issueVericode();
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$noownerUserId, $this->testCarId]);

        $result = $this->get('vericode=' . $code);

        $this->assertSame(404, $result['status']);
        $this->assertStringContainsString('expired or is no longer valid', $result['body']);

        // Restore username before IntegrationTestCase's tearDown() deletes it,
        // purely so cleanup doesn't depend on the 'noowner' rename surviving.
        $this->db->query('UPDATE users SET username = ? WHERE id = ?', ['restored_' . uniqid(), $noownerUserId]);
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    /**
     * The config pin in RateLimitConfigTest proves the 'verification_code_attempt'
     * block exists — it does NOT prove verify_car.php's checkRateLimit()/
     * recordRateLimit() calls actually run against it. That gap matters
     * because RateLimit::check()'s token_max/total_max paths count ONLY rows
     * written by record() — without a working recordRateLimit() call (or if
     * the action-name string ever drifts out of sync between the page and
     * the config), the configured limit can silently never trip while every
     * other test in this file keeps passing, since none of them inspect
     * us_rate_limits directly. This test closes that gap: it proves a real
     * request writes a real row, scoped to the token identifier the page
     * documents using (see verify_car.php's rate-limiting comment block).
     */
    public function testGetRequestRecordsRateLimitAttemptForTokenIdentifier(): void
    {
        $code = $this->issueVericode();
        $identifierKey = hash('sha256', 'token::' . $code);

        $countRows = fn (): int => (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = 'verification_code_attempt' AND identifier_key = ?",
            [$identifierKey]
        )->first()->cnt;

        $before = $countRows();

        $result = $this->get('vericode=' . $code);
        $this->assertSame(200, $result['status']);

        $this->assertSame(
            $before + 1,
            $countRows(),
            "A GET request must record exactly one row against action='verification_code_attempt' "
                . "scoped to this vericode's token identifier — without this, checkRateLimit() sees zero "
                . 'attempts and the per-token brute-force limit can never trip'
        );
    }

    /**
     * RateLimit::check()'s token_max/ip_max paths count ONLY rows with
     * success=0 (getAttemptCount()'s $successOnly=false path) — a rejected
     * request that gets recorded as success=1 is invisible to those limits
     * forever, regardless of how many rows accumulate. A prior version of
     * verify_car.php recorded $rateLimitAllowed (whether the request was
     * throttled) instead of whether the vericode actually resolved, so every
     * wrong guess landed as success=1 and token_max/ip_max were silently
     * inert in production even though testGetRequestRecordsRateLimitAttempt-
     * ForTokenIdentifier above — and every other test in this file — kept
     * passing. This test drives a real unresolvable-vericode request and
     * asserts the row it produces is a recorded failure, closing exactly
     * that gap.
     */
    public function testUnknownVericodeRecordsFailedRateLimitAttempt(): void
    {
        $code = bin2hex(random_bytes(16));
        $identifierKey = hash('sha256', 'token::' . $code);

        $result = $this->get('vericode=' . $code);
        $this->assertSame(404, $result['status']);

        $row = $this->db->query(
            "SELECT success FROM us_rate_limits WHERE action = 'verification_code_attempt' "
                . 'AND identifier_key = ? ORDER BY id DESC LIMIT 1',
            [$identifierKey]
        )->first();

        $this->assertNotNull($row, 'An unresolvable vericode must still record a rate-limit attempt row');
        $this->assertSame(
            0,
            (int) $row->success,
            'An unresolvable vericode must record success=0 — RateLimit::check() counts only success=0 rows '
                . 'toward token_max/ip_max, so recording anything else makes those limits permanently inert'
        );
    }

    /**
     * verify_car.php's own docblock states that a throttled (429) request
     * must render the identical body to every other rejection — "a throttled
     * prober must not learn that they were throttled rather than simply
     * wrong." This is a behavioral (real HTTP, real process) test, so it
     * seeds enough failed us_rate_limits rows to trip the token_max
     * threshold and then asserts on the real response.
     *
     * NOTE: computing the *effective* token_max from this test process is
     * unreliable — the php -S server process this test drives loads .env
     * itself (via Dotenv::createImmutable() inside users/init.php) and
     * applies usersc/includes/rate_limits_dev_override.php's 100x
     * US_ENVIRONMENT=development relaxation, while replicating that exact
     * env/config load from this separate PHPUnit process was not made
     * reliable enough to land here. Seeding a large, fixed row count (well
     * above both the unrelaxed default of 10 and the relaxed 1000) avoids
     * depending on knowing the exact effective threshold.
     */
    public function testThrottledRequestRendersIdenticalBodyToInvalidLink(): void
    {
        $code = $this->issueVericode();
        $identifierKey = hash('sha256', 'token::' . $code);

        // RateLimit::check() counts token_max against success=0 rows
        // specifically (getAttemptCount()'s $success=false path), not all
        // attempts, so seed failed rows directly rather than firing
        // thousands of real HTTP requests first. 1100 comfortably exceeds
        // both the unrelaxed (10) and 100x dev-relaxed (1000) thresholds.
        for ($i = 0; $i < 1100; $i++) {
            $this->db->insert('us_rate_limits', [
                'action' => 'verification_code_attempt',
                'identifier_key' => $identifierKey,
                'success' => 0,
                'attempt_time' => date('Y-m-d H:i:s'),
            ]);
        }

        $result = $this->get('vericode=' . $code);
        $this->assertSame(429, $result['status'], 'A request well past token_max must be throttled');

        $unknownResult = $this->get('vericode=' . bin2hex(random_bytes(16)));
        $this->assertSame(
            $unknownResult['body'],
            $result['body'],
            'A throttled (429) response and an unknown-vericode (404) response must render identical bodies — '
                . 'only the transport-level status may differ, per verify_car.php\'s no-enumeration-signal contract'
        );
    }

    // ------------------------------------------------------------------
    // Dispatch edge cases
    // ------------------------------------------------------------------

    public function testUnknownPostActionRedirectsToLandingPageWithoutMutating(): void
    {
        $code = $this->issueVericode();

        $result = $this->post('vericode=' . $code . '&action=bogus', ['vericode' => $code]);

        $this->assertSame(303, $result['status']);
        $this->assertNotNull($result['location']);
        $this->assertStringNotContainsString('action=', (string) $result['location']);

        $afterRow = $this->carRow();
        $this->assertNull($afterRow['last_verified'], 'An unrecognized POST action must not verify the car');
        $this->assertNull($afterRow['solddate'], 'An unrecognized POST action must not mark the car sold');
    }

    /**
     * Unlike the sold path (already covered above), a repeat verify POST is
     * not guarded — this is deliberate (re-attesting "still accurate" is
     * harmless, unlike re-recording a sale), but that asymmetry was
     * previously untested and undocumented. This pins the current, intended
     * behavior so a future change to it is a conscious decision, not a
     * silent regression.
     */
    public function testRepeatVerifyPostReVerifiesAndInsertsAnotherHistoryRow(): void
    {
        $code = $this->issueVericode();

        $first = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $first['status']);

        $countHistory = fn (): int => (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM cars_hist WHERE car_id = ? AND operation = 'VERIFIED'",
            [$this->testCarId]
        )->first()->cnt;

        $afterFirst = $countHistory();
        $this->assertGreaterThanOrEqual(1, $afterFirst);

        $second = $this->post('vericode=' . $code . '&action=verify', ['vericode' => $code]);
        $this->assertSame(303, $second['status'], 'A repeat verify POST must still succeed, not error');

        $this->assertSame(
            $afterFirst + 1,
            $countHistory(),
            'A repeat verify POST currently re-runs markVerified() and inserts another VERIFIED history row — '
                . 'this test pins that as intended behavior rather than leaving it silently unverified'
        );
    }

    // ------------------------------------------------------------------
    // safelyCaptureDest() wiring
    // ------------------------------------------------------------------

    public function testLoggedOutLandingPageReviewLinkPointsAtLoginNotEditPage(): void
    {
        $code = $this->issueVericode();

        $result = $this->get('vericode=' . $code);

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'usersc/login.php',
            $result['body'],
            'The logged-out landing page\'s "Review & Update" link must point at login, per safelyCaptureDest() wiring'
        );
        $this->assertStringNotContainsString(
            'app/owner/cars/edit.php',
            $result['body'],
            'A logged-out visitor must never be linked straight to the edit page — they cannot reach it without signing in first'
        );
    }

    // ------------------------------------------------------------------
    // action=optout (#1883 — one-click verification-email opt-out)
    // ------------------------------------------------------------------

    public function testGetActionOptoutNeverMutatesEmailSuppressed(): void
    {
        $code = $this->issueVericode();
        $before = $this->carRow();
        $this->assertSame(0, (int) $before['email_suppressed'], 'Test sanity: fixture car must start unsuppressed');

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);

        $after = $this->carRow();
        $this->assertSame(0, (int) $after['email_suppressed'], 'GET must not set email_suppressed');
        $this->assertSame(
            0,
            $this->profileEmailSuppressed($this->testUserId),
            'GET must not set the owner-level flag either (#1883)'
        );
        $this->assertSame($before['mtime'], $after['mtime'], 'GET must not touch mtime (proves no UPDATE was issued at all)');
        $this->assertSame(0, $this->historyCount('EMAIL SUPPRESSED'), 'GET must not write an EMAIL SUPPRESSED cars_hist row');
    }

    public function testPostActionOptoutSuppressesEveryOwnerCarAndInsertsOneHistoryRowEach(): void
    {
        $code = $this->issueVericode();
        $secondCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'email_suppressed' => 0,
        ]);

        $historyBeforeCar1 = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeCar2 = $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED');

        $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $result['status'], 'POST must respond with a PRG redirect');
        $this->assertNotNull($result['location']);
        $this->assertStringContainsString('action=optout', (string) $result['location']);

        $this->assertSame(1, $this->emailSuppressed($this->testCarId), 'The vericode\'s own car must be suppressed');
        $this->assertSame(1, $this->emailSuppressed($secondCarId), 'The owner\'s other car must also be suppressed');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($this->testUserId),
            'The owner-level profiles.email_suppressed flag must also be set (#1883)'
        );

        $this->assertSame(
            $historyBeforeCar1 + 1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for the first car'
        );
        $this->assertSame(
            $historyBeforeCar2 + 1,
            $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED'),
            'Exactly one EMAIL SUPPRESSED cars_hist row must be inserted for the second car'
        );
    }

    public function testRepeatPostActionOptoutIsIdempotentWithNoDuplicateHistoryRows(): void
    {
        $code = $this->issueVericode();

        $first = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $first['status']);
        $this->assertSame(1, $this->emailSuppressed($this->testCarId));

        $historyAfterFirst = $this->historyCount('EMAIL SUPPRESSED');
        $this->assertSame(1, $historyAfterFirst);

        $second = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

        $this->assertSame(303, $second['status'], 'A repeat opt-out POST must still succeed (idempotent), not error');
        $this->assertSame(1, $this->emailSuppressed($this->testCarId), 'email_suppressed must remain 1, not toggle');
        $this->assertSame(
            1,
            $this->profileEmailSuppressed($this->testUserId),
            'The owner-level flag must also remain 1 across a repeat opt-out (#1883)'
        );
        $this->assertSame(
            $historyAfterFirst,
            $this->historyCount('EMAIL SUPPRESSED'),
            'A repeat opt-out POST must not insert a second EMAIL SUPPRESSED cars_hist row'
        );
    }

    public function testGetActionOptoutAfterSuppressionRendersAlreadyUnsubscribedState(): void
    {
        $code = $this->issueVericode();
        $post = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);
        $this->assertSame(303, $post['status']);

        $result = $this->get('vericode=' . $code . '&action=optout');

        $this->assertSame(200, $result['status']);
        $this->assertStringContainsString(
            'unsubscribed',
            strtolower($result['body']),
            'Revisiting after suppression must render the already-unsubscribed success state'
        );
    }

    // A prior version of this test attempted to simulate a mid-loop
    // insertHistory() failure by pre-reserving cars_hist's next
    // auto-increment id with a placeholder row, then relying on the real
    // insert to collide on a duplicate primary key. That collision is not
    // reliable: cars_hist.id also advances from the `cars_insert`/
    // `cars_update`/`cars_delete` triggers (verified live — every write to
    // `cars` fires an unconditional AFTER trigger that inserts its own
    // cars_hist row), so the id reserved by the probe can be consumed by
    // unrelated trigger-driven activity before this test's own POST runs,
    // making the test flaky rather than deterministic (observed: reserved
    // id 1000001044, actual ids landed at 1000001044/1047/1048 — no
    // collision occurred). A genuine per-car failure-injection point does
    // not exist at this HTTP layer via a PRIMARY KEY collision: cars.* and
    // cars_hist.* share identical column widths (deliberately mirrored, see
    // DATABASE.md), so no value can be valid for the cars row a fixture
    // needs but invalid for the cars_hist insert; and Owner::find() resolves
    // per owner, not per car, so it cannot be made to fail for the second
    // car without also failing the first (same owner for both).
    //
    // testOptoutMidTransactionFailureReturns500AndRollsBackAllCarsAndHistoryRows()
    // below replaces that abandoned approach with a different, genuinely
    // deterministic failure-injection technique: a held table lock rather
    // than a row/id collision.

    /**
     * Deterministic HTTP-level failure injection for the optout transaction.
     *
     * Forces the real `UPDATE cars` issued by setSuppressedForOwner() ->
     * updateEmailSuppressed() to fail: the `cars_update` AFTER trigger (see
     * database/migrations/20260709000000_add_elanregistry_baseline.php)
     * unconditionally inserts its own audit row into `cars_hist` as PART OF
     * that same UPDATE statement, so holding `LOCK TABLES cars_hist WRITE`
     * from a second connection blocks the trigger's insert and, transitively,
     * the UPDATE itself — with no reliance on row ids, timing windows, or
     * column-width tricks. `SET GLOBAL lock_wait_timeout` (not
     * innodb_lock_wait_timeout — LOCK TABLES obeys the former, not the
     * latter) is set to 1s beforehand so the fresh PDO connection
     * verify_car.php's own `php -S` request opens picks up a 1s wait as its
     * session default; the pre-existing default (31536000s / one year) would
     * otherwise make this test hang effectively forever. The failing UPDATE
     * throws a PDOException, which CarVerificationManager::persist() catches
     * and rethrows as CarDatabaseException — an ElanRegistryException — which
     * is exactly what verify_car.php's optout branch catches to
     * rollback()/renderInvalidLink(500).
     *
     * A second, unrelated PDO connection is required for the lock: PHP's
     * built-in server (self::ensureServerRunning()) serves one request per
     * process with no persistent state between requests, so the DB
     * connection the POST below uses is necessarily a fresh one opened after
     * the SET GLOBAL below — it cannot be the same connection holding the
     * lock.
     */
    public function testOptoutMidTransactionFailureReturns500AndRollsBackAllCarsAndHistoryRows(): void
    {
        $code = $this->issueVericode();
        $secondCarId = $this->createTestCar($this->testUserId, [
            'chassis' => 'VF' . uniqid(),
            'email_suppressed' => 0,
        ]);

        $beforeCar1 = $this->carRow();
        $beforeCar2Suppressed = $this->emailSuppressed($secondCarId);
        $historyBeforeCar1 = $this->historyCount('EMAIL SUPPRESSED');
        $historyBeforeCar2 = $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED');

        $lockConn = new PDO(
            $this->buildDsn(),
            $_ENV['DB_USER'] ?? getenv('DB_USER'),
            $_ENV['DB_PASS'] ?? getenv('DB_PASS')
        );

        // Capture the real pre-test value rather than assuming MySQL's
        // documented 31536000s default — restoring whatever this environment
        // actually had keeps this test from permanently changing server
        // behavior if some other process had already customized it.
        $originalTimeout = (string) $this->db->query(
            "SHOW VARIABLES LIKE 'lock_wait_timeout'"
        )->first()->Value;

        // Affects only NEW connections' session default (LOCK TABLES obeys
        // this session variable, not the connection-agnostic
        // innodb_lock_wait_timeout) — restored in finally below regardless
        // of outcome, so no other test in this run is affected.
        $this->db->query('SET GLOBAL lock_wait_timeout = 1');

        try {
            $lockConn->exec('LOCK TABLES cars_hist WRITE');

            $result = $this->post('vericode=' . $code . '&action=optout', ['vericode' => $code]);

            $this->assertSame(
                500,
                $result['status'],
                'A blocked cars_hist write mid-transaction must surface as renderInvalidLink(500)'
            );
        } finally {
            // UNLOCK TABLES (and closing the connection) first: a stray
            // global left at 1s is merely annoying for the rest of the run,
            // but a lock left held on cars_hist would deterministically fail
            // every subsequent test in this file that mutates a car.
            $lockConn->exec('UNLOCK TABLES');
            $lockConn = null;
            $this->db->query('SET GLOBAL lock_wait_timeout = ' . (int) $originalTimeout);
        }

        $afterCar1 = $this->carRow();
        $this->assertSame(
            $beforeCar1['email_suppressed'],
            $afterCar1['email_suppressed'],
            'The vericode\'s own car must remain unsuppressed after a rolled-back transaction'
        );
        $this->assertSame(
            $beforeCar1['mtime'],
            $afterCar1['mtime'],
            'A rolled-back UPDATE must leave mtime untouched'
        );
        $this->assertSame(
            $beforeCar2Suppressed,
            $this->emailSuppressed($secondCarId),
            'The owner\'s other car must also remain unsuppressed — the whole fan-out rolls back, not just the first car'
        );

        $this->assertSame(
            $historyBeforeCar1,
            $this->historyCount('EMAIL SUPPRESSED'),
            'No EMAIL SUPPRESSED cars_hist row may survive for the first car'
        );
        $this->assertSame(
            $historyBeforeCar2,
            $this->historyCountForCar($secondCarId, 'EMAIL SUPPRESSED'),
            'No EMAIL SUPPRESSED cars_hist row may survive for the second car'
        );
    }

    /**
     * DSN for a direct PDO connection to the same test database
     * IntegrationTestCase's own $this->db uses, built from the same
     * DB_HOST/DB_PORT/DB_NAME env vars exposeTestDatabaseToEnvironment()
     * already validated are present. Needed only for the second, lock-holding
     * connection in testOptoutMidTransactionFailureReturns500AndRollsBackAllCarsAndHistoryRows()
     * — $this->db itself must stay free to run assertions against the
     * unlocked `cars`/`cars_hist` tables while the second connection holds
     * the lock.
     */
    private function buildDsn(): string
    {
        $host = (string) ($_ENV['DB_HOST'] ?? getenv('DB_HOST'));
        $port = $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: 3306;
        $name = (string) ($_ENV['DB_NAME'] ?? getenv('DB_NAME'));

        // DB_HOST may already carry ":port" (see .env.test.local) — DATABASE.md
        // and this file's own DB_ENV_VARS both treat DB_HOST as authoritative
        // in that case, so prefer it over the separate DB_PORT var.
        if (str_contains($host, ':')) {
            [$host, $port] = explode(':', $host, 2);
        }

        return "mysql:host={$host};port={$port};dbname={$name}";
    }
}
