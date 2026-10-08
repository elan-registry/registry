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
 * #1889, #2034: BrevoEventReconciliationJob::runNow() bypasses
 * CronJobGuard::claim() against the real `er_cron_job_runs` table (the unit
 * test uses a fake DB). Used by 27-Reconcile-Brevo-Events.php "run now".
 *
 * Snapshots and restores the seeded `brevo_reconciliation` row, which
 * CronJobGuardIntegrationTest also changes.
 */
#[Group('integration')]
final class BrevoEventReconciliationRunNowBypassIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_reconciliation';

    /** Original enabled value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the 'brevo_reconciliation' row, restored in tearDown(). */
    private ?string $originalLastRunAt = null;

    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

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

        // run()/runNow() check the site-wide verification switch first and it
        // ships off, so force it on. Restored in tearDown().
        $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        $this->db->query('UPDATE er_verification_settings SET enabled = 1 WHERE id = 1');

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

            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0]
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
     * last_run_at = now makes claim() fail, but runNow() must still do the
     * work: an er_email_events row must appear.
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

    /** enabled = 0 blocks run(); runNow() must bypass it (an operator override). */
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

    /** Control: run() is blocked by the same disabled row, so the bypass is real. */
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
