<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB behavioral test for CronJobRunsReader::state() / lastRunAt() (#2054).
 *
 * CronJobRunsReaderTest (unit, tests/unit/cron/) covers all four
 * CronJobEnabledState outcomes against a FakeDatabase, including the
 * UNREADABLE case a real connection cannot deliberately trigger. This file
 * exercises the same reader against real MySQL instead: the seeded
 * `reconciliation` row (migration 20260908203118_create_cron_job_runs),
 * real `enabled` column round-tripping, and real `datetime` round-tripping
 * through DateTimeImmutable — exactly the kind of thing a mocked-DB unit
 * test cannot fully prove. Mirrors VerificationSettingsCronReadyTest's
 * snapshot-in-setUp/restore-in-tearDown discipline for a shared row.
 */
#[Group('integration')]
final class CronJobRunsReaderDatabaseTest extends IntegrationTestCase
{
    private const JOB_NAME = 'reconciliation';

    /** Original enabled/last_run_at values for the 'reconciliation' row, restored in tearDown(). */
    private int $originalEnabled = 1;
    private ?string $originalLastRunAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db->query(
            'SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        );
        $this->assertFalse(
            $this->db->error(),
            'Failed to read original reconciliation row: ' . $this->db->errorString()
                . ' — likely means this migration has not been applied to the test schema'
        );
        $row = $this->db->first();
        $this->assertIsObject($row, "er_cron_job_runs must have a seeded '" . self::JOB_NAME . "' row");
        $this->originalEnabled = (int) $row->enabled;
        $this->originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
                [$this->originalEnabled, $this->originalLastRunAt, self::JOB_NAME]
            );
        }

        parent::tearDown();
    }

    private function setReconciliationRow(bool $enabled, ?string $lastRunAt): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
            [$enabled ? 1 : 0, $lastRunAt, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to set reconciliation row fixture: ' . $this->db->errorString());
    }

    public function testStateReturnsSeededRowsActualCurrentState(): void
    {
        $reader = new CronJobRunsReader($this->db);

        $state = $reader->state(self::JOB_NAME);

        $this->assertContains(
            $state,
            [CronJobEnabledState::ENABLED, CronJobEnabledState::DISABLED],
            "state('" . self::JOB_NAME . "') must resolve to the row's actual enabled/disabled value, not MISSING or UNREADABLE"
        );
        $this->assertSame(
            (bool) $this->originalEnabled ? CronJobEnabledState::ENABLED : CronJobEnabledState::DISABLED,
            $state
        );
    }

    public function testStateReturnsDisabledWhenEnabledColumnIsZero(): void
    {
        $this->setReconciliationRow(false, $this->originalLastRunAt);

        $reader = new CronJobRunsReader($this->db);

        $this->assertSame(CronJobEnabledState::DISABLED, $reader->state(self::JOB_NAME));
    }

    public function testStateReturnsEnabledWhenEnabledColumnIsOne(): void
    {
        $this->setReconciliationRow(true, $this->originalLastRunAt);

        $reader = new CronJobRunsReader($this->db);

        $this->assertSame(CronJobEnabledState::ENABLED, $reader->state(self::JOB_NAME));
    }

    public function testLastRunAtRoundTripsARealTimestamp(): void
    {
        $knownTimestamp = '2026-01-15 10:30:00';
        $this->setReconciliationRow((bool) $this->originalEnabled, $knownTimestamp);

        $reader = new CronJobRunsReader($this->db);

        $lastRunAt = $reader->lastRunAt(self::JOB_NAME);
        $this->assertNotNull($lastRunAt, 'A recorded last_run_at must be read back');
        $this->assertSame($knownTimestamp, $lastRunAt->format('Y-m-d H:i:s'));
    }

    public function testLastRunAtReturnsNullWhenColumnIsNull(): void
    {
        $this->setReconciliationRow((bool) $this->originalEnabled, null);

        $reader = new CronJobRunsReader($this->db);

        $this->assertNull($reader->lastRunAt(self::JOB_NAME));
    }

    public function testStateReturnsMissingForAJobNameWithNoRow(): void
    {
        $missingJobName = 'nonexistent_test_job_' . uniqid();
        $reader = new CronJobRunsReader($this->db);

        $this->assertSame(CronJobEnabledState::MISSING, $reader->state($missingJobName));
    }

    public function testLastRunAtReturnsNullForAJobNameWithNoRow(): void
    {
        $missingJobName = 'nonexistent_test_job_' . uniqid();
        $reader = new CronJobRunsReader($this->db);

        $this->assertNull($reader->lastRunAt($missingJobName));
    }
}
