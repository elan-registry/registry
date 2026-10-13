<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * requireAdminAjax() at the HTTP layer, through one read-only endpoint:
 * app/admin/includes/process-owner-search.php.
 *
 * The source pins in tests/unit/security/AdminAjaxGuardTest.php only prove
 * that each endpoint calls the guard. These tests prove that the guard
 * refuses a bad request, and that it refuses for the right reason.
 *
 * isRegistryAdmin() accepts permission 2 (Admin) AND 3 (Editor), so an
 * editor passes this guard. The refusal case uses a user with permission 1.
 *
 * Each request runs in its own `php -r` subprocess because
 * ApiResponse::send() calls exit.
 */
#[Group('integration')]
final class AdminAjaxGuardBehaviorTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/admin/includes/process-owner-search.php';
    private const DENIED_NOTE = 'Unauthorized owner search attempt';
    private const CSRF_NOTE = 'Invalid CSRF token in owner search';

    /** @var int Highest logs.id before the test, so tearDown() deletes only rows written after it */
    private int $logIdFloor = 0;

    /** @var int[] Users this class created, whose new logs rows tearDown() deletes */
    private array $guardUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->guardUserIds = [];

        $max = $this->db->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM logs')->first();
        $this->assertFalse($this->db->error(), 'Failed to read logs MAX(id) for test setup: ' . $this->db->errorString());
        $this->logIdFloor = (int) ($max->max_id ?? 0);
    }

    protected function tearDown(): void
    {
        // A floor of 0 means the setUp() snapshot failed. Deleting then would
        // remove rows that other runs wrote.
        if ($this->databaseConnected && $this->logIdFloor > 0) {
            foreach ($this->guardUserIds as $userId) {
                $this->db->query('DELETE FROM logs WHERE id > ? AND user_id = ?', [$this->logIdFloor, $userId]);
                if ($this->db->error()) {
                    fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$userId}: {$this->db->errorString()}\n");
                }
            }
            // requireAdminAjax() writes the access-denied row with user_id 0, so
            // the exact note is the only way to find this test's row.
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND user_id = 0 AND lognote = ?',
                [$this->logIdFloor, self::DENIED_NOTE]
            );
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for access-denied logs rows: {$this->db->errorString()}\n");
            }
        }

        parent::tearDown();
    }

    private function createGuardUser(int $permissionId): int
    {
        $userId = $this->createTestUser();
        $this->grantTestPermission($userId, $permissionId);
        $this->guardUserIds[] = $userId;

        return $userId;
    }

    /**
     * @param array{status: bool, message: string, httpCode: int|null, exitCode: int, raw: string, json: array<string, mixed>|null} $result
     */
    private function assertCleanGuardRefusal(array $result): void
    {
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(403, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertFalse($result['status'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
    }

    public function testUserWithoutAdminOrEditorPermissionIsRefused(): void
    {
        $userId = $this->createGuardUser(1); // 1 = User
        $deniedBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%');

        // A valid token proves that the refusal comes from the authorization check.
        $result = $this->invokeEndpoint(self::ENDPOINT, ['query' => 'smith'], $userId);

        $this->assertCleanGuardRefusal($result);
        $this->assertSame('Unauthorized access', $result['message']);
        $this->assertSame(
            $deniedBefore + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%'),
            'Exactly one access-denied log row must be written'
        );
    }

    public function testAdminWithInvalidCsrfTokenIsRefusedByCsrfCheck(): void
    {
        $adminId = $this->createGuardUser(2); // 2 = Admin
        $deniedBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%');
        $csrfBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $adminId);

        $result = $this->invokeEndpoint(self::ENDPOINT, ['query' => 'smith'], $adminId, 'invalid');

        $this->assertCleanGuardRefusal($result);
        $this->assertSame('Invalid CSRF token', $result['message']);
        $this->assertSame(
            $csrfBefore + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $adminId),
            'Exactly one security log row must be written for this user'
        );
        // Without this check, a broken authorization step could produce the same 403.
        $this->assertSame(
            $deniedBefore,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%'),
            'An admin must not be refused by the authorization check'
        );
    }

    public function testUnauthorizedUserWithInvalidCsrfIsRefusedByAuthorizationFirst(): void
    {
        $userId = $this->createGuardUser(1);
        $deniedBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%');
        $csrfBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $userId);

        $result = $this->invokeEndpoint(self::ENDPOINT, ['query' => 'smith'], $userId, 'invalid');

        // Both checks would refuse this request. Only the order decides which one does.
        $this->assertCleanGuardRefusal($result);
        $this->assertSame('Unauthorized access', $result['message']);
        $this->assertSame(
            $deniedBefore + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%'),
            'Exactly one access-denied log row must be written'
        );
        $this->assertSame(
            $csrfBefore,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $userId),
            'The authorization check must refuse the request before the CSRF check runs'
        );
    }

    public function testAdminWithoutCsrfFieldIsRefusedByCsrfCheck(): void
    {
        $adminId = $this->createGuardUser(2);
        $deniedBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%');
        $csrfBefore = $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $adminId);

        // No csrf key: the isset() branch must refuse the request before Token::check() reads it.
        $result = $this->invokeEndpoint(self::ENDPOINT, ['query' => 'smith'], $adminId, 'omit');

        $this->assertCleanGuardRefusal($result);
        $this->assertSame('Invalid CSRF token', $result['message']);
        $this->assertSame(
            $csrfBefore + 1,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_SECURITY, self::CSRF_NOTE . '%', $adminId),
            'Exactly one security log row must be written for this user'
        );
        $this->assertSame(
            $deniedBefore,
            $this->countMatchingLogs(LogCategories::LOG_CATEGORY_ACCESS_DENIED, self::DENIED_NOTE . '%'),
            'An admin must not be refused by the authorization check'
        );
    }

    public function testAdminWithValidCsrfPassesTheGuard(): void
    {
        $adminId = $this->createGuardUser(2);

        // This request passes the guard, so it writes rate-limit rows. tearDown() deletes them.
        $this->trackNewRateLimitRows('admin_ajax_search');

        $result = $this->invokeEndpoint(self::ENDPOINT, ['query' => 'smith'], $adminId);

        // Control for the two refusal tests: a setup that refuses every request would pass them.
        $this->assertSame(0, $result['exitCode'], 'Subprocess must exit cleanly: ' . $result['raw']);
        $this->assertSame(200, $result['httpCode'], $result['raw']);
        $this->assertIsArray($result['json'], 'Response must be valid JSON: ' . $result['raw']);
        $this->assertTrue($result['status'], $result['raw']);
        $this->assertNoPhpErrorText($result['raw']);
        $this->assertNotSame('Unauthorized access', $result['message']);
        $this->assertNotSame('Invalid CSRF token', $result['message']);
    }
}
