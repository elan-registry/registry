<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../Support/FakeBrevoSuppressionSyncClient.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoBlockedContactReason;
use Tests\Support\FakeBrevoSuppressionSyncClient;

/**
 * Real-DB proof that BrevoSuppressionSyncJob::runNowWithSummary() runs the
 * import regardless of the state of this job's `er_cron_job_runs` row — the
 * property the manual admin backfill path depends on.
 *
 * The bypass here is structurally different from
 * {@see BrevoEventReconciliationRunNowBypassIntegrationTest}'s, and the
 * difference is why this file exists rather than being a copy of that one.
 * There, `runNow()` bypasses the guard by being a sibling entry point to
 * `run()` that skips the enabled read and the claim before calling the same
 * `execute()`. Here, `runNowWithSummary()` is not `AbstractCronJob::runNow()`
 * at all — `runNow()` is `final` and calls `execute()`, the one-page
 * incremental mode — so the job adds its own method that calls
 * {@see BrevoSuppressionSyncJob::runFullBackfill()} directly. That method is
 * deliberately unreachable from `run()`/`execute()` at any depth, and reaches
 * neither {@see \ElanRegistry\Cron\CronJobGuard::claim()} nor
 * `AbstractCronJob`'s `enabled` read. So the assertion under test is that
 * neither `enabled = 0` nor a last_run_at inside the guard interval stops it,
 * and that `last_run_at` is never written by it.
 *
 * The contrast test at the bottom proves the same fixture state genuinely
 * blocks the guarded `run()` path, so "bypassed" is a real distinction rather
 * than an untested claim about a code path nothing gates.
 *
 * Uses the seeded `brevo_suppression_sync` row, snapshotting and restoring its
 * `enabled`/`last_run_at` columns exactly as
 * BrevoEventReconciliationRunNowBypassIntegrationTest and
 * CronJobGuardIntegrationTest do, since all of these mutate seeded fixture
 * rows in the same shared table.
 *
 * @see https://github.com/elan-registry/registry/issues/1923
 */
#[Group('integration')]
final class BrevoSuppressionSyncRunNowBypassIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_suppression_sync';

    /** Original enabled value for the fixture row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the fixture row, restored in tearDown(). */
    private ?string $originalLastRunAt = null;

    private CarRepository $repo;
    private EmailEventApplier $applier;
    private int $userId;
    private int $carId;
    private string $carEmail;

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

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));

        $this->userId = $this->createTestUser();
        $this->carEmail = 'suppression-bypass-' . uniqid() . '@example.com';
        $this->carId = $this->createTestCar($this->userId, ['email' => $this->carEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$this->carId]);

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

    private function countEventRows(string $event): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND event = ?',
            [$this->carId, $event]
        )->first();

        return (int) $row->cnt;
    }

    /**
     * One page carrying exactly this test's car email, hard-bounced.
     *
     * A single-contact page is shorter than PAGE_SIZE, so runFullBackfill()
     * stops after it — one poll, one applied suppression, no paging loop to
     * reason about.
     */
    private function makeJob(): BrevoSuppressionSyncJob
    {
        return new BrevoSuppressionSyncJob(
            $this->db,
            $this->repo,
            $this->applier,
            new FakeBrevoSuppressionSyncClient([
                FakeBrevoSuppressionSyncClient::page([
                    FakeBrevoBlockedContact::withReason(
                        $this->carEmail,
                        FakeBrevoBlockedContactReason::CODE_HARD_BOUNCE,
                        'hard bounce',
                        '2026-09-08T12:00:00.000Z'
                    ),
                ]),
            ]),
            new \DateTimeImmutable('2026-09-09 03:00:00')
        );
    }

    /**
     * er_cron_job_runs.enabled = 0 is how an operator pauses the nightly run.
     * The manual backfill must not be paused with it — an operator who clicks
     * "run backfill" on the admin page has explicitly asked for this run, and
     * runFullBackfill() never reads the enabled column at all.
     */
    public function testRunNowWithSummaryExecutesRealWorkWhenJobIsDisabled(): void
    {
        $this->setFixtureState(enabled: false, lastRunAt: null);

        $summary = $this->makeJob()->runNowWithSummary();

        $this->assertSame(1, $summary->pagesFetched, 'the backfill must have actually polled Brevo');
        $this->assertSame(
            1,
            $summary->matchedCount,
            'the seeded contact must be matched and flagged even though the job is disabled'
        );
        $this->assertSame(
            ['hardBounce' => 1],
            $summary->reasonCodeCounts,
            'the summary must reflect the real contact processed, not an empty/no-op run'
        );
        $this->assertSame(
            1,
            $this->countEventRows('blocked'),
            'the suppression must reach er_email_events — a real write, not just a returned summary'
        );
        $this->assertNull(
            $this->fetchLastRunAt(),
            'runNowWithSummary() must not touch last_run_at — it never calls the guard'
        );
    }

    /**
     * A last_run_at of "just now" is nowhere near the 20-hour guard interval,
     * so CronJobGuard::claim() would refuse if run() were used. The manual
     * backfill never calls claim(), so it must still do the work — and must
     * leave the timestamp exactly as it found it.
     */
    public function testRunNowWithSummaryExecutesRealWorkWhenClaimWouldFailDueToRecentRun(): void
    {
        $justClaimed = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $justClaimed);

        $summary = $this->makeJob()->runNowWithSummary();

        $this->assertSame(
            1,
            $summary->matchedCount,
            'the backfill must run despite a last_run_at that would fail claim()'
        );
        $this->assertSame(
            1,
            $this->countEventRows('blocked'),
            'the suppression must reach er_email_events despite the too-recent claim window'
        );
        $this->assertSame(
            $justClaimed,
            $this->fetchLastRunAt(),
            'runNowWithSummary() must not touch last_run_at at all — it never calls the guard'
        );
    }

    /**
     * Control for the two tests above: prove the guarded entry point really is
     * blocked by the same fixture states the backfill ignores. Without this,
     * the file would only show that runNowWithSummary() does *something* — not
     * that it bypasses gates that would otherwise stop a run.
     *
     * Both blocked cases are asserted in one method because each leaves the
     * database untouched, so they cannot contaminate each other.
     */
    public function testRunIsBlockedByTheSameFixtureStatesTheBackfillIgnores(): void
    {
        $this->setFixtureState(enabled: false, lastRunAt: null);
        $this->makeJob()->run();

        $this->assertSame(
            0,
            $this->countEventRows('blocked'),
            'run() must be blocked by the disabled row that runNowWithSummary() bypasses'
        );
        $this->assertNull(
            $this->fetchLastRunAt(),
            'a disabled job never reaches claim(), so last_run_at must stay unset'
        );

        $justClaimed = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $justClaimed);
        $this->makeJob()->run();

        $this->assertSame(
            0,
            $this->countEventRows('blocked'),
            'run() must be blocked by claim() when the last run is inside the guard interval'
        );
        $this->assertSame(
            $justClaimed,
            $this->fetchLastRunAt(),
            'a refused claim must not advance last_run_at'
        );
    }
}
