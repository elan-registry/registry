<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PhpBuiltinServer;

/**
 * Behavioral (real-process, real-DB) tests for usersc/login.php's
 * failed-login branch (issue #2189).
 *
 * A member who types their password into the username box must not have
 * that value written to storage in any recoverable form. Before the fix, an
 * unmatched login attempt wrote the raw submitted value to both
 * `logs.lognote` and `us_rate_limits.metadata.username_attempted`, and
 * `RateLimit::record()` also stored an `identifier_key` derived from it
 * (`hash('sha256', 'email::' . strtolower($value))`) whenever the value
 * happened to look like an email address — see
 * users/classes/RateLimit.php's sanitizeIdentifiers()/buildIdentifierKey().
 *
 * Drives real HTTP requests (via curl, with a real cookie jar so the
 * session-bound CSRF token round-trips) against usersc/login.php under PHP's
 * built-in web server, following VerifyCarLandingPageTest.php's and
 * BrevoWebhookEndpointTest.php's harness pattern. A plain `include` of
 * login.php cannot exercise this: the page starts a real session, requires a
 * session-bound CSRF token (Token::check()), and reads real superglobals for
 * username/password.
 *
 * No TURNSTILE_SITE_KEY/TURNSTILE_SECRET_KEY are set in .env.test.local, so
 * usersc/includes/turnstile.php's isTurnstileEnabled() gate is off in this
 * environment and the POST is never blocked by a missing Turnstile token.
 *
 * @see usersc/login.php
 * @see usersc/plugins/hooker/hooks/login_fail_logger.php
 * @see https://github.com/elan-registry/registry/issues/2189
 */
#[Group('integration')]
final class LoginFailureLoggingTest extends IntegrationTestCase
{
    /** @var list<string> */
    private const DB_ENV_VARS = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'];

    private static ?PhpBuiltinServer $server = null;
    private static string $projectRoot = '';

    private string $cookieJar = '';

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

        $this->exposeTestDatabaseToEnvironment();
        self::$server ??= PhpBuiltinServer::start(self::$projectRoot, self::routerBody());

        $jar = tempnam(sys_get_temp_dir(), 'login2189_cookies_');
        if ($jar === false) {
            throw new RuntimeException('Could not create a temp file for the curl cookie jar');
        }
        $this->cookieJar = $jar;

