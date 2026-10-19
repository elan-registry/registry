<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * app/admin/includes/process-admin-contact.php applies the send policy
 * (MailKind::AdminContact) directly before email(). See #2360.
 *
 * The page is a POST handler that ends with Redirect::to(). Each request runs
 * in its own `php -r` subprocess, and the admin sees the result as a session
 * flash message, which invokeEndpoint() returns in `flash`.
 *
 * A request that passes the policy reaches email(). The test schema has no
 * Brevo API key, so email() fails there unless a test calls saveBrevoTestKey().
 * The tests accept a "sent" row or a "SEND FAILED" row as proof that the
 * request reached email(). One test saves the key and checks the "sent" path.
 */
#[Group('integration')]
final class AdminContactSendPolicyTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/admin/includes/process-admin-contact.php';

    private const REFUSED = 'Message not sent.';

    /** @var int[] Users whose logs rows tearDown() deletes */
    private array $loggedUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->loggedUserIds = [];
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

    /** Create a user with the given permission. tearDown() deletes the logs rows of this user. */
    private function createCaller(int $permissionId): int
    {
        // createTestUser() omits users.permissions (implicit 0, which securePage() treats as banned).
        $userId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($userId, $permissionId);
        $this->loggedUserIds[] = $userId;

        return $userId;
    }

    /**
     * Create an owner with a known address.
     *
     * @return array{id: int, email: string}
     */
    private function createOwner(bool $withProfile): array
    {
        $email = 'owner_' . uniqid() . '@example.com';
        $id = $this->createTestUser(['email' => $email], $withProfile);

        return ['id' => $id, 'email' => $email];
    }

    /**
     * @param array<string, scalar> $overrides
     * @return array{status: bool|null, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<mixed>|null, flash: array{error: list<string>, success: list<string>, message: list<string>}}
     */
    private function contact(int $callerId, string $ownerId, string $carId, array $overrides = []): array
    {
        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            array_merge([
                'action' => 'admin_contact_owner',
                'message' => 'Policy test message',
                'car_id' => $carId,
                'owner_id' => $ownerId,
                'quality_issue' => 'policy test',
            ], $overrides),
            $callerId
        );
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);

        return $result;
    }

    /**
     * The lognote values that the endpoint wrote for the admin, by prefix.
     *
     * @return list<string>
     */
    private function adminContactLogs(int $adminId, string $logtype, string $prefix): array
    {
        $result = $this->db->query(
            'SELECT lognote FROM logs WHERE user_id = ? AND logtype = ? AND lognote LIKE ? ORDER BY id',
            [$adminId, $logtype, $prefix . '%']
        );
        $this->assertFalse($this->db->error(), 'Log read failed: ' . $this->db->errorString());

        return array_values(array_map(static fn($row): string => (string) $row->lognote, $result->results()));
    }

    /**
     * Assert the request was refused: one error flash, no success flash, one
     * blocked row with the expected flags, no sent or SEND FAILED row, no
     * "sendinblue" row, and no "@" in any contact log line.
     *
     * The "sendinblue" row is the proof that email() did not run: the test
     * schema has no API key, so each email() call fails, and sendinblueFail()
     * logs it for the signed-in admin.
     *
     * @param array{flash: array{error: list<string>, success: list<string>, message: list<string>}} $result
     */
    private function assertRefused(array $result, int $adminId, string $expectedError, string $ownerLabel, string $flags): void
    {
        $this->assertSame([$expectedError], $result['flash']['error']);
        $this->assertSame([], $result['flash']['success']);
        $this->assertSame(
            ["Admin contact blocked - Admin: {$adminId}, Owner: {$ownerLabel}, Flags: {$flags}"],
            $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact blocked')
        );
        $this->assertSame([], $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact sent'));
        $this->assertSame([], $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'Admin contact SEND FAILED'));
        $this->assertSame([], $this->adminContactLogs($adminId, 'sendinblue', ''), 'A blocked send must not call email()');
        $this->assertNoAddressInContactLogs($adminId);
    }

    private function assertNoAddressInContactLogs(int $adminId): void
    {
        $result = $this->db->query('SELECT lognote FROM logs WHERE user_id = ? AND lognote LIKE ?', [$adminId, 'Admin contact%']);
        $this->assertFalse($this->db->error(), 'Log read failed: ' . $this->db->errorString());
        foreach ($result->results() as $row) {
            $this->assertStringNotContainsString('@', (string) $row->lognote, 'A contact log line must not hold an address');
        }
    }

    /**
     * Assert the request reached email(): a sent row or a SEND FAILED row, and no blocked row.
     */
    private function assertReachedEmail(int $adminId, string $ownerLabel): void
    {
        $sent = $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact sent');
        $failed = $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'Admin contact SEND FAILED');
        $this->assertCount(1, array_merge($sent, $failed), 'The request must reach email() and write one outcome row');
        $this->assertSame([], $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact blocked'));
        foreach (array_merge($sent, $failed) as $line) {
            $this->assertStringContainsString("Owner: {$ownerLabel}", $line);
        }
        $this->assertNoAddressInContactLogs($adminId);
    }

    public function testOwnerWithNoProfileIsRefused(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(false);
        $carId = $this->createTestCar($owner['id']);

        $result = $this->contact($adminId, (string) $owner['id'], (string) $carId);

        $this->assertRefused($result, $adminId, self::REFUSED . ' This owner has no profile.', (string) $owner['id'], 'no_profile');
    }

    public function testBouncedOwnerIsRefusedWithTheBounceDate(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $carId = $this->createTestCar($owner['id']);
        $this->setOwnerBounced($owner['id'], $owner['email'], '2025-03-14 13:45:09');

        $result = $this->contact($adminId, (string) $owner['id'], (string) $carId);

        $this->assertRefused(
            $result,
            $adminId,
            self::REFUSED . " This owner's address is invalid. It bounced on 2025-03-14.",
            (string) $owner['id'],
            'email_bounced'
        );
        $text = implode(' ', $result['flash']['error']);
        $this->assertStringNotContainsString('13:45', $text, 'The message shows the date only');
        $this->assertDoesNotMatchRegularExpression('/[<>]/', $text, 'The message holds no HTML tag');
    }

    public function testOptedOutOwnerIsRefused(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $carId = $this->createTestCar($owner['id']);
        $this->setOwnerOptedOut($owner['id']);

        $result = $this->contact($adminId, (string) $owner['id'], (string) $carId);

        $this->assertRefused($result, $adminId, self::REFUSED . ' The owner opted out.', (string) $owner['id'], 'email_opted_out');
    }

    public function testBouncedAndOptedOutOwnerGetsBothReasonsBouncedFirst(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $carId = $this->createTestCar($owner['id']);
        $this->setOwnerOptedOut($owner['id']);
        $this->setOwnerBounced($owner['id'], $owner['email'], '2025-03-14 13:45:09');

        $result = $this->contact($adminId, (string) $owner['id'], (string) $carId);

        $this->assertRefused(
            $result,
            $adminId,
            self::REFUSED . " This owner's address is invalid. It bounced on 2025-03-14. The owner opted out.",
            (string) $owner['id'],
            'email_bounced, email_opted_out'
        );
    }

    public function testOwnerWithOnlyABlockIsNotRefused(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $carId = $this->createTestCar($owner['id']);
        $this->setOwnerBlocked($owner['id'], $owner['email']);

        $result = $this->contact($adminId, (string) $owner['id'], (string) $carId);

        $this->assertReachedEmail($adminId, (string) $owner['id']);
        $this->assertSame([], array_filter($result['flash']['error'], static fn(string $e): bool => str_starts_with($e, self::REFUSED)));
    }

    public function testMultipleWithNoMatchingUserIsRefused(): void
    {
        $adminId = $this->createCaller(2);

        $result = $this->contact($adminId, 'Multiple', 'Multiple', [
            'target_email' => 'nomatch_' . uniqid() . '@example.com',
        ]);

        $this->assertRefused($result, $adminId, self::REFUSED . ' No owner has this address now.', 'Multiple (0 users)', 'no_match');
    }

    public function testMultipleWithOneBouncedMatchIsRefusedWithTheBounceDate(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $this->setOwnerBounced($owner['id'], $owner['email'], '2025-03-14 13:45:09');

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $owner['email']]);

        $this->assertRefused(
            $result,
            $adminId,
            self::REFUSED . " This owner's address is invalid. It bounced on 2025-03-14.",
            'Multiple (1 users)',
            'email_bounced'
        );
    }

    public function testMultipleWithOneNoProfileMatchIsRefused(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(false);

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $owner['email']]);

        $this->assertRefused($result, $adminId, self::REFUSED . ' This owner has no profile.', 'Multiple (1 users)', 'no_profile');
    }

    public function testMultipleWithACleanMatchReachesEmail(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $owner['email']]);

        $this->assertReachedEmail($adminId, 'Multiple (1 users)');
        $this->assertSame([], array_filter($result['flash']['error'], static fn(string $e): bool => str_starts_with($e, self::REFUSED)));
    }

    public function testMultipleSendReachesTheSentPathAndLogsNoAddress(): void
    {
        $this->saveBrevoTestKey();
        $adminId = $this->createCaller(2);
        // FILTER_VALIDATE_EMAIL accepts ' and & in the local part.
        $email = "o'neil&co_" . uniqid() . '@example.com';
        $this->createTestUser(['email' => $email], true);

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $email]);

        $this->assertSame([], $result['flash']['error'], $result['raw']);
        // sessionValMessages() runs sanitizeHTML(), which decodes entities before it
        // stores the text, so the session holds the plain address (see #2439).
        $this->assertSame(
            ['Administrator message sent successfully to duplicate accounts at ' . $email],
            $result['flash']['success']
        );
        $this->assertSame(
            ["Admin contact sent - Admin: {$adminId}, Owner: Multiple (1 users), Car: Multiple, Issue: policy test"],
            $this->adminContactLogs($adminId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact sent')
        );
        $this->assertNoAddressInContactLogs($adminId);
    }

    public function testMultipleWithACleanAndAnOptedOutUserIsRefused(): void
    {
        $adminId = $this->createCaller(2);
        $clean = $this->createOwner(true);
        $optedOut = $this->createOwner(true);
        $this->setOwnerOptedOut($optedOut['id']);
        // The opted-out user holds the same address in another case, with white space around it.
        $this->setUserEmailRaw($optedOut['id'], " \t" . strtoupper($clean['email']) . ' ');

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $clean['email']]);

        $this->assertRefused($result, $adminId, self::REFUSED . ' The owner opted out.', 'Multiple (2 users)', 'email_opted_out');
    }

    public function testMultipleWithABounceOnAnOldAddressOfTheOnlyMatchReachesEmail(): void
    {
        $adminId = $this->createCaller(2);
        $owner = $this->createOwner(true);
        $this->setOwnerBounced($owner['id'], $owner['email']);
        // An admin edit of users.email: the profile keeps the bounce of the old address.
        $newAddress = 'changed_' . uniqid() . '@example.com';
        $this->setUserEmailRaw($owner['id'], $newAddress);

        $result = $this->contact($adminId, 'Multiple', 'Multiple', ['target_email' => $newAddress]);

        $this->assertReachedEmail($adminId, 'Multiple (1 users)');
        $this->assertSame([], array_filter($result['flash']['error'], static fn(string $e): bool => str_starts_with($e, self::REFUSED)));
    }

    public function testEditorIsCheckedLikeAnAdmin(): void
    {
        $editorId = $this->createCaller(3);
        $owner = $this->createOwner(true);
        $carId = $this->createTestCar($owner['id']);
        $this->setOwnerOptedOut($owner['id']);

        $result = $this->contact($editorId, (string) $owner['id'], (string) $carId);

        $this->assertRefused($result, $editorId, self::REFUSED . ' The owner opted out.', (string) $owner['id'], 'email_opted_out');
    }

    public function testRegularOwnerIsRedirectedWithoutFlashSendOrBlockedRow(): void
    {
        $callerId = $this->createCaller(1);
        // A no-profile target: if the guard were missing, the policy would add an error flash.
        $owner = $this->createOwner(false);
        $carId = $this->createTestCar($owner['id']);

        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            [
                'action' => 'admin_contact_owner',
                'message' => 'Policy test message',
                'car_id' => (string) $carId,
                'owner_id' => (string) $owner['id'],
                'quality_issue' => 'policy test',
            ],
            $callerId
        );

        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        // securePage() redirects a user without permission 2 or 3 before the
        // isRegistryAdmin() line runs, so that line is a second guard.
        $this->assertSame(302, $result['httpCode'], 'A regular owner must be redirected: ' . $result['raw']);
        $this->assertSame([], $result['flash']['error']);
        $this->assertSame([], $result['flash']['success']);
        $this->assertSame([], $this->adminContactLogs($callerId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin contact'));
        $this->assertSame([], $this->adminContactLogs($callerId, LogCategories::LOG_CATEGORY_EMAIL_ERROR, 'Admin contact'));
    }
}
