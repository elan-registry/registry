<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/database/MigrationSandbox.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: the privacy step of usersc/scripts/after_user_deletion.php.
 *
 * The step runs first, in its own transaction. It locks the profile row, reads the
 * addresses of the account, deletes the plugin message rows, the events and the
 * profile, and commits. The early returns after it keep no personal data.
 *
 * The test schema has the plugin's `plg_sendinblue` table but not
 * `plg_sendinblue_messages`. A TEMPORARY table would not do: the hook finds the
 * table in information_schema, which does not list temporary tables. So each test
 * makes the real table with the columns of the plugin. The class drops it at the
 * end only if the class made it.
 */
#[Group('integration')]
final class UserDeletionPrivacyStepTest extends IntegrationTestCase
{
    private const PLUGIN_TABLE_SQL = 'CREATE TABLE IF NOT EXISTS plg_sendinblue_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(191) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        sender_email VARCHAR(255) NOT NULL,
        kind VARCHAR(32) NOT NULL,
        tags VARCHAR(255) NULL,
        status VARCHAR(32) NOT NULL,
        accepted_at DATETIME NOT NULL,
        delivered_at DATETIME NULL,
        last_event_at DATETIME NULL,
        last_checked_at DATETIME NULL,
        last_reason VARCHAR(255) NULL,
        UNIQUE KEY message_id (message_id),
        KEY recipient_accepted (recipient_email(128), accepted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** True when this class made the plugin table, so it must drop it. */
    private static bool $madePluginTable = false;

    /** @var list<string> Message IDs of the plugin rows that a test made. */
    private array $pluginMessageIds = [];

    /** @var list<int> Users whose log rows tearDown deletes. */
    private array $loggedUserIds = [];

    private int $adminId = 0;

    /** Output of the hook process that waitUntil() already read. */
    private string $hookStdout = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$madePluginTable) {
            DB::getInstance()->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            self::$madePluginTable = false;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->pluginMessageIds = [];
        $this->loggedUserIds = [];
        $this->hookStdout = '';
        $this->ensurePluginTable();
        $this->adminId = $this->createTestUser();
        $this->loggedUserIds[] = $this->adminId;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // A test that failed in the middle of a transaction must not leave it open.
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->pluginTableExists()) {
                foreach ($this->pluginMessageIds as $messageId) {
                    $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
                }
            }
            foreach ($this->loggedUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
            }
        }
        parent::tearDown();
    }

    private function ensurePluginTable(): void
    {
        if ($this->pluginTableExists()) {
            return;
        }
        $this->db->query(self::PLUGIN_TABLE_SQL);
        $this->assertFalse($this->db->error(), 'Test setup: plugin table: ' . $this->db->errorString());
        self::$madePluginTable = true;
    }

    private function pluginTableExists(): bool
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plg_sendinblue_messages'"
        )->first()->cnt > 0;
    }

    private function insertPluginRow(string $messageId, string $recipient): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'delivered', '2026-05-01 10:00:00')",
            [$messageId, $recipient]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    private function pluginRowExists(string $messageId): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages WHERE message_id = ?',
            [$messageId]
        )->first()->cnt === 1;
    }

    /**
     * @param int|null $carId Null writes an owner-level row
     */
    private function insertEvent(int $userId, ?int $carId, string $address, string $messageId, string $event = 'delivered'): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$userId, $carId, $address, $event, $messageId, '2026-05-01 10:00:00']
        );
        $this->assertFalse($this->db->error(), 'Test setup: event: ' . $this->db->errorString());
    }

    private function eventCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events WHERE user_id = ?', [$userId])->first()->cnt;
    }

    private function profileCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt;
    }

    private function uniqueAddress(string $prefix): string
    {
        return $prefix . '-' . uniqid() . '@example.test';
    }

    /**
     * An owner with a profile whose address sources hold nothing that matches by accident.
     */
    private function createOwner(): int
    {
        $userId = $this->createTestUser(['email' => $this->uniqueAddress('own')], true);
        $this->setEmailAddressSinceSeen($userId, $this->uniqueAddress('seen-default'));
        $this->loggedUserIds[] = $userId;

        return $userId;
    }

    /**
     * What deleteUsers() leaves, then the hook: the users row is already gone.
     */
    private function runHook(int $userId, bool $withSession = true): void
    {
        if ($withSession) {
            $this->loginAsTestUser($this->adminId);
        } else {
            $this->restoreGlobalUser();
            $this->assertEmpty($GLOBALS['user']->isLoggedIn(), 'Test setup: the ambient session must be logged out');
        }
        $this->db->delete('users', ['id', '=', $userId]);

        $id = $userId;
        $db = $this->db;
        require TESTING_ROOT . '/usersc/scripts/after_user_deletion.php';
    }

    /**
     * @return list<string> The log notes that the hook wrote for the user
     */
    private function deletionLogNotes(int $userId): array
    {
        $notes = [];
        foreach ($this->db->query(
            'SELECT lognote FROM logs WHERE user_id = ? AND logtype = ? ORDER BY id',
            [$userId, LogCategories::LOG_CATEGORY_USER_DELETION]
        )->results() as $row) {
            $notes[] = (string) $row->lognote;
        }

        return $notes;
    }

    // --- Plugin rows by message ID, and by each address source ------------------

    public function testPluginRowsWhoseMessageIdIsInTheUsersEventsAreDeleted(): void
    {
        $userId = $this->createOwner();
        $other = $this->createOwner();
        $mine = 'privacy-msg-' . uniqid();
        $theirs = 'privacy-msg-' . uniqid();
        $unrelated = 'privacy-msg-' . uniqid();
        $this->insertEvent($userId, null, $this->uniqueAddress('ev'), $mine);
        $this->insertEvent($other, null, $this->uniqueAddress('ev'), $theirs);
        // The recipient of $mine is not an address of the user. Only the message ID links it.
        $this->insertPluginRow($mine, $this->uniqueAddress('nobody'));
        $this->insertPluginRow($theirs, $this->uniqueAddress('nobody'));
        $this->insertPluginRow($unrelated, $this->uniqueAddress('nobody'));

        $this->runHook($userId);

        $this->assertFalse($this->pluginRowExists($mine));
        $this->assertTrue($this->pluginRowExists($theirs), "Another user's message row stays");
        $this->assertTrue($this->pluginRowExists($unrelated));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function addressSourceProvider(): array
    {
        return [
            'events'             => ['events'],
            'email_address_seen' => ['seen'],
            'email_bounced'      => ['bounced'],
            'email_blocked'      => ['blocked'],
            'cars.email'         => ['cars'],
        ];
    }

    #[DataProvider('addressSourceProvider')]
    public function testPluginRowsForAnAddressFromEachSourceAreDeleted(string $source): void
    {
        $userId = $this->createOwner();
        $address = $this->uniqueAddress('src-' . $source);
        if ($source === 'events') {
            $this->insertEvent($userId, null, $address, 'privacy-src-ev-' . uniqid());
        } elseif ($source === 'seen') {
            $this->setEmailAddressSinceSeen($userId, $address);
        } elseif ($source === 'bounced') {
            $this->setOwnerBounced($userId, $address);
        } elseif ($source === 'blocked') {
            $this->setOwnerBlocked($userId, $address);
        } elseif ($source === 'cars') {
            $this->createTestCar($userId, ['email' => $address]);
        } else {
            $this->fail("Test setup: unknown address source {$source}");
        }
        $hit = 'privacy-hit-' . uniqid();
        $control = 'privacy-control-' . uniqid();
        // The plugin stores the recipient as sent. Case and spaces differ from the source.
        $this->insertPluginRow($hit, '  ' . strtoupper($address) . ' ');
        $this->insertPluginRow($control, $this->uniqueAddress('control'));

        $this->runHook($userId);

        $this->assertFalse($this->pluginRowExists($hit), "A row for an address from the {$source} source must go");
        $this->assertTrue($this->pluginRowExists($control), 'A row for another address stays');
    }

    public function testAnAddressThatAnotherUserHoldsNowIsSkippedButMessageIdRowsStillGo(): void
    {
        $userId = $this->createOwner();
        $oldAddress = $this->uniqueAddress('old');
        $this->setEmailAddressSinceSeen($userId, $oldAddress);
        // Another account took the old address (users.email, other case).
        $this->createTestUser(['email' => strtoupper($oldAddress)]);
        $byAddress = 'privacy-addr-' . uniqid();
        $byMessage = 'privacy-byid-' . uniqid();
        $this->insertPluginRow($byAddress, $oldAddress);
        $this->insertEvent($userId, null, $oldAddress, $byMessage);
        // Same recipient, found by message ID: the user's own message, so it goes.
        $this->insertPluginRow($byMessage, $oldAddress);

        $this->runHook($userId);

        $this->assertTrue($this->pluginRowExists($byAddress), 'The row belongs to the user that holds the address now');
        $this->assertFalse($this->pluginRowExists($byMessage), 'A row found by message ID goes');
    }

    public function testAnOwnerWithNoCarNoFlagAndNoEventLosesThePasswordResetRow(): void
    {
        $email = $this->uniqueAddress('reset');
        $userId = $this->createTestUser(['email' => $email], true);
        $this->loggedUserIds[] = $userId;
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM cars WHERE user_id = ?', [$userId])->first()->cnt,
            'Test setup: no car'
        );
        $this->assertSame(0, $this->eventCount($userId), 'Test setup: no event');
        $reset = 'privacy-reset-' . uniqid();
        $this->insertPluginRow($reset, $email);

        $this->runHook($userId);

        $this->assertFalse($this->pluginRowExists($reset), 'email_address_seen links the password-reset email to the account');
    }

    // --- Events and profile ---------------------------------------------------

    public function testEventsAreDeletedByUserIdIncludingRowsWithCarIdNull(): void
    {
        $userId = $this->createOwner();
        $other = $this->createOwner();
        $carId = $this->createTestCar($userId);
        $this->insertEvent($userId, $carId, $this->uniqueAddress('ev'), 'local:' . uniqid(), 'sent');
        $this->insertEvent($userId, null, $this->uniqueAddress('ev'), 'priv-null-' . uniqid());
        $this->insertEvent($other, null, $this->uniqueAddress('ev'), 'priv-other-' . uniqid());

        $this->runHook($userId);

        $this->assertSame(0, $this->eventCount($userId), 'Every event row of the user goes, also the row with car_id NULL');
        $this->assertSame(1, $this->eventCount($other), "Another user's event stays");
        $this->assertSame(0, $this->profileCount($userId));
    }

    public function testAUserWithNoCarsIsCleanedAndTheHookEndsNormally(): void
    {
        $userId = $this->createOwner();
        $this->insertEvent($userId, null, $this->uniqueAddress('ev'), 'priv-nocar-' . uniqid());

        $this->runHook($userId);

        $this->assertSame(0, $this->eventCount($userId));
        $this->assertSame(0, $this->profileCount($userId));
        $this->assertFalse($this->db->inTransaction());
        $notes = $this->deletionLogNotes($userId);
        $this->assertContains("Complete cleanup: reassigned 0 cars to noowner user (ID: {$this->noownerId()})", $notes);
    }

    public function testAnOwnerWhoChangedAddressOnceLosesRowsForBothAddresses(): void
    {
        $userId = $this->createOwner();
        $old = $this->uniqueAddress('before-change');
        $new = $this->uniqueAddress('after-change');
        $this->setEmailAddressSinceSeen($userId, $new);
        $this->setOwnerBounced($userId, $old);
        $this->createTestCar($userId, ['email' => $old]);
        $this->insertEvent($userId, null, $old, 'priv-old-' . uniqid(), 'hard_bounce');
        $oldRow = 'privacy-oldaddr-' . uniqid();
        $newRow = 'privacy-newaddr-' . uniqid();
        $control = 'privacy-ctrl-' . uniqid();
        $this->insertPluginRow($oldRow, $old);
        $this->insertPluginRow($newRow, $new);
        $this->insertPluginRow($control, $this->uniqueAddress('ctrl'));

        $this->runHook($userId);

        $this->assertFalse($this->pluginRowExists($oldRow));
        $this->assertFalse($this->pluginRowExists($newRow));
        $this->assertTrue($this->pluginRowExists($control));
        $this->assertSame(0, $this->eventCount($userId));
    }

    // --- The early returns keep no personal data and leave no transaction open --------

    public function testWhenFindByOwnerThrowsNoTransactionStaysOpenAndAllDataIsDeleted(): void
    {
        $userId = $this->createOwner();
        $address = $this->uniqueAddress('early');
        $carId = $this->createTestCar($userId, ['email' => $address]);
        $this->insertEvent($userId, null, $address, 'priv-early-' . uniqid());
        $pluginRow = 'privacy-early-' . uniqid();
        $this->insertPluginRow($pluginRow, $address);

        // findByOwner() selects cars.id. The privacy step does not. The rename makes the first one fail.
        $this->db->query('ALTER TABLE cars RENAME COLUMN id TO id_renamed_for_test');
        $this->assertFalse($this->db->error(), 'Test setup: failed to force a DB error: ' . $this->db->errorString());
        try {
            $this->runHook($userId);
        } finally {
            $this->db->query('ALTER TABLE cars RENAME COLUMN id_renamed_for_test TO id');
            $this->assertFalse($this->db->error(), 'Test cleanup: failed to restore cars.id: ' . $this->db->errorString());
        }

        $this->assertFalse($this->db->inTransaction(), 'The early return must leave no transaction open');
        $this->assertSame(0, $this->profileCount($userId), 'The profile is deleted');
        $this->assertSame(0, $this->eventCount($userId), 'The events are deleted');
        $this->assertFalse($this->pluginRowExists($pluginRow), 'The plugin rows are deleted');
        $this->assertContains(
            'Cleanup aborted: findByOwner failed',
            array_map(static fn (string $note): string => substr($note, 0, 35), $this->deletionLogNotes($userId)),
            'Test control: the hook took the findByOwner early return'
        );
        $row = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame($userId, (int) $row->user_id, 'The car stays: the early return skipped the reassignment');
    }

    public function testWithNoAdminSessionNoTransactionStaysOpenAndAllDataIsDeleted(): void
    {
        $userId = $this->createOwner();
        $address = $this->uniqueAddress('nosess');
        $carId = $this->createTestCar($userId, ['email' => $address]);
        $this->insertEvent($userId, null, $address, 'priv-nosess-' . uniqid());
        $pluginRow = 'privacy-nosess-' . uniqid();
        $this->insertPluginRow($pluginRow, $address);

        $this->runHook($userId, false);

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(0, $this->profileCount($userId));
        $this->assertSame(0, $this->eventCount($userId));
        $this->assertFalse($this->pluginRowExists($pluginRow));
        $notes = array_map(static fn (string $note): string => substr($note, 0, 47), $this->deletionLogNotes($userId));
        $this->assertContains('Cleanup aborted: no authenticated admin session', $notes);
        $row = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame($userId, (int) $row->user_id, 'The car stays: the early return skipped the reassignment');
    }

    // --- Failure and absent plugin table ----------------------------------------

    public function testAPrivacyStepFailureRollsBackLogsUserIdAndClassAndTheHookContinues(): void
    {
        $userId = $this->createOwner();
        $address = $this->uniqueAddress('fail');
        $carId = $this->createTestCar($userId, ['email' => $address]);
        $this->insertEvent($userId, null, $address, 'priv-fail-' . uniqid());
        $pluginRow = 'privacy-fail-' . uniqid();
        $this->insertPluginRow($pluginRow, $address);

        // The plugin delete reads er_email_events.brevo_message_id. Without the column it fails
        // after the lock and before the events delete.
        $this->db->query('ALTER TABLE er_email_events RENAME COLUMN brevo_message_id TO brevo_message_id_renamed_for_test');
        $this->assertFalse($this->db->error(), 'Test setup: failed to force a DB error: ' . $this->db->errorString());
        try {
            $this->runHook($userId);
        } finally {
            $this->db->query('ALTER TABLE er_email_events RENAME COLUMN brevo_message_id_renamed_for_test TO brevo_message_id');
            $this->assertFalse($this->db->error(), 'Test cleanup: failed to restore the column: ' . $this->db->errorString());
        }

        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(1, $this->profileCount($userId), 'The failed step rolled back: the profile stays');
        $this->assertSame(1, $this->eventCount($userId), 'The failed step rolled back: the event stays');
        $this->assertTrue($this->pluginRowExists($pluginRow));

        $notes = $this->deletionLogNotes($userId);
        $failure = array_values(array_filter($notes, static fn (string $note): bool => str_starts_with($note, 'CRITICAL: privacy step failed')));
        $this->assertCount(1, $failure);
        $this->assertSame(
            sprintf(
                'CRITICAL: privacy step failed for user %d at stage plugin delete, rolled back, personal data remains: %s (code 0)',
                $userId,
                \ElanRegistry\Exceptions\CarDatabaseException::class
            ),
            $failure[0],
            'The log names the user ID, the stage, the exception class and code, and no message text'
        );
        $this->assertStringNotContainsString('brevo_message_id', $failure[0]);

        $after = $this->db->query('SELECT user_id FROM cars WHERE id = ?', [$carId])->first();
        $this->assertSame($this->noownerId(), (int) $after->user_id, 'The hook continued: the car goes to noowner');
        $this->assertContains("Complete cleanup: reassigned 1 cars to noowner user (ID: {$this->noownerId()})", $notes);
    }

    public function testWhenThePluginTableIsAbsentTheStepLogsAndStillDeletesEventsAndProfile(): void
    {
        if (!self::$madePluginTable) {
            $this->markTestSkipped('plg_sendinblue_messages existed before this class ran, so this test must not drop it.');
        }
        $this->db->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
        $userId = $this->createOwner();
        $this->insertEvent($userId, null, $this->uniqueAddress('absent'), 'priv-absent-' . uniqid());

        $this->runHook($userId);

        $this->assertSame(0, $this->eventCount($userId));
        $this->assertSame(0, $this->profileCount($userId));
        $this->assertContains(
            "Privacy step: plg_sendinblue_messages is absent, no plugin rows to delete for user {$userId}",
            $this->deletionLogNotes($userId)
        );
    }

    // --- Locks -----------------------------------------------------------------

    public function testAnEventTransactionThatHoldsTheProfileLockCommitsBeforeTheStepReadsSoNoEventRemains(): void
    {
        $userId = $this->createOwner();
        $address = $this->uniqueAddress('lock');
        $this->insertEvent($userId, null, $address, 'priv-lock-before-' . uniqid());
        $this->db->delete('users', ['id', '=', $userId]);

        $factory = \MigrationSandbox::defaultPdoFactory();
        $events = $factory(\MigrationSandbox::env('DB_NAME'));
        $events->beginTransaction();
        $events->prepare('SELECT user_id FROM profiles WHERE user_id = ? FOR UPDATE')->execute([$userId]);

        [$process, $pipes] = $this->startHookProcess($userId);
        try {
            // The test user has no PROCESS privilege, so it cannot see the lock wait of another
            // connection. The hook process prints a marker just before it starts. A hook that does
            // not wait for the lock ends in well under a second after the marker.
            $this->assertTrue(
                $this->waitUntil(function () use ($pipes): bool {
                    $this->hookStdout .= (string) stream_get_contents($pipes[1]);

                    return str_contains($this->hookStdout, 'HOOK-START');
                }, 60.0),
                'The hook process must start'
            );
            usleep(2000000);
            $status = proc_get_status($process);
            $this->assertTrue(
                $status['running'],
                'The privacy step must wait for the profile lock. If it does not wait, it can delete before the event commits.'
            );

            // This stands in for the event step that #2359 will add. It writes while it
            // holds the lock, then commits. Today an event write takes no profile lock.
            $events->prepare(
                'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
                 VALUES (?, NULL, ?, ?, NULL, ?, ?)'
            )->execute([$userId, $address, 'delivered', 'priv-lock-during-' . uniqid(), '2026-05-02 10:00:00']);
            $events->commit();
        } finally {
            if ($events->inTransaction()) {
                $events->rollBack();
            }
            [$stdout, $stderr, $exit] = $this->finishHookProcess($process, $pipes);
        }

        $this->assertSame(0, $exit, "The hook process failed: {$stderr}");
        $this->assertStringContainsString('HOOK-DONE', $stdout);
        $this->assertSame(0, $this->eventCount($userId), 'No er_email_events row of the user remains, also the one that committed during the wait');
        $this->assertSame(0, $this->profileCount($userId));
    }

    public function testAUserWithNoProfileRowDoesNotHangEvenWhenAnotherTransactionHoldsAGapLock(): void
    {
        $userId = $this->createTestUser(['email' => $this->uniqueAddress('noprofile')]);
        $this->loggedUserIds[] = $userId;
        $this->assertSame(0, $this->profileCount($userId), 'Test setup: no profile row');
        $this->insertEvent($userId, null, $this->uniqueAddress('noprofile-ev'), 'priv-noprofile-' . uniqid());

        $factory = \MigrationSandbox::defaultPdoFactory();
        $other = $factory(\MigrationSandbox::env('DB_NAME'));
        $other->beginTransaction();
        // A SELECT ... FOR UPDATE on a missing row takes a gap lock. Gap locks do not block each other.
        $other->prepare('SELECT user_id FROM profiles WHERE user_id = ? FOR UPDATE')->execute([$userId]);

        $originalTimeout = (string) $this->db->query("SHOW VARIABLES LIKE 'innodb_lock_wait_timeout'")->first()->Value;
        // A hang would turn into a lock wait timeout after 3 s, and the step would roll back.
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 3');
        try {
            $start = microtime(true);
            $this->runHook($userId);
            $seconds = microtime(true) - $start;
        } finally {
            $this->db->query('SET SESSION innodb_lock_wait_timeout = ' . (int) $originalTimeout);
            $other->rollBack();
        }

        $this->assertLessThan(2.5, $seconds, 'The step must not wait for the gap lock');
        $this->assertSame(0, $this->eventCount($userId), 'The events go');
        $this->assertSame([], array_filter(
            $this->deletionLogNotes($userId),
            static fn (string $note): bool => str_starts_with($note, 'CRITICAL: privacy step failed')
        ), 'The step must not fail');
    }

    private function noownerId(): int
    {
        return (int) $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first()->id;
    }

    private function waitUntil(callable $condition, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            usleep(100000);
        }

        return false;
    }

    /**
     * Start the hook in its own PHP process, with the admin as the session.
     *
     * @return array{0: resource, 1: array<int, resource>} The process and its pipes
     */
    private function startHookProcess(int $userId): array
    {
        $root = dirname(__DIR__, 2);
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("$var=$value");
            }
        }
        $script = sprintf(
            'require %s; \Dotenv\Dotenv::createMutable(%s, ".env.test.local")->load(); ' .
            '$_SERVER["DOCUMENT_ROOT"] = %s; $_SERVER["SCRIPT_FILENAME"] = %s; chdir(%s); require_once %s; ' .
            '$loggedInUser = new User(); $loggedInUser->find(%d); ' .
            '$reflection = new ReflectionClass($loggedInUser); $prop = $reflection->getProperty("_isLoggedIn"); ' .
            '$prop->setValue($loggedInUser, true); global $user; $user = $loggedInUser; $GLOBALS["user"] = $loggedInUser; ' .
            '$id = %d; $db = null; echo "HOOK-START"; flush(); require %s; echo "HOOK-DONE";',
            var_export($root . '/vendor/autoload.php', true),
            var_export($root, true),
            var_export($root, true),
            var_export($root . '/usersc/scripts/after_user_deletion.php', true),
            var_export($root, true),
            var_export($root . '/users/init.php', true),
            $this->adminId,
            $userId,
            var_export($root . '/usersc/scripts/after_user_deletion.php', true)
        );

        $process = proc_open(
            ['php', '-d', 'display_errors=stderr', '-d', 'error_reporting=-1', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'Test setup: the hook process must start');
        stream_set_blocking($pipes[1], false);

        return [$process, $pipes];
    }

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{0: string, 1: string, 2: int}
     */
    private function finishHookProcess($process, array $pipes): array
    {
        stream_set_blocking($pipes[1], true);
        $stdout = $this->hookStdout . (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [$stdout, $stderr, proc_close($process)];
    }
}
