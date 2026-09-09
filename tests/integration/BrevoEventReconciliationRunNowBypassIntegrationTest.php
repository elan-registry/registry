<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoEvent.php';
require_once __DIR__ . '/../Support/FakeBrevoEventReconciliationClient.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoEvent;
use Tests\Support\FakeBrevoEventReconciliationClient;

/**
 * Real-DB proof that BrevoEventReconciliationJob::runNow() (via
 * AbstractCronJob) actually bypasses CronJobGuard::claim() against the real
 * `er_cron_job_runs` table — the path `app/admin/scripts/maintenance/
 * 27-Reconcile-Brevo-Events.php` depends on to let an operator force an
 * immediate run regardless of the guard's schedule.
 *
 * tests/unit/cron/AbstractCronJobTest.php's
 * testRunNowExecutesEvenWhenDisabledAndClaimWouldFail() already proves this
 * against a fake database — this file exists to prove it against the real
 * `er_cron_job_runs` schema, the same rationale
 * CronJobGuardIntegrationTest.php gives for re-proving claim() itself against
 * real MySQL rather than trusting the fake-DB unit test alone (this repo's
 * convention for new SQL: execute it, don't just read it).
 *
 * Uses the seeded `reconciliation` row (created by the
 * 20260908203118_create_cron_job_runs migration), snapshotting and restoring
 * its `enabled`/`last_run_at` columns exactly as CronJobGuardIntegrationTest
 * does, since both files mutate the same fixture row.
 *
 * @see https://github.com/elan-registry/registry/issues/1889
 * @see https://github.com/elan-registry/registry/issues/2034
 */
#[Group('integration')]
final class BrevoEventReconciliationRunNowBypassIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'reconciliation';

    /** Original enabled value for the 'reconciliation' row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the 'reconciliation' row, restored in tearDown(). */
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
        $this->carEmail = 'runnow-bypass-' . uniqid() . '@example.com';
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

    private function countEventRows(string $brevoMessageId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND brevo_message_id = ?',
            [$this->carId, $brevoMessageId]
        )->first();

        return (int) $row->cnt;
    }

    private function makeJob(array $events): BrevoEventReconciliationJob
    {
        return new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            $this->applier,
            new FakeBrevoEventReconciliationClient($events),
            new \DateTimeImmutable('2026-09-09 03:00:00')
        );
    }

    /**
     * A last_run_at set to "just now" means CronJobGuard::claim() would
     * definitely fail (nowhere close to the 20-hour guard interval having
     * elapsed) if run() were used instead. runNow() must still do the real
     * work — proven by an actual er_email_events row appearing, not just by
     * the absence of an exception.
     */
    public function testRunNowExecutesRealWorkWhenClaimWouldFailDueToRecentRun(): void
    {
        $justClaimed = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->setFixtureState(enabled: true, lastRunAt: $justClaimed);

        $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'runnow-bypass-recent-claim',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('runnow-bypass-recent-claim'),
            'runNow() must record the event even though claim() would have refused (too recently run)'
        );
        $this->assertSame(
            $justClaimed,
            $this->fetchLastRunAt(),
            'runNow() must not touch last_run_at at all — it never calls the guard'
        );
    }

    /**
     * er_cron_job_runs.enabled = 0 means both CronJobGuard::claim() and
     * AbstractCronJob::run()'s own enabled check would refuse to run this
     * job. runNow() bypasses both, which is the entire point of the
     * maintenance script's "run now" button — an operator overriding a
     * paused job to run it once regardless.
     */
    public function testRunNowExecutesRealWorkWhenJobIsDisabled(): void
    {
        $this->setFixtureState(enabled: false, lastRunAt: null);

        $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'runnow-bypass-disabled',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('runnow-bypass-disabled'),
            'runNow() must record the event even though the job is disabled'
        );
        $this->assertNull(
            $this->fetchLastRunAt(),
            'runNow() must not touch last_run_at — it never calls the guard, disabled or not'
        );
    }

    /**
     * Sanity check that run() (the guarded entry point) really is blocked by
     * the same disabled row runNow() just bypassed above — otherwise this
     * test file would only be proving runNow() does *something*, not that it
     * specifically bypasses a guard that would otherwise refuse.
     */
    public function testRunIsBlockedByTheSameDisabledRowRunNowBypasses(): void
    {
        $this->setFixtureState(enabled: false, lastRunAt: null);

        $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'runnow-bypass-control',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->run();

        $this->assertSame(
            0,
            $this->countEventRows('runnow-bypass-control'),
            'run() must be blocked by the disabled row that runNow() bypasses in the sibling test'
        );
    }
}
