<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/contact/send-owner-email.php applies the send policy (#2360).
 *
 * A blocked send returns the same success answer as a real send, writes one
 * "blocked" log row with user IDs and flag names (no address), records the
 * rate-limit row like any send, and never calls email().
 *
 * Proof of "no email() call": the endpoint writes a "sent" or "SEND FAILED"
 * log row after every email() call, and the sendinblue plugin writes a plg_sendinblue_messages row for
 * each message that Brevo accepts. A blocked send has none of them. The clean
 * control proves that the same request without a flag does reach email().
 *
 * Each request runs in its own `php -r` subprocess because ApiResponse::send()
 * calls exit.
 */
#[Group('integration')]
final class SendOwnerEmailSendPolicyTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/contact/send-owner-email.php';
    private const RATE_LIMIT_ACTION = 'owner_contact_email';

    /** @var int[] User IDs whose logs rows tearDown() deletes */
    private array $loggedUserIds = [];

    /** @var string[] Recipient addresses whose plg_sendinblue_messages rows tearDown() deletes */
    private array $recipientAddresses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->loggedUserIds = [];
        $this->recipientAddresses = [];
        $this->trackNewRateLimitRows(self::RATE_LIMIT_ACTION);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->loggedUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
                if ($this->db->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$userId}: {$this->db->errorString()}\n");
                }
            }
            if ($this->recipientAddresses !== [] && $this->sendinblueTableExists()) {
                foreach ($this->recipientAddresses as $address) {
                    $this->db->query('DELETE FROM plg_sendinblue_messages WHERE recipient_email = ?', [$address]);
                    if ($this->db->error()) {
                        fwrite(STDERR, "NOTE: tearDown() cleanup failed for plg_sendinblue_messages: {$this->db->errorString()}\n");
                    }
                }
            }
        }

        parent::tearDown();
    }

    private function sendinblueTableExists(): bool
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['plg_sendinblue_messages']
        )->first();

        return is_object($row) && (int) $row->cnt > 0;
    }

    /** A sender that securePage() lets through to the endpoint logic. */
    private function createSender(): int
    {
        // permissions = 1 and a user_permission_matches row: see SendOwnerEmailOwnerMismatchTest.
        $senderId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($senderId, 1);
        $this->loggedUserIds[] = $senderId;

        return $senderId;
    }

    /**
     * Create a recipient that owns a car.
     *
     * @return array{userId: int, carId: int, email: string}
     */
    private function createRecipient(bool $withProfile = true): array
    {
        $userId = $this->createTestUser([], $withProfile);
        $carId = $this->createTestCar($userId);
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first();
        $this->assertIsObject($row, 'Test setup: recipient row must exist');
        $this->recipientAddresses[] = (string) $row->email;

        return ['userId' => $userId, 'carId' => $carId, 'email' => (string) $row->email];
    }

    /**
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function sendMessage(int $senderId, int $toUserId, int $carId): array
    {
        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'send_message',
                'message' => 'Send policy test message',
                'to_user_id' => (string) $toUserId,
                'car_id' => (string) $carId,
            ],
            $senderId
        );
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);

        return $result;
    }

    /** Count of "sent" and "SEND FAILED" rows: only a request that reached email() writes one. */
    private function countOutcomeLogs(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, 'send-owner-email.php sent: sender %', $senderId)
            + $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php SEND FAILED: sender %', $senderId);
    }

    private function countBlockedLogs(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, 'send-owner-email.php blocked: sender %', $senderId);
    }

    /** Count of plg_sendinblue_messages rows for an address; 0 when the plugin table does not exist. */
    private function countSendinblueMessages(string $address): int
    {
        if (!$this->sendinblueTableExists()) {
            return 0;
        }
        $row = $this->db->query('SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages WHERE recipient_email = ?', [$address])->first();

        return (int) $row->cnt;
    }

    /** Count of us_rate_limits rows that the endpoint wrote for this sender and action. */
    private function countRateLimitRows(int $senderId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM us_rate_limits WHERE action = ? AND identifier_key = ? AND success = 1',
            [self::RATE_LIMIT_ACTION, hash('sha256', 'user::' . $senderId)]
        )->first();

        return (int) $row->cnt;
    }

    /**
     * @param array{userId: int, carId: int, email: string} $recipient
     */
    private function assertEmailNotCalled(int $senderId, array $recipient): void
    {
        $this->assertSame(0, $this->countOutcomeLogs($senderId), 'A blocked send must not reach email() (no sent or SEND FAILED row)');
        $this->assertSame(0, $this->countSendinblueMessages($recipient['email']), 'A blocked send must leave no plg_sendinblue_messages row');
    }

    /**
     * Assert the shape of the single blocked row and return its note.
     *
     * @param array{userId: int, carId: int, email: string} $recipient
     */
    private function assertOneBlockedRow(int $senderId, array $recipient, string $expectedFlags): string
    {
        $this->assertSame(1, $this->countBlockedLogs($senderId), 'Exactly one blocked log row must exist');
        $rows = $this->db->query(
            'SELECT lognote FROM logs WHERE logtype = ? AND lognote LIKE ? AND user_id = ?',
            [LogCategories::LOG_CATEGORY_ELAN_REGISTRY, 'send-owner-email.php blocked: sender %', $senderId]
        )->results();
        $this->assertCount(1, $rows);
        $note = (string) $rows[0]->lognote;

        $this->assertSame(
            "send-owner-email.php blocked: sender {$senderId} recipient {$recipient['userId']} flags {$expectedFlags}",
            $note
        );
        $this->assertStringNotContainsString('@', $note, 'The blocked row must hold no email address');

        // No log row of this sender, from any path, may hold an address.
        $all = $this->db->query('SELECT lognote FROM logs WHERE user_id = ?', [$senderId])->results();
        $this->assertNotEmpty($all);
        foreach ($all as $row) {
            $this->assertStringNotContainsString('@', (string) $row->lognote, 'No log row may hold an email address');
        }

        return $note;
    }

    /**
     * Set a recipient up for each blocking case.
     *
     * @return array{userId: int, carId: int, email: string}
     */
    private function createBlockedRecipient(string $case): array
    {
        if ($case === 'no_profile') {
            return $this->createRecipient(false);
        }
        $recipient = $this->createRecipient();
        match ($case) {
            'email_opted_out' => $this->setOwnerOptedOut($recipient['userId']),
            'email_blocked'   => $this->setOwnerBlocked($recipient['userId'], $recipient['email']),
            'email_bounced'   => $this->setOwnerBounced($recipient['userId'], $recipient['email']),
            default           => throw new \InvalidArgumentException("Unknown blocking case '{$case}'"),
        };

        return $recipient;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockingCaseProvider(): array
    {
        return [
            'no profile' => ['no_profile'],
            'opted out'  => ['email_opted_out'],
            'blocked'    => ['email_blocked'],
            'bounced'    => ['email_bounced'],
        ];
    }

    #[DataProvider('blockingCaseProvider')]
    public function testBlockedRecipientGetsSuccessAnswerAndNoEmailCall(string $case): void
    {
        $senderId = $this->createSender();
        $recipient = $this->createBlockedRecipient($case);

        $result = $this->sendMessage($senderId, $recipient['userId'], $recipient['carId']);

        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame('Your message has been sent.', $result['message']);
        // The whole body, not only the message: an extra key (for example from
        // ->withData()) would tell the sender that the send was blocked.
        // ApiResponse::send() emits json_encode(toArray(), JSON_THROW_ON_ERROR).
        $this->assertSame(['success' => true, 'message' => 'Your message has been sent.'], $result['json']);
        $this->assertSame(
            json_encode(['success' => true, 'message' => 'Your message has been sent.'], JSON_THROW_ON_ERROR),
            trim($result['raw']),
            'The blocked answer must be the exact success body'
        );
        $this->assertEmailNotCalled($senderId, $recipient);
        $this->assertOneBlockedRow($senderId, $recipient, $case);
        $this->assertSame(1, $this->countRateLimitRows($senderId), 'A blocked send must record the rate-limit row');
    }

    public function testSeveralFlagsAreLoggedInFlagOrder(): void
    {
        $senderId = $this->createSender();
        $recipient = $this->createRecipient();
        $this->setOwnerBlocked($recipient['userId'], $recipient['email']);
        $this->setOwnerOptedOut($recipient['userId']);
        $this->setOwnerBounced($recipient['userId'], $recipient['email']);

        $result = $this->sendMessage($senderId, $recipient['userId'], $recipient['carId']);

        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertEmailNotCalled($senderId, $recipient);
        $this->assertOneBlockedRow($senderId, $recipient, 'email_bounced,email_opted_out,email_blocked');
    }

    #[DataProvider('oldAddressProvider')]
    public function testFlagOnAnOldAddressAfterAdminEmailEditDoesNotBlock(string $flag): void
    {
        $senderId = $this->createSender();
        $recipient = $this->createRecipient();
        $oldAddress = $recipient['email'];
        if ($flag === 'email_bounced') {
            $this->setOwnerBounced($recipient['userId'], $oldAddress);
        } else {
            $this->setOwnerBlocked($recipient['userId'], $oldAddress);
        }
        // An admin edit of users.email: the profile keeps the old address.
        $newAddress = 'changed_' . uniqid() . '@example.com';
        $this->setUserEmailRaw($recipient['userId'], $newAddress);
        $this->recipientAddresses[] = $newAddress;

        $result = $this->sendMessage($senderId, $recipient['userId'], $recipient['carId']);

        $this->assertContains($result['httpCode'], [200, 500], 'The send must proceed: ' . $result['raw']);
        $this->assertSame(0, $this->countBlockedLogs($senderId), 'A flag on an old address must not block');
        $this->assertSame(1, $this->countOutcomeLogs($senderId), 'The send must reach email()');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oldAddressProvider(): array
    {
        return [
            'bounced' => ['email_bounced'],
            'blocked' => ['email_blocked'],
        ];
    }

    public function testCleanControlReachesEmail(): void
    {
        $senderId = $this->createSender();
        $recipient = $this->createRecipient();

        $result = $this->sendMessage($senderId, $recipient['userId'], $recipient['carId']);

        // 200 or 500, depending on the Brevo setup of the checkout. The outcome row is the
        // proof that the request reached email().
        $this->assertContains($result['httpCode'], [200, 500], 'The clean owner must reach email(): ' . $result['raw']);
        $this->assertSame(1, $this->countOutcomeLogs($senderId), 'The clean owner must reach email()');
        $this->assertSame(0, $this->countBlockedLogs($senderId));
        $this->assertSame(1, $this->countRateLimitRows($senderId), 'The control records the same rate-limit row as a blocked send');
    }

    public function testBlockedResponseIsByteEqualToTheControl(): void
    {
        $controlSender = $this->createSender();
        $control = $this->createRecipient();
        $controlResult = $this->sendMessage($controlSender, $control['userId'], $control['carId']);

        if ($controlResult['httpCode'] !== 200) {
            $this->markTestSkipped(
                'The control send returned HTTP ' . var_export($controlResult['httpCode'], true)
                . ' (Brevo is not set up in this environment), so there is no real-send answer to compare with.'
            );
        }

        $compared = 0;
        foreach (array_keys(self::blockingCaseProvider()) as $label) {
            $case = self::blockingCaseProvider()[$label][0];
            $senderId = $this->createSender();
            $recipient = $this->createBlockedRecipient($case);

            $result = $this->sendMessage($senderId, $recipient['userId'], $recipient['carId']);

            $this->assertSame(200, $result['httpCode'], "Case {$case}: " . $result['raw']);
            $this->assertNotSame('', $result['raw'], "Case {$case}: the answer must not be empty");
            $this->assertSame($controlResult['raw'], $result['raw'], "Case {$case}: the blocked answer must be byte-equal to the control");
            $this->assertSame($controlResult['flash'], $result['flash'], "Case {$case}: session flash messages must match the control");
            $compared++;
        }
        $this->assertSame(4, $compared);
    }
}
