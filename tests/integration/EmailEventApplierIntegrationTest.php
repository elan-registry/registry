<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Exceptions\CarDatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-database tests for EmailEventApplier (#2359): the one locked
 * transaction that records an event and sets its flag.
 *
 * The unit test (tests/unit/cars/services/EmailEventApplierTest.php) covers
 * the decisions with a mocked repository. These tests cover what only the
 * database shows: the transaction and rollback, the lock, the duplicate rule
 * (`id = id`), the date guards against stored columns, the Clear Block bound
 * against real `logs` rows, and the full match-then-apply flow with the real
 * EmailEventMatcher. The test schema has no `plg_sendinblue_messages`, so the
 * class makes the plugin's table when it is missing and drops it at the end
 * only if the class made it.
 *
 * The Clear Block row is the `email_blocked` log row with `new = 0` and
 * `source = admin` ("Not doing" of #2359).
 *
 * @see https://github.com/elan-registry/registry/issues/2359
 */
#[Group('integration')]
#[Group('car-verification')]
final class EmailEventApplierIntegrationTest extends IntegrationTestCase
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

    /** A time that every fixture guard is before. */
    private const LONG_AGO = '2020-01-01 00:00:00';

    private static bool $madePluginTable = false;

    private CarRepository $repo;
    private EmailEventApplier $applier;
    private EmailEventMatcher $matcher;

    /** @var list<string> */
    private array $pluginMessageIds = [];

    private int $logIdFloor = 0;

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
        $this->makePluginTable();
        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));
        $this->matcher = new EmailEventMatcher($this->repo);
        $this->logIdFloor = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM logs')->first()->m;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->pluginMessageIds as $messageId) {
                $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
            }
            $this->deleteOwnLogs();
        }
        $this->pluginMessageIds = [];

        parent::tearDown();
    }

    /**
     * Remove the flag audit rows and log lines about this test's users. The base class removes no logs.
     * It matches by user ID and note text, so it never removes a row of a parallel run.
     */
    private function deleteOwnLogs(): void
    {
        foreach ($this->knownUserIds() as $userId) {
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND (user_id = ? OR lognote LIKE ? OR lognote LIKE ?)',
                [$this->logIdFloor, $userId, "Owner {$userId} %", "%owner {$userId} %"]
            );
        }
    }

    /** @return list<int> */
    private function knownUserIds(): array
    {
        $property = new ReflectionProperty(IntegrationTestCase::class, 'createdUserIds');

        /** @var list<int> */
        return $property->getValue($this);
    }

    private function makePluginTable(): void
    {
        $exists = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plg_sendinblue_messages'"
        )->first()->cnt > 0;
        if ($exists) {
            return;
        }
        $this->db->query(self::PLUGIN_TABLE_SQL);
        $this->assertFalse($this->db->error(), 'Test setup: plugin table: ' . $this->db->errorString());
        self::$madePluginTable = true;
    }

    // --- Fixtures --------------------------------------------------------

    /**
     * A user with a profile whose guard dates are long ago, so no guard fails by accident.
     *
     * @param array<string, mixed> $profile Profile columns to set after creation
     */
    private function makeOwner(array $profile = [], ?string $joinDate = self::LONG_AGO, ?string $email = null): int
    {
        $userId = $this->createTestUser(array_filter([
            'join_date' => $joinDate,
            'email' => $email,
        ], static fn ($v): bool => $v !== null), true);
        $columns = array_merge(['email_address_since' => self::LONG_AGO], $profile);
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($columns)));
        $this->db->query("UPDATE profiles SET {$set} WHERE user_id = ?", [...array_values($columns), $userId]);
        $this->assertFalse($this->db->error(), 'Test setup: profile: ' . $this->db->errorString());

        return $userId;
    }

    private function emailOf(int $userId): string
    {
        return (string) $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first()->email;
    }

    private function seedPluginRow(string $messageId, string $recipient, string $acceptedAt): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'accepted', ?)",
            [$messageId, $recipient, $acceptedAt]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    private function seedSentRow(int $userId, ?int $carId, string $email, string $messageId, string $at): void
    {
        $this->db->query(
            "INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, 'sent', NULL, ?, ?)",
            [$userId, $carId, $email, $messageId, $at]
        );
        $this->assertFalse($this->db->error(), 'Test setup: sent row: ' . $this->db->errorString());
    }

    /** Seed the Clear Block row: the `email_blocked` log row with new = 0 and source = admin. */
    private function seedClearBlockRow(int $ownerId): void
    {
        $this->assertTrue(
            (new DeliverabilityFlagLog())->record(0, $ownerId, DeliverabilityFlagLog::FLAG_BLOCKED, 1, 0, DeliverabilityFlagLog::SOURCE_ADMIN),
            'Test setup: the Clear Block row must be written'
        );
    }

    private function applyEvent(
        int $userId,
        string $event,
        string $messageId,
        string $messageTime,
        string $occurredAt,
        ?string $address = null,
        string $mode = EmailEventApplier::MODE_EVENT,
        ?string $reason = null,
        ?int $carId = null,
        string $source = 'webhook',
        ?string $blockedCause = null,
    ): bool {
        return $this->applier->apply(
            EmailEventMatch::matched($userId, $carId, $messageTime),
            $address ?? $this->emailOf($userId),
            $event,
            $reason,
            $messageId,
            $occurredAt,
            $source,
            $mode,
            $blockedCause
        );
    }

    /** @return list<object> */
    private function eventRows(int $userId, string $event = ''): array
    {
        $sql = "SELECT * FROM er_email_events WHERE user_id = ? AND event != 'sent'";
        $params = [$userId];
        if ($event !== '') {
            $sql .= ' AND event = ?';
            $params[] = $event;
        }

        return $this->db->query($sql . ' ORDER BY id', $params)->results();
    }

    /** @return array{bounced: string, blocked: string, bounced_at: ?string, blocked_at: ?string, bounced_address: ?string, blocked_address: ?string, cause: ?string} */
    private function flags(int $userId): array
    {
        $f = $this->getOwnerEmailFlags($userId);

        return [
            'bounced' => (string) $f['email_bounced'],
            'blocked' => (string) $f['email_blocked'],
            'bounced_at' => $f['email_bounced_at'] === null ? null : (string) $f['email_bounced_at'],
            'blocked_at' => $f['email_blocked_at'] === null ? null : (string) $f['email_blocked_at'],
            'bounced_address' => $f['email_bounced_address'] === null ? null : (string) $f['email_bounced_address'],
            'blocked_address' => $f['email_blocked_address'] === null ? null : (string) $f['email_blocked_address'],
            'cause' => $f['email_blocked_cause'] === null ? null : (string) $f['email_blocked_cause'],
        ];
    }

    private function assertNoFlags(int $userId, string $why): void
    {
        $flags = $this->flags($userId);
        $this->assertSame('0', $flags['bounced'], $why . ' (bounced)');
        $this->assertSame('0', $flags['blocked'], $why . ' (blocked)');
    }

    private function id(string $prefix): string
    {
        return $prefix . '-' . uniqid('', true);
    }

    // --- Transaction and rollback ----------------------------------------

    public function testAFailureInTheProfileStepRollsBackTheEventRowAndTheFlag(): void
    {
        $userId = $this->makeOwner();
        // The audit row insert fails after the profile write. The applier must roll back both writes.
        $failingManager = new CarVerificationManager($this->repo, new DeliverabilityFlagLog(static fn (): int => 0));
        $applier = new EmailEventApplier($this->repo, $failingManager);

        try {
            $applier->apply(
                EmailEventMatch::matched($userId, null, '2026-10-14 09:00:00'),
                $this->emailOf($userId),
                'hard_bounce',
                'fail',
                $this->id('rollback'),
                '2026-10-14 09:05:00',
                'webhook',
                EmailEventApplier::MODE_EVENT
            );
            $this->fail('A CarDatabaseException was expected');
        } catch (CarDatabaseException) {
            // expected: the caller maps it to a retryable code
        }

        $this->assertFalse($this->db->inTransaction(), 'The applier must not leave a transaction open');
        $this->assertSame([], $this->eventRows($userId), 'The event row must be rolled back');
        $this->assertNoFlags($userId, 'The profile write must be rolled back');
    }

    public function testARetryAfterARolledBackFailureIsNewAndSetsTheFlag(): void
    {
        $userId = $this->makeOwner();
        $messageId = $this->id('retry');
        $failing = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo, new DeliverabilityFlagLog(static fn (): int => 0)));
        try {
            $failing->apply(
                EmailEventMatch::matched($userId, null, '2026-10-14 09:00:00'),
                $this->emailOf($userId),
                'hard_bounce',
                null,
                $messageId,
                '2026-10-14 09:05:00',
                'webhook',
                EmailEventApplier::MODE_EVENT
            );
        } catch (CarDatabaseException) {
            // expected
        }

        $isNew = $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:05:00');

        $this->assertTrue($isNew, 'Brevo retries the event, and the retry must count as new');
        $this->assertSame('1', $this->flags($userId)['bounced']);
    }

    // --- Date guards -----------------------------------------------------

    /**
     * @return array<string, array{string, array<string, string>, string|null, string}>
     */
    public static function guardProvider(): array
    {
        return [
            'hard bounce before join_date' => ['hard_bounce', [], '2026-10-10 00:00:00', '2026-10-09 23:59:59'],
            'delivered before join_date' => ['delivered', [], '2026-10-10 00:00:00', '2026-10-09 23:59:59'],
            'spam before join_date' => ['spam', [], '2026-10-10 00:00:00', '2026-10-09 23:59:59'],
            'hard bounce before email_address_since' => ['hard_bounce', ['email_address_since' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
            'spam before email_address_since' => ['spam', ['email_address_since' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
            'hard bounce before email_bounce_cleared_at' => ['hard_bounce', ['email_bounce_cleared_at' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
            'blocked before email_bounce_cleared_at' => ['blocked', ['email_bounce_cleared_at' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
            'invalid_email before email_bounce_cleared_at' => ['invalid_email', ['email_bounce_cleared_at' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
            'soft bounce before email_bounce_cleared_at' => ['soft_bounce', ['email_bounce_cleared_at' => '2026-10-10 00:00:00'], null, '2026-10-09 23:59:59'],
        ];
    }

    /**
     * @param array<string, string> $profile
     */
    #[DataProvider('guardProvider')]
    public function testAMessageBeforeAGuardDateWritesNoRowAndSetsNoFlag(string $event, array $profile, ?string $joinDate, string $messageTime): void
    {
        $userId = $this->makeOwner($profile, $joinDate ?? self::LONG_AGO);

        $isNew = $this->applyEvent($userId, $event, $this->id('guard'), $messageTime, '2026-10-14 09:00:00');

        $this->assertFalse($isNew);
        $this->assertSame([], $this->eventRows($userId), 'A failed guard writes no row');
        $this->assertNoFlags($userId, 'A failed guard sets no flag');
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'at join_date' => ['hard_bounce', ['join' => '2026-10-10 00:00:00']],
            'at email_address_since' => ['hard_bounce', ['email_address_since' => '2026-10-10 00:00:00']],
            'at email_bounce_cleared_at' => ['hard_bounce', ['email_bounce_cleared_at' => '2026-10-10 00:00:00']],
        ];
    }

    /**
     * @param array<string, string> $bound
     */
    #[DataProvider('boundaryProvider')]
    public function testAMessageAtAGuardTimeExactlyPassesTheGuard(string $event, array $bound): void
    {
        $join = $bound['join'] ?? self::LONG_AGO;
        unset($bound['join']);
        $userId = $this->makeOwner($bound, $join);

        $this->assertTrue($this->applyEvent($userId, $event, $this->id('edge'), '2026-10-10 00:00:00', '2026-10-14 09:00:00'));
        $this->assertSame('1', $this->flags($userId)['bounced']);
    }

    public function testNonBounceEventsIgnoreTheBounceClearedGuard(): void
    {
        $userId = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);

        $this->assertTrue($this->applyEvent($userId, 'delivered', $this->id('del'), '2026-10-09 00:00:00', '2026-10-09 00:00:05'));
        $this->assertCount(1, $this->eventRows($userId, 'delivered'));
    }

    public function testSpamAndUnsubscribeOnAMessageBeforeClearBouncedStillRecordAndSetBlockedButAHardBounceDoesNot(): void
    {
        $userId = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);
        $before = '2026-10-09 12:00:00';

        $this->assertTrue($this->applyEvent($userId, 'spam', $this->id('spam'), $before, '2026-10-14 09:00:00'));
        $this->assertSame('1', $this->flags($userId)['blocked'], 'A spam complaint on a message before Clear Bounced must set email_blocked');
        $this->assertSame('spam', $this->flags($userId)['cause']);

        $other = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);
        $this->assertTrue($this->applyEvent($other, 'unsubscribed', $this->id('unsub'), $before, '2026-10-14 09:00:00'));
        $this->assertSame('1', $this->flags($other)['blocked']);
        $this->assertSame('unsubscribed', $this->flags($other)['cause']);

        $bounce = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);
        $this->assertFalse($this->applyEvent($bounce, 'hard_bounce', $this->id('hb'), $before, '2026-10-14 09:00:00'));
        $this->assertSame([], $this->eventRows($bounce));
        $this->assertNoFlags($bounce, 'A hard bounce on a message before Clear Bounced sets no flag');
    }

    public function testAHardBounceForAVerificationMessageSentBeforeClearBouncedSetsNoFlagInStepA(): void
    {
        $userId = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);
        $address = $this->emailOf($userId);
        $messageId = $this->id('stepa-clear');
        $this->seedSentRow($userId, null, $address, $messageId, '2026-10-09 12:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $address);
        $this->assertTrue($match->isMatched(), 'Test setup: step (a) must match');
        $isNew = $this->applier->apply($match, $address, 'hard_bounce', null, $messageId, '2026-10-14 09:00:00', 'webhook', EmailEventApplier::MODE_EVENT);

        $this->assertFalse($isNew);
        $this->assertSame([], $this->eventRows($userId));
        $this->assertNoFlags($userId, 'The bounce guard must hold in step (a)');
    }

    // --- Moved addresses: full match-then-apply flow ----------------------

    /**
     * @return array<string, array{bool}>
     */
    public static function movedAddressProvider(): array
    {
        return [
            'X joined after the message' => [true],
            'X changed address after the message' => [false],
        ];
    }

    #[DataProvider('movedAddressProvider')]
    public function testALateBounceOnTheEarlierMessageOfTheFormerHolderSetsNoFlagOnTheNewHolder(bool $newHolderJoinedAfter): void
    {
        $address = 'moved-' . uniqid() . '@example.com';
        $earlier = $this->id('y-msg');
        $messageTime = '2026-10-10 08:00:00';
        $userY = $this->makeOwner([], self::LONG_AGO, $address);
        $this->seedPluginRow($earlier, $address, $messageTime);
        // The address moves from Y to X after the message.
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', ['y-new-' . uniqid() . '@example.com', $userY]);
        $userX = $newHolderJoinedAfter
            ? $this->makeOwner(['email_address_since' => '2026-10-11 00:00:00'], '2026-10-11 00:00:00', $address)
            : $this->makeOwner(['email_address_since' => '2026-10-11 00:00:00'], self::LONG_AGO, $address);
        // X has a newer plugin row for the same address. Step (b) must not read it.
        $this->seedPluginRow($this->id('x-newer'), $address, '2026-10-11 12:00:00');

        $match = $this->matcher->matchByMessageId($earlier, $address);
        $this->assertSame($userX, $match->userId(), 'Test setup: the current holder is the candidate');
        $isNew = $this->applier->apply($match, $address, 'hard_bounce', null, $earlier, '2026-10-12 00:00:00', 'webhook', EmailEventApplier::MODE_EVENT);

        $this->assertFalse($isNew);
        $this->assertSame([], $this->eventRows($userX), 'No row for X');
        $this->assertNoFlags($userX, 'The late bounce on Y\'s message must not flag X');
        $this->assertNoFlags($userY, 'Y no longer holds the address');
    }

    public function testAnEventForAMessageSentBeforeTheUserChangedAddressSetsNoFlagOnEitherAddress(): void
    {
        $oldAddress = 'old-' . uniqid() . '@example.com';
        $userId = $this->makeOwner([], self::LONG_AGO, $oldAddress);
        $messageId = $this->id('before-change');
        $this->seedSentRow($userId, null, $oldAddress, $messageId, '2026-10-10 08:00:00');
        // The user changes address after the send.
        $newAddress = 'new-' . uniqid() . '@example.com';
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$newAddress, $userId]);
        $this->db->query('UPDATE profiles SET email_address_since = ? WHERE user_id = ?', ['2026-10-11 00:00:00', $userId]);

        // The sent row names the old address. The matcher finds the user for it, and the date guard in
        // the applier must stop the flag. The new address matches no sent row, so it matches no user.
        $match = $this->matcher->matchByMessageId($messageId, $oldAddress);
        $this->assertTrue($match->isMatched(), 'Test setup: the sent row matches the old address');
        $this->assertSame($userId, $match->userId(), 'Test setup: the sent row names the user');
        $this->applier->apply($match, $oldAddress, 'hard_bounce', null, $messageId, '2026-10-12 00:00:00', 'webhook', EmailEventApplier::MODE_EVENT);

        $this->assertFalse(
            $this->matcher->matchByMessageId($messageId, $newAddress)->isMatched(),
            'The new address is not the address of the sent row, so it matches no user'
        );

        $this->assertSame([], $this->eventRows($userId), 'No row for either address');
        $this->assertNoFlags($userId, 'No flag on either address (R6-SR3)');
    }

    // --- id = id ----------------------------------------------------------

    public function testADuplicateEventWithADifferentOccurredAtChangesNoColumnAndIsNotNew(): void
    {
        $userId = $this->makeOwner();
        $messageId = $this->id('dup');

        $this->assertTrue($this->applyEvent($userId, 'delivered', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:00:05', reason: 'first', carId: null));
        $before = $this->eventRows($userId, 'delivered');

        $this->assertFalse($this->applyEvent($userId, 'delivered', $messageId, '2026-10-14 09:00:00', '2026-10-14 11:30:00', reason: 'second', carId: null));

        $after = $this->eventRows($userId, 'delivered');
        $this->assertCount(1, $after);
        $this->assertEquals($before, $after, 'No column of the stored row may change (id, car_id, email, event, reason, brevo_message_id, occurred_at)');
        $this->assertSame('2026-10-14 09:00:05', $after[0]->occurred_at);
        $this->assertSame('first', $after[0]->reason);
    }

    public function testAReplayLeavesTheBouncedAndBlockedTimesUnchanged(): void
    {
        $userId = $this->makeOwner();
        $bounceId = $this->id('replay-b');
        $spamId = $this->id('replay-s');
        $this->applyEvent($userId, 'hard_bounce', $bounceId, '2026-10-14 09:00:00', '2026-10-14 09:01:00');
        $this->applyEvent($userId, 'spam', $spamId, '2026-10-14 09:00:00', '2026-10-14 09:02:00');
        $first = $this->flags($userId);
        $this->assertSame('2026-10-14 09:01:00', $first['bounced_at'], 'Test setup');
        $this->assertSame('2026-10-14 09:02:00', $first['blocked_at'], 'Test setup');

        $this->assertFalse($this->applyEvent($userId, 'hard_bounce', $bounceId, '2026-10-14 09:00:00', '2026-10-14 15:00:00'));
        $this->assertFalse($this->applyEvent($userId, 'spam', $spamId, '2026-10-14 09:00:00', '2026-10-14 15:00:00'));

        $this->assertSame($first, $this->flags($userId), 'A replay must change no flag column');
    }

    public function testAReplayDoesNotSetAFlagThatWasClearedByAPathThatKeepsTheGuardDates(): void
    {
        $userId = $this->makeOwner();
        $messageId = $this->id('replay-cleared');
        $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:01:00');
        $this->assertSame('1', $this->flags($userId)['bounced'], 'Test setup');
        // A path that clears the flag and moves no guard date (here a direct write).
        $this->clearOwnerEmailFlags($userId);

        $isNew = $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:01:00', source: 'reconciliation');

        $this->assertFalse($isNew);
        $this->assertSame('0', $this->flags($userId)['bounced'], 'A replay is not new, so it must not set the flag again');
    }

    public function testAReplayAfterClearBouncedLeavesTheFlagClear(): void
    {
        $userId = $this->makeOwner();
        $messageId = $this->id('replay-clear');
        $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:01:00');
        // Admin Clear Bounced.
        $this->db->query(
            'UPDATE profiles SET email_bounced = 0, email_bounced_address = NULL, email_bounced_at = NULL, email_bounce_cleared_at = ? WHERE user_id = ?',
            ['2026-10-14 10:00:00', $userId]
        );

        // The reconciliation job replays the event with its original message time.
        $isNew = $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:01:00', source: 'reconciliation');

        $this->assertFalse($isNew);
        $this->assertSame('0', $this->flags($userId)['bounced']);
    }

    // --- Missing rows -----------------------------------------------------

    public function testAnEventForAUserIdWithNoUsersRowWritesNoRow(): void
    {
        $missing = 2147000000;
        $this->assertFalse($this->applyEvent($missing, 'hard_bounce', $this->id('nouser'), '2026-10-14 09:00:00', '2026-10-14 09:01:00', 'nobody@example.com'));

        $count = (int) $this->db->query('SELECT COUNT(*) AS n FROM er_email_events WHERE user_id = ?', [$missing])->first()->n;
        $this->assertSame(0, $count);
    }

    public function testAnEventForTheNoownerAccountWritesNoRow(): void
    {
        $noowner = (int) $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first()->id;

        $isNew = $this->applyEvent($noowner, 'hard_bounce', $this->id('noowner'), '2026-10-14 09:00:00', '2026-10-14 09:01:00', 'x@example.com');

        $this->assertFalse($isNew);
        $count = (int) $this->db->query('SELECT COUNT(*) AS n FROM er_email_events WHERE user_id = ?', [$noowner])->first()->n;
        $this->assertSame(0, $count);
    }

    public function testAnEventForAUserWithNoProfilesRowKeepsTheRowSetsNothingAndLogsTheUserId(): void
    {
        $userId = $this->createTestUser(['join_date' => self::LONG_AGO]);
        $messageId = $this->id('noprofile');

        $isNew = $this->applyEvent($userId, 'hard_bounce', $messageId, '2026-10-14 09:00:00', '2026-10-14 09:01:00');

        $this->assertTrue($isNew, 'The event row is kept');
        $this->assertCount(1, $this->eventRows($userId, 'hard_bounce'));
        $profiles = (int) $this->db->query('SELECT COUNT(*) AS n FROM profiles WHERE user_id = ?', [$userId])->first()->n;
        $this->assertSame(0, $profiles, 'No profiles row may be created');
        $this->assertSame(
            1,
            $this->countMatchingLogs('EmailWebhook', "%owner {$userId} %no profiles row%", $userId),
            'The log line names the user ID'
        );
    }

    // --- Clear Block bound -----------------------------------------------

    public function testSpamAndUnsubscribeBeforeTheLatestClearBlockRecordTheEventAndLeaveBlockedClear(): void
    {
        $userId = $this->makeOwner();
        $this->seedClearBlockRow($userId);
        $before = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->assertTrue($this->applyEvent($userId, 'spam', $this->id('cb-spam'), self::LONG_AGO, $before));
        $this->assertTrue($this->applyEvent($userId, 'unsubscribed', $this->id('cb-unsub'), self::LONG_AGO, $before));

        $this->assertCount(1, $this->eventRows($userId, 'spam'), 'The event is recorded');
        $this->assertCount(1, $this->eventRows($userId, 'unsubscribed'));
        $this->assertSame('0', $this->flags($userId)['blocked'], 'email_blocked stays clear');
    }

    public function testASpamEventAfterTheLatestClearBlockSetsBlocked(): void
    {
        $userId = $this->makeOwner();
        $this->seedClearBlockRow($userId);
        $this->applyEvent($userId, 'spam', $this->id('cb-old'), self::LONG_AGO, date('Y-m-d H:i:s', strtotime('-1 day')));
        $this->assertSame('0', $this->flags($userId)['blocked'], 'Test setup');

        $later = date('Y-m-d H:i:s', strtotime('+1 day'));
        $this->assertTrue($this->applyEvent($userId, 'spam', $this->id('cb-new'), self::LONG_AGO, $later));

        $flags = $this->flags($userId);
        $this->assertSame('1', $flags['blocked']);
        $this->assertSame('spam', $flags['cause']);
        $this->assertSame($later, $flags['blocked_at']);
    }

    public function testAClearBlockOfAnotherOwnerDoesNotBoundThisOwner(): void
    {
        $userId = $this->makeOwner();
        $other = $this->makeOwner();
        $this->seedClearBlockRow($other);

        $this->applyEvent($userId, 'spam', $this->id('cb-other'), self::LONG_AGO, date('Y-m-d H:i:s', strtotime('-1 day')));

        $this->assertSame('1', $this->flags($userId)['blocked']);
    }

    public function testAHardBounceIsNotBoundByAClearBlock(): void
    {
        $userId = $this->makeOwner();
        $this->seedClearBlockRow($userId);

        $this->applyEvent($userId, 'hard_bounce', $this->id('cb-hb'), self::LONG_AGO, date('Y-m-d H:i:s', strtotime('-1 day')));

        $this->assertSame('1', $this->flags($userId)['bounced']);
    }

    // --- Reason ----------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function longReasonProvider(): array
    {
        return [
            'ASCII' => [str_repeat('a', 10240)],
            'multibyte' => [str_repeat("\u{00E9}", 10240)],
        ];
    }

    #[DataProvider('longReasonProvider')]
    public function testA10KbReasonIsStoredAs255Characters(string $reason): void
    {
        $userId = $this->makeOwner();

        $this->applyEvent($userId, 'hard_bounce', $this->id('reason'), '2026-10-14 09:00:00', '2026-10-14 09:01:00', reason: $reason);

        $rows = $this->eventRows($userId, 'hard_bounce');
        $this->assertCount(1, $rows);
        $this->assertSame(255, mb_strlen((string) $rows[0]->reason, 'UTF-8'));
        $this->assertSame(mb_substr($reason, 0, 255, 'UTF-8'), $rows[0]->reason);
    }

    public function testInvalidUtf8InTheReasonIsScrubbedToQuestionMarks(): void
    {
        $userId = $this->makeOwner();

        $this->applyEvent($userId, 'hard_bounce', $this->id('utf8'), '2026-10-14 09:00:00', '2026-10-14 09:01:00', reason: "bad \xFF\xFE bytes");

        $rows = $this->eventRows($userId, 'hard_bounce');
        $this->assertCount(1, $rows);
        // The connection runs with sql_mode '' and would cut the text at the first bad byte. mb_scrub keeps the rest.
        $this->assertSame('bad ?? bytes', $rows[0]->reason);
    }

    // --- Step (b) before the sent row exists -------------------------------

    public function testAnEventBeforeItsSentRowExistsIsMatchedByStepBRecordedWithNoCarAndSetsTheFlag(): void
    {
        $userId = $this->makeOwner();
        $address = $this->emailOf($userId);
        $messageId = $this->id('early');
        $this->seedPluginRow($messageId, $address, '2026-10-14 09:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $address);
        $this->assertTrue($this->applier->apply($match, $address, 'hard_bounce', null, $messageId, '2026-10-14 09:00:30', 'webhook', EmailEventApplier::MODE_EVENT));

        $rows = $this->eventRows($userId, 'hard_bounce');
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->car_id, 'A step (b) match has no car');
        $this->assertSame('1', $this->flags($userId)['bounced']);
    }

    public function testAStepAMatchRecordsTheCarOfTheSentRow(): void
    {
        $userId = $this->makeOwner();
        $address = $this->emailOf($userId);
        $carId = $this->createTestCar($userId, ['email' => $address]);
        $messageId = $this->id('with-car');
        $this->seedSentRow($userId, $carId, $address, $messageId, '2026-10-14 09:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $address);
        $this->applier->apply($match, $address, 'delivered', null, $messageId, '2026-10-14 09:00:30', 'webhook', EmailEventApplier::MODE_EVENT);

        $rows = $this->eventRows($userId, 'delivered');
        $this->assertSame($carId, (int) $rows[0]->car_id);
    }

    // --- Flags stored for an old address ----------------------------------

    public function testABounceFlagStoredForAnOldAddressIsReplacedByAnEventForTheCurrentAddress(): void
    {
        $userId = $this->makeOwner();
        $this->setOwnerBounced($userId, 'old-' . uniqid() . '@example.com', '2026-01-01 00:00:00');
        $current = $this->emailOf($userId);

        $this->applyEvent($userId, 'hard_bounce', $this->id('replace-b'), '2026-10-14 09:00:00', '2026-10-14 09:01:00');

        $flags = $this->flags($userId);
        $this->assertSame('1', $flags['bounced']);
        $this->assertSame($current, $flags['bounced_address']);
        $this->assertSame('2026-10-14 09:01:00', $flags['bounced_at']);
    }

    public function testABlockFlagStoredForAnOldAddressIsReplacedByAnEventForTheCurrentAddress(): void
    {
        $userId = $this->makeOwner();
        $this->setOwnerBlocked($userId, 'old-' . uniqid() . '@example.com', '2026-01-01 00:00:00', 'unsubscribed');
        $current = $this->emailOf($userId);

        $this->applyEvent($userId, 'spam', $this->id('replace-s'), '2026-10-14 09:00:00', '2026-10-14 09:01:00');

        $flags = $this->flags($userId);
        $this->assertSame($current, $flags['blocked_address']);
        $this->assertSame('2026-10-14 09:01:00', $flags['blocked_at']);
        $this->assertSame('spam', $flags['cause']);
    }

    public function testAnEventForAnAddressThatIsNotTheCurrentOneRecordsTheRowAndSetsNoFlag(): void
    {
        $userId = $this->makeOwner();

        $isNew = $this->applyEvent($userId, 'hard_bounce', $this->id('stale-addr'), '2026-10-14 09:00:00', '2026-10-14 09:01:00', 'not-current-' . uniqid() . '@example.com');

        $this->assertTrue($isNew);
        $this->assertCount(1, $this->eventRows($userId, 'hard_bounce'));
        $this->assertNoFlags($userId, 'An event for another address must not flag the current one');
    }

    // --- Soft bounces -----------------------------------------------------

    public function testThreeSoftBounceCyclesEscalateAtTheThirdAndNotBefore(): void
    {
        $userId = $this->makeOwner();

        $this->applyEvent($userId, 'soft_bounce', $this->id('soft1'), '2026-10-14 09:00:00', '2026-10-14 09:01:00');
        $this->applyEvent($userId, 'soft_bounce', $this->id('soft2'), '2026-10-14 10:00:00', '2026-10-14 10:01:00');
        $this->assertSame('0', $this->flags($userId)['bounced']);

        $this->applyEvent($userId, 'soft_bounce', $this->id('soft3'), '2026-10-14 11:00:00', '2026-10-14 11:01:00');

        $flags = $this->flags($userId);
        $this->assertSame('1', $flags['bounced']);
        $this->assertSame('2026-10-14 11:01:00', $flags['bounced_at'], 'The time is that of the soft bounce that escalated');
    }

    // --- MODE_STATE -------------------------------------------------------

    public function testStateModeSetsAClearFlagAndSkipsTheJoinAndAddressGuards(): void
    {
        $userId = $this->makeOwner(['email_address_since' => '2026-10-14 00:00:00'], '2026-10-14 00:00:00');

        // Brevo's list is current state. blockedAt (the message time here) may be older than the join date.
        $isNew = $this->applyEvent(
            $userId,
            'spam',
            'import-' . $userId,
            '2026-01-01 00:00:00',
            '2026-10-14 09:00:00',
            mode: EmailEventApplier::MODE_STATE,
            source: 'import'
        );

        $this->assertTrue($isNew);
        $flags = $this->flags($userId);
        $this->assertSame('1', $flags['blocked']);
        $this->assertSame('2026-10-14 09:00:00', $flags['blocked_at']);
    }

    public function testStateModeSetsTheFlagAgainForAnAlreadyRecordedEventWhenTheFlagIsClear(): void
    {
        $userId = $this->makeOwner();
        $this->applyEvent($userId, 'unsubscribed', 'import-' . $userId, self::LONG_AGO, '2026-10-14 09:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import');
        // An admin clears the block. The next import finds the same entry.
        $this->db->query('UPDATE profiles SET email_blocked = 0, email_blocked_address = NULL, email_blocked_at = NULL, email_blocked_cause = NULL WHERE user_id = ?', [$userId]);

        $isNew = $this->applyEvent($userId, 'unsubscribed', 'import-' . $userId, self::LONG_AGO, '2026-10-14 12:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import');

        $this->assertFalse($isNew, 'The event row is a duplicate');
        $this->assertSame('1', $this->flags($userId)['blocked'], 'State mode applies when the flag is clear, even for a duplicate row');
    }

    public function testStateModeChangesNothingWhenTheFlagAlreadyNamesTheSameAddress(): void
    {
        $userId = $this->makeOwner();
        $address = $this->emailOf($userId);
        $this->setOwnerBlocked($userId, strtoupper($address), '2026-10-01 00:00:00', 'spam');
        $before = $this->flags($userId);

        $this->applyEvent($userId, 'unsubscribed', 'import-' . $userId, self::LONG_AGO, '2026-10-14 09:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import');

        $this->assertSame($before, $this->flags($userId), 'A repeat for the same address (any case) changes nothing');
    }

    public function testStateModeReplacesAFlagThatNamesAnotherAddress(): void
    {
        $userId = $this->makeOwner();
        $this->setOwnerBlocked($userId, 'old-' . uniqid() . '@example.com', '2026-10-01 00:00:00', 'spam');

        $this->applyEvent($userId, 'unsubscribed', 'import-' . $userId, self::LONG_AGO, '2026-10-14 09:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import');

        $flags = $this->flags($userId);
        $this->assertSame($this->emailOf($userId), $flags['blocked_address']);
        $this->assertSame('unsubscribed', $flags['cause']);
        $this->assertSame('2026-10-14 09:00:00', $flags['blocked_at']);
    }

    public function testStateModeBlockCauseReplacesTheCauseButTheEventRowKeepsTheEventName(): void
    {
        $userId = $this->makeOwner();

        $this->applyEvent(
            $userId,
            'unsubscribed',
            'import-' . $userId,
            self::LONG_AGO,
            '2026-10-14 09:00:00',
            mode: EmailEventApplier::MODE_STATE,
            source: 'import',
            blockedCause: CarRepository::BLOCKED_CAUSE_BLOCKLIST
        );

        $this->assertSame('blocklist', $this->flags($userId)['cause']);
        $this->assertCount(1, $this->eventRows($userId, 'unsubscribed'));
    }

    public function testStateModeHardBounceUsesBlockedAtForTheClearBouncedGuard(): void
    {
        $userId = $this->makeOwner(['email_bounce_cleared_at' => '2026-10-10 00:00:00']);

        $this->assertFalse($this->applyEvent($userId, 'hard_bounce', 'import-old-' . $userId, self::LONG_AGO, '2026-10-09 00:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import'));
        $this->assertNoFlags($userId, 'An entry older than Clear Bounced sets nothing');

        $this->assertTrue($this->applyEvent($userId, 'hard_bounce', 'import-new-' . $userId, self::LONG_AGO, '2026-10-11 00:00:00', mode: EmailEventApplier::MODE_STATE, source: 'import'));
        $this->assertSame('1', $this->flags($userId)['bounced']);
    }

    public function testStateModeSpamBeforeTheLatestClearBlockLeavesBlockedClear(): void
    {
        $userId = $this->makeOwner();
        $this->seedClearBlockRow($userId);

        $this->applyEvent($userId, 'spam', 'import-' . $userId, self::LONG_AGO, date('Y-m-d H:i:s', strtotime('-1 day')), mode: EmailEventApplier::MODE_STATE, source: 'import');

        $this->assertSame('0', $this->flags($userId)['blocked']);
    }

    // --- Live-DB guard: the affected-row count ----------------------------

    public function testInsertingTheSameEventTwiceThroughTheRealAdapterGivesOneThenZeroAffectedRows(): void
    {
        $userId = $this->makeOwner();
        $messageId = $this->id('rowcount');

        $first = $this->repo->insertOwnerEmailEvent($userId, 'a@example.com', 'delivered', 'r', $messageId, '2026-10-14 09:00:00');
        $second = $this->repo->insertOwnerEmailEvent($userId, 'a@example.com', 'delivered', 'other', $messageId, '2026-10-14 10:00:00');

        $this->assertSame(1, $first, 'A new row counts 1');
        $this->assertSame(0, $second, 'A duplicate counts 0: the connection must not set MYSQL_ATTR_FOUND_ROWS');
    }

    public function testTheConnectionDoesNotSetMysqlAttrFoundRows(): void
    {
        $connection = DB::getInstance();
        $property = new ReflectionProperty($connection, 'opts');
        /** @var array<int, mixed> $opts */
        $opts = $property->getValue($connection);
        $foundRows = (int) constant(defined('Pdo\Mysql::ATTR_FOUND_ROWS') ? 'Pdo\Mysql::ATTR_FOUND_ROWS' : 'PDO::MYSQL_ATTR_FOUND_ROWS');

        $this->assertFalse(
            (bool) ($opts[$foundRows] ?? false),
            'MYSQL_ATTR_FOUND_ROWS makes a duplicate count 1, so every replay would look new'
        );
    }
}
