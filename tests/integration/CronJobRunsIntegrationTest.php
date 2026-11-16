<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobGuard;
use ElanRegistry\Cron\CronJobRunsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2034, #2054: CronJobGuard and CronJobRunsReader against real MySQL types
 * and the job_name key. Logic is unit-tested in
 * tests/unit/cron/CronJobGuardTest.php and CronJobRunsReaderTest.php.
 *
 * The failure round trip, CronJobGuard::recordFailure() -> CronJobRunsReader
 * status() -> badgeFor(), is here too. Both timestamps come from MySQL NOW()
 * at one-second resolution, so only a real connection proves that a claim
 * followed at once by a failure still shows "Last run failed".
 *
 * Fixture: the seeded `brevo_reconciliation` row (#2129), with `enabled`,
 * `last_run_at` and `last_failure_at` restored in tearDown(). The missing
 * `last_failure_at` case is in CronJobRunsReaderMissingColumnTest.
 */
#[Group('integration')]
final class CronJobRunsIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_reconciliation';

    /** Original enabled value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private ?string $originalLastRunAt = null;

    /** Original last_failure_at value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private ?string $originalLastFailureAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->db->query(
            'SELECT enabled, last_run_at, last_failure_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        );
        $this->assertFalse(
            $this->db->error(),
            'Failed to read original er_cron_job_runs row: ' . $this->db->errorString()
                . ' — likely means a migration has not been applied to the test schema'
        );
        $row = $this->db->first();
        $this->assertIsObject($row, "er_cron_job_runs must already have a seeded '" . self::JOB_NAME . "' row");

        $this->originalEnabled = (bool) $row->enabled;
        $this->originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;
        $this->originalLastFailureAt = !empty($row->last_failure_at) ? (string) $row->last_failure_at : null;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = ? WHERE job_name = ?',
                [$this->originalEnabled ? 1 : 0, $this->originalLastRunAt, $this->originalLastFailureAt, self::JOB_NAME]
            );
        }

        parent::tearDown();
    }

    private function setRowState(bool $enabled, ?string $lastRunAt, ?string $lastFailureAt = null): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = ? WHERE job_name = ?',
            [$enabled ? 1 : 0, $lastRunAt, $lastFailureAt, self::JOB_NAME]
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

    // --- CronJobGuard::claim() ---------------------------------------------

    public function testClaimSucceedsForANeverRunEnabledJob(): void
    {
        $this->setRowState(enabled: true, lastRunAt: null);

        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertNotNull(
            $this->fetchLastRunAt(),
            'A successful claim must actually update last_run_at in the real table'
        );
    }

    public function testClaimNoOpsWhenJobDisabled(): void
    {
        // Disabled with NULL last_run_at: the `enabled = 1` condition in claim() must still block it.
        $this->setRowState(enabled: false, lastRunAt: null);

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
        $this->setRowState(enabled: true, lastRunAt: $now);

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
        $this->setRowState(enabled: true, lastRunAt: $exactlyAtInterval);
        $guard = new CronJobGuard($this->db);

        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A last_run_at exactly 24 hours old must NOT be claimable — the boundary is strictly exclusive'
        );

        $justOverInterval = (new DateTimeImmutable('-24 hours -1 second'))->format('Y-m-d H:i:s');
        $this->setRowState(enabled: true, lastRunAt: $justOverInterval);

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
        $this->setRowState(enabled: true, lastRunAt: null);
        $guard = new CronJobGuard($this->db);

        $this->assertTrue($guard->claim(self::JOB_NAME, 24));
        $this->assertFalse(
            $guard->claim(self::JOB_NAME, 24),
            'A second claim immediately after the first must not also succeed'
        );
    }

    // --- Seeded rows: send_verification_batch (#1885), retention_purge (#2384)

    /**
     * @return array<string, array{string, string}>
     */
    public static function seededJobProvider(): array
    {
        return [
            'send_verification_batch (#1885)' => ['send_verification_batch', '20260916000001_seed_send_verification_batch_cron'],
            'retention_purge (#2384)' => ['retention_purge', '20261025000000_seed_retention_purge_cron'],
        ];
    }

    /**
     * The allowlist entry and the seeded row agree: a name present in only
     * one of them never claims.
     */
    #[DataProvider('seededJobProvider')]
    public function testClaimRoundTripAgainstSeededRow(string $jobName, string $migration): void
    {
        $this->db->query('SELECT enabled, last_run_at FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
        $this->assertFalse($this->db->error(), "Failed to read seeded {$jobName} row: " . $this->db->errorString());
        $row = $this->db->first();
        $this->assertIsObject(
            $row,
            "er_cron_job_runs must already have a seeded '{$jobName}' row"
            . " — run: composer migrate ({$migration})"
        );

        $originalEnabled = (bool) $row->enabled;
        $originalLastRunAt = !empty($row->last_run_at) ? (string) $row->last_run_at : null;

        try {
            // send_verification_batch is seeded enabled=0 (paused), so enable it for the claim.
            $this->db->query(
                'UPDATE er_cron_job_runs SET enabled = 1, last_run_at = NULL WHERE job_name = ?',
                [$jobName]
            );
            $this->assertFalse($this->db->error());

            $guard = new CronJobGuard($this->db);

            $this->assertTrue(
                $guard->claim($jobName, 20),
                "A real claim() against the seeded {$jobName} row must succeed when enabled"
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

    // --- CronJobRunsReader (#2054) -----------------------------------------

    public function testStateReturnsDisabledWhenEnabledColumnIsZero(): void
    {
        $this->setRowState(false, $this->originalLastRunAt);

        $reader = new CronJobRunsReader($this->db);

        $this->assertSame(CronJobEnabledState::DISABLED, $reader->state(self::JOB_NAME));
    }

    public function testStateReturnsEnabledWhenEnabledColumnIsOne(): void
    {
        $this->setRowState(true, $this->originalLastRunAt);

        $reader = new CronJobRunsReader($this->db);

        $this->assertSame(CronJobEnabledState::ENABLED, $reader->state(self::JOB_NAME));
    }

    public function testLastRunAtRoundTripsARealTimestamp(): void
    {
        $knownTimestamp = '2026-01-15 10:30:00';
        $this->setRowState($this->originalEnabled, $knownTimestamp);

        $reader = new CronJobRunsReader($this->db);

        $lastRunAt = $reader->lastRunAt(self::JOB_NAME);
        $this->assertNotNull($lastRunAt, 'A recorded last_run_at must be read back');
        $this->assertSame($knownTimestamp, $lastRunAt->format('Y-m-d H:i:s'));
    }

    public function testLastRunAtReturnsNullWhenColumnIsNull(): void
    {
        $this->setRowState($this->originalEnabled, null);

        $reader = new CronJobRunsReader($this->db);

        $this->assertNull($reader->lastRunAt(self::JOB_NAME));
    }

    // --- Failure round trip ------------------------------------------------

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
        // Literals, not recordFailure(): a fixed past time, so the claim below is a later run.
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
}
