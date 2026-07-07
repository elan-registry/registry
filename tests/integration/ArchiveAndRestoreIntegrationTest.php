<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/account-cleanup-helpers.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for archiveAccounts() and restoreArchivedAccount().
 *
 * All fixtures are cleaned up in tearDown().
 *
 * @see ArchiveAccountsTest        (unit tests)
 * @see RestoreArchivedAccountTest (unit tests)
 */
#[Group('integration')]
#[Group('admin')]
final class ArchiveAndRestoreIntegrationTest extends IntegrationTestCase
{
    /** @var int[] Archive row IDs created during this test */
    private array $createdArchiveIds = [];

    /** @var int[] New user IDs created by restoreArchivedAccount() */
    private array $restoredUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->restoredUserIds as $userId) {
                try {
                    $this->db->query("DELETE FROM user_permission_matches WHERE user_id = ?", [$userId]);
                    $this->db->delete('users', ['id', '=', $userId]);
                } catch (RuntimeException $e) {
                    // ignore cleanup errors
                }
            }
            foreach ($this->createdArchiveIds as $archiveId) {
                try {
                    $this->db->query("DELETE FROM deleted_accounts_archive WHERE id = ?", [$archiveId]);
                } catch (RuntimeException $e) {
                    // ignore cleanup errors
                }
            }
        }
        $this->createdArchiveIds = [];
        $this->restoredUserIds   = [];
        parent::tearDown();
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days")) . ' 00:00:00';
    }

    /** @return int[] */
    private function archiveRowsFor(int $userId): array
    {
        $rows = $this->db->query(
            "SELECT id FROM deleted_accounts_archive WHERE original_user_id = ?",
            [$userId]
        )->results();
        return array_map(fn($r) => (int) $r->id, $rows);
    }

    // -------------------------------------------------------------------------
    // archiveAccounts() integration tests
    // -------------------------------------------------------------------------

    public function testArchiveAccountsCreatesArchiveRows(): void
    {
        $userId1 = $this->createTestUser(['join_date' => $this->daysAgo(31)]);
        $userId2 = $this->createTestUser(['join_date' => $this->daysAgo(45)]);

        archiveAccounts($this->db, [$userId1, $userId2], 1, 'unverified');

        $ids1 = $this->archiveRowsFor($userId1);
        $ids2 = $this->archiveRowsFor($userId2);
        $this->createdArchiveIds = array_merge($this->createdArchiveIds, $ids1, $ids2);

        $this->assertCount(1, $ids1, 'One archive row created for user 1');
        $this->assertCount(1, $ids2, 'One archive row created for user 2');

        $row = $this->db->query(
            "SELECT deletion_type FROM deleted_accounts_archive WHERE id = ?",
            [$ids1[0]]
        )->first();
        $this->assertSame('unverified', $row->deletion_type);
    }

    public function testArchiveAccountsEmptyArrayIsNoOp(): void
    {
        $before = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM deleted_accounts_archive"
        )->first()->cnt;

        archiveAccounts($this->db, [], 1, 'unverified');

        $after = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM deleted_accounts_archive"
        )->first()->cnt;

        $this->assertSame($before, $after, 'Empty user list must not insert any archive rows');
    }

    public function testDeletionTypeStoredCorrectly(): void
    {
        $userId1 = $this->createTestUser(['join_date' => $this->daysAgo(31)]);
        $userId2 = $this->createTestUser(['join_date' => $this->daysAgo(45)]);

        archiveAccounts($this->db, [$userId1], 1, 'unverified');
        archiveAccounts($this->db, [$userId2], 1, 'verified');

        $ids1 = $this->archiveRowsFor($userId1);
        $ids2 = $this->archiveRowsFor($userId2);
        $this->createdArchiveIds = array_merge($this->createdArchiveIds, $ids1, $ids2);

        $row1 = $this->db->query("SELECT deletion_type FROM deleted_accounts_archive WHERE id = ?", [$ids1[0]])->first();
        $row2 = $this->db->query("SELECT deletion_type FROM deleted_accounts_archive WHERE id = ?", [$ids2[0]])->first();

        $this->assertSame('unverified', $row1->deletion_type);
        $this->assertSame('verified', $row2->deletion_type);
    }

    // -------------------------------------------------------------------------
    // restoreArchivedAccount() integration tests
    // -------------------------------------------------------------------------

    public function testRestoreArchivedAccountCreatesUserAndPermission(): void
    {
        $originalUserId = $this->createTestUser([
            'join_date' => $this->daysAgo(31),
            'fname'     => 'Restore',
            'lname'     => 'Test',
        ]);

        archiveAccounts($this->db, [$originalUserId], 1, 'unverified');
        $archiveId = $this->archiveRowsFor($originalUserId)[0];
        $this->createdArchiveIds[] = $archiveId;

        // Simulate deletion: remove permissions and user row
        $this->db->query("DELETE FROM user_permission_matches WHERE user_id = ?", [$originalUserId]);
        $this->db->delete('users', ['id', '=', $originalUserId]);

        $newUserId = restoreArchivedAccount($this->db, $archiveId, 1);
        $this->restoredUserIds[] = $newUserId;

        $user = $this->db->query("SELECT email_verified FROM users WHERE id = ?", [$newUserId])->first();
        $this->assertNotNull($user, 'Restored user must exist');
        $this->assertSame('0', (string) $user->email_verified, 'Restored user must have email_verified = 0');

        $perm = $this->db->query(
            "SELECT id FROM user_permission_matches WHERE user_id = ? AND permission_id = 1",
            [$newUserId]
        )->first();
        $this->assertNotNull($perm, 'Restored user must have base permission (permission_id=1)');

        $archiveRow = $this->db->query(
            "SELECT restored_at FROM deleted_accounts_archive WHERE id = ?",
            [$archiveId]
        )->first();
        $this->assertNotNull($archiveRow->restored_at, 'Archive row must have restored_at set');
    }

    public function testRestoreThrowsForAlreadyRestoredRow(): void
    {
        $userId = $this->createTestUser(['join_date' => $this->daysAgo(31)]);
        archiveAccounts($this->db, [$userId], 1, 'unverified');
        $archiveId = $this->archiveRowsFor($userId)[0];
        $this->createdArchiveIds[] = $archiveId;

        $this->db->query(
            "UPDATE deleted_accounts_archive SET restored_at = NOW(), restored_by = 1 WHERE id = ?",
            [$archiveId]
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or already restored/');
        restoreArchivedAccount($this->db, $archiveId, 1);
    }

    public function testRestoreThrowsForNonExistentArchiveId(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or already restored/');
        restoreArchivedAccount($this->db, 999999999, 1);
    }
}
