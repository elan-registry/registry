<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * isRegistryAdmin() against real user_permission_matches rows (#1897).
 *
 * The Email on file row on the Vehicle Information card shows only when
 * isRegistryAdmin() is true. Editors (permission 3) must see it, the same as
 * admins (permission 2). A plain user (permission 1) must not. The unit tests
 * of the card stub this value, so this test checks the real permission query.
 */
#[Group('integration')]
final class IsRegistryAdminTest extends IntegrationTestCase
{
    private const PERMISSION_USER = 1;
    private const PERMISSION_ADMIN = 2;
    private const PERMISSION_EDITOR = 3;

    /** @var int[] user_permission_matches row IDs that this test creates, removed in tearDown() */
    private array $createdPermissionMatchIds = [];

    /** True when setUp() set $master_account, so tearDown() must unset it. */
    private bool $setMasterAccount = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->createdPermissionMatchIds = [];

        // hasPerm() reads $master_account, which users/init.php sets but the
        // integration bootstrap does not. Without it, a user with no matching
        // permission makes hasPerm() throw a TypeError instead of returning false.
        if (!isset($GLOBALS['master_account'])) {
            $GLOBALS['master_account'] = [1];
            $this->setMasterAccount = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->setMasterAccount) {
            unset($GLOBALS['master_account']);
            $this->setMasterAccount = false;
        }

        if ($this->databaseConnected) {
            foreach ($this->createdPermissionMatchIds as $id) {
                $this->db->query('DELETE FROM user_permission_matches WHERE id = ?', [$id]);
            }
        }

        parent::tearDown();
    }

    /** Give $permissionId to $userId with a real user_permission_matches row. */
    private function grantPermission(int $userId, int $permissionId): void
    {
        $this->db->insert('user_permission_matches', [
            'user_id' => $userId,
            'permission_id' => $permissionId,
        ]);
        $this->assertFalse($this->db->error(), 'Failed to grant permission for test setup: ' . $this->db->errorString());
        $this->createdPermissionMatchIds[] = (int) $this->db->lastId();
    }

    /** Create a user who has only $permissionId. */
    private function userWithOnlyPermission(int $permissionId): int
    {
        $userId = $this->createTestUser();
        $this->grantPermission($userId, $permissionId);

        return $userId;
    }

    public function testEditorOnlyIsRegistryAdmin(): void
    {
        $this->assertTrue(isRegistryAdmin($this->userWithOnlyPermission(self::PERMISSION_EDITOR)));
    }

    public function testAdminOnlyIsRegistryAdmin(): void
    {
        $this->assertTrue(isRegistryAdmin($this->userWithOnlyPermission(self::PERMISSION_ADMIN)));
    }

    public function testPlainUserIsNotRegistryAdmin(): void
    {
        $this->assertFalse(isRegistryAdmin($this->userWithOnlyPermission(self::PERMISSION_USER)));
    }
}
