<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/contact/send-owner-email.php takes the sender from the session, not
 * from the request, and refuses a request without a valid CSRF token.
 *
 * The recipient has a bounced flag, so a send that passes every guard ends on
 * the "blocked" path and no mail goes out. The blocked log row names the
 * sender, which shows which user ID the endpoint used.
 *
 * Each request runs in its own `php -r` subprocess because ApiResponse::send()
 * calls exit.
 */
#[Group('integration')]
final class SendOwnerEmailSenderAndCsrfTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/contact/send-owner-email.php';

    /** @var int[] User IDs whose logs rows tearDown() deletes */
    private array $loggedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->trackNewRateLimitRows('owner_contact_email');
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
        }

        parent::tearDown();
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
     * A recipient with a bounced flag who owns a car.
     *
     * @return array{userId: int, carId: int}
     */
    private function createBouncedRecipient(): array
    {
        // A profile row, because the bounced flag lives on the profile (#2101).
        $userId = $this->createTestUser([], true);
        $carId = $this->createTestCar($userId);
        $row = $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first();
        $this->assertIsObject($row, 'Test setup: recipient row must exist');
        $this->setOwnerBounced($userId, (string) $row->email);

        return ['userId' => $userId, 'carId' => $carId];
    }

    /**
     * Count the outcome rows that name this sender, written by any user.
     * The endpoint writes the row as the session user, so a forged sender
     * would show up only in the note.
     */
    private function countOutcomeRowsNamingSender(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php blocked: sender {$senderId} %")
            + $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, "send-owner-email.php sent: sender {$senderId} %")
            + $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, "send-owner-email.php SEND FAILED: sender {$senderId} %");
    }

    public function testSenderComesFromTheSessionNotFromThePostedFromUserId(): void
    {
        $senderA = $this->createSender();
        $senderB = $this->createSender();
        $recipient = $this->createBouncedRecipient();

        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'send_message',
                'message' => 'Sender forgery test ' . uniqid(),
                'to_user_id' => (string) $recipient['userId'],
                'car_id' => (string) $recipient['carId'],
                'from_user_id' => (string) $senderB,
            ],
            $senderA
        );

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertSame(
            1,
            $this->countMatchingLogs(
                LogCategories::LOG_CATEGORY_ELAN_REGISTRY,
                "send-owner-email.php blocked: sender {$senderA} recipient {$recipient['userId']} flags %",
                $senderA
            ),
            'The blocked row must name the signed-in sender A'
        );
        $this->assertSame(0, $this->countOutcomeRowsNamingSender($senderB), 'No row may name the posted sender B');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badCsrfProvider(): array
    {
        return [
            'invalid token' => ['invalid'],
            'no token'      => ['omit'],
        ];
    }

    #[DataProvider('badCsrfProvider')]
    public function testRequestWithoutAValidCsrfTokenIsRefusedWith403AndSendsNothing(string $csrfMode): void
    {
        $senderId = $this->createSender();
        $recipient = $this->createBouncedRecipient();
        $csrfNeedle = 'send-owner-email.php: CSRF validation failed%';
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, $csrfNeedle, $senderId);

        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'send_message',
                'message' => 'CSRF test ' . uniqid(),
                'to_user_id' => (string) $recipient['userId'],
                'car_id' => (string) $recipient['carId'],
            ],
            $senderId,
            $csrfMode
        );

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertSame('Invalid CSRF token', $result['message'], $result['raw']);
        $this->assertSame(
            $before + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, $csrfNeedle, $senderId),
            'Exactly one Security row must record the CSRF failure'
        );
        $this->assertSame(0, $this->countOutcomeRowsNamingSender($senderId), 'A refused request must write no sent or blocked row');
    }
}
