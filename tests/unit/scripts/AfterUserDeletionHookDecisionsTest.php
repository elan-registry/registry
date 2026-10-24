<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\UserDeletionHookFakeDatabase;

require_once __DIR__ . '/../../Support/FakeDatabase.php';
require_once __DIR__ . '/../../Support/UserDeletionHookFakeDatabase.php';

/**
 * Runs usersc/scripts/after_user_deletion.php over a recording database double (#2419).
 *
 * AccountDeletionPrivacyStepTest covers the step itself. This test covers the
 * two decisions the hook makes from the step result: it skips the car cleanup
 * when the step refused, and it writes a CRITICAL line when a committed step
 * deleted no holding row.
 *
 * The fake reports no noowner user, so the hook takes the fallback branch.
 * That branch needs no admin session, so the test sets none.
 */
#[Group('fast')]
final class AfterUserDeletionHookDecisionsTest extends TestCase
{
    private const USER_ID = 77;

    private const SKIP_LINE = 'CRITICAL: car cleanup skipped for user 77 because the privacy step refused; '
        . 'the cars and pending transfer requests still name this ID';

    private const NO_HOLDING_ROW_LINE = 'CRITICAL: privacy step for user 77 found no er_deleted_user_addresses row; '
        . 'check trigger users_delete_capture_addresses, or the users row was already gone before this deletion';

    /** Statements that only the car cleanup sends. */
    private const CAR_CLEANUP_SQL = [
        'SELECT id FROM cars WHERE user_id = ?',
        'SELECT id FROM users WHERE username',
        'car_transfer_requests',
        'UPDATE cars SET user_id',
    ];

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
    private function runHook(UserDeletionHookFakeDatabase $db): void
    {
        $GLOBALS['hookTestDb'] = $db;
        $runner = static function (int $id): void {
            require dirname(__DIR__, 3) . '/usersc/scripts/after_user_deletion.php';
        };
        $runner(self::USER_ID);
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

    private function assertNoCarCleanup(UserDeletionHookFakeDatabase $db): void
    {
        foreach (self::CAR_CLEANUP_SQL as $needle) {
            $this->assertNull($db->indexOf($needle), "A refused step must stop the car cleanup, but the hook sent: {$needle}");
        }
    }

    private function assertNoHoldingRowLine(): void
    {
        foreach ($this->userDeletionLogs() as $message) {
            $this->assertStringNotContainsString('found no er_deleted_user_addresses row', $message);
        }
    }

    /** A users row that still exists makes the step refuse, and the hook must not move a live owner's cars. */
    public function testLiveUsersRowSkipsTheCarCleanup(): void
    {
        $db = new UserDeletionHookFakeDatabase(liveUserIds: [self::USER_ID]);

        $this->runHook($db);

        $this->assertContains(self::SKIP_LINE, $this->userDeletionLogs());
        $this->assertNoCarCleanup($db);
        $this->assertNoHoldingRowLine();
    }

    /** A failed users-row check is a refusal too, because the row may still exist. */
    public function testFailedUsersRowCheckSkipsTheCarCleanup(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'SELECT id FROM users WHERE id = ?');

        $this->runHook($db);

        $this->assertContains(self::SKIP_LINE, $this->userDeletionLogs());
        $this->assertNoCarCleanup($db);
        $this->assertNoHoldingRowLine();
    }

    /**
     * A rolled-back step is not a refusal. The users row is gone, so the car
     * cleanup must still run. The holding row count of a rolled-back step
     * means nothing, so the hook must not report a missing trigger.
     */
    public function testRolledBackStepStillRunsTheCarCleanupAndReportsNoMissingHoldingRow(): void
    {
        $db = new UserDeletionHookFakeDatabase(failOnSql: 'DELETE FROM er_email_events');

        $this->runHook($db);

        $rollback = $db->indexOf('ROLLBACK');
        $this->assertNotNull($rollback);
        foreach (self::CAR_CLEANUP_SQL as $needle) {
            $position = $db->indexOf($needle);
            $this->assertNotNull($position, "The car cleanup must still send: {$needle}");
            $this->assertGreaterThan($rollback, $position, "The car cleanup must run after the step rolls back: {$needle}");
        }
        $this->assertNotContains(self::SKIP_LINE, $this->userDeletionLogs());
        $this->assertNoHoldingRowLine();
    }

    /** A committed step that deleted no holding row points to a missing trigger. */
    public function testCommittedStepWithNoHoldingRowLogsCritical(): void
    {
        $db = new UserDeletionHookFakeDatabase(countBySql: ['DELETE FROM er_deleted_user_addresses' => 0]);

        $this->runHook($db);

        $this->assertNull($db->indexOf('ROLLBACK'));
        $this->assertContains(self::NO_HOLDING_ROW_LINE, $this->userDeletionLogs());
        $this->assertNotNull($db->indexOf('UPDATE cars SET user_id'), 'The car cleanup must still run');
    }

    /** A committed step that deleted the holding row is the normal case and needs no CRITICAL line. */
    public function testCommittedStepWithOneHoldingRowLogsNoCritical(): void
    {
        $db = new UserDeletionHookFakeDatabase(countBySql: ['DELETE FROM er_deleted_user_addresses' => 1]);

        $this->runHook($db);

        $this->assertNull($db->indexOf('ROLLBACK'));
        $this->assertNotNull($db->indexOf('DELETE FROM er_deleted_user_addresses WHERE user_id = ?'));
        $this->assertNoHoldingRowLine();
        $this->assertNotNull($db->indexOf('UPDATE cars SET user_id'), 'The car cleanup must still run');
    }
}

// The hook calls dbi(). Same seam and global as the other hook tests. The
// guard stops a second definition when another test file loads first.
if (!function_exists('dbi')) {
    function dbi(): \ElanRegistry\DatabaseInterface
    {
        return $GLOBALS['hookTestDb'];
    }
}
