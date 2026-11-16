<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/contact/send-owner-email.php refuses two recipients with a 409 and a
 * readable message:
 * - The noowner system account (ADR-010, #2505). Its address is noowner@invalid.
 *   The endpoint writes no log row.
 * - An owner whose address is malformed (#2712). The endpoint writes one
 *   EmailError row under the sender's user id. Its note names the recipient's
 *   user id (to_user_id=N), so an admin can correct the address.
 * Before the fixes both requests answered 500.
 *
 * Each request runs in its own `php -r` subprocess because ApiResponse::send()
 * calls exit.
 */
#[Group('integration')]
final class SendOwnerEmailNoOwnerTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/api/contact/send-owner-email.php';

    /** @var int[] User IDs whose logs rows tearDown() deletes */
    private array $loggedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->loggedUserIds = [];
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
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null}
     */
    private function sendMessage(int $senderId, int $toUserId, int $carId): array
    {
        return $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'send_message',
                'message' => 'No owner test message',
                'to_user_id' => (string) $toUserId,
                'car_id' => (string) $carId,
            ],
            $senderId
        );
    }

    /** Rows that the endpoint writes after email() returns. Only a request that reached email() writes one. */
    private function countSendOutcomeLogs(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, 'send-owner-email.php sent: sender %', $senderId)
            + $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php SEND FAILED: sender %', $senderId);
    }

    private function countEmailErrorLogs(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, '%', $senderId);
    }

    /** @return array<string, array{'noowner'|'malformed', string[]}> */
    public static function refusedRecipientProvider(): array
    {
        return [
            'noowner' => ['noowner', ['no current owner']],
            'malformed address' => ['malformed', ['cannot be contacted', 'Feedback form']],
        ];
    }

    /**
     * The user id of the recipient for a provider case.
     *
     * @param 'noowner'|'malformed' $case
     */
    private function createRefusedRecipient(string $case): int
    {
        return match ($case) {
            'noowner' => $this->noownerId(),
            'malformed' => $this->createMalformedOwner(),
        };
    }

    /** An owner whose address fails FILTER_VALIDATE_EMAIL. The profile row lets a request that gets past the address check reach email(), the same as a real owner (#2360). */
    private function createMalformedOwner(): int
    {
        return $this->createTestUser(['email' => 'malformed_' . uniqid()], true);
    }

    /**
     * @param 'noowner'|'malformed' $case
     * @param string[] $messageFragments
     */
    #[DataProvider('refusedRecipientProvider')]
    public function testRefusedRecipientGets409AndReadableMessage(string $case, array $messageFragments): void
    {
        $senderId = $this->createSender();
        $recipientId = $this->createRefusedRecipient($case);
        $carId = $this->createTestCar($recipientId);

        $result = $this->sendMessage($senderId, $recipientId, $carId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(409, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        foreach ($messageFragments as $fragment) {
            $this->assertStringContainsString($fragment, $result['message'], $result['raw']);
        }
        $this->assertNoPhpErrorText($result['raw']);
    }

    public function testNoownerRecipientWritesNoEmailErrorRowAndSendsNothing(): void
    {
        $senderId = $this->createSender();
        $noownerId = $this->noownerId();
        $carId = $this->createTestCar($noownerId);

        $before = $this->countEmailErrorLogs($senderId);

        $result = $this->sendMessage($senderId, $noownerId, $carId);

        $this->assertSame(
            $before,
            $this->countEmailErrorLogs($senderId),
            'A refused noowner request must not write an EmailError row'
        );
        $this->assertSame(0, $this->countSendOutcomeLogs($senderId), 'A refused noowner request must not reach email()');
        // Guard: a request that never reached the endpoint logic would also leave the counts unchanged.
        $this->assertSame(409, $result['httpCode'], $result['raw']);
    }

    public function testMalformedRecipientWritesEmailErrorRowAndSendsNothing(): void
    {
        $senderId = $this->createSender();
        $ownerId = $this->createMalformedOwner();
        $carId = $this->createTestCar($ownerId);
        $note = 'send-owner-email.php: invalid recipient email for to_user_id=' . $ownerId;

        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $note, $senderId);

        $result = $this->sendMessage($senderId, $ownerId, $carId);

        $this->assertSame(
            $before + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, $note, $senderId),
            'A malformed recipient address must write exactly one EmailError row'
        );
        $this->assertSame(0, $this->countSendOutcomeLogs($senderId), 'A refused malformed-address request must not reach email()');
        // Guard: a request that never reached the endpoint logic would also leave the send count at 0.
        $this->assertSame(409, $result['httpCode'], $result['raw']);
    }

    public function testRealOwnerWithProfileStillReachesEmail(): void
    {
        $senderId = $this->createSender();
        // A profile row, because the send policy refuses an owner with none (#2360).
        $ownerId = $this->createTestUser([], true);
        $carId = $this->createTestCar($ownerId);

        $result = $this->sendMessage($senderId, $ownerId, $carId);

        // Control: a refusal that matched every recipient would pass the two tests above.
        // The send gives 200 or 500, depending on the Brevo setup of the checkout. The
        // send-outcome row proves that the request reached email().
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertIsArray($result['json'], 'The endpoint must answer with JSON: ' . $result['raw']);
        $this->assertContains($result['httpCode'], [200, 500], 'The real owner must not be refused: ' . $result['raw']);
        $this->assertSame(1, $this->countSendOutcomeLogs($senderId), 'The real owner must reach email(): ' . $result['raw']);
    }
}
