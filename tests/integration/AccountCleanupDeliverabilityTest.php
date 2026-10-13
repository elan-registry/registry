<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../../app/admin/includes/account-cleanup-helpers.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #2101: archive and restore of the owner's deliverability record, against the
 * real database.
 *
 * The profile is the system of record. A restore keeps the owner's opt-out,
 * never copies a block or a bounce, always creates a profile row (also for an
 * owner with none, or with empty text fields), and dates the address from the
 * restore time, not from the old join date.
 */
#[Group('integration')]
#[Group('admin')]
final class AccountCleanupDeliverabilityTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $archiveIds = [];

    /** @var list<int> */
    private array $restoredUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->archiveIds = [];
        $this->restoredUserIds = [];
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->restoredUserIds as $userId) {
                $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
                $this->db->query('DELETE FROM user_permission_matches WHERE user_id = ?', [$userId]);
                $this->db->query('DELETE FROM users WHERE id = ?', [$userId]);
            }
            foreach ($this->archiveIds as $archiveId) {
                $this->db->query('DELETE FROM deleted_accounts_archive WHERE id = ?', [$archiveId]);
            }
        }
        parent::tearDown();
    }

    /**
     * Archive the user, then remove the user as the real deletion does.
     *
     * @return int The archive row id
     */
    private function archiveAndDelete(int $userId): int
    {
        archiveAccounts($this->db, [$userId], 1, 'verified');
        $rows = $this->db->query('SELECT id FROM deleted_accounts_archive WHERE original_user_id = ?', [$userId])->results();
        $this->assertCount(1, $rows, 'Test setup: exactly one archive row');
        $archiveId = (int) $rows[0]->id;
        $this->archiveIds[] = $archiveId;

        $this->db->query('DELETE FROM profiles WHERE user_id = ?', [$userId]);
        $this->db->query('DELETE FROM user_permission_matches WHERE user_id = ?', [$userId]);
        $this->db->query('DELETE FROM users WHERE id = ?', [$userId]);

        return $archiveId;
    }

    private function restore(int $archiveId): int
    {
        $newUserId = restoreArchivedAccount($this->db, $archiveId, 1);
        $this->restoredUserIds[] = $newUserId;

        return $newUserId;
    }

    /**
     * @return array<string, mixed>
     */
    private function restoredProfile(int $newUserId): array
    {
        $rows = $this->db->query('SELECT * FROM profiles WHERE user_id = ?', [$newUserId])->results();
        $this->assertCount(1, $rows, 'The restore must create exactly one profile row');

        return (array) $rows[0];
    }

    public function testArchiveCarriesTheOptOutAndItsTime(): void
    {
        $userId = $this->createTestUser(['email' => 'archive-optout@example.com'], true);
        $this->setOwnerOptedOut($userId, true, '2026-03-04 05:06:07');

        archiveAccounts($this->db, [$userId], 1, 'verified');
        $row = $this->db->query(
            'SELECT id, email_opted_out, email_opted_out_at FROM deleted_accounts_archive WHERE original_user_id = ?',
            [$userId]
        )->first();
        $this->assertIsObject($row);
        $this->archiveIds[] = (int) $row->id;

        $this->assertSame('1', (string) $row->email_opted_out);
        $this->assertSame('2026-03-04 05:06:07', $row->email_opted_out_at);
    }

    public function testArchiveOfAnOwnerWhoDidNotOptOutStoresZero(): void
    {
        $userId = $this->createTestUser(['email' => 'archive-no-optout@example.com'], true);

        archiveAccounts($this->db, [$userId], 1, 'verified');
        $row = $this->db->query(
            'SELECT id, email_opted_out, email_opted_out_at FROM deleted_accounts_archive WHERE original_user_id = ?',
            [$userId]
        )->first();
        $this->assertIsObject($row);
        $this->archiveIds[] = (int) $row->id;

        $this->assertSame('0', (string) $row->email_opted_out);
        $this->assertNull($row->email_opted_out_at);
    }

    public function testRestoreSetsTheArchivedOptOutOnTheNewProfile(): void
    {
        $userId = $this->createTestUser(['email' => 'restore-optout@example.com'], true);
        $this->setOwnerOptedOut($userId, true, '2026-03-04 05:06:07');
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        $this->assertSame('1', (string) $profile['email_opted_out'], 'A restore must not turn mail on for an owner who opted out');
        $this->assertSame('2026-03-04 05:06:07', $profile['email_opted_out_at']);
    }

    public function testRestoreDoesNotCopyBlockedOrBouncedFlags(): void
    {
        $userId = $this->createTestUser(['email' => 'restore-flags@example.com'], true);
        $this->setOwnerBlocked($userId, 'restore-flags@example.com', '2026-01-01 00:00:00', 'spam');
        $this->setOwnerBounced($userId, 'restore-flags@example.com', '2026-01-02 00:00:00');
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        foreach (['email_blocked', 'email_bounced', 'email_opted_out'] as $flag) {
            $this->assertSame('0', (string) $profile[$flag], "{$flag} must be 0 after a restore");
        }
        foreach (
            [
                'email_blocked_address', 'email_blocked_at', 'email_blocked_cause',
                'email_bounced_address', 'email_bounced_at', 'email_bounce_cleared_at', 'email_opted_out_at',
            ] as $column
        ) {
            $this->assertNull($profile[$column], "{$column} must be NULL after a restore");
        }
    }

    public function testRestoreOfAnOwnerWithNoProfileRowStillInsertsOneWithEmptyTextFields(): void
    {
        $userId = $this->createTestUser(['email' => 'restore-noprofile@example.com']);
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt,
            'Test setup: the owner has no profile row'
        );
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        foreach (['bio', 'city', 'state', 'country'] as $column) {
            $this->assertSame('', $profile[$column], "{$column} must be an empty string, not NULL");
        }
        $this->assertNull($profile['website']);
        $this->assertSame('0', (string) $profile['email_opted_out']);
    }

    public function testRestoreOfAnOwnerWhoseProfileHasNoCityStateCountryBioOrWebsiteInsertsAProfile(): void
    {
        // createTestUser stores '' for bio, city, state and country, and no website.
        $userId = $this->createTestUser(['email' => 'restore-emptyprofile@example.com'], true);
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        foreach (['bio', 'city', 'state', 'country'] as $column) {
            $this->assertSame('', $profile[$column]);
        }
        $this->assertNull($profile['website']);
    }

    public function testRestoreKeepsTheArchivedProfileText(): void
    {
        $userId = $this->createTestUser(['email' => 'restore-text@example.com'], true);
        $this->db->query(
            "UPDATE profiles SET city = 'Norwich', state = 'Norfolk', country = 'UK', bio = 'Elan S4 owner', website = 'https://example.org' WHERE user_id = ?",
            [$userId]
        );
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        $this->assertSame('Norwich', $profile['city']);
        $this->assertSame('Norfolk', $profile['state']);
        $this->assertSame('UK', $profile['country']);
        $this->assertSame('Elan S4 owner', $profile['bio']);
        $this->assertSame('https://example.org', $profile['website']);
    }

    public function testRestoreSetsEmailAddressSinceToTheRestoreTimeAndSeenToTheNormalizedAddress(): void
    {
        $oldJoin = date('Y-m-d H:i:s', strtotime('-400 days'));
        $userId = $this->createTestUser(['email' => 'Restore-Since@Example.COM', 'join_date' => $oldJoin], true);
        $archiveId = $this->archiveAndDelete($userId);

        $newUserId = $this->restore($archiveId);

        $profile = $this->restoredProfile($newUserId);
        $this->assertNotSame($oldJoin, $profile['email_address_since'], 'The old join_date must not be used');
        $this->assertEqualsWithDelta(
            time(),
            strtotime((string) $profile['email_address_since']),
            10,
            'email_address_since is the restore time'
        );
        $this->assertSame('restore-since@example.com', $profile['email_address_seen']);
    }
}
