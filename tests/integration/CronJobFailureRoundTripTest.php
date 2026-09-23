<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobGuard;
use ElanRegistry\Cron\CronJobRunsReader;
use PHPUnit\Framework\Attributes\Group;

/**
 * Real-DB round trip for the write-then-read path that makes a crashed cron
 * run visible: {@see CronJobGuard::recordFailure()} stamps
 * `er_cron_job_runs.last_failure_at`, {@see CronJobRunsReader::status()} reads
 * it back, and {@see CronJobRunsReader::badgeFor()} renders the failure badge.
 *
 * Each of those three is unit-tested in isolation against fakes, which is
 * where the branch coverage lives. What no fake can prove is the seam the
 * original bug lived in: the two timestamps are compared against each other,
 * and both are written by MySQL's own `NOW()` at one-second resolution and
 * round-tripped through a `datetime` column into DateTimeImmutable. A fake
 * hands back whatever string the test wrote; only a real connection can show
 * that a claim followed immediately by a failure — the exact sequence
 * AbstractCronJob::run() produces when execute() throws on its first statement
 * — still resolves to "Last run failed" rather than losing the tie and
 * rendering green.
 *
 * Follows CronJobRunsReaderDatabaseTest's snapshot/restore discipline for the
 * shared seeded row: every column this test writes is captured in setUp() and
 * put back in tearDown(), so a failure here cannot leave the row altered for
 * tests that run after it.
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

    /**
     * Skip rather than fail on a schema that predates the column — the same
     * convention every other migration-dependent test in this suite uses, so
     * a developer who has not run `composer migrate` gets an actionable skip
     * instead of a confusing assertion failure.
     */
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
     * The whole path, in the order AbstractCronJob::run() walks it on a failed
     * run: claim (which stamps last_run_at), then recordFailure() from the
     * catch block. A reader that ran afterwards used to report this as a clean
     * "Ran" — the Critical bug — because it had only last_run_at to look at.
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
     * The recovery half: a later successful run out-dates the earlier failure
     * with no explicit clearing step, because the badge compares timestamps
     * rather than reading a flag someone has to remember to reset — a crashing
     * job being the party least able to do that bookkeeping.
     */
    public function testASubsequentClaimSupersedesAnEarlierFailure(): void
    {
        // A failure from yesterday, and no run since. Written as literals
        // rather than by calling recordFailure() so the two are unambiguously
        // ordered a day apart — the tie-at-one-second case is the test above.
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

        // A real claim now — NOW() is necessarily later than the 2026-09-01
        // fixture, so last_run_at moves ahead of last_failure_at.
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
     * A row that has never failed must round-trip a real NULL back as null,
     * not as a zero-date or an epoch value — the badge compares timestamps,
     * and a bogus year -1 last_failure_at would silently lose every comparison
     * while a 1970 one would win them all.
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
