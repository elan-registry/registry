<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for usersc/plugins/hooker/hooks/login_fail_logger.php (issue
 * #2189).
 *
 * Pins the hook's one security rule: an unmatched login attempt's
 * `$username` is untrusted free text — a member who types their password
 * into the username box must not have that value written to `logs`. The
 * submitted `$username` is logged only when it matched a real account
 * (`$userId` resolves to a positive int); every unmatched case gets a fixed,
 * value-free message instead.
 *
 * @see usersc/plugins/hooker/hooks/login_fail_logger.php
 */
#[Group('fast')]
#[Group('unit')]
#[Group('security')]
final class LoginFailLoggerHookTest extends TestCase
{
    private const HOOK = __DIR__
        . '/../../../usersc/plugins/hooker/hooks/login_fail_logger.php';

    protected function setUp(): void
    {
        parent::setUp();
        global $mockLogEntries, $username, $userId;
        $mockLogEntries = [];
        $username = null;
        $userId = null;
    }

    /**
     * The hook is `require`d (not `require_once`) so each test gets a fresh
     * run with its own globals.
     */
    private function fireHook(): void
    {
        require self::HOOK;
    }

    /**
     * A password typed into the username field must never reach the log,
     * whether raw or in the HTML-encoded form Input::get() produces.
     */
    public function testUnmatchedUsernameIsNeverLoggedEvenWhenItLooksLikeAPassword(): void
    {
        global $mockLogEntries, $username, $userId;
        $username = 'Tr0ub4dor&amp;3x!';
        $userId = null;

        $this->fireHook();

        $this->assertCount(1, $mockLogEntries);
        $entry = $mockLogEntries[0];
        $this->assertSame('Failed login attempt for unrecognised username', $entry['message']);
        // The whole entry, not only the message: a later edit could leak the value
        // through logger()'s metadata argument, which lands in logs.metadata.
        $wholeEntry = (string) json_encode($entry, JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('Tr0ub4dor&3x!', $wholeEntry);
        $this->assertStringNotContainsString('Tr0ub4dor&amp;3x!', $wholeEntry);
        $this->assertStringNotContainsString('Tr0ub4dor', $wholeEntry);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function unmatchedUserIdProvider(): array
    {
        return [
            'null (no account found)' => [null],
            'zero' => [0],
            'negative' => [-5],
        ];
    }

    #[DataProvider('unmatchedUserIdProvider')]
    public function testUnmatchedUserIdLogsTheFixedMessage(?int $userIdValue): void
    {
        global $mockLogEntries, $username, $userId;
        $username = 'Tr0ub4dor&amp;3x!';
        $userId = $userIdValue;

        $this->fireHook();

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(
            'Failed login attempt for unrecognised username',
            $mockLogEntries[0]['message']
        );
        $this->assertSame(LogCategories::LOG_CATEGORY_SECURITY, $mockLogEntries[0]['category']);
        $this->assertSame((int) ($userIdValue ?? 0), $mockLogEntries[0]['user_id']);
    }

    public function testMatchedUserLogsTheUsername(): void
    {
        global $mockLogEntries, $username, $userId;
        $username = 'jdoe';
        $userId = 42;

        $this->fireHook();

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(
            'Failed login attempt for username: jdoe',
            $mockLogEntries[0]['message']
        );
        $this->assertSame(LogCategories::LOG_CATEGORY_SECURITY, $mockLogEntries[0]['category']);
        $this->assertSame(42, $mockLogEntries[0]['user_id']);
    }

    public function testNullUsernameAndNullUserIdLogsTheUnrecognisedMessageWithoutError(): void
    {
        global $mockLogEntries, $username, $userId;
        $username = null;
        $userId = null;

        $this->fireHook();

        $this->assertCount(1, $mockLogEntries);
        $this->assertSame(
            'Failed login attempt for unrecognised username',
            $mockLogEntries[0]['message']
        );
    }

    /**
     * DB drivers can return an id column as a numeric string rather than an
     * int; the hook's `(int)` cast must still treat it as a match.
     */
    public function testNumericStringUserIdIsTreatedAsMatched(): void
    {
        global $mockLogEntries, $username, $userId;
        $username = 'jdoe';
        $userId = '42';

        $this->fireHook();

        $this->assertCount(1, $mockLogEntries);
        $entry = $mockLogEntries[0];
        $this->assertSame('Failed login attempt for username: jdoe', $entry['message']);
        $this->assertSame(42, $entry['user_id']);
    }
}
