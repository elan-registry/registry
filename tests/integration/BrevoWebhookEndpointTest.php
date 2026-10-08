<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\BrevoOverrideStub;
use Tests\Support\PhpBuiltinServer;

/**
 * Behavioral tests for app/api/webhooks/brevo.php (#1887).
 *
 * Uses `php -S` and curl: under the CLI SAPI, php://input is empty, so a
 * `php -r` subprocess cannot send a request body or Authorization header.
 */
#[Group('integration')]
final class BrevoWebhookEndpointTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private const TEST_TOKEN = 'test-brevo-webhook-token-987654321';

    private static ?PhpBuiltinServer $server = null;
    private static string $projectRoot = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$projectRoot = dirname(__DIR__, 2);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->exposeTestDatabaseAndTokenToEnvironment();
        self::$server ??= PhpBuiltinServer::start(self::$projectRoot, self::routerBody());

        $this->setSwitchEnabled(true);
        $this->makeBrevoReady();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->cleanUpBrevoReadyFixture();
            $this->db->query('UPDATE er_verification_settings SET enabled = 0, unmatched_recipient_count = 0 WHERE id = 1');

            // Keep runs independent: clear both rate-limit keys separately.
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'brevo_webhook'");

            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'brevo_webhook_auth_failure'");
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

    private static function routerBody(): string
    {
        $projectRoot = self::$projectRoot;
        // TEST_TOKEN is a fixed safe literal, so the single-quote wrap is enough.
        $defaultToken = self::TEST_TOKEN;

        // Test-only header X-Test-Brevo-Webhook-Token: an env var cannot change per
        // request in the running `php -S` child. The endpoint does not know this
        // header. "__EMPTY__" stands for an empty value because an empty header
        // is dropped in transit.
        return <<<PHP
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
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
    }

    private function exposeTestDatabaseAndTokenToEnvironment(): void
    {
        // putenv() does not reach the `php -S` child, which reads $_ENV via phpdotenv.
        // The router script loads the token and DB config itself.
        foreach (self::DB_ENV_VARS as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
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
        $url = self::$server->url('/app/api/webhooks/brevo.php');

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

    /** Makes VerificationSettings::brevoReady() report true. */
    private function makeBrevoReady(): void
    {
        $overridePath = self::$projectRoot . '/usersc/plugins/sendinblue/override.php';
        $this->brevoOverrideCreatedByTest = !file_exists($overridePath);
        if ($this->brevoOverrideCreatedByTest) {
            file_put_contents($overridePath, BrevoOverrideStub::CONTENT);
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
        $row = $this->db->query('SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = 1')->first();
        return (int) $row->unmatched_recipient_count;
    }

    private function eventRowCount(int $carId, string $brevoMessageId, string $event): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND brevo_message_id = ? AND event = ?',
            [$carId, $brevoMessageId, $event]
        )->first();
        return (int) $row->cnt;
    }

    /**
     * @param array<string, mixed> $extraColumns Extra cars columns, merged over the email default
     */
    private function createFixtureCarWithEmail(string $email, array $extraColumns = []): int
    {
        $userId = $this->createTestUser();
        return $this->createTestCar($userId, array_merge(['email' => $email], $extraColumns));
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
    // Gate tests

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
        // An empty configured token must reject even a valid-looking token (fail closed).
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
        $this->assertSame($before + 1, $after, 'unmatched_recipient_count must increment by exactly 1');
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

        // One SELECT, so the two columns cannot be read in disagreement.
        $carRow = $this->db->query('SELECT email_bounced, email_bounced_address FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(1, (int) $carRow->email_bounced, 'cars.email_bounced must be set');
        $this->assertSame($email, $carRow->email_bounced_address, 'cars.email_bounced_address must equal the event email');
    }

    /**
     * Bounce journey: a verification-eligible car is excluded from
     * CarRepository::findVerificationEligible() once the real webhook endpoint
     * records a hard_bounce for its address. The car starts with a stale
     * owner_last_updated and last_verified NULL, so the inclusion assertion
     * proves eligibility before the bounce, not by default.
     */
    public function testHardBounceWebhookExcludesCarFromVerificationEligibleSet(): void
    {
        $email = 'bouncejourney-' . uniqid() . '@example.com';
        $staleDate = date('Y-m-d H:i:s', strtotime('-3 years'));
        $carId = $this->createFixtureCarWithEmail($email, [
            'owner_last_updated' => $staleDate,
            'last_verified' => null,
        ]);

        $this->assertContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            'Precondition: the stale, un-bounced fixture car must be verification-eligible before the bounce'
        );

        $result = $this->postAuthorized($this->taggedPayload([
            'email' => $email,
            'event' => 'hard_bounce',
            'reason' => 'Mailbox does not exist',
        ]));
        $this->assertGreaterThanOrEqual(200, $result['status']);
        $this->assertLessThan(300, $result['status'], 'Matched hard_bounce must respond 2xx');

        $carRow = $this->db->query('SELECT email_bounced FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame(1, (int) $carRow->email_bounced, 'cars.email_bounced must be set by the webhook');

        $this->assertNotContains(
            $carId,
            $this->allVerificationEligibleCarIds(),
            'A car flagged email_bounced = 1 must no longer be verification-eligible'
        );
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
     * Seeds us_rate_limits directly: ip_max is 500, too many real requests.
     *
     * RateLimit::getRealIP() rejects 127.0.0.1 (reserved range), so a loopback
     * request is never limited. The test trusts 127.0.0.1 as a reverse proxy
     * and sends a public IP in X-Forwarded-For. tearDown() restores both settings.
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

            // rate_limits_dev_override.php multiplies *_max by 100 in development, and
            // the server subprocess applies it, so seed the effective ip_max.
            $envUsEnvironment = $_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: 'production';
            if ($envUsEnvironment === 'development') {
                $ipMax = (int) min($ipMax * 100, PHP_INT_MAX);
            }

            // ip_max counts only success=0 rows.
            $now = date('Y-m-d H:i:s');
            $rows = [];
            for ($i = 0; $i < $ipMax; $i++) {
                $rows[] = [$identifierKey, 'brevo_webhook', 0, $now, '{}'];
            }

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

    /**
     * Proves the endpoint writes rate-limit rows. Without record() calls the
     * limit never trips, and the seeded-row test above cannot see that.
     */
    public function testEndpointRecordsRateLimitAttemptsForBothOutcomes(): void
    {
        // Loopback has no usable IP, so record() writes nothing without the
        // trusted-proxy fixture.
        $spoofedPublicIp = '203.0.113.77'; // TEST-NET-3 (RFC 5737)

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

        $identifierKey = hash('sha256', 'ip::' . $spoofedPublicIp);
        $countFor = fn (int $success): int => (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = 'brevo_webhook' AND success = ? AND identifier_key = ?",
            [$success, $identifierKey]
        )->first()->cnt;

        try {
            $successBefore = $countFor(1);
            $failureBefore = $countFor(0);

            $result = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer ' . self::TEST_TOKEN,
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);
            $this->assertSame(200, $result['status']);

            $successAfterAllowed = $countFor(1);
            $this->assertSame(
                $successBefore + 1,
                $successAfterAllowed,
                'A normal allowed request must write exactly one success=1 row via recordRateLimit(..., true) — '
                    . 'without this, checkRateLimit() would see zero attempts and the configured limit could never trip'
            );

            // The 'brevo_webhook' key records only after auth passes. Auth failures use
            // 'brevo_webhook_auth_failure' (#2087).
            $rejectedResult = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer wrong-token',
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);
            $this->assertSame(401, $rejectedResult['status']);
            $this->assertSame(
                $successAfterAllowed,
                $countFor(1),
                'An auth-rejected request must not record a rate-limit attempt — auth runs before rate limiting'
            );
            $this->assertSame($failureBefore, $countFor(0), 'An auth-rejected request must not record a rate-limit attempt');
        } finally {
            $this->db->query('UPDATE settings SET behind_reverse_proxy = 0 WHERE id = 1');
            $this->db->query('DELETE FROM us_rate_limit_proxy_settings WHERE id = ?', [$proxyFixtureId]);
        }
    }

    /** Over ip_max, the auth-failure branch suppresses only its log line; the 401 always fires. */
    public function testAuthFailureLoggingIsSuppressedAfterRateLimitExceeded(): void
    {
        $spoofedPublicIp = '203.0.113.88'; // TEST-NET-3 (RFC 5737)
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
            $ipMax = (int) $rateLimits['brevo_webhook_auth_failure']['ip_max'];

            // Effective (dev-multiplied) threshold; see testRateLimitExceededRespondsWithTooManyRequests().
            $envUsEnvironment = $_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: 'production';
            if ($envUsEnvironment === 'development') {
                $ipMax = (int) min($ipMax * 100, PHP_INT_MAX);
            }

            $now = date('Y-m-d H:i:s');
            $rows = [];
            for ($i = 0; $i < $ipMax; $i++) {
                $rows[] = [$identifierKey, 'brevo_webhook_auth_failure', 0, $now, '{}'];
            }

            $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
            $params = array_merge(...$rows);
            $this->db->query(
                "INSERT INTO us_rate_limits (identifier_key, action, success, attempt_time, metadata) VALUES {$placeholders}",
                $params
            );
            $this->assertFalse($this->db->error(), 'Failed to seed rate limit rows: ' . $this->db->errorString());

            $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');

            $result = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer wrong-token',
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);

            $this->assertSame(401, $result['status'], 'The 401 must fire unconditionally even once the auth-failure log rate limit is exceeded');

            $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');
            $this->assertSame($before, $after, 'No new LOG_CATEGORY_SECURITY row must be written once the auth-failure rate limit is exceeded');
        } finally {
            $this->db->query('UPDATE settings SET behind_reverse_proxy = 0 WHERE id = 1');
            $this->db->query('DELETE FROM us_rate_limit_proxy_settings WHERE id = ?', [$proxyFixtureId]);
        }
    }

    /** An under-limit auth failure must still be logged. */
    public function testAuthFailureLoggingOccursUnderRateLimit(): void
    {
        // Loopback has no usable IP, so without the trusted-proxy fixture the
        // limiter is not live and the test passes for the wrong reason.
        $spoofedPublicIp = '203.0.113.111'; // TEST-NET-3 (RFC 5737)

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
            $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');

            $result = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer wrong-token',
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);

            $this->assertSame(401, $result['status']);

            $after = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, '%bearer token%');
            $this->assertSame($before + 1, $after, 'A single under-the-limit auth failure must still be logged exactly once');
        } finally {
            $this->db->query('UPDATE settings SET behind_reverse_proxy = 0 WHERE id = 1');
            $this->db->query('DELETE FROM us_rate_limit_proxy_settings WHERE id = ?', [$proxyFixtureId]);
        }
    }

    /**
     * Auth failures are recorded even while logging is suppressed, so the
     * window count stays accurate. Seeds past ip_max so the request is
     * suppressed: a single fresh request did not catch a conditional record().
     */
    public function testAuthFailureRateLimitRecordsAttemptRegardless(): void
    {
        $spoofedPublicIp = '203.0.113.100'; // TEST-NET-3 (RFC 5737), distinct from sibling tests' IPs
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
            $ipMax = (int) $rateLimits['brevo_webhook_auth_failure']['ip_max'];

            // Effective (dev-multiplied) threshold; see testRateLimitExceededRespondsWithTooManyRequests().
            $envUsEnvironment = $_ENV['US_ENVIRONMENT'] ?? getenv('US_ENVIRONMENT') ?: 'production';
            if ($envUsEnvironment === 'development') {
                $ipMax = (int) min($ipMax * 100, PHP_INT_MAX);
            }

            $now = date('Y-m-d H:i:s');
            $rows = [];
            for ($i = 0; $i < $ipMax; $i++) {
                $rows[] = [$identifierKey, 'brevo_webhook_auth_failure', 0, $now, '{}'];
            }

            $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
            $params = array_merge(...$rows);
            $this->db->query(
                "INSERT INTO us_rate_limits (identifier_key, action, success, attempt_time, metadata) VALUES {$placeholders}",
                $params
            );
            $this->assertFalse($this->db->error(), 'Failed to seed rate limit rows: ' . $this->db->errorString());

            $countFailures = fn (): int => (int) $this->db->query(
                "SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = 'brevo_webhook_auth_failure' AND success = 0 AND identifier_key = ?",
                [$identifierKey]
            )->first()->cnt;

            $before = $countFailures();
            $this->assertSame($ipMax, $before, 'Test setup: expected exactly the seeded rows before the request under test');

            $result = $this->postToWebhook($this->taggedPayload(), [
                'Authorization' => 'Bearer wrong-token',
                'X-Forwarded-For' => $spoofedPublicIp,
            ]);
            $this->assertSame(401, $result['status'], 'The 401 must fire unconditionally even while suppressed');

            $after = $countFailures();
            $this->assertSame(
                $before + 1,
                $after,
                "recordRateLimit('brevo_webhook_auth_failure', false) must write exactly one NEW success=0 row for this "
                    . 'request even while logging is suppressed for having exceeded ip_max — proving recording is '
                    . 'unconditional, not gated on whether the log line itself fired'
            );
        } finally {
            $this->db->query('UPDATE settings SET behind_reverse_proxy = 0 WHERE id = 1');
            $this->db->query('DELETE FROM us_rate_limit_proxy_settings WHERE id = ?', [$proxyFixtureId]);
        }
    }

    // ------------------------------------------------------------------
    // Transient DB error (5xx)
    // ------------------------------------------------------------------

    /**
     * Forces a real DB error by renaming a column. Restored in finally so a
     * broken schema never leaks into later tests.
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
