<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Email\DeliveryLogFilter;
use ElanRegistry\Email\DeliveryLogReport;
use ElanRegistry\Email\DeliveryLogRepository;
use ElanRegistry\Email\DeliveryOutcome;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\SendinblueMessagesTable;

/**
 * #2102: DeliveryLogRepository reads plg_sendinblue_messages and er_email_events
 * on a real database.
 *
 * The test schema may not have plg_sendinblue_messages. setUp() makes it with
 * the plugin's columns and tearDown() drops it only when this test made it.
 * Each seeded mail and event uses a message ID that holds a per-test token, so
 * rows that already exist in the table cannot change a result: the assertions
 * read only the rows of this test.
 *
 * The cutoff is fixed (2026-11-01 00:00:00), and the seeded `accepted_at` values
 * are fixed around it.
 */
#[Group('integration')]
#[Group('admin')]
final class DeliveryLogRepositoryIntegrationTest extends IntegrationTestCase
{
    private const CUTOFF = '2026-11-01 00:00:00';

    private bool $madeTable = false;

    private string $token = '';

    /** @var list<int> */
    private array $userIds = [];

    private DeliveryLogRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->token = bin2hex(random_bytes(6));
        $this->userIds = [];
        $this->madeTable = SendinblueMessagesTable::createIfMissing($this->db);
        $this->repo = new DeliveryLogRepository($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $like = '%' . $this->token . '%';
            $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id LIKE ?', [$like]);
            $this->db->query('DELETE FROM er_email_events WHERE brevo_message_id LIKE ?', [$like]);
            $this->deleteEmailEventsForUserIds($this->userIds);
            if ($this->madeTable) {
                $this->db->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            }
        }
        parent::tearDown();
    }

    private function id(string $name): string
    {
        return '<' . $name . '-' . $this->token . '@brevo>';
    }

    private function recipient(string $name): string
    {
        return $name . '-' . $this->token . '@example.com';
    }

    /** @return array{int, int} User ID and car ID */
    private function seedUserAndCar(): array
    {
        $userId = $this->createTestUser([], true);
        $this->userIds[] = $userId;
        // The base class tracks the car and deletes it in tearDown.
        $carId = $this->createTestCar($userId);

        return [$userId, $carId];
    }

    private function seedMail(string $messageId, string $recipient, string $acceptedAt, ?string $tags = null): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages
                (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', ?, 'accepted', ?)",
            [$messageId, $recipient, $tags, $acceptedAt]
        );
        $this->assertFalse($this->db->error(), 'Test setup: mail: ' . $this->db->errorString());
    }

    private function seedEvent(
        int $userId,
        ?int $carId,
        string $email,
        string $event,
        string $messageId,
        string $at,
        ?string $reason = null
    ): void {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $carId, $email, $event, $reason, $messageId, $at]
        );
        $this->assertFalse($this->db->error(), 'Test setup: event: ' . $this->db->errorString());
    }

    /**
     * Seed the mail and events of the test.
     *
     * @return array<string, int> The user and car IDs by name
     */
    private function seedScenario(): array
    {
        [$u1, $c1] = $this->seedUserAndCar();
        [$u2, $c2] = $this->seedUserAndCar();
        [$u3, $c3] = $this->seedUserAndCar();

        // id('bounce'): sent, delivered, then a later hard bounce.
        $this->seedMail($this->id('bounce'), $this->recipient('r1'), '2026-11-02 08:00:00', 'verification');
        $this->seedEvent($u1, $c1, $this->recipient('r1'), 'sent', $this->id('bounce'), '2026-11-02 08:00:01');
        $this->seedEvent($u1, $c1, $this->recipient('r1'), 'delivered', $this->id('bounce'), '2026-11-02 08:01:00');
        $this->seedEvent($u1, $c1, $this->recipient('r1'), 'hard_bounce', $this->id('bounce'), '2026-11-02 09:00:00', 'mailbox full');

        // id('sentonly'): only a `sent` row, so no outcome.
        $this->seedMail($this->id('sentonly'), $this->recipient('r2'), '2026-11-02 08:10:00', 'welcome');
        $this->seedEvent($u2, $c2, $this->recipient('r2'), 'sent', $this->id('sentonly'), '2026-11-02 08:10:01');

        // id('elsewhere'): an event of ANOTHER user, on ANOTHER message, to
        // the same address as id('bounce'). It must not attach to id('bounce').
        $this->seedEvent($u3, $c3, $this->recipient('r1'), 'delivered', $this->id('elsewhere'), '2026-11-02 12:00:00');

        // id('delivered'): tags with two values.
        $this->seedMail($this->id('delivered'), $this->recipient('r3'), '2026-11-03 08:00:00', 'welcome,verification');
        $this->seedEvent($u2, $c2, $this->recipient('r3'), 'delivered', $this->id('delivered'), '2026-11-03 08:00:30');

        // Window edges: at the cutoff is in, one second before is out.
        $this->seedMail($this->id('edge'), $this->recipient('r4'), self::CUTOFF, null);
        $this->seedMail($this->id('old'), $this->recipient('r5'), '2026-10-31 23:59:59', 'verification');
        $this->seedEvent($u3, $c3, $this->recipient('r5'), 'delivered', $this->id('old'), '2026-10-31 23:59:59');

        // Message IDs are case-sensitive: an event whose ID differs only in
        // case does not attach.
        $this->seedMail('<Case-' . $this->token . '@brevo>', $this->recipient('r6'), '2026-11-04 08:00:00', null);
        $this->seedEvent($u3, $c3, $this->recipient('r6'), 'delivered', '<case-' . $this->token . '@brevo>', '2026-11-04 08:00:10');

        return ['u1' => $u1, 'c1' => $c1, 'u2' => $u2, 'c2' => $c2, 'u3' => $u3, 'c3' => $c3];
    }

    /**
     * Load the window and group the rows of this test by message ID.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadMine(?string $recipient = null, ?string $tag = null): array
    {
        $rows = $this->repo->loadWindow(new DateTimeImmutable(self::CUTOFF), $recipient, $tag);
        $mine = [];
        foreach ($rows as $row) {
            if (str_contains((string) $row['message_id'], $this->token)) {
                $mine[(string) $row['message_id']][] = $row;
            }
        }

        return $mine;
    }

    /** @return array<string, \ElanRegistry\Email\DeliveryLogEntry> by message ID */
    private function entriesFor(?string $recipient = null, ?string $tag = null): array
    {
        $rows = array_merge(...array_values($this->loadMine($recipient, $tag)) ?: [[]]);
        $report = DeliveryLogReport::build(
            $rows,
            DeliveryLogFilter::fromInput(null, null, 'all', null, null),
            new DateTimeImmutable('2026-11-05 00:00:00')
        );
        $byId = [];
        foreach ($report->entries as $entry) {
            $byId[$entry->messageId] = $entry;
        }

        return $byId;
    }

    public function testTablesReadyAndTagsColumnAreTrueWhenThePluginTableExists(): void
    {
        $this->assertTrue($this->repo->tablesReady());
        $this->assertTrue($this->repo->hasTagsColumn());
    }

    public function testLoadWindowLaterHardBounceWinsOverEarlierDeliveredAndAttachesUserAndCar(): void
    {
        $ids = $this->seedScenario();

        $entry = $this->entriesFor()[$this->id('bounce')] ?? null;

        $this->assertNotNull($entry, 'The bounced mail is in the window');
        $this->assertSame(DeliveryOutcome::HardBounce, $entry->outcome);
        $this->assertSame('mailbox full', $entry->reason);
        $this->assertSame('2026-11-02 09:00:00', $entry->outcomeAt?->format('Y-m-d H:i:s'));
        $this->assertSame($ids['u1'], $entry->userId);
        $this->assertSame($ids['c1'], $entry->carId);
        $this->assertSame($this->recipient('r1'), $entry->recipient);
    }

    public function testLoadWindowSentOnlyMailHasNoOutcomeAndNoUserOrCar(): void
    {
        $this->seedScenario();

        $entry = $this->entriesFor()[$this->id('sentonly')] ?? null;

        $this->assertNotNull($entry);
        $this->assertSame(DeliveryOutcome::None, $entry->outcome);
        $this->assertNull($entry->userId);
        $this->assertNull($entry->carId);
        $this->assertNull($entry->outcomeAt);
        $rows = $this->loadMine()[$this->id('sentonly')];
        $this->assertCount(1, $rows, 'The sent row is left out of the join');
        $this->assertNull($rows[0]['event_id']);
    }

    /** #2611: an event stored with no owner (user_id 0) gives the outcome of the mail, with no owner or car. */
    public function testLoadWindowEventWithNoOwnerGivesTheOutcomeWithANullOwnerAndCar(): void
    {
        $this->seedScenario();
        $messageId = $this->id('noowner');
        $recipient = $this->recipient('r-noowner');
        $this->seedMail($messageId, $recipient, '2026-11-02 08:20:00', 'verification');
        $written = (new CarRepository($this->db))->insertUnmatchedEmailEvent($recipient, 'delivered', null, $messageId, '2026-11-02 08:21:00');
        $this->assertSame(1, $written, 'Test setup: the row with no owner must be written');

        $entry = $this->entriesFor()[$messageId] ?? null;

        $this->assertNotNull($entry, 'The mail is in the window');
        $this->assertSame(DeliveryOutcome::Delivered, $entry->outcome);
        $this->assertSame('2026-11-02 08:21:00', $entry->outcomeAt?->format('Y-m-d H:i:s'));
        $this->assertNull($entry->userId, 'user_id 0 is no owner');
        $this->assertNull($entry->carId);
        $this->assertSame($recipient, $entry->recipient);
    }

    public function testLoadWindowEventOfAnotherUserOnAnotherMessageDoesNotAttachByAddress(): void
    {
        $ids = $this->seedScenario();

        $rows = $this->loadMine()[$this->id('bounce')];

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNotSame($ids['u3'], (int) $row['user_id'], 'The event of user 3 is not on this mail');
            $this->assertNotSame($this->id('elsewhere'), (string) $row['message_id']);
        }
        $this->assertArrayNotHasKey($this->id('elsewhere'), $this->loadMine(), 'The other message has no mail row');
    }

    public function testLoadWindowMessageIdMatchIsCaseSensitive(): void
    {
        $this->seedScenario();

        $entry = $this->entriesFor()['<Case-' . $this->token . '@brevo>'] ?? null;

        $this->assertNotNull($entry);
        $this->assertSame(DeliveryOutcome::None, $entry->outcome, 'An ID that differs in case is another message');
    }

    public function testLoadWindowIncludesTheCutoffInstantAndExcludesEarlierMail(): void
    {
        $this->seedScenario();

        $mine = $this->loadMine();

        $this->assertArrayHasKey($this->id('edge'), $mine, 'accepted_at equal to the cutoff is included');
        $this->assertArrayNotHasKey($this->id('old'), $mine, 'accepted_at before the cutoff is excluded');
    }

    public function testLoadWindowTagFilterMatchesOneTagInACommaListAndNotASubstring(): void
    {
        $this->seedScenario();

        $verification = array_keys($this->loadMine(null, 'verification'));
        sort($verification);
        $expected = [$this->id('bounce'), $this->id('delivered')];
        sort($expected);
        $this->assertSame($expected, $verification, 'Single and listed tags match; the out-of-window mail does not');

        $welcome = array_keys($this->loadMine(null, 'welcome'));
        sort($welcome);
        $expectedWelcome = [$this->id('delivered'), $this->id('sentonly')];
        sort($expectedWelcome);
        $this->assertSame($expectedWelcome, $welcome);
        $this->assertSame([], array_keys($this->loadMine(null, 'verif')), 'A part of a tag does not match');
    }

    public function testLoadWindowRecipientFilterReturnsOnlyThatAddress(): void
    {
        $this->seedScenario();

        $mine = $this->loadMine($this->recipient('r1'));

        $this->assertSame([$this->id('bounce')], array_keys($mine));
        foreach ($mine[$this->id('bounce')] as $row) {
            $this->assertSame($this->recipient('r1'), $row['recipient_email']);
        }
    }

    public function testLoadWindowRecipientAndTagFiltersMustBothMatch(): void
    {
        $this->seedScenario();

        // r1 has only the verification-tagged bounce. OR would also return
        // the delivered mail (verification) or the welcome-tagged mail.
        $this->assertSame([$this->id('bounce')], array_keys($this->loadMine($this->recipient('r1'), 'verification')));
        $this->assertSame([], array_keys($this->loadMine($this->recipient('r1'), 'welcome')));
    }

    public function testLoadWindowWritesNothingToThePluginTable(): void
    {
        $this->seedScenario();
        $before = $this->tableState();

        $this->repo->loadWindow(new DateTimeImmutable(self::CUTOFF), null, null);
        $this->repo->loadWindow(new DateTimeImmutable(self::CUTOFF), $this->recipient('r1'), 'verification');
        $this->repo->tablesReady();
        $this->repo->hasTagsColumn();

        $this->assertSame($before, $this->tableState());
        $this->assertGreaterThan(0, $before['rows'], 'Test setup: the table holds the seeded mail');
    }

    /** @return array{rows: int, checksum: string} */
    private function tableState(): array
    {
        $rows = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages')->first()->cnt;
        $checksum = $this->db->query('CHECKSUM TABLE plg_sendinblue_messages')->first();
        $this->assertFalse($this->db->error(), $this->db->errorString());
        $this->assertTrue(isset($checksum->Checksum), 'CHECKSUM TABLE returned a checksum');

        return ['rows' => $rows, 'checksum' => (string) $checksum->Checksum];
    }
}
