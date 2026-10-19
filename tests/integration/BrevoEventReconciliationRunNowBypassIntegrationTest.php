<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoEvent.php';
require_once __DIR__ . '/../Support/FakeBrevoEventReconciliationClient.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoEvent;
use Tests\Support\FakeBrevoEventReconciliationClient;

/**
 * #1889, #2034, #2358: BrevoEventReconciliationJob::runNow() bypasses
 * CronJobGuard::claim() against the real `er_cron_job_runs` table (the unit
 * test uses a fake DB). Used by 27-Reconcile-Brevo-Events.php "run now".
 *
 * Since #2358 the job ignores the site-wide verification switch, so each test
 * runs with the switch off, as it ships.
 *
 * Since #2359 the job applies only events of messages that this environment
 * sent. makeJob() seeds a verification `sent` row for each event's message ID.
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

        // The switch ships off. The job must run with it off (#2358). Restored in tearDown().
        $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));

        // Events match the owner's users.email, so the owner holds the address.
        // The owner joined before the seeded sends, so the join-date guard passes.
        $this->carEmail = 'runnow-bypass-' . uniqid() . '@example.com';
        $this->userId = $this->createTestUser(['email' => $this->carEmail, 'join_date' => '2026-01-01 00:00:00']);
        $this->carId = $this->createTestCar($this->userId, ['email' => $this->carEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
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
            "SELECT COUNT(*) AS cnt FROM er_email_events
              WHERE user_id = ? AND brevo_message_id = ? AND event != 'sent'",
            [$this->userId, $brevoMessageId]
        )->first();

        return (int) $row->cnt;
    }

    /**
     * @param list<FakeBrevoEvent> $events
     */
    private function makeJob(array $events): BrevoEventReconciliationJob
    {
        foreach ($events as $event) {
            $this->repo->insertEmailEvent(
                $this->carId,
                $this->carEmail,
                'sent',
                null,
                (string) $event->getMessageId(),
                '2026-09-08 04:00:00'
            );
        }

        return new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            new EmailEventMatcher($this->repo),
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

    /** #2358: run() claims the run and reaches execute() while the verification switch is off. */
    public function testRunReachesExecuteWithTheVerificationSwitchOff(): void
    {
        $this->setFixtureState(enabled: true, lastRunAt: null);
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled,
            'Test setup: the verification switch is off'
        );

        $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'runnow-bypass-switch-off-run',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->run();

        $this->assertSame(1, $this->countEventRows('runnow-bypass-switch-off-run'), 'run() must do the backfill');
        $this->assertNotNull($this->fetchLastRunAt(), 'run() claimed the run');
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
