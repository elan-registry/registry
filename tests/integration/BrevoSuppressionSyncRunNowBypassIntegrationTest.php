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
 * #1923: the manual admin backfill (runNowWithSummary()) must ignore the
 * er_cron_job_runs row: neither enabled = 0 nor a recent last_run_at stops it,
 * and it never writes last_run_at. Unlike runNow(), it calls runFullBackfill(),
 * which reaches neither CronJobGuard::claim() nor the enabled read.
 *
 * Mutates the seeded brevo_suppression_sync row; setUp/tearDown snapshot and restore it.
 */
#[Group('integration')]
final class BrevoSuppressionSyncRunNowBypassIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'brevo_suppression_sync';

    /** Original enabled value for the fixture row, restored in tearDown(). */
    private bool $originalEnabled = true;

    /** Original last_run_at value for the fixture row, restored in tearDown(). */
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

        // The verification switch ships off and would short-circuit both paths.
        $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        $this->db->query('UPDATE er_verification_settings SET enabled = 1 WHERE id = 1');

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

    private function countEventRows(string $event): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND event = ?',
            [$this->carId, $event]
        )->first();

        return (int) $row->cnt;
    }

    /**
     * A single-contact page is shorter than PAGE_SIZE, so the backfill stops after one poll.
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
     * Pausing the nightly run must not pause a backfill the operator asked for.
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
     * Control: without it, the tests above would not prove a real bypass.
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
