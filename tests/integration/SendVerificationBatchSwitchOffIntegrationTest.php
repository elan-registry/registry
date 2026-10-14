<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationEmailComposer;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\CarVerificationSendService;
use ElanRegistry\Car\VerificationBatchSender;
use ElanRegistry\Car\VerificationSettings;
use ElanRegistry\Cron\SendVerificationBatchJob;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: with the verification switch off, SendVerificationBatchJob::run() and
 * runNow() send nothing and leave the real `er_cron_job_runs.last_run_at`
 * alone. The first run() after the switch goes on is due at once.
 *
 * The switch check must come before the claim. A check inside execute() would
 * stamp last_run_at first, and the job would then wait the 20 hour interval
 * after the switch goes on. These tests use the real job, the real settings and
 * the real guard table.
 *
 * Snapshots and restores the seeded `send_verification_batch` row and the
 * verification switch.
 */
#[Group('integration')]
final class SendVerificationBatchSwitchOffIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'send_verification_batch';

    private const OLD_RUN = '2026-01-01 00:00:00';

    private const SENTINEL_COUNT = 77;

    /** The line that execute() logs when no car is due. It proves that the work ran. */
    private const EXECUTE_LOG = 'Verification send: cron run found no eligible cars%';

    private ?object $originalRow = null;
    private bool $originalSwitch = false;
    private CarRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->originalRow = $this->db->query(
            'SELECT enabled, last_run_at, last_sent_count, last_skipped_count, last_failed_count
               FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        )->first() ?: null;
        $this->assertIsObject($this->originalRow, "er_cron_job_runs must hold a seeded '" . self::JOB_NAME . "' row");
        $this->originalSwitch = (bool) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled;

        $this->repo = new CarRepository($this->db);

        $this->setRow(self::OLD_RUN);
        // Precondition for the run() that claims: no car may be due, so the job sends no real email.
        $this->assertSame(
            [],
            $this->makeService()->findEligible(50),
            'Test setup: a car in the test schema is due a verification email. The run that claims would send it.'
        );
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected && $this->originalRow !== null) {
            $this->db->query(
                'UPDATE er_cron_job_runs
                    SET enabled = ?, last_run_at = ?, last_sent_count = ?, last_skipped_count = ?, last_failed_count = ?
                  WHERE job_name = ?',
                [
                    $this->originalRow->enabled,
                    $this->originalRow->last_run_at,
                    $this->originalRow->last_sent_count,
                    $this->originalRow->last_skipped_count,
                    $this->originalRow->last_failed_count,
                    self::JOB_NAME,
                ]
            );
            $this->setSwitch($this->originalSwitch);
            $this->db->query('DELETE FROM logs WHERE logtype = ? AND lognote LIKE ?', [LogCategories::LOG_CATEGORY_CAR_VERIFICATION, self::EXECUTE_LOG]);
        }
        parent::tearDown();
    }

    private function setRow(?string $lastRunAt): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs
                SET enabled = 1, last_run_at = ?, last_sent_count = ?, last_skipped_count = ?, last_failed_count = ?
              WHERE job_name = ?',
            [$lastRunAt, self::SENTINEL_COUNT, self::SENTINEL_COUNT, self::SENTINEL_COUNT, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Failed to set the er_cron_job_runs fixture: ' . $this->db->errorString());
    }

    private function setSwitch(bool $on): void
    {
        $this->db->query('UPDATE er_verification_settings SET enabled = ? WHERE id = 1', [$on ? 1 : 0]);
        $this->assertFalse($this->db->error(), 'Failed to set the verification switch: ' . $this->db->errorString());
    }

    private function lastRunAt(): ?string
    {
        $value = $this->db->query('SELECT last_run_at FROM er_cron_job_runs WHERE job_name = ?', [self::JOB_NAME])->first()->last_run_at;

        return $value === null ? null : (string) $value;
    }

    private function sentCount(): int
    {
        return (int) $this->db->query('SELECT last_sent_count FROM er_cron_job_runs WHERE job_name = ?', [self::JOB_NAME])->first()->last_sent_count;
    }

    private function makeService(): CarVerificationSendService
    {
        return new CarVerificationSendService(
            $this->repo,
            new CarVerificationManager($this->repo),
            new CarVerificationEmailComposer(),
            new VerificationSettings($this->db)
        );
    }

    private function makeJob(): SendVerificationBatchJob
    {
        $service = $this->makeService();

        return new SendVerificationBatchJob(
            $this->db,
            new VerificationSettings($this->db),
            $service,
            new VerificationBatchSender($this->repo, $service, 0)
        );
    }

    /** How many times execute() ran. Its "no eligible cars" line is the only trace when no car is due. */
    private function executeCount(): int
    {
        return $this->countMatchingLogs(LogCategories::LOG_CATEGORY_CAR_VERIFICATION, self::EXECUTE_LOG);
    }

    private function sentEventCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS cnt FROM er_email_events WHERE event = 'sent'")->first()->cnt;
    }

    public function testRunWithTheSwitchOffLeavesLastRunAtUnchanged(): void
    {
        $this->setSwitch(false);
        $sentRowsBefore = $this->sentEventCount();
        $executedBefore = $this->executeCount();

        $this->makeJob()->run();

        $this->assertSame(self::OLD_RUN, $this->lastRunAt(), 'A refused run must not stamp last_run_at');
        $this->assertSame(self::SENTINEL_COUNT, $this->sentCount(), 'A refused run writes no outcome counts');
        $this->assertSame($sentRowsBefore, $this->sentEventCount());
        $this->assertSame($executedBefore, $this->executeCount(), 'A refused run must not reach execute()');
    }

    public function testRunNowWithTheSwitchOffLeavesLastRunAtUnchanged(): void
    {
        $this->setSwitch(false);
        $executedBefore = $this->executeCount();

        $this->makeJob()->runNow();

        $this->assertSame(self::OLD_RUN, $this->lastRunAt());
        $this->assertSame(self::SENTINEL_COUNT, $this->sentCount());
        $this->assertSame($executedBefore, $this->executeCount(), 'A refused runNow() must not reach execute()');
    }

    public function testTheFirstRunAfterTheSwitchGoesOnClaimsTheRun(): void
    {
        $this->setSwitch(false);
        $this->makeJob()->run();
        $this->assertSame(self::OLD_RUN, $this->lastRunAt(), 'Test setup: the refused run left the row alone');

        $this->setSwitch(true);
        $before = new \DateTimeImmutable('now');
        $executedBefore = $this->executeCount();
        $this->makeJob()->run();
        $this->assertSame($executedBefore + 1, $this->executeCount(), 'The first run after the switch goes on reaches execute()');

        $stamped = $this->lastRunAt();
        $this->assertNotNull($stamped);
        $this->assertNotSame(self::OLD_RUN, $stamped, 'The run must claim: last_run_at moves on');
        $this->assertGreaterThanOrEqual(
            $before->modify('-5 seconds')->getTimestamp(),
            (new \DateTimeImmutable($stamped))->getTimestamp(),
            'last_run_at must be the time of this run'
        );
    }

    public function testWithTheSwitchOnRunNowDoesNotTouchLastRunAt(): void
    {
        // Control: runNow() never claims. Only the switch gate, not the harness, keeps the value above.
        $this->setSwitch(true);
        $executedBefore = $this->executeCount();

        $this->makeJob()->runNow();

        $this->assertSame(self::OLD_RUN, $this->lastRunAt());
        $this->assertSame($executedBefore + 1, $this->executeCount(), 'The control: with the switch on, runNow() reaches execute()');
    }
}
