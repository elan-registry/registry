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
 * Real-DB behavioral tests for BrevoEventReconciliationJob (#1889) covering
 * query-shape correctness that a fake-database unit test cannot prove.
 *
 * tests/unit/cron/BrevoEventReconciliationJobTest.php already covers the
 * job's control flow (tag gate, per-event failure isolation, bounded page,
 * window math, date fallback) against a mocked CarRepository and a fake
 * Brevo client — none of that is repeated here. This file wires the job with
 * a REAL CarRepository and a REAL EmailEventApplier (and, transitively, a
 * real CarVerificationManager) against the local MySQL test schema, so the
 * actual SQL — insertEmailEvent()'s ON DUPLICATE KEY UPDATE dedup and
 * deleteEmailEventsOlderThan()'s cutoff comparison — runs for real. The
 * Brevo client stays a fake (FakeBrevoEventReconciliationClient): there is
 * no real Brevo API to poll in a test, and the point here is what the job
 * does with events once it has them, not how it fetches them.
 *
 * tests/integration/database/CarRepositoryEmailEventsTest.php already proves
 * insertEmailEvent()'s dedup and deleteEmailEventsOlderThan()'s cutoff
 * directly against CarRepository — this file's job is narrower: prove the
 * *job*, wired end-to-end, actually reaches those same code paths when run
 * twice (simulating a subsequent night's re-processing of the same Brevo
 * event) and when its own execute() drives the purge, not just that
 * CarRepository's methods work in isolation.
 *
 * @see https://github.com/elan-registry/registry/issues/1889
 */
#[Group('integration')]
final class BrevoEventReconciliationJobIntegrationTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private EmailEventApplier $applier;
    private int $userId;
    private int $carId;
    private string $carEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));

        $this->userId = $this->createTestUser();
        $this->carEmail = 'reconcile-' . uniqid() . '@example.com';
        $this->carId = $this->createTestCar($this->userId, ['email' => $this->carEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$this->carId]);
        }
        parent::tearDown();
    }

    /**
     * @param list<object> $events Returned verbatim by the fake Brevo client.
     */
    private function makeJob(array $events, ?\DateTimeImmutable $now = null): BrevoEventReconciliationJob
    {
        return new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            $this->applier,
            new FakeBrevoEventReconciliationClient($events),
            $now
        );
    }

    private function countEventRows(string $brevoMessageId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND brevo_message_id = ?',
            [$this->carId, $brevoMessageId]
        )->first();

        return (int) $row->cnt;
    }

    // --- Dedup across two execute() calls (simulating a re-processed night) --

    /**
     * The same Brevo event "polled" twice across two separate execute() calls
     * — e.g. a partial success one night, then the same event still inside
     * the 48-hour lookback window the following night — must dedup down to
     * one er_email_events row via the real ON DUPLICATE KEY UPDATE, not two.
     * A mocked CarRepository unit test cannot catch a regression here (e.g.
     * a change to the unique index, or a switch to DB::insert()'s
     * indiscriminate update mode) because it never touches the real schema.
     */
    public function testSameEventAcrossTwoRunsDedupsToOneRow(): void
    {
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'delivered',
            messageId: 'reconcile-dedup-msg',
            date: '2026-09-08T12:00:00Z',
            reason: null
        );

        $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();
        $this->assertSame(
            1,
            $this->countEventRows('reconcile-dedup-msg'),
            'First run must record exactly one row'
        );

        // Second night's run "re-polls" the same event — still inside the
        // 48-hour window, exactly as the job's own retry model expects.
        $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('reconcile-dedup-msg'),
            'Reprocessing the same event on a subsequent run must dedup via '
                . 'insertEmailEvent()\'s real ON DUPLICATE KEY UPDATE, not append a second row'
        );
    }

    /**
     * Same as above, but for an event type that also triggers a flag write
     * (hard_bounce -> setBounced()), proving the dedup holds even when the
     * full EmailEventApplier::apply() escalation path runs both times, not
     * just the event-row insert.
     */
    public function testSameHardBounceEventAcrossTwoRunsDedupsAndStaysBounced(): void
    {
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'hard_bounce',
            messageId: 'reconcile-bounce-msg',
            date: '2026-09-08T12:00:00Z',
            reason: 'mailbox unavailable'
        );

        $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();
        $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('reconcile-bounce-msg'),
            'A duplicated hard_bounce must still dedup to one row even though it also drives a flag write'
        );

        $carRow = $this->db->query('SELECT email_bounced FROM cars WHERE id = ?', [$this->carId])->first();
        $this->assertSame(1, (int) $carRow->email_bounced, 'The car must still be flagged bounced after both runs');
    }

    // --- Retention purge exercised through execute() ------------------------

    /**
     * CarRepositoryEmailEventsTest already proves deleteEmailEventsOlderThan()
     * itself respects the cutoff. This proves the *job's* execute() actually
     * drives that call with its real 24-month cutoff, end-to-end, against a
     * seeded row old enough to purge and one recent enough to survive — with
     * a fake Brevo client returning no events, isolating the purge from the
     * backfill so this test cannot pass or fail on backfill behavior.
     */
    public function testExecutePurgesOnlyRowsOlderThanTheTwentyFourMonthCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 03:00:00');

        // 25 months old — must be purged.
        $this->repo->insertEmailEvent(
            $this->carId,
            $this->carEmail,
            'delivered',
            null,
            'reconcile-purge-old',
            '2024-08-09 03:00:00'
        );
        // Recent — must survive.
        $this->repo->insertEmailEvent(
            $this->carId,
            $this->carEmail,
            'delivered',
            null,
            'reconcile-purge-recent',
            '2026-08-09 03:00:00'
        );

        $this->makeJob([], $now)->runNow();

        $remaining = $this->db->query(
            'SELECT brevo_message_id FROM er_email_events WHERE car_id = ?',
            [$this->carId]
        )->results();

        $this->assertCount(1, $remaining, 'Only the row older than the 24-month cutoff should be purged');
        $this->assertSame('reconcile-purge-recent', $remaining[0]->brevo_message_id);
    }
}
