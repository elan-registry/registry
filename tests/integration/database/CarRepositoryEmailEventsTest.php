<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SendinblueMessagesTable;

/**
 * Real-SQL tests for CarRepository's er_email_events methods; unit tests only
 * stub their return values.
 */
#[Group('integration')]
final class CarRepositoryEmailEventsTest extends IntegrationTestCase
{
    private static bool $madePluginTable = false;

    private CarRepository $repo;
    private int $userId;
    private int $carId;

    /** @var list<string> */
    private array $pluginMessageIds = [];

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

        // The plugin table must exist before the first repository read, because
        // the repository caches its table and column probe per instance.
        if (SendinblueMessagesTable::createIfMissing($this->db)) {
            self::$madePluginTable = true;
        }
        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser([], true);
        $this->carId = $this->createTestCar($this->userId, [
            'email' => 'softbounce-' . uniqid() . '@example.com',
        ]);
        // A new profile has email_address_since = now, and the fixture events are in the past.
        // A soft bounce before that time does not count, so move the time back.
        $this->db->query('UPDATE profiles SET email_address_since = ? WHERE user_id = ?', ['2000-01-01 00:00:00', $this->userId]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->pluginMessageIds as $messageId) {
                $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
            }
            // The noowner tests must write no row. If the code under test is wrong, the row
            // belongs to an account that the base class does not track, so delete it here.
            $noowner = $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first();
            if (is_object($noowner)) {
                $this->deleteEmailEventsForUserIds([(int) $noowner->id]);
            }
        }
        $this->pluginMessageIds = [];
        // The other er_email_events rows go with their users in IntegrationTestCase::tearDown() (#2358).
        parent::tearDown();
    }

    private function carEmail(): string
    {
        $row = $this->db->query('SELECT email FROM cars WHERE id = ?', [$this->carId])->first();
        return (string) $row->email;
    }

    private function insertRawEvent(string $event, string $brevoMessageId, string $occurredAt): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$this->userId, $this->carId, $this->carEmail(), $event, $brevoMessageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    // --- countSoftBouncesSinceLastDelivered() -----------------------------

    #[Group('fast')]
    public function testCountsDistinctMessageIdsNotRawEventRows(): void
    {
        $email = $this->carEmail();

        // Three distinct send cycles...
        $this->insertRawEvent('soft_bounce', 'msg-1', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'msg-2', '2026-01-01 11:00:00');
        $this->insertRawEvent('soft_bounce', 'msg-3', '2026-01-01 12:00:00');
        // ...and a duplicate report of msg-1 (Brevo re-sending the same event).
        // A regression to COUNT(*) instead of COUNT(DISTINCT brevo_message_id)
        // would count this as a 4th cycle and escalate one cycle too early.
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE occurred_at = VALUES(occurred_at)',
            [$this->userId, $this->carId, $email, 'soft_bounce', 'msg-1', '2026-01-01 10:05:00']
        );

        $count = $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email);

        $this->assertSame(3, $count, 'Must count distinct brevo_message_id values, not raw event rows');
    }

    #[Group('fast')]
    public function testOnlyCountsCyclesAfterMostRecentDelivered(): void
    {
        $email = $this->carEmail();

        // Two soft bounces before a delivery...
        $this->insertRawEvent('soft_bounce', 'pre-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'pre-2', '2026-01-01 09:30:00');
        // ...then a successful delivery...
        $this->insertRawEvent('delivered', 'delivered-1', '2026-01-01 10:00:00');
        // ...then two more soft bounces after it.
        $this->insertRawEvent('soft_bounce', 'post-1', '2026-01-01 11:00:00');
        $this->insertRawEvent('soft_bounce', 'post-2', '2026-01-01 11:30:00');

        $count = $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email);

        $this->assertSame(
            2,
            $count,
            'A delivered event must reset the window — pre-delivery soft bounces must not count '
                . 'toward a fresh threshold check'
        );
    }

    #[Group('fast')]
    public function testCountsAllCyclesWhenNoDeliveredEventExistsYet(): void
    {
        $email = $this->carEmail();

        $this->insertRawEvent('soft_bounce', 'nodeliv-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'nodeliv-2', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'nodeliv-3', '2026-01-01 11:00:00');

        $count = $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email);

        $this->assertSame(3, $count, 'With no delivered event on record, all soft-bounce cycles must count');
    }

    #[Group('fast')]
    public function testReturnsZeroForAnEmailWithNoSoftBounceHistory(): void
    {
        $count = $this->repo->countSoftBouncesSinceLastDelivered($this->userId, 'never-bounced-' . uniqid() . '@example.com');

        $this->assertSame(0, $count);
    }

    #[Group('fast')]
    public function testOnlyCountsCyclesAfterTheOwnersBounceClear(): void
    {
        $email = $this->carEmail();

        $this->insertRawEvent('soft_bounce', 'clr-pre-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'clr-pre-2', '2026-01-01 09:30:00');
        $this->insertRawEvent('soft_bounce', 'clr-pre-3', '2026-01-01 09:45:00');
        // The admin clears the bounce at 10:00, with no delivered event after the soft bounces.
        $this->db->query(
            'UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?',
            ['2026-01-01 10:00:00', $this->userId]
        );
        $this->insertRawEvent('soft_bounce', 'clr-post-1', '2026-01-01 11:00:00');

        $this->assertSame(
            1,
            $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email),
            'One new soft bounce after Clear Bounced must count as 1, so it does not reach the escalation threshold'
        );
    }

    #[Group('fast')]
    public function testClearTimeOfAnotherOwnerDoesNotHideSoftBounces(): void
    {
        $email = $this->carEmail();
        $otherOwnerId = $this->createTestUser([], true);
        $this->db->query(
            'UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?',
            ['2026-06-01 00:00:00', $otherOwnerId]
        );
        $this->insertRawEvent('soft_bounce', 'oth-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'oth-2', '2026-01-01 10:00:00');

        $this->assertSame(
            2,
            $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email),
            'Only the car owner\'s own email_bounce_cleared_at limits the count'
        );
    }

    #[Group('fast')]
    public function testCarWithNoProfileCountsAllSoftBounceCycles(): void
    {
        $noProfileOwnerId = $this->createTestUser();
        $email = 'np-' . uniqid() . '@example.com';
        foreach (['np-1' => '2026-01-01 09:00:00', 'np-2' => '2026-01-01 10:00:00'] as $msgId => $at) {
            $this->db->query(
                'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
                 VALUES (?, NULL, ?, ?, NULL, ?, ?)',
                [$noProfileOwnerId, $email, 'soft_bounce', $msgId, $at]
            );
        }

        $this->assertSame(2, $this->repo->countSoftBouncesSinceLastDelivered($noProfileOwnerId, $email));
    }

    #[Group('fast')]
    public function testASoftBounceForAFormerHolderOfTheAddressDoesNotCount(): void
    {
        $email = $this->carEmail();
        $formerHolderId = $this->createTestUser();
        // The same address, kept by an earlier account. Its bounce is not this owner's bounce.
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, ?, NULL, ?, ?)',
            [$formerHolderId, $email, 'soft_bounce', 'former-1', '2026-01-01 09:00:00']
        );
        $this->insertRawEvent('soft_bounce', 'mine-1', '2026-01-01 10:00:00');

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email));
    }

    #[Group('fast')]
    public function testASoftBounceForAnAccentVariantOfTheAddressDoesNotCount(): void
    {
        $address = 'jose-' . uniqid() . '@example.com';
        $accent = 'josé-' . substr($address, 5);
        // The column collation (utf8mb4_unicode_ci) calls these equal. COLLATE utf8mb4_bin must not.
        $this->insertEventWithAddress($accent, 'soft_bounce', 'acc-1', '2026-01-01 09:00:00');
        $this->insertEventWithAddress($address, 'soft_bounce', 'acc-2', '2026-01-01 10:00:00');

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $address));
        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $accent));
    }

    #[Group('fast')]
    public function testTheAddressIsNormalizedBeforeTheCompare(): void
    {
        $address = 'case-' . uniqid() . '@example.com';
        $this->insertEventWithAddress($address, 'soft_bounce', 'norm-1', '2026-01-01 09:00:00');

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, '  ' . strtoupper($address) . ' '));
    }

    #[Group('fast')]
    public function testADeliveredEventForAnotherAddressDoesNotResetTheWindow(): void
    {
        $email = $this->carEmail();
        $this->insertRawEvent('soft_bounce', 'oa-1', '2026-01-01 09:00:00');
        $this->insertEventWithAddress('other-' . uniqid() . '@example.com', 'delivered', 'oa-d', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'oa-2', '2026-01-01 11:00:00');

        $this->assertSame(
            2,
            $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email),
            'A delivery to another address says nothing about this address'
        );
    }

    #[Group('fast')]
    public function testADeliveredEventForTheSameAddressResetsTheWindow(): void
    {
        $email = $this->carEmail();
        $this->insertRawEvent('soft_bounce', 'sa-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('delivered', 'sa-d', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'sa-2', '2026-01-01 11:00:00');

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email));
    }

    #[Group('fast')]
    public function testTheAddressSinceTimeBoundsTheWindow(): void
    {
        $email = $this->carEmail();
        $this->insertRawEvent('soft_bounce', 'since-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'since-2', '2026-01-01 10:00:00');
        $this->db->query(
            'UPDATE profiles SET email_address_since = ? WHERE user_id = ?',
            ['2026-01-01 09:30:00', $this->userId]
        );
        $this->insertRawEvent('soft_bounce', 'since-3', '2026-01-01 11:00:00');

        $this->assertSame(
            2,
            $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email),
            'Only the bounces after email_address_since count'
        );
    }

    #[Group('fast')]
    public function testTheBounceClearTimeBoundsTheWindowEvenWhenItIsLaterThanTheAddressSinceTime(): void
    {
        $email = $this->carEmail();
        $this->insertRawEvent('soft_bounce', 'gr-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'gr-2', '2026-01-01 10:30:00');
        $this->insertRawEvent('soft_bounce', 'gr-3', '2026-01-01 12:00:00');
        $this->db->query(
            'UPDATE profiles SET email_address_since = ?, email_bounce_cleared_at = ? WHERE user_id = ?',
            ['2026-01-01 08:00:00', '2026-01-01 11:00:00', $this->userId]
        );

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email));
    }

    private function insertEventWithAddress(string $address, string $event, string $messageId, string $occurredAt): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, ?, NULL, ?, ?)',
            [$this->userId, $address, $event, $messageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    // --- insertEmailEvent() (sent rows) ---------------------------------------

    #[Group('fast')]
    public function testInsertEmailEventWritesTheOwnerAsUserIdAndNormalizesTheAddress(): void
    {
        $count = $this->repo->insertEmailEvent(
            $this->carId,
            '  Mixed.Case@Example.COM ',
            'sent',
            null,
            'local:norm',
            '2026-01-01 10:00:00'
        );

        $this->assertSame(1, $count);
        $row = $this->db->query(
            'SELECT user_id, car_id, email FROM er_email_events WHERE brevo_message_id = ?',
            ['local:norm']
        )->first();
        $this->assertSame($this->userId, (int) $row->user_id);
        $this->assertSame($this->carId, (int) $row->car_id);
        $this->assertSame('mixed.case@example.com', $row->email);
    }

    #[Group('fast')]
    public function testInsertEmailEventForACarWithoutAnOwnerReturnsZeroAndWritesNothing(): void
    {
        $carId = $this->createTestCar($this->userId, ['email' => 'noowner-car-' . uniqid() . '@example.com']);
        $this->db->query('UPDATE cars SET user_id = NULL WHERE id = ?', [$carId]);

        $count = $this->repo->insertEmailEvent($carId, 'x@example.com', 'sent', null, 'local:noown-null', '2026-01-01 10:00:00');

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->eventCountForMessage('local:noown-null'));
    }

    #[Group('fast')]
    public function testInsertEmailEventForACarOfTheNoownerAccountReturnsZeroAndWritesNothing(): void
    {
        $noownerId = (int) $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first()->id;
        $carId = $this->createTestCar($this->userId, ['email' => 'noowner-car2-' . uniqid() . '@example.com']);
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$noownerId, $carId]);

        $count = $this->repo->insertEmailEvent($carId, 'x@example.com', 'sent', null, 'local:noown-acct', '2026-01-01 10:00:00');

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->eventCountForMessage('local:noown-acct'));
    }

    #[Group('fast')]
    public function testInsertEmailEventForACarThatDoesNotExistReturnsZero(): void
    {
        $count = $this->repo->insertEmailEvent(2147483000, 'x@example.com', 'sent', null, 'local:nocar', '2026-01-01 10:00:00');

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->eventCountForMessage('local:nocar'));
    }

    #[Group('fast')]
    public function testDuplicateKeepsTheFirstOccurredAtWhateverTheArrivalOrder(): void
    {
        $email = $this->carEmail();

        // T2, then T1 (earlier), then T3 (later). A duplicate changes no column (#2359).
        $this->repo->insertEmailEvent($this->carId, $email, 'delivered', null, 'order-1', '2026-01-01 12:00:00');
        $this->assertSame('2026-01-01 12:00:00', $this->occurredAtFor('order-1'));

        $this->repo->insertEmailEvent($this->carId, $email, 'delivered', null, 'order-1', '2026-01-01 10:00:00');
        $this->assertSame('2026-01-01 12:00:00', $this->occurredAtFor('order-1'), 'An earlier time must not replace the first one');

        $this->repo->insertEmailEvent($this->carId, $email, 'delivered', null, 'order-1', '2026-01-01 15:00:00');
        $this->assertSame('2026-01-01 12:00:00', $this->occurredAtFor('order-1'), 'A later time must not move the row later');
        $this->assertSame(1, $this->eventCountForMessage('order-1'));
    }

    #[Group('fast')]
    public function testDuplicateDeliveryChangesNoColumnAndReportsZeroRows(): void
    {
        $email = $this->carEmail();

        $firstCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'hard_bounce',
            'Mailbox full',
            'dup-msg-1',
            '2026-01-02 15:30:00'
        );
        $this->assertSame(1, $firstCount, 'A fresh insert must report rowCount() == 1');

        // Same unique key (a Brevo retry) with a different reason and an earlier time.
        $secondCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'hard_bounce',
            'Mailbox permanently full',
            'dup-msg-1',
            '2026-01-01 10:00:00'
        );
        // The connection sets no MYSQL_ATTR_FOUND_ROWS, so the `id = id` no-op counts 0.
        // With that attribute set, a duplicate would count 1 and look new.
        $this->assertSame(0, $secondCount, 'A duplicate must report rowCount() == 0');

        $rows = $this->db->query(
            'SELECT user_id, car_id, email, event, reason, brevo_message_id, occurred_at FROM er_email_events WHERE user_id = ?',
            [$this->userId]
        )->results();

        $this->assertCount(1, $rows, 'Exactly one row must exist - the unique index must have deduped, not appended');

        $row = $rows[0];
        $this->assertSame($this->userId, (int) $row->user_id);
        $this->assertSame($this->carId, (int) $row->car_id, 'car_id must be unchanged by the update');
        $this->assertSame($email, $row->email, 'email must be unchanged by the update');
        $this->assertSame('hard_bounce', $row->event, 'event must be unchanged by the update');
        $this->assertSame('dup-msg-1', $row->brevo_message_id, 'brevo_message_id must be unchanged by the update');
        $this->assertSame('Mailbox full', $row->reason, "reason must be the first payload's value");
        $this->assertSame('2026-01-02 15:30:00', $row->occurred_at, 'occurred_at must be the first time');
    }

    #[Group('fast')]
    public function testIdenticalDuplicateDeliveryReportsZeroRowsChanged(): void
    {
        $email = $this->carEmail();

        $this->repo->insertEmailEvent($this->carId, $email, 'delivered', null, 'idem-msg-1', '2026-01-01 10:00:00');

        // Byte-for-byte identical second delivery: MySQL's rowCount() is 0
        // for an ON DUPLICATE KEY UPDATE that changes nothing.
        $secondCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'delivered',
            null,
            'idem-msg-1',
            '2026-01-01 10:00:00'
        );

        $this->assertSame(0, $secondCount);
        $this->assertSame(1, $this->eventCountForMessage('idem-msg-1'), '0 is a "nothing changed" signal, not a failure');
    }

    // --- insertOwnerEmailEvent() --------------------------------------------

    #[Group('fast')]
    public function testInsertOwnerEmailEventWritesOneRowWithCarIdNullAndANormalizedAddress(): void
    {
        $count = $this->repo->insertOwnerEmailEvent(
            $this->userId,
            ' Owner.Level@Example.COM',
            'delivered',
            'ok',
            'owner-1',
            '2026-01-01 10:00:00'
        );

        $this->assertSame(1, $count);
        $row = $this->db->query(
            'SELECT user_id, car_id, email, reason FROM er_email_events WHERE brevo_message_id = ?',
            ['owner-1']
        )->first();
        $this->assertSame($this->userId, (int) $row->user_id);
        $this->assertNull($row->car_id, 'An owner-level row has no car');
        $this->assertSame('owner.level@example.com', $row->email);
        $this->assertSame('ok', $row->reason);
    }

    #[Group('fast')]
    public function testInsertOwnerEmailEventKeepsTheFirstOccurredAtInTheT2T1T3Order(): void
    {
        $this->assertSame(1, $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'ord-owner', '2026-01-01 12:00:00'));
        $this->assertSame(0, $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'ord-owner', '2026-01-01 10:00:00'));
        $this->assertSame(0, $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'ord-owner', '2026-01-01 15:00:00'));

        $this->assertSame('2026-01-01 12:00:00', $this->occurredAtFor('ord-owner'));
        $this->assertSame(1, $this->eventCountForMessage('ord-owner'));
    }

    #[Group('fast')]
    public function testInsertOwnerEmailEventForTheNoownerAccountReturnsZeroAndWritesNothing(): void
    {
        $noownerId = (int) $this->db->query("SELECT id FROM users WHERE username = 'noowner'")->first()->id;

        $count = $this->repo->insertOwnerEmailEvent($noownerId, 'x@example.com', 'hard_bounce', null, 'owner-noowner', '2026-01-01 10:00:00');

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->eventCountForMessage('owner-noowner'));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function userIdsThatWriteNoRow(): array
    {
        return [
            'user that does not exist' => [2147483000],
            'zero'                     => [0],
            'negative'                 => [-5],
        ];
    }

    #[DataProvider('userIdsThatWriteNoRow')]
    public function testInsertOwnerEmailEventForAMissingOrInvalidUserReturnsZeroAndWritesNothing(int $userId): void
    {
        $count = $this->repo->insertOwnerEmailEvent($userId, 'x@example.com', 'hard_bounce', null, 'owner-missing', '2026-01-01 10:00:00');

        $this->assertSame(0, $count);
        $this->assertSame(0, $this->eventCountForMessage('owner-missing'));
    }

    #[Group('fast')]
    public function testInsertOwnerEmailEventTwiceForTwoOwnersKeepsTwoRows(): void
    {
        $otherUserId = $this->createTestUser();

        $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'two-owners', '2026-01-01 10:00:00');
        $this->repo->insertOwnerEmailEvent($otherUserId, 'a@example.com', 'delivered', null, 'two-owners', '2026-01-01 10:00:00');

        $this->assertSame(2, $this->eventCountForMessage('two-owners'), 'The unique key is per user');
    }

    // --- deleteEmailEventsForUser() and detachEmailEventsFromCar() --------------

    #[Group('fast')]
    public function testDeleteForUserWithAnInvalidIdIssuesNoDelete(): void
    {
        $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'keep-1', '2026-01-01 10:00:00');

        $this->assertSame(0, $this->repo->deleteEmailEventsForUser(0));
        $this->assertSame(0, $this->repo->deleteEmailEventsForUser(-1));
        $this->assertSame(1, $this->eventCountForMessage('keep-1'));
    }

    #[Group('fast')]
    public function testDeleteForUserRemovesCarRowsAndNullCarRowsOfThatUserOnly(): void
    {
        $otherUserId = $this->createTestUser();
        $this->repo->insertEmailEvent($this->carId, $this->carEmail(), 'sent', null, 'local:del-1', '2026-01-01 10:00:00');
        $this->repo->insertOwnerEmailEvent($this->userId, 'a@example.com', 'delivered', null, 'del-2', '2026-01-01 10:00:00');
        $this->repo->insertOwnerEmailEvent($otherUserId, 'b@example.com', 'delivered', null, 'del-3', '2026-01-01 10:00:00');

        $deleted = $this->repo->deleteEmailEventsForUser($this->userId);

        $this->assertSame(2, $deleted);
        $this->assertSame(0, $this->eventCountForMessage('local:del-1'));
        $this->assertSame(0, $this->eventCountForMessage('del-2'));
        $this->assertSame(1, $this->eventCountForMessage('del-3'), "Another user's row stays");
    }

    #[Group('fast')]
    public function testDetachSetsCarIdNullAndKeepsUserIdAndOtherCars(): void
    {
        $otherCarId = $this->createTestCar($this->userId, ['email' => 'detach-other-' . uniqid() . '@example.com']);
        $this->repo->insertEmailEvent($this->carId, $this->carEmail(), 'sent', null, 'local:det-1', '2026-01-01 10:00:00');
        $this->repo->insertEmailEvent($otherCarId, 'o@example.com', 'sent', null, 'local:det-2', '2026-01-01 10:00:00');

        $changed = $this->repo->detachEmailEventsFromCar($this->carId);

        $this->assertSame(1, $changed);
        $detached = $this->db->query('SELECT user_id, car_id FROM er_email_events WHERE brevo_message_id = ?', ['local:det-1'])->first();
        $this->assertNull($detached->car_id);
        $this->assertSame($this->userId, (int) $detached->user_id);
        $other = $this->db->query('SELECT car_id FROM er_email_events WHERE brevo_message_id = ?', ['local:det-2'])->first();
        $this->assertSame($otherCarId, (int) $other->car_id);
    }

    // --- findLatestEmailEventsByCarIds() ------------------------------------

    #[Group('fast')]
    public function testReturnsOnlyTheMaxDatedRowForACarWithMultipleEvents(): void
    {
        $this->insertRawEvent('soft_bounce', 'multi-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('hard_bounce', 'multi-2', '2026-01-02 10:00:00');
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->userId, $this->carId, $this->carEmail(), 'blocked', 'Mailbox full', 'multi-3', '2026-01-03 11:00:00']
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());

        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId]);

        $this->assertArrayHasKey($this->carId, $latest, 'The requested car id must be present as a key');
        $row = $latest[$this->carId];
        $this->assertSame('blocked', $row->event, 'Must return the row with the max occurred_at, not the first/last inserted');
        $this->assertSame('2026-01-03 11:00:00', $row->occurred_at);
        $this->assertSame('Mailbox full', $row->reason);
    }

    #[Group('fast')]
    public function testEachCarReturnsItsOwnLatestEventNotTheOtherCarsEvent(): void
    {
        $otherCarId = $this->createTestCar($this->userId, [
            'email' => 'other-latest-' . uniqid() . '@example.com',
        ]);
        $otherEmail = (string) $this->db->query('SELECT email FROM cars WHERE id = ?', [$otherCarId])->first()->email;

        $this->insertRawEvent('delivered', 'car-a-old', '2026-01-01 08:00:00');
        $this->insertRawEvent('hard_bounce', 'car-a-new', '2026-01-05 08:00:00');

        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$this->userId, $otherCarId, $otherEmail, 'delivered', 'car-b-old', '2026-01-02 08:00:00']
        );
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$this->userId, $otherCarId, $otherEmail, 'soft_bounce', 'car-b-new', '2026-01-05 08:00:00']
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());

        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId, $otherCarId]);

        $this->assertArrayHasKey($this->carId, $latest);
        $this->assertArrayHasKey($otherCarId, $latest);
        $this->assertSame('hard_bounce', $latest[$this->carId]->event, "Car A must get its own latest event, not car B's");
        $this->assertSame('soft_bounce', $latest[$otherCarId]->event, "Car B must get its own latest event, not car A's");
    }

    #[Group('fast')]
    public function testCarIdWithNoEventsIsAbsentFromTheResultNotNull(): void
    {
        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId]);

        $this->assertArrayNotHasKey(
            $this->carId,
            $latest,
            'A car with no email events must be absent from the map, not present with a null value'
        );
    }

    #[Group('fast')]
    public function testEmptyCarIdsArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->repo->findLatestEmailEventsByCarIds([]));
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidCarIdsProvider(): array
    {
        return [
            'non-int element (string)' => [[1, 'two']],
            'negative element'         => [[1, -1]],
            'zero element'             => [[1, 0]],
            'string-numeric element'   => [[1, '2']],
        ];
    }

    /**
     * @param array<mixed> $carIds
     */
    #[DataProvider('invalidCarIdsProvider')]
    public function testFindLatestThrowsForAWrongTypedOrOutOfRangeElement(array $carIds): void
    {
        $this->expectException(\ElanRegistry\Exceptions\CarValidationException::class);

        $this->repo->findLatestEmailEventsByCarIds($carIds);
    }

    // --- Owner contact soft bounces (#2460) --------------------------------

    /** Insert a plugin message row. A null $tags value stores SQL NULL. */
    private function seedPluginRow(string $messageId, ?string $tags): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages
                (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', ?, 'accepted', '2026-01-01 08:00:00')",
            [$messageId, $this->carEmail(), $tags]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    /**
     * Seed one soft-bounce event for each tag value. A null entry seeds the
     * event with a plugin row that has NULL tags.
     *
     * @param list<?string> $tagsPerMessage
     * @return list<string> The message IDs
     */
    private function seedSoftBouncesWithTags(array $tagsPerMessage): array
    {
        $ids = [];
        foreach ($tagsPerMessage as $i => $tags) {
            $id = 'tag-' . uniqid('', true) . '-' . $i;
            $this->seedPluginRow($id, $tags);
            $this->insertRawEvent('soft_bounce', $id, sprintf('2026-01-01 %02d:00:00', 10 + $i));
            $ids[] = $id;
        }
        $this->assertNotEmpty($ids, 'Test setup: no soft bounce was seeded');

        return $ids;
    }

    private function softBounceCount(): int
    {
        return $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $this->carEmail());
    }

    /** Fails without the #2460 change: the count has no owner-contact filter. */
    #[Group('fast')]
    public function testOwnerContactSoftBouncesAreNotCounted(): void
    {
        $ids = $this->seedSoftBouncesWithTags(['send-owner-email', 'send-owner-email', 'send-owner-email']);

        $this->assertSame(0, $this->softBounceCount());
        foreach ($ids as $id) {
            $this->assertSame(1, $this->eventCountForMessage($id), 'The event row is still recorded');
        }
    }

    /** Passes without the #2460 change: verification mail has always counted, and the filter must not change that. */
    #[Group('fast')]
    public function testVerificationSoftBouncesStillCount(): void
    {
        $this->seedSoftBouncesWithTags(['car_verification', 'car_verification', 'car_verification']);

        $this->assertSame(3, $this->softBounceCount());
    }

    /** Fails without the #2460 change: the two owner contact cycles count. */
    #[Group('fast')]
    public function testMixedTwoOwnerContactAndOneVerificationCountsOne(): void
    {
        $this->seedSoftBouncesWithTags(['send-owner-email', 'car_verification', 'send-owner-email']);

        $this->assertSame(1, $this->softBounceCount());
    }

    /** Fails without the #2460 change: the tagged cycle counts. */
    #[Group('fast')]
    public function testTagListWithOwnerContactAmongOthersIsExcluded(): void
    {
        $this->seedSoftBouncesWithTags(['foo,send-owner-email']);

        $this->assertSame(0, $this->softBounceCount());
    }

    /** Passes without the #2460 change: it guards that FIND_IN_SET matches a whole tag, not a prefix. */
    #[Group('fast')]
    public function testSimilarTagIsNotExcluded(): void
    {
        $this->seedSoftBouncesWithTags(['send-owner-email-x']);

        $this->assertSame(1, $this->softBounceCount());
    }

    /** Passes without the #2460 change: with no plugin row the cycle counted before and still counts. */
    #[Group('fast')]
    public function testSoftBounceWithNoPluginRowStillCounts(): void
    {
        $this->insertRawEvent('soft_bounce', 'noplugin-' . uniqid('', true), '2026-01-01 10:00:00');

        $this->assertSame(1, $this->softBounceCount());
    }

    /** Passes without the #2460 change: NULL tags mean untagged mail, which counted before and still counts. */
    #[Group('fast')]
    public function testSoftBounceWithNullTagsStillCounts(): void
    {
        $this->seedSoftBouncesWithTags([null]);

        $this->assertSame(1, $this->softBounceCount());
    }

    /**
     * The message ID columns have different collations. The compare must run
     * without MySQL error 1267 and give the expected count. The count throws
     * on a SQL error, so a collation failure fails this test.
     * Fails without the #2460 change: the owner-contact cycle counts.
     */
    #[Group('fast')]
    public function testMessageIdCompareRunsWithoutCollationError(): void
    {
        $this->seedSoftBouncesWithTags(['send-owner-email', 'car_verification']);

        $this->assertSame(1, $this->softBounceCount());
    }

    /**
     * A plugin table without a `tags` column gets the original query. The
     * test changes the table definition (DDL commits, so no transaction
     * wraps it) and restores the column in `finally`. It runs only on a
     * table that this class created, because the drop loses the tag data
     * of a real plugin table.
     *
     * Passes without the #2460 change: the original query is the fallback.
     */
    public function testPluginTableWithoutTagsColumnFallsBackToOldCount(): void
    {
        if (!self::$madePluginTable) {
            $this->markTestSkipped('plg_sendinblue_messages was not created by this test class; the DDL would drop real tag data.');
        }

        $this->seedSoftBouncesWithTags([null, null]);

        $this->db->query('ALTER TABLE plg_sendinblue_messages DROP COLUMN tags');
        $this->assertFalse($this->db->error(), 'Test setup: drop column: ' . $this->db->errorString());
        try {
            // A new instance, because the probe result is cached per instance.
            $repo = new CarRepository($this->db);
            $this->assertSame(2, $repo->countSoftBouncesSinceLastDelivered($this->userId, $this->carEmail()));
        } finally {
            $this->db->query('ALTER TABLE plg_sendinblue_messages ADD COLUMN tags VARCHAR(255) NULL AFTER kind');
            $this->assertFalse($this->db->error(), 'Test cleanup: restore column: ' . $this->db->errorString());
        }
    }

    private function occurredAtFor(string $messageId): string
    {
        $row = $this->db->query('SELECT occurred_at FROM er_email_events WHERE brevo_message_id = ?', [$messageId])->first();
        $this->assertIsObject($row, "No row for message {$messageId}");

        return (string) $row->occurred_at;
    }

    private function eventCountForMessage(string $messageId): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE brevo_message_id = ?',
            [$messageId]
        )->first()->cnt;
    }
}
