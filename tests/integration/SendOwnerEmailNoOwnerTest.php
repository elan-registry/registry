<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\AppConstants;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/contact/send-owner-email.php refuses a message for a car that the
 * noowner system account holds (ADR-010, #2505). The account has the address
 * noowner@invalid. Before the fix the request answered 500 and wrote an
 * EmailError row. Now it answers 409 and writes no row.
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

    /** The id of the noowner system account, found by username. */
    private function noownerId(): int
    {
        $row = $this->db->query('SELECT id FROM users WHERE username = ?', [AppConstants::NOOWNER_USERNAME])->first();
        $this->assertIsObject($row, 'Test setup: the noowner account must exist');

        return (int) $row->id;
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

    public function testNoownerRecipientIsRefusedWith409AndReadableMessage(): void
    {
        $senderId = $this->createSender();
        $noownerId = $this->noownerId();
        $carId = $this->createTestCar($noownerId);

        $result = $this->sendMessage($senderId, $noownerId, $carId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(409, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertStringContainsString('no current owner', $result['message'], $result['raw']);
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
