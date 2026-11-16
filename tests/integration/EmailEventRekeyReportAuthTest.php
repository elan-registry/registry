<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: 29-Report-Email-Event-Rekey.php is admin-only.
 *
 * As in AdminDeliverabilityActionsAuthTest, each request runs the real page in
 * its own `php -r` subprocess. The admin request is the control: it shows that
 * the same harness gets the report when the request is allowed.
 */
#[Group('integration')]
#[Group('admin')]
final class EmailEventRekeyReportAuthTest extends IntegrationTestCase
{
    private const SCRIPT = 'app/admin/scripts/maintenance/29-Report-Email-Event-Rekey.php';

    private const PERMISSION_ADMIN = 2;
    private const PERMISSION_EDITOR = 3;

    /** @var list<int> user_permission_matches ids created by this test */
    private array $permissionMatchIds = [];

    private int $adminId = 0;
    private int $editorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->permissionMatchIds = [];

        $this->adminId = $this->createTestUser(['permissions' => 1]);
        $this->editorId = $this->createTestUser(['permissions' => 1]);
        $this->grantPermission($this->adminId, self::PERMISSION_ADMIN);
        $this->grantPermission($this->editorId, self::PERMISSION_EDITOR);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->permissionMatchIds as $id) {
                $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$id]);
            }
            foreach ([$this->adminId, $this->editorId] as $userId) {
                $this->db->query('DELETE FROM logs WHERE user_id = ?', [$userId]);
            }
        }
        parent::tearDown();
    }

    private function grantPermission(int $userId, int $permissionId): void
    {
        $this->db->insert('user_permission_matches', ['user_id' => $userId, 'permission_id' => $permissionId]);
        $this->assertFalse($this->db->error(), 'Test setup: grant permission: ' . $this->db->errorString());
        $this->permissionMatchIds[] = (int) $this->db->lastId();
    }

    public function testAdminGetsTheReport(): void
    {
        $result = $this->invokeEndpoint(self::SCRIPT, [], $this->adminId);

        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertStringContainsString('Email Event Re-key Report', $result['raw']);
        $this->assertNotSame(302, $result['httpCode'], 'The control: an admin gets the page, not a redirect');
        $this->assertStringNotContainsString('Administrator access required', $result['raw']);
    }

    public function testEditorIsRefusedAndGetsNoReport(): void
    {
        $result = $this->invokeEndpoint(self::SCRIPT, [], $this->editorId);

        // The positive marker: the page ran to the end and securePage() sent the
        // redirect. Without it, a crash would also show no report.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect an editor');
        $this->assertStringNotContainsString('Email Event Re-key Report', $result['raw'], 'An editor must not see the report');
        $this->assertStringNotContainsString('Already migrated', $result['raw']);
        $this->assertStringNotContainsString('Matched by car owner', $result['raw']);
    }

    public function testVisitorWhoIsNotLoggedInIsRefusedAndGetsNoReport(): void
    {
        $result = $this->invokeEndpoint(self::SCRIPT, [], null);

        // The positive marker, as for the editor: a crash would also show no report.
        $this->assertSame(0, $result['exitCode'], substr($result['raw'], 0, 500));
        $this->assertSame(302, $result['httpCode'], 'securePage() must redirect a visitor who is not logged in');
        $this->assertStringNotContainsString('Email Event Re-key Report', $result['raw']);
    }
}
