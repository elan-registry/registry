<?php

declare(strict_types=1);

require_once __DIR__ . '/../../IntegrationTestCase.php';

use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Car\ProcessingResult;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2356: a Brevo event finds its owner through `users.email`.
 *
 * The verification send mails `users.email`. `cars.email` is only a copy and
 * can be stale. These tests run the webhook processor with the real
 * repository, so the match SQL and the flag writes run for real. Since #2359
 * the event must carry the message ID of a plugin row for the address
 * (step (b) of EmailEventMatcher), so each event seeds one. The test schema
 * has no `plg_sendinblue_messages`, so the class makes the plugin's table
 * when it is missing and drops it at the end only if the class made it.
 *
 * @see https://github.com/elan-registry/registry/issues/2356
 */
#[Group('integration')]
#[Group('car-verification')]
final class BrevoEventOwnerEmailMatchTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private BrevoWebhookEventProcessor $processor;

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

    /** @var list<string> Plugin rows that this test wrote, for tearDown(). */
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
        $this->makePluginTable();
        $this->repo = new CarRepository($this->db);
        $applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));
        $this->processor = new BrevoWebhookEventProcessor(new EmailEventMatcher($this->repo), $applier);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->pluginMessageIds as $messageId) {
                $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
            }
        }
        $this->pluginMessageIds = [];

        parent::tearDown();
    }

    /** Event rows of a user, whatever their car_id (#2358: events belong to the user). */
    private function eventRowCount(int $userId): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS n FROM er_email_events WHERE user_id = ?', [$userId])->first()->n;
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

    /**
     * A hard bounce whose message ID has a plugin row for the trimmed address
     *
     * @return array<string, mixed>
     */
    private function hardBounce(string $email): array
    {
        $messageId = 'owner-match-' . uniqid();
        // One second after the later of the PHP and SQL clocks, so the
        // message is not older than the user's join_date or email_address_since.
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'accepted', DATE_ADD(GREATEST(NOW(), ?), INTERVAL 1 SECOND))",
            [$messageId, trim($email), date('Y-m-d H:i:s')]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;

        return [
            'email' => $email,
            'event' => 'hard_bounce',
            'message-id' => $messageId,
        ];
    }

    /** An event for users.email matches the owner's car, also when the car holds a stale address. */
    public function testEventForUsersEmailMatchesTheOwnerWhoseCarHoldsAStaleCopy(): void
    {
        $current = 'current-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => $current], true);
        $carId = $this->createTestCar($ownerId, ['email' => 'stale-' . uniqid() . '@example.com']);

        $result = $this->processor->process($this->hardBounce('  ' . strtoupper($current) . ' '));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $flags = $this->getOwnerEmailFlags($ownerId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'The bounce lands on the owner who holds the address');
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->bounced, 'The bounce applies to users.email');
        $this->assertSame(1, $this->eventRowCount($ownerId), 'One owner-level event row');
    }

    /** An event for an old cars.email copy matches no user, so nothing is flagged. */
    public function testEventForAnOldCarEmailNoLongerMatches(): void
    {
        $old = 'old-' . uniqid() . '@example.com';
        $ownerId = $this->createTestUser(['email' => 'new-' . uniqid() . '@example.com'], true);
        $carId = $this->createTestCar($ownerId, ['email' => $old]);

        $result = $this->processor->process($this->hardBounce($old));

        $this->assertSame(ProcessingResult::NO_USER_MATCH, $result);
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($ownerId)['email_bounced']);
        $this->assertSame(
            0,
            $this->eventRowCount($ownerId),
            'An unmatched event writes no event row'
        );
    }

    /**
     * Two owners have cars that share one cars.email value, but only one
     * owner holds it as users.email. The event flags that owner only.
     */
    public function testSharedCarEmailGivesNoCrossOwnerFlag(): void
    {
        $shared = 'shared-' . uniqid() . '@example.com';
        $holderId = $this->createTestUser(['email' => $shared], true);
        $holderCarId = $this->createTestCar($holderId, ['email' => $shared]);
        $otherId = $this->createTestUser(['email' => 'other-' . uniqid() . '@example.com'], true);
        $otherCarId = $this->createTestCar($otherId, ['email' => $shared]);

        $result = $this->processor->process($this->hardBounce($shared));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($holderId)['email_bounced']);
        $this->assertSame(
            '0',
            (string) $this->getOwnerEmailFlags($otherId)['email_bounced'],
            'The other owner only shares a stale car copy and must not get the flag'
        );
        $this->assertSame(0, $this->eventRowCount($otherId), 'The other owner gets no event row');
        // The control: the event was recorded, once, for the holder. Without it a count of 0 could mean a dead query.
        $this->assertSame(1, $this->eventRowCount($holderId), 'The holder gets exactly one event row');
    }

    /** The noowner system account never takes an event, also when its users.email is the event address. */
    public function testNoownerAccountHoldingTheAddressIsNotMatched(): void
    {
        $noOwnerRow = $this->db->query('SELECT id, email FROM users WHERE username = ?', ['noowner'])->first();
        $this->assertIsObject($noOwnerRow, 'noowner system account missing. Run composer migrate (RegisterNoownerAccount).');
        $noOwnerId = (int) $noOwnerRow->id;
        $address = 'noowner-' . uniqid() . '@example.com';
        $flagsBefore = $this->profileFlagRow($noOwnerId);
        $carId = $this->createTestCar($noOwnerId, ['email' => $address]);

        // The shared test schema keeps the seeded account, so its email must go back.
        $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$address, $noOwnerId]);
        try {
            $this->assertSame(ProcessingResult::NO_USER_MATCH, $this->processor->process($this->hardBounce($address)));
        } finally {
            $this->db->query('UPDATE users SET email = ? WHERE id = ?', [$noOwnerRow->email, $noOwnerId]);
        }

        $this->assertSame($flagsBefore, $this->profileFlagRow($noOwnerId), 'The noowner profile must not get a flag');
        $this->assertSame(0, $this->eventRowCount($noOwnerId), 'No event row for the noowner account');
    }

    /** A stored users.email with case and white space (a tab too) still matches the plain event address. */
    public function testStoredUsersEmailIsNormalizedForTheMatch(): void
    {
        $local = 'owner-' . uniqid();
        $ownerId = $this->createTestUser(['email' => $local . '@example.com'], true);
        $this->setUserEmailRaw($ownerId, '  ' . ucfirst($local) . "@Example.COM\t");
        $carId = $this->createTestCar($ownerId);

        $result = $this->processor->process($this->hardBounce($local . '@example.com'));

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->bounced, 'The bounce applies to the stored address');
    }

    /**
     * PHP trim() keeps \f and a no-break space, but the SQL wrapper removes
     * them. Such an event address must not match an owner whose email is empty.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blankAfterSqlNormalizeProvider')]
    public function testAnAddressThatNormalizesToEmptyMatchesNoOwner(string $address): void
    {
        $ownerId = $this->createTestUser(['email' => 'empty-' . uniqid() . '@example.com'], true);
        $this->setUserEmailRaw($ownerId, '');
        $carId = $this->createTestCar($ownerId);

        $this->assertNotSame('', trim($address), 'Test setup: PHP trim() keeps this address');
        // The address check rejects it before any match (#2359).
        $this->assertSame(ProcessingResult::MALFORMED, $this->processor->process($this->hardBounce($address)));
    }

    /** @return array<string, array{string}> */
    public static function blankAfterSqlNormalizeProvider(): array
    {
        return [
            'form feed'     => ["\f"],
            'no-break space' => ["\u{00A0}"],
        ];
    }

    /**
     * The flag columns of a profile row, or null when the user has none.
     *
     * @return array<string, mixed>|null
     */
    private function profileFlagRow(int $userId): ?array
    {
        $row = $this->db->query(
            'SELECT email_opted_out, email_blocked, email_blocked_address, email_bounced, email_bounced_address
               FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();

        return $row === null || $row === false ? null : (array) $row;
    }
}
