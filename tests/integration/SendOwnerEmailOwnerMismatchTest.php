<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2165 gap 2: app/api/contact/send-owner-email.php refuses a to_user_id that
 * is not the owner of car_id (IDOR guard), at the HTTP layer.
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

    public function testRecipientWhoDoesNotOwnTheCarIsRefusedWith403AndLogged(): void
    {
        // createTestUser() omits users.permissions, so MySQL stores an implicit 0.
        // securePage() treats 0 as banned and redirects (302), so the sender gets 1.
        $senderId = $this->createTestUser(['permissions' => 1]);
        // Page 13 is private with permission 1. Without this row, securePage()
        // redirects (302) before the owner check runs.
        $this->grantTestPermission($senderId, 1);
        $ownerId = $this->createTestUser();
        $otherUserId = $this->createTestUser(); // real user, owns no car
        $carId = $this->createTestCar($ownerId);
        $this->loggedUserIds = [$senderId];

        $needle = "%does not match owner of car_id={$carId}%";
        $before = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle);

        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'send_message',
                'message' => 'Owner mismatch test message',
                'to_user_id' => (string) $otherUserId,
                'car_id' => (string) $carId,
            ],
            $senderId
        );

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertSame(
            $before + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, $needle),
            'Exactly one AccessDenied row must record the owner mismatch'
        );
    }
}
