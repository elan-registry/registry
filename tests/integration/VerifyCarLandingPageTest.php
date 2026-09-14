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

        $this->testUserId = $this->createTestUser();
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
}
