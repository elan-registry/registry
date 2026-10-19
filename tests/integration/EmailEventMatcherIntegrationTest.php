<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\EmailEventMatch;
use ElanRegistry\Car\EmailEventMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-database tests for EmailEventMatcher (#2359).
 *
 * Covers steps (a), (b) and (c), "not ours", the matching rule (case and outer
 * white space only) and the rule that step (b) reads only the plugin row with
 * the event's message ID. The test schema has no `plg_sendinblue_messages`,
 * so the class makes the plugin's table when it is missing and drops it at
 * the end only if the class made it. (A TEMPORARY table does not work here:
 * the repository checks `information_schema`.)
 *
 * The decisions on canned rows are in tests/unit/cars/EmailEventMatcherTest.php.
 *
 * @see https://github.com/elan-registry/registry/issues/2359
 */
#[Group('integration')]
#[Group('car-verification')]
final class EmailEventMatcherIntegrationTest extends IntegrationTestCase
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

    private CarRepository $repo;
    private EmailEventMatcher $matcher;

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
        $this->matcher = new EmailEventMatcher($this->repo);
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

    private function uniqueId(string $prefix = 'matcher'): string
    {
        return $prefix . '-' . uniqid('', true);
    }

    /** A user whose email is unique, with a profile. */
    private function makeUser(?string $email = null): int
    {
        return $this->createTestUser($email === null ? [] : ['email' => $email], true);
    }

    private function userEmail(int $userId): string
    {
        return (string) $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first()->email;
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

    private function assertNoUser(EmailEventMatch $match, string $why): void
    {
        $this->assertTrue($match->isNoUser(), $why);
    }

    // --- Not ours --------------------------------------------------------

    public function testAMessageIdInNeitherTableIsNotOursEvenForTheAddressOfARealUser(): void
    {
        $userId = $this->makeUser();

        $match = $this->matcher->matchByMessageId($this->uniqueId('unknown'), $this->userEmail($userId));

        $this->assertTrue($match->isNotOurs(), 'An address match alone must never make an event ours');
    }

    public function testAnEmptyMessageIdIsNotOurs(): void
    {
        $userId = $this->makeUser();

        $this->assertTrue($this->matcher->matchByMessageId('', $this->userEmail($userId))->isNotOurs());
    }

    public function testAMessageIdThatDiffersOnlyInCaseIsNotOurs(): void
    {
        $userId = $this->makeUser();
        $email = $this->userEmail($userId);
        $messageId = $this->uniqueId('Case');
        $this->seedSentRow($userId, null, $email, $messageId, '2026-10-14 09:00:00');
        $this->seedPluginRow($this->uniqueId('Case2'), $email, '2026-10-14 09:00:00');

        // The column collation ignores case. The matcher must not.
        $match = $this->matcher->matchByMessageId(strtolower($messageId), $email);

        $this->assertTrue($match->isNotOurs());
    }

    // --- Step (a) --------------------------------------------------------

    public function testAVerificationSentRowMatchesItsUserCarAndSendTime(): void
    {
        $userId = $this->makeUser();
        $carId = $this->createTestCar($userId, ['email' => $this->userEmail($userId)]);
        $email = $this->userEmail($userId);
        $messageId = $this->uniqueId('stepa');
        $this->seedSentRow($userId, $carId, $email, $messageId, '2026-10-14 09:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $email);

        $this->assertTrue($match->isMatched());
        $this->assertSame($userId, $match->userId());
        $this->assertSame($carId, $match->carId());
        $this->assertSame('2026-10-14 09:00:00', $match->messageTime());
    }

    public function testStepAMatchesTheSentRowUserEvenAfterTheUserChangedAddress(): void
    {
        // The matcher finds the user of the send. The date guards of the applier
        // decide that the event sets no flag (EmailEventApplierIntegrationTest).
        $userId = $this->makeUser();
        $oldAddress = $this->userEmail($userId);
        $messageId = $this->uniqueId('stepa-moved');
        $this->seedSentRow($userId, null, $oldAddress, $messageId, '2026-10-14 09:00:00');
        $this->setUserEmailRaw($userId, 'new-' . uniqid() . '@example.com');

        $match = $this->matcher->matchByMessageId($messageId, $oldAddress);

        $this->assertSame($userId, $match->userId());
    }

    public function testStepAOnlyTheSentRowUserCanGetTheFlagWhenAnotherUserHoldsTheAddressNow(): void
    {
        $userY = $this->makeUser();
        $address = $this->userEmail($userY);
        $messageId = $this->uniqueId('stepa-only');
        $this->seedSentRow($userY, null, $address, $messageId, '2026-10-14 09:00:00');
        // The address moves to user X.
        $userX = $this->makeUser();
        $this->setUserEmailRaw($userY, 'moved-' . uniqid() . '@example.com');
        $this->setUserEmailRaw($userX, $address);

        $match = $this->matcher->matchByMessageId($messageId, $address);

        $this->assertSame($userY, $match->userId(), 'Step (a) must pick the user of the sent row, not the user who holds the address now');
    }

    public function testASentRowAddressThatDiffersFromTheEventAddressFallsThroughToStepB(): void
    {
        $sentUser = $this->makeUser();
        $otherUser = $this->makeUser();
        $otherAddress = $this->userEmail($otherUser);
        $messageId = $this->uniqueId('multi');
        $this->seedSentRow($sentUser, null, $this->userEmail($sentUser), $messageId, '2026-10-14 09:00:00');
        $this->seedPluginRow($messageId, $otherAddress, '2026-10-14 09:00:03');

        $match = $this->matcher->matchByMessageId($messageId, $otherAddress);

        $this->assertSame($otherUser, $match->userId());
        $this->assertNull($match->carId());
        $this->assertSame('2026-10-14 09:00:03', $match->messageTime());
    }

    // --- Step (b) --------------------------------------------------------

    public function testAnEventBeforeItsSentRowExistsMatchesByTheOnePluginRowWithNoCar(): void
    {
        $userId = $this->makeUser();
        $email = $this->userEmail($userId);
        $messageId = $this->uniqueId('stepb');
        $this->seedPluginRow($messageId, $email, '2026-10-14 10:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $email);

        $this->assertTrue($match->isMatched());
        $this->assertSame($userId, $match->userId());
        $this->assertNull($match->carId());
        $this->assertSame('2026-10-14 10:00:00', $match->messageTime());
    }

    public function testStepBIgnoresCaseAndOuterWhiteSpaceOfBothAddresses(): void
    {
        $userId = $this->makeUser('Mixed.Case-' . uniqid() . '@Example.COM');
        $stored = $this->userEmail($userId);
        $messageId = $this->uniqueId('stepb-norm');
        $this->seedPluginRow($messageId, '  ' . strtoupper($stored) . "\t", '2026-10-14 10:00:00');

        $match = $this->matcher->matchByMessageId($messageId, " \n" . strtolower($stored) . ' ');

        $this->assertSame($userId, $match->userId());
    }

    public function testStepBReadsOnlyThePluginRowWithTheEventMessageId(): void
    {
        // Address A belonged to user Y and moved to user X. X then got a newer
        // plugin row for A. A late event on Y's earlier message M1 must carry
        // M1's time, not the time of X's newer row.
        $userY = $this->makeUser();
        $address = $this->userEmail($userY);
        $earlier = $this->uniqueId('y-earlier');
        $newer = $this->uniqueId('x-newer');
        $this->seedPluginRow($earlier, $address, '2026-10-14 08:00:00');
        $userX = $this->makeUser();
        $this->setUserEmailRaw($userY, 'gone-' . uniqid() . '@example.com');
        $this->setUserEmailRaw($userX, $address);
        $this->seedPluginRow($newer, $address, '2026-10-14 12:00:00');

        $match = $this->matcher->matchByMessageId($earlier, $address);

        $this->assertTrue($match->isMatched());
        $this->assertSame($userX, $match->userId(), 'The current holder of the address is the candidate');
        $this->assertSame('2026-10-14 08:00:00', $match->messageTime(), 'The time must be the time of the plugin row with the event message ID');
    }

    public function testAnAddressWithAPluginRowForADifferentMessageIdIsNeverMatched(): void
    {
        $userId = $this->makeUser();
        $email = $this->userEmail($userId);
        $this->seedPluginRow($this->uniqueId('other-msg'), $email, '2026-10-14 10:00:00');
        // The event message ID is ours (a sent row of another user), but its plugin row does not exist.
        $otherUser = $this->makeUser();
        $messageId = $this->uniqueId('only-sent');
        $this->seedSentRow($otherUser, null, $this->userEmail($otherUser), $messageId, '2026-10-14 09:00:00');

        $match = $this->matcher->matchByMessageId($messageId, $email);

        $this->assertNoUser($match, 'A plugin row selected by address alone must not match');
    }

    // --- Step (c) --------------------------------------------------------

    public function testAnOurMessageWhoseAddressNoUserHoldsReturnsNoUser(): void
    {
        $messageId = $this->uniqueId('stepc');
        $address = 'nobody-' . uniqid() . '@example.com';
        $this->seedPluginRow($messageId, $address, '2026-10-14 10:00:00');

        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $address), 'No user holds the address');
    }

    public function testAnAddressSharedByTwoUsersReturnsNoUserInStepB(): void
    {
        $shared = 'shared-' . uniqid() . '@example.com';
        $this->makeUser($shared);
        $this->makeUser($shared);
        $messageId = $this->uniqueId('shared');
        $this->seedPluginRow($messageId, $shared, '2026-10-14 10:00:00');

        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $shared), 'A shared address must set no flag');
    }

    public function testAPluginRowWithMoreThanOneRecipientMatchesNoUser(): void
    {
        $userId = $this->makeUser();
        $email = $this->userEmail($userId);
        $messageId = $this->uniqueId('multi-rcpt');
        $this->seedPluginRow($messageId, $email . ', other-' . uniqid() . '@example.com', '2026-10-14 10:00:00');

        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $email), 'A multi-recipient row matches no user');
    }

    public function testASubstringOfThePluginRecipientDoesNotMatch(): void
    {
        $suffix = uniqid();
        $this->makeUser("bob-{$suffix}@x.example");
        $this->makeUser("jimbob-{$suffix}@x.example");
        $messageId = $this->uniqueId('substr');
        $this->seedPluginRow($messageId, "jimbob-{$suffix}@x.example", '2026-10-14 10:00:00');

        // Event for bob-...: the plugin row is for jimbob-... . A whole-address compare gives no match.
        $match = $this->matcher->matchByMessageId($messageId, "bob-{$suffix}@x.example");

        $this->assertNoUser($match, 'bob@x must not match a row for jimbob@x');
    }

    // --- Normalization: case and outer white space only -------------------

    public function testAnAccentVariantTheCollationHidesIsNotAMatch(): void
    {
        $suffix = uniqid();
        $accentUser = $this->makeUser("b\u{00F3}b-{$suffix}@example.com");
        $plain = "bob-{$suffix}@example.com";
        // Precondition: SQL treats the two addresses as equal. Without it the test could not fail.
        $sqlCandidates = $this->db->query('SELECT id FROM users WHERE email = ?', [$plain])->results();
        $this->assertCount(1, $sqlCandidates, 'Test setup: the collation must select the accent variant');

        $messageId = $this->uniqueId('accent');
        $this->seedPluginRow($messageId, $plain, '2026-10-14 10:00:00');
        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $plain), 'The PHP compare must reject the accent variant');
        $this->assertNull($this->matcher->matchUserByAddress($plain));

        // And in step (a): a sent row for the accent address is not for the plain address.
        $stepAId = $this->uniqueId('accent-a');
        $this->seedSentRow($accentUser, null, "b\u{00F3}b-{$suffix}@example.com", $stepAId, '2026-10-14 09:00:00');
        $this->assertNoUser($this->matcher->matchByMessageId($stepAId, $plain), 'Step (a) must not match an accent variant');
    }

    public function testATrailingWhiteSpaceVariantThatPhpTrimKeepsIsNotAMatch(): void
    {
        // PHP trim() keeps a form feed. The SQL wrapper of the candidate query strips it.
        $suffix = uniqid();
        $this->makeUser("ff-{$suffix}@example.com\x0C");
        $plain = "ff-{$suffix}@example.com";
        $this->assertSame("x\x0C", trim("x\x0C"), 'Test setup: trim() must keep the form feed');

        $messageId = $this->uniqueId('formfeed');
        $this->seedPluginRow($messageId, $plain, '2026-10-14 10:00:00');

        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $plain), 'A trailing form feed is not outer white space of the rule');
        $this->assertNull($this->matcher->matchUserByAddress($plain));
    }

    public function testATrailingSpaceInTheStoredPluginRecipientStillMatchesBecauseTheRuleTrims(): void
    {
        $userId = $this->makeUser();
        $email = $this->userEmail($userId);
        $messageId = $this->uniqueId('trail');
        $this->seedPluginRow($messageId, $email . '   ', '2026-10-14 10:00:00');

        $this->assertSame($userId, $this->matcher->matchByMessageId($messageId, $email)->userId());
    }

    // --- matchUserByAddress ----------------------------------------------

    public function testMatchUserByAddressFindsTheOneUserIgnoringCaseAndOuterWhiteSpace(): void
    {
        $userId = $this->makeUser('Import-' . uniqid() . '@Example.com');
        $stored = $this->userEmail($userId);

        $this->assertSame($userId, $this->matcher->matchUserByAddress('  ' . strtoupper($stored) . "\n"));
    }

    public function testMatchUserByAddressReturnsNullForASharedAddressAndForNoUser(): void
    {
        $shared = 'imp-shared-' . uniqid() . '@example.com';
        $this->makeUser($shared);
        $this->makeUser($shared);

        $this->assertNull($this->matcher->matchUserByAddress($shared));
        $this->assertNull($this->matcher->matchUserByAddress('nobody-' . uniqid() . '@example.com'));
        $this->assertNull($this->matcher->matchUserByAddress('   '));
    }

    // --- Wrong-typed values: strings that PHP's types allow ---------------

    /**
     * @return array<string, array{string}>
     */
    public static function oddStringProvider(): array
    {
        return [
            'NUL byte' => ["abc\0def"],
            'invalid UTF-8' => ["\xFF\xFE\xFD"],
            'SQL quote' => ["' OR '1'='1"],
            'LIKE wildcard percent' => ['%'],
            'LIKE wildcard underscore' => ['_'],
            'backslash' => ['\\'],
            'very long' => [str_repeat('a', 100000)],
            'white space only' => ["  \t\n "],
            'empty' => [''],
            'JSON text' => ['{"a":[1,2]}'],
        ];
    }

    #[DataProvider('oddStringProvider')]
    public function testAnOddMessageIdIsNotOursAndRaisesNoException(string $messageId): void
    {
        $this->assertTrue($this->matcher->matchByMessageId($messageId, 'bob@example.com')->isNotOurs());
    }

    #[DataProvider('oddStringProvider')]
    public function testAnOddAddressForAnOurMessageMatchesNoUserAndRaisesNoException(string $address): void
    {
        $userId = $this->makeUser();
        $messageId = $this->uniqueId('odd-addr');
        $this->seedSentRow($userId, null, $this->userEmail($userId), $messageId, '2026-10-14 09:00:00');
        $this->seedPluginRow($messageId, $this->userEmail($userId), '2026-10-14 09:00:00');

        $this->assertNoUser($this->matcher->matchByMessageId($messageId, $address), 'An odd address matches no user');
    }

    #[DataProvider('oddStringProvider')]
    public function testMatchUserByAddressForAnOddAddressReturnsNullAndRaisesNoException(string $address): void
    {
        $this->makeUser();

        $this->assertNull($this->matcher->matchUserByAddress($address));
    }

    // --- Live-DB: the lookups use an index --------------------------------

    public function testStepALookupUsesTheMessageIdIndex(): void
    {
        // An empty table gives "no matching row in const table" and no key, so seed a row.
        $userId = $this->makeUser();
        $this->seedSentRow($userId, null, $this->userEmail($userId), $this->uniqueId('explain-a'), '2026-10-14 09:00:00');
        $rows = $this->db->query(
            'EXPLAIN SELECT user_id, car_id, email, brevo_message_id, occurred_at
               FROM er_email_events WHERE brevo_message_id = ? AND event = ?',
            ['some-id', 'sent']
        )->results();

        $this->assertNotEmpty($rows);
        $this->assertSame('idx_er_email_events_brevo_message_id', $rows[0]->key ?? null);
    }

    public function testStepBLookupUsesTheUniqueMessageIdKey(): void
    {
        $messageId = $this->uniqueId('explain-b');
        $this->seedPluginRow($messageId, 'explain@example.com', '2026-10-14 09:00:00');
        $rows = $this->db->query(
            'EXPLAIN SELECT message_id, recipient_email, accepted_at FROM plg_sendinblue_messages WHERE message_id = ?',
            [$messageId]
        )->results();

        $this->assertNotEmpty($rows);
        $this->assertSame('message_id', $rows[0]->key ?? null);
    }
}
