<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobGuard;
use ElanRegistry\Cron\CronJobRunsReader;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB round trip: CronJobGuard::recordFailure() -> CronJobRunsReader
 * status() -> badgeFor(). Both timestamps come from MySQL NOW() at
 * one-second resolution, so only a real connection proves that a claim
 * followed at once by a failure still shows "Last run failed".
 *
 * setUp()/tearDown() snapshot and restore the shared seeded row.
 */
#[Group('integration')]
final class CronJobFailureRoundTripTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_reconciliation';

    private int $originalEnabled = 1;
    private ?string $originalLastRunAt = null;
    private ?string $originalLastFailureAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->requireMigrationApplied();

        $this->db->query(
            'SELECT enabled, last_run_at, last_failure_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to read original row: ' . $this->db->errorString());

        $row = $this->db->first();
        $this->assertIsObject($row, "er_cron_job_runs must have a seeded '" . self::JOB_NAME . "' row");

        $this->originalEnabled = (int) $row->enabled;
        $this->originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;
        $this->originalLastFailureAt = !empty($row->last_failure_at) ? (string) $row->last_failure_at : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = ? WHERE job_name = ?',
                [$this->originalEnabled, $this->originalLastRunAt, $this->originalLastFailureAt, self::JOB_NAME]
            );
        }

        parent::tearDown();
    }

    /** Skip, not fail, when the column is absent (run `composer migrate`). */
    private function requireMigrationApplied(): void
    {
        $this->db->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            ['er_cron_job_runs', 'last_failure_at']
        );

        if ($this->db->error() || !is_object($this->db->first())) {
            $this->markTestSkipped(
                'Migration 20260922171500_add_cron_job_runs_last_failure_at has not been applied'
                . ' — er_cron_job_runs.last_failure_at does not exist. Run: composer migrate'
            );
        }
    }

    private function setRowState(bool $enabled, ?string $lastRunAt, ?string $lastFailureAt): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = ? WHERE job_name = ?',
            [$enabled ? 1 : 0, $lastRunAt, $lastFailureAt, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to set row fixture: ' . $this->db->errorString());
    }

    /**
     * Claim, then recordFailure(), as AbstractCronJob::run() does on a failed
     * run. The reader used to report this as a clean "Ran".
     */
    public function testClaimThenRecordFailureRendersTheFailureBadge(): void
    {
        // Enabled, and never claimed, so claim() below is guaranteed to win
        // rather than being declined as too recent.
        $this->setRowState(enabled: true, lastRunAt: null, lastFailureAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertTrue(
            $guard->claim(self::JOB_NAME, 24),
            'Test precondition: the claim must be won, since recordFailure() only follows a claimed run'
        );

        $guard->recordFailure(self::JOB_NAME);

        $status = (new CronJobRunsReader($this->db))->status(self::JOB_NAME);

        $this->assertSame(CronJobEnabledState::ENABLED, $status['state']);
        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $status['lastFailureAt'],
            'recordFailure() must have written a real, parseable timestamp'
        );

        $badge = CronJobRunsReader::badgeFor($status['state'], $status['lastRunAt'], $status['lastFailureAt']);

        $this->assertSame(
            'Last run failed',
            $badge['text'],
            'A claim immediately followed by a failure — both stamped by NOW() within the same second —'
            . ' must render as failed, not as the green "Ran" badge the pre-fix code showed'
        );
        $this->assertSame('badge text-bg-danger', $badge['badgeClass']);
    }

    /**
     * A later successful run out-dates the failure with no clearing step:
     * the badge compares timestamps, not a flag.
     */
    public function testASubsequentClaimSupersedesAnEarlierFailure(): void
    {
        // Literals, not recordFailure(), so the two are a day apart.
        $this->setRowState(
            enabled: true,
            lastRunAt: '2026-09-01 02:00:00',
            lastFailureAt: '2026-09-01 02:00:00'
        );

        $failedStatus = (new CronJobRunsReader($this->db))->status(self::JOB_NAME);
        $this->assertSame(
            'Last run failed',
            CronJobRunsReader::badgeFor(
                $failedStatus['state'],
                $failedStatus['lastRunAt'],
                $failedStatus['lastFailureAt']
            )['text'],
            'Test precondition: the row must start in the failed state'
        );

        $this->assertTrue((new CronJobGuard($this->db))->claim(self::JOB_NAME, 24));

        $recoveredStatus = (new CronJobRunsReader($this->db))->status(self::JOB_NAME);
        $badge = CronJobRunsReader::badgeFor(
            $recoveredStatus['state'],
            $recoveredStatus['lastRunAt'],
            $recoveredStatus['lastFailureAt']
        );

        $this->assertSame(
            'Ran',
            $badge['text'],
            'A run claimed after the failure must clear the badge — the failure is history, not current state'
        );
        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $recoveredStatus['lastFailureAt'],
            'The failure timestamp itself is retained; only its currency changes'
        );
    }

    /**
     * A never-failed row must round-trip NULL as null, not a zero-date or
     * epoch, or every timestamp comparison goes wrong.
     */
    public function testNeverFailedRowReadsBackAsNull(): void
    {
        $this->setRowState(enabled: true, lastRunAt: '2026-09-01 02:00:00', lastFailureAt: null);

        $status = (new CronJobRunsReader($this->db))->status(self::JOB_NAME);

        $this->assertNull($status['lastFailureAt']);
        $this->assertSame(
            'Ran',
            CronJobRunsReader::badgeFor($status['state'], $status['lastRunAt'], $status['lastFailureAt'])['text']
        );
    }

    /**
     * Pre-migration deploy with the column really absent. With emulated
     * prepares, DB::query() reports the missing column through error(), not
     * a PDOException; the first fallback assumed a throw and never ran. Only
     * a real connection can show this.
     *
     * The column is restored in a `finally`: other cron tests depend on it.
     */
    public function testStatusDegradesGracefullyWhenTheColumnIsGenuinelyAbsent(): void
    {
        $this->setRowState(enabled: true, lastRunAt: '2026-09-01 02:00:00', lastFailureAt: null);

        $this->db->query('ALTER TABLE er_cron_job_runs DROP COLUMN last_failure_at');
        $this->assertFalse($this->db->error(), 'Test setup: could not drop the column');

        try {
            $status = (new CronJobRunsReader($this->db))->status(self::JOB_NAME);

            $this->assertSame(
                CronJobEnabledState::ENABLED,
                $status['state'],
                'An absent last_failure_at must NOT blank the row out as UNREADABLE — the tab has to keep'
                . ' rendering every job\'s status, just without failure detection'
            );
            $this->assertInstanceOf(
                DateTimeImmutable::class,
                $status['lastRunAt'],
                'The retry must still return the two original columns'
            );
            $this->assertNull($status['lastFailureAt'], 'An absent column reads as "never failed"');

            $this->assertSame(
                'Ran',
                CronJobRunsReader::badgeFor(
                    $status['state'],
                    $status['lastRunAt'],
                    $status['lastFailureAt']
                )['text'],
                'The badge must degrade to its pre-migration behaviour, not to "Status unavailable"'
            );
        } finally {
            $this->db->query(
                'ALTER TABLE er_cron_job_runs ADD COLUMN last_failure_at DATETIME NULL DEFAULT NULL'
                . " COMMENT 'When this job''s execute() last threw; NULL until it fails once.'"
            );
        }

        $this->db->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            ['er_cron_job_runs', 'last_failure_at']
        );
        $this->assertIsObject(
            $this->db->first(),
            'last_failure_at must be restored after this test, regardless of the assertions above'
        );
    }
}
