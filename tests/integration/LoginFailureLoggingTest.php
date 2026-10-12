<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\UserscPageClient;

/**
 * #2189: a password typed into the username box must not be stored in any
 * recoverable form (logs.lognote, us_rate_limits metadata, or an email-derived
 * identifier_key).
 *
 * Uses Tests\Support\UserscPageClient. Turnstile is off because
 * .env.test.local sets no Turnstile keys.
 *
 * @see usersc/login.php
 * @see usersc/plugins/hooker/hooks/login_fail_logger.php
 * @see https://github.com/elan-registry/registry/issues/2189
 */
#[Group('integration')]
final class LoginFailureLoggingTest extends IntegrationTestCase
{
    use UserscPageClient;

    public static function tearDownAfterClass(): void
    {
        self::stopPageServer();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // RateLimit::getRealIP() rejects loopback (FILTER_FLAG_NO_RES_RANGE), so
        // without a routable REMOTE_ADDR no 'ip' row is written and the
        // us_rate_limits assertions check nothing. 203.0.113.0/24 is TEST-NET-3.
        $this->startPageClient('login.php', '203.0.113.9');

        // Old 'login_attempt' rows for the fixed REMOTE_ADDR could trip the IP rate limit.
        $this->db->query("DELETE FROM us_rate_limits WHERE action = 'login_attempt'");
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query("DELETE FROM us_rate_limits WHERE action = 'login_attempt'");
        }

        $this->stopPageClient();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // HTTP helpers
    // ------------------------------------------------------------------

    /** Fresh session; returns the session-bound CSRF token from the page. */
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
     * raw value in metadata.username_attempted. Each row written now must carry
     * '(unrecognised)' and no part of the value.
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
     * An unmatched attempt still records the IP-scoped row, so an empty result
     * means the harness wrote nothing and the checks below proved nothing.
     *
     * @param list<object> $rows
     */
    private function assertNoRateLimitRowLeaks(array $rows, string $submitted): void
    {
        $this->assertNotEmpty($rows, 'An unmatched failed login must still record an IP-scoped us_rate_limits row');
        foreach ($rows as $row) {
            $this->assertSame('(unrecognised)', $this->usernameAttempted($row));
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
        // #2189 this request stored the key below.
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
     * Uses the email column: only an email-shaped value keeps the 'email'
     * identifier type, which this test needs. login.php accepts username or email.
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
