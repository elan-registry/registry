<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * Behavioral (real-process, real-DB) tests for app/api/webhooks/brevo.php —
 * the real Brevo webhook receiver built by #1887, replacing the gate-only
 * placeholder stub previously covered by BrevoWebhookStubTest.php (retired:
 * the stub it tested no longer exists, git history preserves it).
 *
 * Unlike the old stub (which never parsed a request body and was invoked via
 * a `php -r` subprocess with no real HTTP layer), the real endpoint requires
 * a genuine request body and a real Authorization header — `php://input` is
 * empty when piped via `php -r '...' <<< $body` (a CLI SAPI limitation,
 * confirmed empirically), so this harness instead starts PHP's built-in web
 * server (`php -S`) for the endpoint file and drives it with real HTTP
 * requests via curl. Verified empirically that the built-in server correctly
 * populates both `php://input` and `$_SERVER['HTTP_AUTHORIZATION']` from a
 * real POST.
 */
#[Group('integration')]
final class BrevoWebhookEndpointTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const TEST_TOKEN = 'test-brevo-webhook-token-987654321';

    /** @var resource|null */
    private static $serverProcess = null;
    private static int $serverPort = 0;
    private static string $projectRoot = '';
    private static string $routerScriptPath = '';

    /** @var array<int> Car ids created by this test's own raw inserts, cleaned up in tearDown() in addition to trackCarId()-tracked ones. */
    private array $extraCarIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 2);

        // Pick a pseudo-random port (seeded by PID) to avoid collisions across
        // parallel test runs — defensive even though phpunit-integration.xml
        // runs with processIsolation="false" (single process).
        self::$serverPort = 20000 + (getmypid() % 20000);

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

        $this->exposeTestDatabaseAndTokenToEnvironment();
        self::ensureServerRunning();

        $this->setSwitchEnabled(true);
        $this->makeBrevoReady();
    }

    protected function tearDown(): void
    {
        foreach ($this->extraCarIds as $carId) {
            $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$carId]);
            $this->deleteTestCar($carId);
        }
        $this->extraCarIds = [];

        if ($this->databaseConnected) {
            $this->cleanUpBrevoReadyFixture();
            $this->db->query('UPDATE er_verification_settings SET enabled = 0, unmatched_webhook_recipient_count = 0 WHERE id = 1');

            // Clear rate-limit rows this test may have seeded/generated for the
            // brevo_webhook action so runs stay independent.
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'brevo_webhook'");
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }
        putenv('BREVO_WEBHOOK_TOKEN');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Server lifecycle
    // ------------------------------------------------------------------

    private static function writeRouterScript(): string
    {
        $projectRoot = self::$projectRoot;
        // TEST_TOKEN is a fixed alphanumeric/dash/underscore literal (see the
        // class constant above) — no escaping beyond the single-quote wrap
        // is needed for safe embedding in the generated PHP source below.
        $defaultToken = self::TEST_TOKEN;

        // Reads a test-only override header (X-Test-Brevo-Webhook-Token) so
        // individual requests can simulate BREVO_WEBHOOK_TOKEN being empty —
        // a real env var, once set at server-process spawn time, cannot be
        // changed per-request from the PHPUnit process (a later putenv()/
        // $_ENV write in the test process never reaches the already-running
        // `php -S` child process). This header is a test harness construct
        // only; the real endpoint (app/api/webhooks/brevo.php) has no
        // knowledge of it — it still reads $_ENV['BREVO_WEBHOOK_TOKEN']
        // exactly as in production, this router script just overrides that
        // value before requiring it. The sentinel value "__EMPTY__" (rather
        // than a literal empty header value) works around curl/PHP's
        // built-in server silently omitting a header sent with an empty
        // value (confirmed empirically) — the router below maps that
        // sentinel back to an actually-empty value.
        $routerSource = <<<PHP
        <?php
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
        // Default configured token for every request; the per-request test
        // header (below) can override it to simulate a wrong/empty value.
        \$_ENV['BREVO_WEBHOOK_TOKEN'] = '{$defaultToken}';
        if (isset(\$_SERVER['HTTP_X_TEST_BREVO_WEBHOOK_TOKEN'])) {
            \$testToken = \$_SERVER['HTTP_X_TEST_BREVO_WEBHOOK_TOKEN'] === '__EMPTY__'
                ? ''
                : \$_SERVER['HTTP_X_TEST_BREVO_WEBHOOK_TOKEN'];
            \$_ENV['BREVO_WEBHOOK_TOKEN'] = \$testToken;
        }
        chdir('{$projectRoot}/app/api/webhooks');
        require '{$projectRoot}/app/api/webhooks/brevo.php';
        PHP;

        $path = tempnam(sys_get_temp_dir(), 'brevo_webhook_router_') . '.php';
        $written = file_put_contents($path, $routerSource);
        if ($written === false) {
            throw new RuntimeException('Could not write router script for brevo webhook test server');
        }

        return $path;
    }

    private function exposeTestDatabaseAndTokenToEnvironment(): void
    {
        // NOTE: putenv() here affects only this PHPUnit process's own real
        // process environment; it does NOT reach the already-running (or
        // not-yet-started) `php -S` child process's $_ENV, since this app's
        // .env loading reads $_ENV (populated by phpdotenv), not getenv() —
        // see writeRouterScript()'s BREVO_WEBHOOK_TOKEN default and header
        // override for how the subprocess actually gets its token/DB config
        // (via the router script's own Dotenv::createMutable() load).
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
            throw new RuntimeException('Could not start PHP built-in server for brevo webhook test');
        }

        self::$serverProcess = $process;

        // Give the server a moment to bind the port before the first request.
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
            // Give it a moment to exit cleanly before the process handle is closed.
            usleep(100_000);
        }
        proc_close(self::$serverProcess);
        self::$serverProcess = null;
    }

    // ------------------------------------------------------------------
    // HTTP helper
    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $headers Extra headers, e.g. ['Authorization' => 'Bearer x']
     * @return array{status: int, body: string}
     */
    private function postToWebhook(?string $body, array $headers = []): array
    {
        $url = sprintf('http://127.0.0.1:%d/app/api/webhooks/brevo.php', self::$serverPort);

        $ch = curl_init($url);
        $headerLines = ['Content-Type: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body ?? '',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("curl request to webhook endpoint failed: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }

    private function postAuthorized(?string $body): array
    {
        return $this->postToWebhook($body, ['Authorization' => 'Bearer ' . self::TEST_TOKEN]);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function setSwitchEnabled(bool $enabled): void
    {
        $this->db->query('UPDATE er_verification_settings SET enabled = ? WHERE id = 1', [$enabled ? 1 : 0]);
        $this->assertFalse($this->db->error(), 'Failed to set up er_verification_settings.enabled for test');
    }

    /** @var object|array<string, mixed>|null Snapshot of the existing plg_sendinblue row (if any), for restoration. */
    private object|array|null $existingBrevoRow = null;
    private bool $brevoRowInsertedByTest = false;
    private bool $brevoOverrideCreatedByTest = false;

    /**
     * Makes VerificationSettings::brevoReady() report true — mirrors
     * BrevoWebhookStubTest's makeBrevoReady() precedent.
     */
    private function makeBrevoReady(): void
    {
        $overridePath = self::$projectRoot . '/usersc/plugins/sendinblue/override.php';
        $this->brevoOverrideCreatedByTest = !file_exists($overridePath);
        if ($this->brevoOverrideCreatedByTest) {
            file_put_contents($overridePath, "<?php\n");
        }

        $this->existingBrevoRow = $this->db->query('SELECT id, `key` FROM plg_sendinblue LIMIT 1')->first();

        if (is_object($this->existingBrevoRow) && isset($this->existingBrevoRow->id)) {
            $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', ['sib-test-key', $this->existingBrevoRow->id]);
            $this->brevoRowInsertedByTest = false;
        } else {
            $this->db->insert('plg_sendinblue', ['key' => 'sib-test-key']);
            $this->brevoRowInsertedByTest = true;
        }
    }

    private function cleanUpBrevoReadyFixture(): void
    {
        $overridePath = self::$projectRoot . '/usersc/plugins/sendinblue/override.php';

        if ($this->brevoOverrideCreatedByTest && file_exists($overridePath)) {
            unlink($overridePath);
        }

        if ($this->brevoRowInsertedByTest) {
            $this->db->query('DELETE FROM plg_sendinblue WHERE `key` = ?', ['sib-test-key']);
        } elseif (is_object($this->existingBrevoRow) && isset($this->existingBrevoRow->id)) {
            $this->db->query('UPDATE plg_sendinblue SET `key` = ? WHERE id = ?', [$this->existingBrevoRow->key, $this->existingBrevoRow->id]);
        }
    }

    private function unmatchedCounter(): int
    {
        $row = $this->db->query('SELECT unmatched_webhook_recipient_count FROM er_verification_settings WHERE id = 1')->first();
        return (int) $row->unmatched_webhook_recipient_count;
    }

    private function eventRowCount(int $carId, string $brevoMessageId, string $event): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND brevo_message_id = ? AND event = ?',
            [$carId, $brevoMessageId, $event]
        )->first();
        return (int) $row->cnt;
    }

    private function createFixtureCarWithEmail(string $email): int
    {
        $userId = $this->createTestUser();
        $carId = $this->createTestCar($userId, ['email' => $email]);
        return $carId;
    }

    private function taggedPayload(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'email' => 'nomatch-' . uniqid() . '@example.com',
            'event' => 'delivered',
            'message-id' => 'msg-' . uniqid(),
            'tags' => ['car_verification'],
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Gate tests (preserved/adapted from the retired stub test)
    // ------------------------------------------------------------------

    public function testSwitchOffRespondsSuccessfullyWithNoWrites(): void
    {
        $this->setSwitchEnabled(false);

        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, '%');
        $result = $this->postAuthorized($this->taggedPayload());

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Switch-off must respond 2xx');

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, '%');
        $this->assertSame($before, $after, 'Switch-off must not write any EmailWebhook log row');
    }

    public function testBrevoNotReadyRespondsSuccessfullyWithOneLogLine(): void
    {
        $this->setSwitchEnabled(true);
        $this->cleanUpBrevoReadyFixture(); // undo setUp()'s makeBrevoReady() for this test only

        $before = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received but Brevo prerequisites are not met%'
        );

        $result = $this->postAuthorized($this->taggedPayload());

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Brevo-not-ready must still respond 2xx');

        $after = $this->countMatchingLogs(
            LogCategories::LOG_CATEGORY_VERIFICATION_CONFIG_WARNING,
            '%Webhook received but Brevo prerequisites are not met%'
        );
        $this->assertSame($before + 1, $after, 'Brevo-not-ready must log exactly one refusal');

        // Restore readiness for any subsequent assertions in this test run.
        $this->makeBrevoReady();
    }

    // ------------------------------------------------------------------
    // Auth tests
    // ------------------------------------------------------------------

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');

        $result = $this->postToWebhook($this->taggedPayload(), []);

        $this->assertGreaterThanOrEqual(400, $result['status']);
        $this->assertLessThan(500, $result['status'], 'Missing auth header must be rejected with 4xx');

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');
        $this->assertSame($before + 1, $after, 'Missing auth must be logged exactly once');

        $logRow = $this->db->query(
            "SELECT lognote FROM logs WHERE logtype = ? ORDER BY id DESC LIMIT 1",
            [LogCategories::LOG_CATEGORY_SECURITY]
        )->first();
        $this->assertStringContainsString('(empty)', (string) $logRow->lognote, 'No-token rejection must log "(empty)", never a hash of nothing meaningful');
        $this->assertStringNotContainsString(self::TEST_TOKEN, (string) $logRow->lognote, 'The log must never contain the raw token value');
    }

    public function testWrongBearerTokenIsRejected(): void
    {
        $wrongToken = 'this-is-definitely-the-wrong-token';

        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');

        $result = $this->postToWebhook($this->taggedPayload(), ['Authorization' => 'Bearer ' . $wrongToken]);

        $this->assertGreaterThanOrEqual(400, $result['status']);
        $this->assertLessThan(500, $result['status'], 'Wrong token must be rejected with 4xx');

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');
        $this->assertSame($before + 1, $after, 'Wrong-token rejection must be logged exactly once');

        $logRow = $this->db->query(
            "SELECT lognote FROM logs WHERE logtype = ? ORDER BY id DESC LIMIT 1",
            [LogCategories::LOG_CATEGORY_SECURITY]
        )->first();
        $this->assertStringNotContainsString($wrongToken, (string) $logRow->lognote, 'The log must never contain the wrong token value');
        $this->assertStringNotContainsString(self::TEST_TOKEN, (string) $logRow->lognote, 'The log must never contain the correct token value either');
    }

    public function testEmptyConfiguredTokenRejectsEverythingFailClosed(): void
    {
        // Simulate BREVO_WEBHOOK_TOKEN being empty in the environment via the
        // router script's test-only override header (see writeRouterScript()'s
        // docblock for why a real env var can't be changed per-request against
        // an already-running php -S child process) — even a "valid-looking"
        // provided token must be rejected (fail closed, not fail open).
        $result = $this->postToWebhook($this->taggedPayload(), [
            'Authorization' => 'Bearer ' . self::TEST_TOKEN,
            'X-Test-Brevo-Webhook-Token' => '__EMPTY__',
        ]);

        $this->assertGreaterThanOrEqual(400, $result['status']);
        $this->assertLessThan(500, $result['status'], 'An empty configured token must reject every request, including a "valid-looking" one');
    }

    // ------------------------------------------------------------------
    // Payload validation
    // ------------------------------------------------------------------

    public function testCorrectTokenWithListBodyIsMalformed(): void
    {
        $result = $this->postAuthorized(json_encode([1, 2, 3]));

        $this->assertGreaterThanOrEqual(400, $result['status']);
        $this->assertLessThan(500, $result['status'], 'A top-level JSON list body must be 4xx malformed');
    }

    public function testCorrectTokenWithUntaggedPayloadIsAcceptedButNotRecorded(): void
    {
        $email = 'untagged-' . uniqid() . '@example.com';
        $carId = $this->createFixtureCarWithEmail($email);

        $messageId = 'msg-' . uniqid();
        $payload = json_encode([
            'email' => $email,
            'event' => 'delivered',
            'message-id' => $messageId,
            'tags' => ['unrelated_tag'],
        ]);

        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, '%no recognized verification-system tag%');

        $result = $this->postAuthorized($payload);

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Untagged payload must respond 2xx');

        $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_WEBHOOK, '%no recognized verification-system tag%');
        $this->assertSame($before + 1, $after, 'Untagged payload must be logged');

        $this->assertSame(0, $this->eventRowCount($carId, $messageId, 'delivered'), 'Untagged payload must not write an er_email_events row');
    }

    public function testCorrectTokenWithTaggedPayloadMatchingNoCarIncrementsUnmatchedCounter(): void
    {
        $before = $this->unmatchedCounter();

        $result = $this->postAuthorized($this->taggedPayload());

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'No-car-match must respond 2xx');

        $after = $this->unmatchedCounter();
        $this->assertSame($before + 1, $after, 'unmatched_webhook_recipient_count must increment by exactly 1');
    }

    // ------------------------------------------------------------------
    // Matched-car scenarios
    // ------------------------------------------------------------------

    public function testCorrectTokenWithHardBounceMatchingCarSetsEmailBouncedAndAddressTogether(): void
    {
        $email = 'hardbounce-' . uniqid() . '@example.com';
        $carId = $this->createFixtureCarWithEmail($email);
        $messageId = 'msg-' . uniqid();

        $payload = json_encode([
            'email' => $email,
            'event' => 'hard_bounce',
            'message-id' => $messageId,
            'tags' => ['car_verification'],
            'reason' => 'Mailbox does not exist',
        ]);

        $result = $this->postAuthorized($payload);

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Matched hard_bounce must respond 2xx');

        $eventRow = $this->db->query(
            'SELECT car_id, email, event, reason, brevo_message_id FROM er_email_events WHERE car_id = ? AND brevo_message_id = ?',
            [$carId, $messageId]
        )->first();

        $this->assertIsObject($eventRow, 'er_email_events row must exist for the matched car/message');
        $this->assertSame($email, $eventRow->email);
        $this->assertSame('hard_bounce', $eventRow->event);
        $this->assertSame('Mailbox does not exist', $eventRow->reason);

        // Single SELECT so email_bounced and email_bounced_address can never be
        // observed in disagreement.
        $carRow = $this->db->query('SELECT email_bounced, email_bounced_address FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(1, (int) $carRow->email_bounced, 'cars.email_bounced must be set');
        $this->assertSame($email, $carRow->email_bounced_address, 'cars.email_bounced_address must equal the event email');
    }

    public function testCorrectTokenWithSpamMatchingCarSetsEmailSuppressed(): void
    {
        $email = 'spam-' . uniqid() . '@example.com';
        $carId = $this->createFixtureCarWithEmail($email);
        $messageId = 'msg-' . uniqid();

        $payload = json_encode([
            'email' => $email,
            'event' => 'spam',
            'message-id' => $messageId,
            'tags' => ['car_verification'],
        ]);

        $result = $this->postAuthorized($payload);

        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Matched spam must respond 2xx');

        $this->assertSame(1, $this->eventRowCount($carId, $messageId, 'spam'));

        $carRow = $this->db->query('SELECT email_suppressed FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(1, (int) $carRow->email_suppressed, 'cars.email_suppressed must be set');
    }

    public function testDuplicateDeliveryProducesExactlyOneEventRow(): void
    {
        $email = 'dup-' . uniqid() . '@example.com';
        $carId = $this->createFixtureCarWithEmail($email);
        $messageId = 'msg-' . uniqid();

        $payload = json_encode([
            'email' => $email,
            'event' => 'delivered',
            'message-id' => $messageId,
            'tags' => ['car_verification'],
        ]);

        $first = $this->postAuthorized($payload);
        $this->assertGreaterThanOrEqual(200, $first['status']);
        $this->assertLessThan(300, $first['status'], 'First delivery must respond 2xx');

        $second = $this->postAuthorized($payload);
        $this->assertGreaterThanOrEqual(200, $second['status']);
        $this->assertLessThan(300, $second['status'], 'Duplicate delivery must also respond 2xx');

        $this->assertSame(
            1,
            $this->eventRowCount($carId, $messageId, 'delivered'),
            'Exactly one er_email_events row must exist after two identical deliveries — real MySQL unique-index/ON DUPLICATE KEY behavior'
        );
    }

    // ------------------------------------------------------------------
    // Rate limiting
    // ------------------------------------------------------------------

    /**
     * Drives the configured ip_max for 'brevo_webhook' past its limit by
     * seeding us_rate_limits directly (matching RateLimit's own
     * identifier_key = sha256("ip::<ip>") scheme and its window/count
     * semantics), rather than firing hundreds of real HTTP requests — the
     * configured ip_max is 500 (see RateLimitConfigTest), which would make a
     * real-request approach slow and not meaningfully more faithful, since
     * this test's real target is the endpoint's *handling* of a rate-limited
     * response, not RateLimit's own counting logic (already covered
     * elsewhere).
     *
     * RateLimit::getRealIP() reports `false` (no usable IP) for a request
     * whose REMOTE_ADDR is 127.0.0.1 — PHP's FILTER_FLAG_NO_RES_RANGE treats
     * loopback as a "reserved" range — which every request from this test
     * harness necessarily has. With no `ip` identifier, RateLimit::check()
     * has nothing to check and trivially allows every request, so testing
     * this from a bare loopback request can never observe a 429 (confirmed
     * empirically). To exercise the real code path, this test turns on
     * `settings.behind_reverse_proxy`, registers 127.0.0.1 (this harness's
     * real REMOTE_ADDR) as a trusted proxy reading X-Forwarded-For, and sends
     * a spoofed public IP in that header — exactly the configuration a real
     * deployment behind a reverse proxy would use. Both settings are
     * restored in tearDown().
     */
    public function testRateLimitExceededRespondsWithTooManyRequests(): void
    {
        $spoofedPublicIp = '203.0.113.55'; // TEST-NET-3 (RFC 5737), safe to use as a fixture value
        $identifierKey = hash('sha256', 'ip::' . $spoofedPublicIp);

        $this->db->query('UPDATE settings SET behind_reverse_proxy = 1 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: failed to enable behind_reverse_proxy');

        $this->db->insert('us_rate_limit_proxy_settings', [
            'proxy_ip' => '127.0.0.1',
            'header_name' => 'X-Forwarded-For',
            'priority' => 1,
            'enabled' => 1,
        ]);
        $this->assertFalse($this->db->error(), 'Test setup: failed to register trusted proxy fixture');
        $proxyFixtureId = (int) $this->db->lastId();

        try {
            /** @var array<string, array<string, int>> $rateLimits */
            $rateLimits = [];
            require self::$projectRoot . '/usersc/includes/rate_limits.php';
            $ipMax = (int) $rateLimits['brevo_webhook']['ip_max'];

            // usersc/includes/rate_limits_dev_override.php multiplies every
            // *_max threshold by 100x when US_ENVIRONMENT=development (this
            // repo's default local .env setting) — the webhook server
            // subprocess applies that same override, so the seeded row count
            // must match its EFFECTIVE ip_max, not the raw configured value,
            // or this test would seed 500 rows against an actual 50,000
            // threshold and never trip it.
            $envUsEnvironment = $_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: 'production';
            if ($envUsEnvironment === 'development') {
                $ipMax = (int) min($ipMax * 100, PHP_INT_MAX);
            }

            // RateLimit::check()'s ip_max/ip_window branch counts only
            // success=0 (failed) rows (see RateLimit::getAttemptCount()'s
            // $success=false argument) — success=1 rows would only count
            // toward total_max/total_window instead, never tripping ip_max.
            $now = date('Y-m-d H:i:s');
            $rows = [];
            for ($i = 0; $i < $ipMax; $i++) {
                $rows[] = [$identifierKey, 'brevo_webhook', 0, $now, '{}'];
            }

            // Bulk-insert directly for speed rather than $ipMax separate ->insert() calls.
            $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
            $params = array_merge(...$rows);
            $this->db->query(
                "INSERT INTO us_rate_limits (identifier_key, action, success, attempt_time, metadata) VALUES {$placeholders}",
                $params
            );
            $this->assertFalse($this->db->error(), 'Failed to seed rate limit rows: ' . $this->db->errorString());

            $result = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer ' . self::TEST_TOKEN,
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);

            $this->assertSame(429, $result['status'], 'Exceeding ip_max within ip_window must respond 429');
        } finally {
            $this->db->query('UPDATE settings SET behind_reverse_proxy = 0 WHERE id = 1');
            $this->db->query('DELETE FROM us_rate_limit_proxy_settings WHERE id = ?', [$proxyFixtureId]);
        }
    }

    // ------------------------------------------------------------------
    // Transient DB error (5xx)
    // ------------------------------------------------------------------

    /**
     * A genuine transient DB error surfacing as 5xx. Forces this by
     * temporarily dropping the unique index that insertEmailEvent()'s
     * ON DUPLICATE KEY UPDATE clause depends on — without it, the same
     * INSERT still succeeds (there's no longer a conflict to resolve), so
     * instead this test forces the failure via a column rename, matching the
     * plan's "if you can force a genuine transient DB error cleanly" allowance.
     * Restored in a finally block so this never leaks a broken schema into
     * subsequent tests.
     */
    public function testGenuineDbWriteFailureRespondsWithServerError(): void
    {
        $email = 'dberror-' . uniqid() . '@example.com';
        $carId = $this->createFixtureCarWithEmail($email);

        $this->db->query('ALTER TABLE er_email_events RENAME COLUMN reason TO reason_renamed_for_test');
        $this->assertFalse($this->db->error(), 'Test setup: failed to rename er_email_events.reason: ' . $this->db->errorString());

        try {
            $payload = json_encode([
                'email' => $email,
                'event' => 'delivered',
                'message-id' => 'msg-' . uniqid(),
                'tags' => ['car_verification'],
            ]);

            $result = $this->postAuthorized($payload);

            $this->assertGreaterThanOrEqual(500, $result['status'], 'A genuine DB write failure must respond 5xx');
            $this->assertLessThan(600, $result['status']);
        } finally {
            $this->db->query('ALTER TABLE er_email_events RENAME COLUMN reason_renamed_for_test TO reason');
            $this->assertFalse($this->db->error(), 'Test cleanup: failed to restore er_email_events.reason column');
        }
    }
}
