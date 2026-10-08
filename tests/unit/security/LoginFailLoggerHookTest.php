<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2189: an unmatched username is untrusted free text (often a password
 * typed in the wrong box), so it is logged only when it matched an account.
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

    // Not require_once, so each test gets a fresh run.
    private function fireHook(): void
    {
        require self::HOOK;
    }

    /**
     * Checks both the raw value and the HTML-encoded form Input::get() produces.
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
     * DB drivers can return the id column as a numeric string.
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
