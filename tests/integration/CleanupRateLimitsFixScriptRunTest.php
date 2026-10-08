<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1775: script #25 (25-Cleanup-Rate-Limits.php) records a fix_script_runs
 * row. The logic is inline in a gated page, so the test runs its call
 * sequence directly: cleanup(24), then admin_script_record_completion().
 * The page gating and HTML are not covered.
 */
#[Group('integration')]
#[Group('database')]
final class CleanupRateLimitsFixScriptRunTest extends IntegrationTestCase
{
    private const SCRIPT_NAME = '25-Cleanup-Rate-Limits.php';

    /** @var list<int> us_rate_limits row IDs seeded by this test, deleted in tearDown if cleanup() didn't already remove them. */
    private array $seededRateLimitIds = [];

    /** @var list<int> fix_script_runs row IDs inserted by this test, deleted in tearDown. */
    private array $insertedFixScriptRunIds = [];

    private ?int $testUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        require_once __DIR__ . '/../../app/admin/includes/fix-script-core.php';

        $this->testUserId = $this->createTestUser();
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->insertedFixScriptRunIds as $id) {
                $this->db->query('DELETE FROM fix_script_runs WHERE id = ?', [$id]);
            }
            foreach ($this->seededRateLimitIds as $id) {
                // cleanup() should delete these, but a cutoff change must not leak rows.
                $this->db->query('DELETE FROM us_rate_limits WHERE id = ?', [$id]);
            }
        }

        parent::tearDown();
    }

    public function testCompletedCleanupRunInsertsExactlyOneFixScriptRunsRow(): void
    {
        $this->seedExpiredRateLimitRow();
        $this->seedExpiredRateLimitRow();

        $beforeCount = $this->countFixScriptRunsRows();

        // Same sequence as the script's try block.
        $removed = (new \RateLimit())->cleanup(24);
        $this->assertGreaterThanOrEqual(
            2,
            $removed,
            'cleanup(24) must have removed at least the two expired rows seeded by this test'
        );

        $beforeInsert = new \DateTimeImmutable('now');
        admin_script_record_completion(
            __DIR__ . '/../../app/admin/scripts/maintenance/' . self::SCRIPT_NAME,
            (int) $this->testUserId
        );
        $afterInsert = new \DateTimeImmutable('now');

        $afterCount = $this->countFixScriptRunsRows();
        $this->assertSame(
            $beforeCount + 1,
            $afterCount,
            'Exactly one new fix_script_runs row must be inserted for a completed cleanup run'
        );

        $rows = $this->fetchAllRows(
            'SELECT * FROM fix_script_runs WHERE script_name = ? ORDER BY id DESC LIMIT 1',
            [self::SCRIPT_NAME]
        );
        $this->assertCount(1, $rows, 'A fix_script_runs row for ' . self::SCRIPT_NAME . ' must exist');

        $row = $rows[0];
        $this->insertedFixScriptRunIds[] = (int) $row['id'];

        $this->assertSame(
            self::SCRIPT_NAME,
            $row['script_name'],
            'script_name must be the bare filename, matching basename(__FILE__) inside the script'
        );

        $completedAt = new \DateTimeImmutable((string) $row['completed_at']);
        $this->assertGreaterThanOrEqual(
            $beforeInsert->modify('-2 seconds'),
            $completedAt,
            'completed_at must be fresh (at or after the moment admin_script_record_completion() was called)'
        );
        $this->assertLessThanOrEqual(
            $afterInsert->modify('+2 seconds'),
            $completedAt,
            'completed_at must be fresh (at or before the moment admin_script_record_completion() returned)'
        );
    }

    /** Zero rows removed is still a successful run and still records completion. */
    public function testCleanupWithNoExpiredRowsStillRecordsCompletion(): void
    {
        $beforeCount = $this->countFixScriptRunsRows();

        $removed = (new \RateLimit())->cleanup(24);
        $this->assertGreaterThanOrEqual(0, $removed);

        admin_script_record_completion(
            __DIR__ . '/../../app/admin/scripts/maintenance/' . self::SCRIPT_NAME,
            (int) $this->testUserId
        );

        $afterCount = $this->countFixScriptRunsRows();
        $this->assertSame(
            $beforeCount + 1,
            $afterCount,
            'A completion row must be recorded even when there was nothing to clean up'
        );

        $rows = $this->fetchAllRows(
            'SELECT id FROM fix_script_runs WHERE script_name = ? ORDER BY id DESC LIMIT 1',
            [self::SCRIPT_NAME]
        );
        $this->assertCount(1, $rows);
        $this->insertedFixScriptRunIds[] = (int) $rows[0]['id'];
    }

    private function seedExpiredRateLimitRow(): void
    {
        $identifier = 'test:' . uniqid('fix-script-run-test-', true);
        $expiredAttemptTime = date('Y-m-d H:i:s', time() - (25 * 3600));

        $this->db->insert('us_rate_limits', [
            'identifier_key' => $identifier,
            'action' => 'test_action',
            'success' => 0,
            'attempt_time' => $expiredAttemptTime,
            'metadata' => json_encode([]),
        ]);

        $this->seededRateLimitIds[] = (int) $this->db->lastId();
    }

    private function countFixScriptRunsRows(): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM fix_script_runs WHERE script_name = ?',
            [self::SCRIPT_NAME]
        )->first();

        return is_object($row) ? (int) $row->cnt : 0;
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    private function fetchAllRows(string $sql, array $bindings = []): array
    {
        $rows = $this->db->query($sql, $bindings)->results();

        return array_map(static fn(object $row): array => (array) $row, $rows);
    }
}
