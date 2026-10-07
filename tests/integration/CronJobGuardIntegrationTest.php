<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobGuard;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2034: CronJobGuard::claim() against real MySQL types and the job_name key.
 * Logic is unit-tested in tests/unit/cron/CronJobGuardTest.php. The
 * "Integration" suffix avoids a "Cannot redeclare class" fatal when phpunit.xml
 * loads both suites in one process.
 *
 * Fixture: the seeded `brevo_reconciliation` row (#2129), with `enabled` and
 * `last_run_at` restored in tearDown().
 */
#[Group('integration')]
final class CronJobGuardIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_reconciliation';

    /** Original enabled value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the 'brevo_reconciliation' row, restored in tearDown(). */
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
            'Failed to read original er_cron_job_runs row: ' . $this->db->errorString()
                . ' — likely means this migration has not been applied to the test schema'
        );
        $row = $this->db->first();
        $this->assertIsObject($row, "er_cron_job_runs must already have a seeded '" . self::JOB_NAME . "' row");

        $this->originalEnabled = (bool) $row->enabled;
        $this->originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
                [$this->originalEnabled ? 1 : 0, $this->originalLastRunAt, self::JOB_NAME]
            );
        }

        parent::tearDown();
    }

    private function setFixtureState(bool $enabled, ?string $lastRunAt): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
            [$enabled ? 1 : 0, $lastRunAt, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to set er_cron_job_runs fixture: ' . $this->db->errorString());
    }

    private function fetchLastRunAt(): ?string
    {
        $this->db->query('SELECT last_run_at FROM er_cron_job_runs WHERE job_name = ?', [self::JOB_NAME]);
        $row = $this->db->first();
        $this->assertIsObject($row);

        return !empty($row->last_run_at) ? (string) $row->last_run_at : null;
    }

    public function testClaimSucceedsForANeverRunEnabledJob(): void
    {
        $this->setFixtureState(enabled: true, lastRunAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertNotNull(
            $this->fetchLastRunAt(),
            'A successful claim must actually update last_run_at in the real table'
        );
    }

    public function testClaimNoOpsWhenJobDisabled(): void
    {
        // Disabled with NULL last_run_at: `enabled = 1` must still block it.
        $this->setFixtureState(enabled: false, lastRunAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertFalse($guard->claim(self::JOB_NAME, 24));
        $this->assertNull(
            $this->fetchLastRunAt(),
            'A disabled job claim must not update last_run_at'
        );
    }

    public function testClaimFailsWhenAlreadyClaimedWithinInterval(): void
    {
        $now = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $now);

        $guard = new CronJobGuard($this->db);

        $this->assertFalse($guard->claim(self::JOB_NAME, 24));
        $this->assertSame(
            $now,
            $this->fetchLastRunAt(),
            'A too-recent claim must not update last_run_at'
        );
    }

    /**
     * The interval comparison is strictly `<`: exactly $intervalHours old is not
     * elapsed. Only real MySQL datetime math can catch a change to `<=`.
     */
    public function testClaimBoundaryIsStrictlyExclusive(): void
    {
        $exactlyAtInterval = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $exactlyAtInterval);
        $guard = new CronJobGuard($this->db);

        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A last_run_at exactly 24 hours old must NOT be claimable — the boundary is strictly exclusive'
        );

        $justOverInterval = (new DateTimeImmutable('-24 hours -1 second'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $justOverInterval);

        $this->assertTrue(
            $guard->claim(self::JOB_NAME, 24),
            'A last_run_at more than 24 hours old must be claimable'
        );
    }

    /**
     * The single-statement conditional UPDATE allows only one winner. Sequential
     * calls are the closest practical proxy for concurrency in PHPUnit.
     */
    public function testSequentialClaimsOnlyFirstSucceeds(): void
    {
        $this->setFixtureState(enabled: true, lastRunAt: null);
        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A second claim immediately after the first must not also succeed'
        );
    }

    // --- send_verification_batch (#1885) -----------------------------------

    /**
     * The send_verification_batch allowlist entry and its seeded row agree: a
     * name present in only one of them never claims.
     */
    public function testClaimRoundTripAgainstSeededSendVerificationBatchRow(): void
    {
        $jobName = 'send_verification_batch';

        $this->db->query('SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
        $this->assertFalse($this->db->error(), 'Failed to read seeded send_verification_batch row: ' . $this->db->errorString());
        $row = $this->db->first();
        $this->assertIsObject(
            $row,
            "er_cron_job_runs must already have a seeded '{$jobName}' row"
            . ' — run: composer migrate (20260916000001_seed_send_verification_batch_cron)'
        );

        $originalEnabled = (bool) $row->enabled;
        $originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;

        try {
            // Seeded enabled=0 (paused) in every environment.
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = 1, last_run_at = NULL WHERE job_name = ?',
                [$jobName]
            );
            $this->assertFalse($this->db->error());

            $guard = new CronJobGuard($this->db);

            $this->assertTrue(
                $guard->claim($jobName, 20),
                'A real claim() against the seeded send_verification_batch row must succeed when enabled'
            );

            $this->db->query('SELECT last_run_at FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
            $confirmRow = $this->db->first();
            $this->assertIsObject($confirmRow);
            $this->assertNotEmpty(
                $confirmRow->last_run_at,
                'A successful claim must actually update last_run_at in the real table'
            );

            $this->assertFalse(
                $guard->claim($jobName, 20),
                'A second immediate claim against the same row must not also succeed'
            );
        } finally {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ? WHERE job_name = ?',
                [$originalEnabled ? 1 : 0, $originalLastRunAt, $jobName]
            );
        }
    }
}
