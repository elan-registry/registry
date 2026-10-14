<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\UserDeletionHookFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/UserDeletionHookFakeDatabase.php';

/**
 * Runs usersc/scripts/after_user_deletion.php over a recording database double (#2358).
 *
 * The real transaction and lock behavior needs a real database, and
 * UserDeletionPrivacyStepTest (integration) covers it. This test covers the
 * paths that a unit double can show: the order of the statements, the plugin
 * table that is absent, a failure that rolls the privacy step back, and the
 * early return that leaves no open transaction.
 */
#[Group('fast')]
final class AfterUserDeletionPrivacyStepTest extends TestCase
{
    private const USER_ID = 77;

    protected function setUp(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['hookTestDb']);
    }

    /**
     * Run the hook with $id set, as deleteUsers() does.
     */
    private function runHook(UserDeletionHookFakeDatabase $db, int $userId = self::USER_ID): void
    {
        $GLOBALS['hookTestDb'] = $db;
        $runner = static function (int $id): void {
            require dirname(__DIR__, 3) . '/usersc/scripts/after_user_deletion.php';
        };
        $runner($userId);
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

        $this->runHook($db);

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

        $this->runHook($db);

        $plugin = $db->indexOf('DELETE FROM plg_sendinblue_messages');
        $events = $db->indexOf('DELETE FROM er_email_events WHERE user_id = ?');
        $this->assertNotNull($plugin);
        $this->assertNotNull($events);
        $this->assertLessThan($events, $plugin, 'The plugin delete reads the event message IDs, so it runs first');
    }

    /**
     * The profile lock read opens the step, and the step commits before the
     * car cleanup reads the user's cars.
     */
    public function testPrivacyStepLocksTheProfileFirstAndCommitsBeforeTheCarCleanup(): void
    {
        $db = new UserDeletionHookFakeDatabase();

        $this->runHook($db);

        $this->assertSame('BEGIN', $db->events[0]);
        $this->assertStringContainsString('FROM profiles WHERE user_id = ? FOR UPDATE', $db->events[1]);
        $commit = $db->indexOf('COMMIT');
        $cars = $db->indexOf('SELECT id FROM cars WHERE user_id = ?');
        $this->assertNotNull($commit);
        $this->assertNotNull($cars);
        $this->assertLessThan($cars, $commit);
    }

    /**
     * A failure in the privacy step rolls back that step, logs the user ID and
     * the exception class, and the car cleanup still runs. The profile must
     * not be deleted when the step rolls back.
     */
    public function testPrivacyStepFailureRollsBackLogsTheClassOnlyAndTheCleanupContinues(): void
    {
        $db = new UserDeletionHookFakeDatabase(
            failOnSql: 'DELETE FROM er_email_events',
            failInfo: ['HY000', 1205, 'Lock wait timeout for owner@example.com'],
        );

        $this->runHook($db);

        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertLogged(
            'CRITICAL: privacy step failed for user 77 at stage event delete, rolled back, personal data remains: '
            . 'ElanRegistry\Exceptions\CarDatabaseException (code 0)'
        );
        foreach ($this->userDeletionLogs() as $message) {
            $this->assertStringNotContainsString('owner@example.com', $message, 'A log line must not hold an address');
        }
        $this->assertNotNull($db->indexOf('SELECT id FROM cars WHERE user_id = ?'), 'The car cleanup must still run');
        $this->assertLogged('Privacy step for user 77: rolled back');
    }

    /**
     * A plugin-table error (1146) after a positive existence check is a failure
     * of the step, not a reason to stop the hook.
     */
    public function testPluginTableError1146RollsTheStepBackAndTheCleanupContinues(): void
    {
        $db = new UserDeletionHookFakeDatabase(
            pluginTableExists: true,
            failOnSql: 'DELETE FROM plg_sendinblue_messages',
            failInfo: ['42S02', 1146, "Table 'plg_sendinblue_messages' doesn't exist"],
        );

        $this->runHook($db);

        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertLogged('at stage plugin delete, rolled back');
        $this->assertNull($db->indexOf('DELETE FROM profiles'), 'A rolled-back step must not reach the profile delete');
        $this->assertNotNull($db->indexOf('SELECT id FROM cars WHERE user_id = ?'));
    }

    /**
     * findByOwner() fails: the hook returns early. The privacy step is already
     * committed, so no transaction stays open and the personal data is gone.
     */
    public function testFindByOwnerFailureLeavesNoOpenTransactionAfterThePrivacyCommit(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'SELECT id FROM cars WHERE user_id = ?');

        $this->runHook($db);

        $this->assertFalse($db->inTransaction(), 'An early return must leave no open transaction');
        $commit = $db->indexOf('COMMIT');
        $this->assertNotNull($db->indexOf('DELETE FROM profiles WHERE user_id = ?'));
        $this->assertNotNull($db->indexOf('DELETE FROM er_email_events WHERE user_id = ?'));
        $this->assertNotNull($commit);
        $this->assertLessThan($db->indexOf('SELECT id FROM cars WHERE user_id = ?'), $commit);
        $this->assertNull($db->indexOf('SELECT id FROM users WHERE username'), 'The hook must stop before the noowner lookup');
        $this->assertLogged('Cleanup aborted: findByOwner failed');
        $this->assertLogged('Privacy step for user 77: committed, deleted 0 er_email_events rows');
    }

    /** The absent-table note is written only for a step that committed. */
    public function testPluginTableAbsentIsNotLoggedWhenTheStepRollsBack(): void
    {
        $db = new UserDeletionHookFakeDatabase(pluginTableExists: false, failOnSql: 'DELETE FROM profiles');

        $this->runHook($db);

        $this->assertNotNull($db->indexOf('ROLLBACK'));
        $this->assertLogged('at stage profile delete, rolled back');
        foreach ($this->userDeletionLogs() as $message) {
            $this->assertStringNotContainsString('is absent', $message);
        }
    }

    /**
     * A user ID below 1 matches no row, so the hook logs a CRITICAL line and
     * sends no statement.
     */
    public function testUserIdBelowOneLogsCriticalAndRunsNoStatement(): void
    {
        $db = new UserDeletionHookFakeDatabase();

        $this->runHook($db, 0);

        $this->assertSame([], $db->events, 'The hook must send no statement for user ID 0');
        $this->assertSame(['CRITICAL: after_user_deletion received user ID 0, cleanup skipped'], $this->userDeletionLogs());
    }
}

// The hook calls dbi(). Same seam and global as the other hook tests, guarded
// so that no load order can define the function twice.
if (!function_exists('dbi')) {
    function dbi(): \ElanRegistry\DatabaseInterface
    {
        return $GLOBALS['hookTestDb'];
    }
}
