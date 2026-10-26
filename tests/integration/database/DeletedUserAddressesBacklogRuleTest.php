<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\Retention\DeletedUserAddressesBacklogRule;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2419: DeletedUserAddressesBacklogRule against the real
 * er_deleted_user_addresses table.
 *
 * The rule compares with MySQL NOW(), as the trigger writes deleted_at with
 * MySQL NOW(). So the fixture rows use MySQL NOW() too, not the PHP clock.
 *
 * Fixture rows use user IDs near the INT maximum, far above any real
 * users.id. Not negative IDs: the column is unsigned in some schemas, and a
 * non-strict session stores a negative value as 0.
 */
#[Group('integration')]
final class DeletedUserAddressesBacklogRuleTest extends IntegrationTestCase
{
    private const OLD_ID = 2000241901;

    private const FRESH_ID = 2000241902;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $tableCheck = $this->db->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['er_deleted_user_addresses']
        );
        $this->assertFalse($this->db->error(), 'Table check failed: ' . $this->db->errorString());
        if ($tableCheck->count() === 0) {
            $this->markTestSkipped('er_deleted_user_addresses does not exist. Run: composer migrate (20261023)');
        }

        $this->deleteFixtureRows();

        // Only a stale row changes the rule's result. A stale row that this
        // test did not write is a leaked fixture, so the test fails and does not skip.
        $staleResult = $this->db->query(
            'SELECT COALESCE(GROUP_CONCAT(user_id ORDER BY user_id), \'\') AS ids FROM er_deleted_user_addresses
             WHERE deleted_at < NOW() - INTERVAL 24 HOUR AND user_id NOT IN (?, ?)',
            [self::OLD_ID, self::FRESH_ID]
        );
        $this->assertFalse($this->db->error(), 'Stale-row check failed: ' . $this->db->errorString());
        $staleForeign = (string) $staleResult->first()->ids;
        if ($staleForeign !== '') {
            $this->fail(
                "er_deleted_user_addresses holds stale rows that this test did not write (user IDs {$staleForeign}). "
                . 'The rule would report them. Remove the leaked fixture rows: '
                . "DELETE FROM er_deleted_user_addresses WHERE user_id IN ({$staleForeign})"
            );
        }
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->deleteFixtureRows();
        }
        parent::tearDown();
    }

    private function deleteFixtureRows(): void
    {
        $this->db->query('DELETE FROM er_deleted_user_addresses WHERE user_id IN (?, ?)', [self::OLD_ID, self::FRESH_ID]);
    }

    private function seedHoldingRow(int $userId, int $hoursAgo): void
    {
        $this->db->query(
            "INSERT INTO er_deleted_user_addresses (user_id, email, email_new, deleted_at)
             VALUES (?, 'held-2419@example.com', NULL, NOW() - INTERVAL ? HOUR)",
            [$userId, $hoursAgo]
        );
        $this->assertFalse($this->db->error(), 'Test setup: holding row: ' . $this->db->errorString());
    }

    /** Counts only this test's rows: a fresh foreign row can exist. */
    private function holdingRowCount(): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_deleted_user_addresses WHERE user_id IN (?, ?)',
            [self::OLD_ID, self::FRESH_ID]
        )->first()->cnt;
    }

    public function testAnEmptyTablePasses(): void
    {
        $result = (new DeletedUserAddressesBacklogRule($this->db))->purge(new DateTimeImmutable());

        $this->assertFalse($result->isSkipped());
        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
    }

    public function testAFreshRowPasses(): void
    {
        $this->seedHoldingRow(self::FRESH_ID, 23);

        $result = (new DeletedUserAddressesBacklogRule($this->db))->purge(new DateTimeImmutable());

        $this->assertFalse($result->isSkipped());
        $this->assertSame(1, $this->holdingRowCount(), 'The rule must delete nothing');
    }

    public function testAnOldRowFailsTheRuleAndStays(): void
    {
        $this->seedHoldingRow(self::OLD_ID, 25);
        $this->seedHoldingRow(self::FRESH_ID, 1);

        try {
            (new DeletedUserAddressesBacklogRule($this->db))->purge(new DateTimeImmutable());
            $this->fail('A holding row older than 24 hours must fail the rule');
        } catch (RetentionPurgeException $e) {
            $this->assertSame('stale holding rows', $e->step());
            $this->assertStringContainsString('1 deleted user(s)', $e->getMessage());
            $this->assertStringContainsString('user IDs ' . self::OLD_ID . '.', $e->getMessage(), 'Only the old row must be named');
            $this->assertStringNotContainsString('@', $e->getMessage());
        }

        $this->assertSame(2, $this->holdingRowCount(), 'The rerun script needs the holding row');
    }
}
