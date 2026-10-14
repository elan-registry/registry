<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/api/contact/send-owner-email.php refuses a to_user_id that is not the
 * owner of car_id (IDOR guard), at the HTTP layer. See #2165.
 *
 * Each request runs in its own `php -r` subprocess because ApiResponse::send()
 * calls exit.
 */
#[Group('integration')]
final class SendOwnerEmailOwnerMismatchTest extends IntegrationTestCase
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

    /**
     * Create a sender that securePage() lets through to the owner check.
     * tearDown() deletes the logs rows of this user.
     */
    private function createSender(): int
    {
        // createTestUser() omits users.permissions, so MySQL stores an implicit 0.
        // securePage() treats 0 as banned and redirects (302), so the sender gets 1.
        $senderId = $this->createTestUser(['permissions' => 1]);
        // app/api/contact/send-owner-email.php is a private page for permission 1.
        // Without this row, securePage() redirects (302) before the owner check runs.
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
                'message' => 'Owner check test message',
                'to_user_id' => (string) $toUserId,
                'car_id' => (string) $carId,
            ],
            $senderId
        );
    }

    /**
     * Count the rows that the endpoint writes after email() returns, sent or
     * failed. Only a request that reached email() can write one. A refusal
     * exits before these rows, so a count of 0 does not prove that no message
     * went out.
     */
    private function countSendOutcomeLogs(int $senderId): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ELAN_REGISTRY, 'send-owner-email.php sent from %', $senderId)
            + $this->countMatchingLogs(LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'send-owner-email.php SEND FAILED from %', $senderId);
    }

    public function testRecipientWhoDoesNotOwnTheCarIsRefusedWith403AndLogged(): void
    {
        $senderId = $this->createSender();
        $ownerId = $this->createTestUser();
        $otherUserId = $this->createTestUser(); // real user, owns no car
        $carId = $this->createTestCar($ownerId);

        $needle = "%does not match owner of car_id={$carId}%";
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle, $senderId);

        $result = $this->sendMessage($senderId, $otherUserId, $carId);

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            $before + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle, $senderId),
            'Exactly one AccessDenied row must record the owner mismatch'
        );
    }

    public function testRecipientWhoOwnsTheCarPassesTheOwnerCheck(): void
    {
        $senderId = $this->createSender();
        $ownerId = $this->createTestUser();
        $carId = $this->createTestCar($ownerId);

        $needle = "%does not match owner of car_id={$carId}%";
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle, $senderId);

        $result = $this->sendMessage($senderId, $ownerId, $carId);

        // Control for the mismatch test: a guard that refuses every recipient would pass it.
        // The send gives 200 or 500, depending on the Brevo setup of the checkout. A 500
        // can also come from a step before the owner check, so the send-outcome row is
        // the proof that the request reached email().
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertIsArray($result['json'], 'The endpoint must answer with JSON: ' . $result['raw']);
        $this->assertContains($result['httpCode'], [200, 500], 'The real owner must pass the owner check: ' . $result['raw']);
        $this->assertSame(1, $this->countSendOutcomeLogs($senderId), 'The real owner must reach email(): ' . $result['raw']);
        $this->assertSame(
            $before,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle, $senderId),
            'No AccessDenied row may record an owner mismatch for the real owner'
        );
    }
}
