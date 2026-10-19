<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source test for the order of steps in usersc/scripts/after_user_deletion.php (#2358).
 *
 * The privacy step must run first, in its own transaction. Each early return
 * of the hook (findByOwner failure, noowner lookup failure, no admin session)
 * then keeps no personal data and leaves no transaction open. A runtime test
 * cannot see a later reorder of those lines, so this reads the code text with
 * comments removed. AfterUserDeletionPrivacyStepTest covers the behavior.
 */
#[Group('fast')]
final class AfterUserDeletionWiringTest extends TestCase
{
    private const HOOK = 'usersc/scripts/after_user_deletion.php';

    private string $code;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . self::HOOK);
        $this->assertIsString($source);

        // Comments are removed so that a word in a comment cannot satisfy or break an order check.
        $this->code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $this->code .= $token[1];
            } else {
                $this->code .= $token;
            }
        }
    }

    /**
     * Position of the first match of $needle, or a failed assertion.
     */
    private function posOf(string $code, string $needle): int
    {
        $pos = strpos($code, $needle);
        $this->assertNotFalse($pos, "The hook must contain: {$needle}");

        return $pos;
    }

    /**
     * The privacy step opens its transaction inside the try, then takes the
     * profile lock as its first statement. #2359 will make the event step
     * take the same lock. Today an event write takes no profile lock.
     */
    private function assertPrivacyStepStartsWithTransactionAndLock(string $code): void
    {
        $this->assertMatchesRegularExpression(
            '/try\s*\{\s*\$repo->beginTransaction\(\);\s*\$privacyStage\s*=\s*\'[^\']+\';\s*'
                . '\$addresses\s*=\s*\$repo->lockProfileAndFindAddresses\(/',
            $code,
            'The privacy step must begin a transaction inside the try and then lock the profile row, with only the stage name between.'
        );
    }

    public function testPrivacyStepStartsWithTransactionAndProfileLock(): void
    {
        $this->assertPrivacyStepStartsWithTransactionAndLock($this->code);
    }

    public function testNegativeControlOrderMatcherRejectsALockBeforeTheTransaction(): void
    {
        $bad = "try { \$privacyStage = 'lock'; \$addresses = \$repo->lockProfileAndFindAddresses(\$id); \$repo->beginTransaction(); }";

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertPrivacyStepStartsWithTransactionAndLock($bad);
    }

    /** The summary log line must come before the first early return, or that return loses it. */
    public function testPrivacySummaryIsLoggedBeforeTheFirstEarlyReturn(): void
    {
        $this->assertLessThan(
            $this->posOf($this->code, '$repo->findByOwner('),
            $this->posOf($this->code, "'Privacy step for user %d"),
        );
    }

    public function testPrivacyStepRunsBeforeEveryEarlyReturnOfTheHook(): void
    {
        $lock = $this->posOf($this->code, 'lockProfileAndFindAddresses(');
        // The commit call itself, directly before the flag that records it.
        $this->assertSame(
            1,
            preg_match('/\$repo->commit\(\);\s*\$privacyCommitted = true;/', $this->code, $match, PREG_OFFSET_CAPTURE),
            'The privacy step must commit, then record that it committed.'
        );
        $committed = $match[0][1];

        $this->assertLessThan(
            $this->posOf($this->code, '$repo->findByOwner('),
            $committed,
            'The privacy commit must come before the findByOwner() early return.'
        );
        $this->assertLessThan(
            $this->posOf($this->code, "'SELECT id FROM users WHERE username = ?'"),
            $committed,
            'The privacy commit must come before the noowner lookup early return.'
        );
        $this->assertLessThan(
            $this->posOf($this->code, 'currentUserId()'),
            $committed,
            'The privacy commit must come before the admin-session early return.'
        );
        $this->assertLessThan($committed, $lock);
    }

    public function testPrivacyStepRollsBackBeforeTheCarCleanupStarts(): void
    {
        $this->assertSame(
            1,
            preg_match('/\$repo->rollback\(\);\s*logger\(\$id, LogCategories::LOG_CATEGORY_USER_DELETION, sprintf\(\s*\'CRITICAL: privacy step failed/', $this->code, $match, PREG_OFFSET_CAPTURE),
            'The privacy step must roll back, then log the failure.'
        );

        $this->assertLessThan($this->posOf($this->code, '$repo->findByOwner('), $match[0][1]);
    }

    /** The plugin delete reads the user's event message IDs, so it must run before the event delete. */
    public function testPluginRowsAreDeletedBeforeEventsAndTheProfileLast(): void
    {
        $plugin = $this->posOf($this->code, '$repo->deleteSendinblueMessagesForUser(');
        $events = $this->posOf($this->code, '$repo->deleteEmailEventsForUser(');
        $profile = $this->posOf($this->code, 'DELETE FROM profiles WHERE user_id = ?');
        $commit = $this->posOf($this->code, '$privacyCommitted = true');

        $this->assertLessThan($events, $plugin);
        $this->assertLessThan($profile, $events);
        $this->assertLessThan($commit, $profile);
    }

    /**
     * After the privacy step, the car cleanup must not delete the profile or
     * the event rows. The closure helper and both work callbacks are in this part.
     */
    public function testCarCleanupAndTheTransactionClosureDeleteNoProfileOrEventRows(): void
    {
        $closureStart = $this->posOf($this->code, '$inTransaction = function');
        $privacyStart = $this->posOf($this->code, '$privacyCommitted = false');
        $closure = substr($this->code, $closureStart, $privacyStart - $closureStart);
        $carCleanup = substr($this->code, $this->posOf($this->code, '$repo->findByOwner('));

        $this->assertNotSame('', $closure);
        foreach ([$closure, $carCleanup] as $part) {
            $this->assertStringNotContainsString('profiles', $part);
            $this->assertStringNotContainsString('er_email_events', $part);
            $this->assertStringNotContainsString('deleteEmailEventsForUser', $part);
            $this->assertStringNotContainsString('deleteSendinblueMessagesForUser', $part);
        }
    }
}
