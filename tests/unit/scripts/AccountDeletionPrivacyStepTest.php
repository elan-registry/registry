<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyStep;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\UserDeletionHookFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/UserDeletionHookFakeDatabase.php';

/**
 * Runs AccountDeletionPrivacyStep over a recording database double (#2358, #2419).
 *
 * The real transaction and lock behavior needs a real database, and
 * UserDeletionPrivacyStepTest (integration) covers it. This test covers the
 * paths that a unit double can show: the order of the statements, the plugin
 * table that is absent, a failure that rolls the step back, and the three
 * refusals before any delete.
 */
#[Group('fast')]
final class AccountDeletionPrivacyStepTest extends TestCase
{
    private const USER_ID = 77;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    /**
     * @return list<string>
     */
    private function userDeletionLogs(): array
    {
        global $mockLogEntries;

        $messages = [];
        foreach ($mockLogEntries as $entry) {
            if ($entry['category'] === LogCategories::LOG_CATEGORY_USER_DELETION) {
                $messages[] = (string) $entry['message'];
            }
        }

        return $messages;
    }

    private function assertLogged(string $needle): void
    {
        foreach ($this->userDeletionLogs() as $message) {
            if (str_contains($message, $needle)) {
                $this->addToAssertionCount(1);

                return;
            }
        }
        $this->fail("No user-deletion log line contains: {$needle}");
    }

    public function testPluginTableAbsentLogsAndStillDeletesEventsAndProfile(): void
    {
        $db = new UserDeletionHookFakeDatabase(pluginTableExists: false);

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertTrue($result->committed);
        $this->assertTrue($result->pluginTableAbsent);
        $this->assertNull($db->indexOf('DELETE m FROM plg_sendinblue_messages'), 'No plugin delete without the table');
        $this->assertNull($db->indexOf('DELETE FROM plg_sendinblue_messages'));
        $this->assertNotNull($db->indexOf('DELETE FROM er_email_events WHERE user_id = ?'));
        $this->assertNotNull($db->indexOf('DELETE FROM profiles WHERE user_id = ?'));
        $this->assertNotNull($db->indexOf('COMMIT'), 'The privacy step must commit');
        $this->assertLogged('plg_sendinblue_messages is absent');
        $this->assertLogged('Privacy step for user 77: committed');
    }

    public function testPluginTableExistingRunsThePluginDeleteBeforeTheEventDelete(): void
    {
        $db = new UserDeletionHookFakeDatabase(pluginTableExists: true);

        (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $plugin = $db->indexOf('DELETE FROM plg_sendinblue_messages');
        $events = $db->indexOf('DELETE FROM er_email_events WHERE user_id = ?');
        $this->assertNotNull($plugin);
        $this->assertNotNull($events);
        $this->assertLessThan($events, $plugin, 'The plugin delete reads the event message IDs, so it runs first');
    }

    /** Stage order: plugin, events, profile, holding row, and then the commit. */
    public function testStagesRunInOrderPluginEventsProfileHoldingRowThenCommit(): void
    {
        $db = new UserDeletionHookFakeDatabase(pluginTableExists: true);

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertTrue($result->committed);
        $positions = [
            $db->indexOf('DELETE FROM plg_sendinblue_messages'),
            $db->indexOf('DELETE FROM er_email_events WHERE user_id = ?'),
            $db->indexOf('DELETE FROM profiles WHERE user_id = ?'),
            $db->indexOf('DELETE FROM er_deleted_user_addresses WHERE user_id = ?'),
            $db->indexOf('COMMIT'),
        ];
        foreach ($positions as $position) {
            $this->assertNotNull($position);
        }
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'Order must be plugin, events, profile, holding row, commit');
        $this->assertCount(5, array_unique($positions));
    }

    /** The transaction opens first, the profile lock read is the first statement in it, and the commit ends it. */
    public function testPrivacyStepLocksTheProfileFirstAndCommitsLast(): void
    {
        $db = new UserDeletionHookFakeDatabase();

        (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $beginAt = $db->indexOf('BEGIN');
        $this->assertNotNull($beginAt);
        $this->assertStringContainsString('SELECT id FROM users WHERE id = ?', $db->events[$beginAt - 1], 'The live-user check runs before BEGIN');
        $this->assertStringContainsString('FROM profiles WHERE user_id = ? FOR UPDATE', $db->events[$beginAt + 1]);
        $this->assertSame('COMMIT', $db->events[array_key_last($db->events)]);
        $this->assertFalse($db->inTransaction(), 'A committed step leaves no open transaction');
    }

    /**
     * A failure rolls back the step, logs the user ID and the exception class
     * and no message text. The profile must not be deleted when the step
     * rolls back, and run() does not throw.
     */
    public function testFailureRollsBackAndLogsTheClassOnly(): void
    {
        $db = new UserDeletionHookFakeDatabase(
            failOnSql: 'DELETE FROM er_email_events',
            failInfo: ['HY000', 1205, 'Lock wait timeout for owner@example.com'],
        );

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertFalse($result->committed);
        $this->assertFalse($result->refused);
        $this->assertSame('event delete', $result->failedStage);
        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertNull($db->indexOf('COMMIT'));
        $this->assertNull($db->indexOf('DELETE FROM profiles'), 'A rolled-back step must not reach the profile delete');
        $this->assertLogged(
            'CRITICAL: privacy step failed for user 77 at stage event delete, rolled back, personal data remains: '
            . 'ElanRegistry\Exceptions\CarDatabaseException (code 0)'
        );
        foreach ($this->userDeletionLogs() as $message) {
            $this->assertStringNotContainsString('owner@example.com', $message, 'A log line must not hold an address');
        }
        $this->assertLogged('Privacy step for user 77: rolled back');
    }

    /**
     * A rollback that throws must not escape run() or stop the CRITICAL line.
     */
    public function testARollbackThatThrowsIsLoggedAndDoesNotEscape(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'DELETE FROM er_email_events', rollbackThrows: true);

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertFalse($result->committed);
        $this->assertSame('event delete', $result->failedStage);
        $this->assertLogged(
            'CRITICAL: privacy step failed for user 77 at stage event delete, rollback failed: PDOException (code 2006), '
            . 'personal data remains: ElanRegistry\Exceptions\CarDatabaseException (code 0)'
        );
    }

