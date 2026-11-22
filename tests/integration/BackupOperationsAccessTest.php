<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * securePage() on app/admin/includes/system/backup-operations.php refuses a
 * visitor who is not logged in. The visitor gets a redirect and no backup data.
 *
 * Each request runs the real endpoint in its own `php -r` subprocess. The
 * admin request is the control. It shows that the same harness gets past
 * securePage() when the request is allowed. The control uses list_backups
 * only, because create_manual_backup as an admin writes a real backup file.
 */
#[Group('integration')]
#[Group('admin')]
#[Group('security')]
final class BackupOperationsAccessTest extends IntegrationTestCase
{
    private const ENDPOINT = 'app/admin/includes/system/backup-operations.php';

    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        // The admin list_backups request writes a backup-manager log row.
        if ($this->databaseConnected && $this->adminId > 0) {
            $this->db->query('DELETE FROM logs WHERE user_id = ?', [$this->adminId]);
            if ($this->db->error()) {
                fwrite(STDERR, "NOTE: tearDown() cleanup failed for logs of user ID {$this->adminId}: {$this->db->errorString()}\n");
            }
        }
        parent::tearDown();
    }

    public function testVisitorWhoIsNotLoggedInCannotListBackups(): void
    {
        $result = $this->invokeEndpoint(self::ENDPOINT, ['action' => 'list_backups'], null);

        // The positive marker: the endpoint ran to the end and securePage() sent
        // the redirect. Without it, a crash would also show no backup data.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect a visitor who is not logged in');
        $this->assertStringNotContainsString('"success":true', $result['raw']);
        $this->assertStringNotContainsString('"backups"', $result['raw'], 'A visitor must not get the backup list');
    }

    public function testVisitorWhoIsNotLoggedInCannotCreateManualBackup(): void
    {
        $result = $this->invokeEndpoint(
            self::ENDPOINT,
            ['action' => 'create_manual_backup', 'reason' => 'security-test'],
            null
        );

        // The positive marker, as for list_backups.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect a visitor who is not logged in');
        $this->assertStringNotContainsString('"success":true', $result['raw']);
        $this->assertStringNotContainsString('"filename"', $result['raw'], 'A visitor must not create a backup');
    }

    public function testAdminIsNotRedirected(): void
    {
        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->grantTestPermission($this->adminId, 2); // 2 = Admin

        $result = $this->invokeEndpoint(self::ENDPOINT, ['action' => 'list_backups'], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertNotSame(302, $result['httpCode'], 'The control: an admin gets past securePage(), not a redirect');
        $this->assertTrue($result['status'], 'The control: an admin gets the backup list: ' . substr($result['raw'], 0, 500));
    }
}