        // Earlier tests (or earlier runs of this file) may have left
        // 'login_attempt' rows for 127.0.0.1 — the built-in server's own
        // loopback address — that would otherwise trip the IP-scoped rate
        // limit before a test's own POST runs.
        $this->db->query("DELETE FROM us_rate_limits WHERE action = 'login_attempt'");
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'login_attempt'");
        }

        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }

        foreach (self::DB_ENV_VARS as $var) {
            putenv($var);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Server lifecycle (Tests\Support\PhpBuiltinServer owns the process)
    // ------------------------------------------------------------------

    private static function routerBody(): string
    {
        $projectRoot = self::$projectRoot;

        return <<<PHP
        require '{$projectRoot}/vendor/autoload.php';
        \\Dotenv\\Dotenv::createMutable('{$projectRoot}', '.env.test.local')->load();
        chdir('{$projectRoot}/usersc');
        require '{$projectRoot}/usersc/login.php';
        PHP;
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

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /**
     * GET the login page with a fresh session (cookie jar written), and pull
     * the session-bound CSRF token out of the rendered hidden field
     * (`<input type="hidden" name="csrf" value="...">`, from tokenHere()).
     */
    private function fetchCsrfToken(): string
    {
        $result = $this->request('GET', null);
        $this->assertSame(200, $result['status'], 'GET login.php must succeed to obtain a CSRF token');

        $matched = preg_match(
            '/name="csrf"\s+value="([0-9a-f]{64})"/',
            $result['body'],
            $matches
        );
        $this->assertSame(1, $matched, 'Could not find a 64-char hex csrf hidden field on the rendered login page');

        return $matches[1];
    }

    /**
     * @return array{status: int, body: string}
     */
    private function postLogin(string $username, string $password): array
    {
        $csrf = $this->fetchCsrfToken();

        return $this->request('POST', http_build_query([
            'csrf' => $csrf,
            'username' => $username,
            'password' => $password,
        ]));
    }

    /**
     * @return array{status: int, body: string}
     */
    private function request(string $method, ?string $body): array
    {
        $url = self::$server->url('/login.php');

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
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
            throw new RuntimeException("curl request to login.php failed: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $response];
    }

    // ------------------------------------------------------------------
    // DB helpers
    // ------------------------------------------------------------------

    private function latestLogRow(): ?object
    {
        $row = $this->db->query(
            "SELECT * FROM logs WHERE logtype = 'Security' ORDER BY id DESC LIMIT 1"
        )->first();

        return $row ?: null;
    }

    /**
     * @return list<object>
     */
    private function newRateLimitRowsSince(int $sinceId): array
    {
        $rows = $this->db->query(
            "SELECT * FROM us_rate_limits WHERE action = 'login_attempt' AND id > ? ORDER BY id ASC",
            [$sinceId]
        )->results();

        return $rows ?: [];
    }

    private function maxSecurityLogId(): int
    {
        return (int) $this->db->query(
            "SELECT COALESCE(MAX(id), 0) AS max_id FROM logs WHERE logtype = 'Security'"
        )->first()->max_id;
    }

    private function maxRateLimitId(): int
    {
        $row = $this->db->query(
            "SELECT COALESCE(MAX(id), 0) AS max_id FROM us_rate_limits WHERE action = 'login_attempt'"
        )->first();

        return (int) $row->max_id;
    }

    private function usernameAttempted(object $rateLimitRow): ?string
    {
        $metadata = json_decode((string) $rateLimitRow->metadata, true);
        if (!is_array($metadata) || !array_key_exists('username_attempted', $metadata)) {
            return null;
        }

        return $metadata['username_attempted'];
    }

    // ------------------------------------------------------------------
    // Unmatched identifier: password-shaped value
    // ------------------------------------------------------------------

    public function testUnmatchedPasswordShapedUsernameProducesFixedLogMessageWithNoRawValue(): void
    {
        $submitted = 'Tr0ub4dor&3x!';
        $lastLogIdBefore = $this->maxSecurityLogId();

        $result = $this->postLogin($submitted, 'irrelevant-password');
        $this->assertSame(200, $result['status']);

        $logRow = $this->latestLogRow();
        $this->assertNotNull($logRow, 'The failed login must write a Security log row');
        $this->assertGreaterThan($lastLogIdBefore, (int) $logRow->id, 'A new Security log row must have been inserted');

        $this->assertSame(
            'Failed login attempt for unrecognised username',
            $logRow->lognote,
            'An unmatched identifier must log the fixed, value-free message'
        );
        $this->assertStringNotContainsString($submitted, (string) $logRow->lognote);
        $this->assertStringNotContainsString(htmlspecialchars($submitted, ENT_QUOTES, 'UTF-8'), (string) $logRow->lognote);
        $wholeLogRow = (string) json_encode($logRow, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('Tr0ub4dor', $wholeLogRow, 'No part of the submitted value may appear anywhere in the logs row');
        $this->assertStringNotContainsString(htmlspecialchars('Tr0ub4dor&3x!', ENT_QUOTES, 'UTF-8'), $wholeLogRow);
    }

    /**
     * Before #2189, every row RateLimit::record() wrote for this request held the
     * raw value in metadata.username_attempted. Any row written now must carry
     * '(unrecognised)' and no part of the value. See assertNoRateLimitRowLeaks()
     * for why this harness usually writes no row at all.
     */
    public function testUnmatchedPasswordShapedUsernameProducesRateLimitRowsWithFixedMetadataAndNoRawValue(): void
    {
        $submitted = 'Tr0ub4dor&3x!';
        $sinceId = $this->maxRateLimitId();

        $result = $this->postLogin($submitted, 'irrelevant-password');
        $this->assertSame(200, $result['status']);

        $this->assertNoRateLimitRowLeaks($this->newRateLimitRowsSince($sinceId), $submitted);
    }

    /**
     * In production an 'ip' row is written for an unmatched attempt; this harness's
     * loopback address fails getRealIP(), so usually no row exists here. Check any
     * row that does exist rather than asserting the count, so the contract holds
     * in either environment.
     *
     * @param list<object> $rows
     */
    private function assertNoRateLimitRowLeaks(array $rows, string $submitted): void
    {
        foreach ($rows as $row) {
            $this->assertContains($this->usernameAttempted($row), ['(unrecognised)', null]);
            $this->assertStringNotContainsString($submitted, (string) $row->metadata);
            $this->assertStringNotContainsString(htmlspecialchars($submitted, ENT_QUOTES, 'UTF-8'), (string) $row->metadata);
        }
    }

    // ------------------------------------------------------------------
    // Unmatched identifier: email-shaped value (proves the identifier_key rule)
    // ------------------------------------------------------------------

    public function testUnmatchedEmailShapedUsernameProducesFixedLogMessageAndNoIdentifierKeyForIt(): void
    {
        $submitted = 'nobody-2189@example.invalid';
        $lastLogIdBefore = $this->maxSecurityLogId();
        $sinceId = $this->maxRateLimitId();

        $result = $this->postLogin($submitted, 'irrelevant-password');
        $this->assertSame(200, $result['status']);

        $logRow = $this->latestLogRow();
        $this->assertNotNull($logRow);
        $this->assertGreaterThan($lastLogIdBefore, (int) $logRow->id);
        $this->assertSame('Failed login attempt for unrecognised username', $logRow->lognote);
        $this->assertStringNotContainsString($submitted, (string) $logRow->lognote);

        $newRows = $this->newRateLimitRowsSince($sinceId);

        // An email-shaped value survives RateLimit::sanitizeIdentifiers(), so before
        // #2189 this request stored the key below. The query is not scoped to this
        // request, so it holds even when the harness writes no row.
        $forbiddenKey = hash('sha256', 'email::' . strtolower($submitted));
        $leakedRow = $this->db->query(
            "SELECT id FROM us_rate_limits WHERE action = 'login_attempt' AND identifier_key = ?",
            [$forbiddenKey]
        )->first();
        // DbAdapter::first() returns an empty array (never null) when no row matches.
        $this->assertEmpty(
            $leakedRow,
            'No us_rate_limits row for any test run may carry the identifier_key RateLimit would build from the submitted value'
        );

        $this->assertNoRateLimitRowLeaks($newRows, $submitted);
    }

    // ------------------------------------------------------------------
    // Matched identifier, wrong password
    // ------------------------------------------------------------------

    /**
     * Logs in with the user's `email` column value rather than `username`:
     * usersc/login.php accepts either (its lookup query is
     * `WHERE username = ? OR email = ?`), and RateLimit::sanitizeIdentifiers()
     * only keeps the 'email' identifier type for a value that passes
     * FILTER_VALIDATE_EMAIL — createTestUser()'s default username
     * ("testuser_<uniqid>") does not, so it would produce only a 'user'-type
     * row and never exercise the identifier_key this test pins. Using the
     * real email column guarantees the submitted value is email-shaped,
     * giving a direct proof that a MATCHED identifier is passed through to
     * RateLimit (unlike the unmatched case, which must never produce this
     * row at all — see the email-shaped unmatched test above).
     */
    public function testMatchedUsernameWithWrongPasswordLogsTheUsernameAndRecordsItInRateLimitMetadata(): void
    {
        $userId = $this->createTestUser();
        $userRow = $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first();
        $email = (string) $userRow->email;

        $lastLogIdBefore = $this->maxSecurityLogId();
        $sinceId = $this->maxRateLimitId();

        $result = $this->postLogin($email, 'definitely-the-wrong-password');
        $this->assertSame(200, $result['status']);

        $logRow = $this->latestLogRow();
        $this->assertNotNull($logRow);
        $this->assertGreaterThan($lastLogIdBefore, (int) $logRow->id);
        $this->assertSame(
            'Failed login attempt for username: ' . $email,
            $logRow->lognote,
            'A matched identifier must log the real submitted value (here, the email used to log in)'
        );
        $this->assertSame((string) $userId, (string) $logRow->user_id);

        $newRows = $this->newRateLimitRowsSince($sinceId);
        $this->assertNotEmpty($newRows, 'A failed login for a matched user must record at least one us_rate_limits row');

        $sawEmailIdentifierRow = false;
        $expectedIdentifierKey = hash('sha256', 'email::' . strtolower($email));
        foreach ($newRows as $row) {
            $this->assertSame($email, $this->usernameAttempted($row), 'metadata.username_attempted must equal the real submitted value');
            if ($row->identifier_key === $expectedIdentifierKey) {
                $sawEmailIdentifierRow = true;
            }
        }
        $this->assertTrue(
            $sawEmailIdentifierRow,
            'A matched identifier must still be passed through to RateLimit, producing the identifier_key RateLimit::buildIdentifierKey() derives from it'
        );
    }
}