    /**
     * A plugin-table error (1146) after a positive existence check is a failure
     * of the step.
     */
    public function testPluginTableError1146RollsTheStepBack(): void
    {
        $db = new UserDeletionHookFakeDatabase(
            pluginTableExists: true,
            failOnSql: 'DELETE FROM plg_sendinblue_messages',
            failInfo: ['42S02', 1146, "Table 'plg_sendinblue_messages' doesn't exist"],
        );

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertSame('plugin delete', $result->failedStage);
        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertLogged('at stage plugin delete, rolled back');
        $this->assertNull($db->indexOf('DELETE FROM profiles'), 'A rolled-back step must not reach the profile delete');
    }

    /** A failure at the holding row delete rolls back the profile delete too, and the holding row stays. */
    public function testHoldingRowDeleteFailureRollsBackBeforeTheCommit(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'DELETE FROM er_deleted_user_addresses');

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertFalse($result->committed);
        $this->assertSame('holding row delete', $result->failedStage);
        $this->assertNotNull($db->indexOf('DELETE FROM profiles WHERE user_id = ?'));
        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertNull($db->indexOf('COMMIT'));
        $this->assertLogged('at stage holding row delete, rolled back');
    }

    /** A committed step leaves no open transaction, so a caller that returns early holds none. */
    public function testCommittedStepLeavesNoOpenTransaction(): void
    {
        $db = new UserDeletionHookFakeDatabase();

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertTrue($result->committed);
        $this->assertFalse($db->inTransaction());
        $this->assertNotNull($db->indexOf('DELETE FROM profiles WHERE user_id = ?'));
        $this->assertNotNull($db->indexOf('DELETE FROM er_email_events WHERE user_id = ?'));
        $this->assertLogged('Privacy step for user 77: committed, deleted 0 er_email_events rows');
    }

    /** The absent-table note is written only for a step that committed. */
    public function testPluginTableAbsentIsNotLoggedWhenTheStepRollsBack(): void
    {
        $db = new UserDeletionHookFakeDatabase(pluginTableExists: false, failOnSql: 'DELETE FROM profiles');

        (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertLogged('at stage profile delete, rolled back');
        foreach ($this->userDeletionLogs() as $message) {
            $this->assertStringNotContainsString('is absent', $message);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function userIdBelowOneProvider(): array
    {
        return [
            'zero' => [0],
            'minus one' => [-1],
            'PHP_INT_MIN' => [PHP_INT_MIN],
        ];
    }

    /** A user ID below 1 matches no row: the step logs CRITICAL and sends no statement. */
    #[DataProvider('userIdBelowOneProvider')]
    public function testUserIdBelowOneLogsCriticalAndRunsNoStatement(int $userId): void
    {
        $db = new UserDeletionHookFakeDatabase();

        $result = (new AccountDeletionPrivacyStep($db))->run($userId);

        $this->assertTrue($result->refused);
        $this->assertFalse($result->committed);
        $this->assertSame([], $db->events, 'The step must send no statement and open no transaction');
        $this->assertSame(
            [sprintf('CRITICAL: privacy step refused user ID %d: the ID is below 1, no statement run', $userId)],
            $this->userDeletionLogs()
        );
    }

    /** A user ID that still has a users row is refused before BEGIN, and no delete runs. */
    public function testLiveUserIdRunsNoDelete(): void
    {
        $db = new UserDeletionHookFakeDatabase(liveUserIds: [self::USER_ID]);

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertTrue($result->refused);
        $this->assertFalse($result->committed);
        $this->assertSame(['SQL: SELECT id FROM users WHERE id = ?'], $db->events, 'Only the live-user check runs: no BEGIN, no delete');
        $this->assertLogged('CRITICAL: privacy step refused user 77: a users row still exists, no row deleted; if an account deletion ran, check the trigger users_delete_capture_addresses and the user permission rows');
    }

    /** A failed live-user check refuses too: the step cannot prove the user is gone. */
    public function testFailedLiveUserCheckRunsNoDelete(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'SELECT id FROM users WHERE id = ?');

        $result = (new AccountDeletionPrivacyStep($db))->run(self::USER_ID);

        $this->assertTrue($result->refused);
        $this->assertSame(['SQL: SELECT id FROM users WHERE id = ?'], $db->events);
        $this->assertLogged('the users row check failed, no row deleted');
    }
}
