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
 * #1889: BrevoEventReconciliationJob with a real CarRepository and
 * EmailEventApplier, so the dedup and purge SQL runs for real. Control flow
 * is in tests/unit/cron/BrevoEventReconciliationJobTest.php.
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

    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

    /** Original er_verification_settings.unmatched_recipient_count, restored in tearDown(). */
    private int $originalUnmatchedCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // execute() returns early while verification is off (the default), so force
        // it on. Also restore unmatched_recipient_count: tables are not reset
        // between tests.
        $this->db->query('SELECT enabled, unmatched_recipient_count FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        $this->originalUnmatchedCount = is_object($verificationRow)
            ? (int) $verificationRow->unmatched_recipient_count
            : 0;
        $this->db->query('UPDATE er_verification_settings SET enabled = 1 WHERE id = 1');

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
            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ?, unmatched_recipient_count = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0, $this->originalUnmatchedCount]
            );
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
     * An event polled again inside the 48-hour window must dedup to one row
     * via the real ON DUPLICATE KEY UPDATE.
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

        $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('reconcile-dedup-msg'),
            'Reprocessing the same event on a subsequent run must dedup via '
                . 'insertEmailEvent()\'s real ON DUPLICATE KEY UPDATE, not append a second row'
        );
    }

    /** Dedup holds when the full apply() escalation runs twice. */
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
     * execute() drives the purge with its real 24-month cutoff. No events, so
     * the backfill cannot affect the result.
     */
    public function testExecutePurgesOnlyRowsOlderThanTheTwentyFourMonthCutoff(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 03:00:00');

        $this->repo->insertEmailEvent(
            $this->carId,
            $this->carEmail,
            'delivered',
            null,
            'reconcile-purge-old',
            '2024-08-09 03:00:00'
        );
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

    // --- Unmatched recipient counter (#2085) --------------------------------

    /** #2085: an unmatched recipient increments the real DB counter. */
    public function testUnmatchedRecipientIncrementsTheDashboardCounterInTheDatabase(): void
    {
        $unmatchedEmail = 'reconcile-unmatched-' . uniqid() . '@example.com';
        $messageId = 'reconcile-unmatched-' . uniqid();

        $event = new FakeBrevoEvent(
            email: $unmatchedEmail,
            event: 'delivered',
            messageId: $messageId,
            date: '2026-09-08T12:00:00Z',
            reason: null
        );

        $summary = $this->makeJob([$event], new \DateTimeImmutable('2026-09-09 03:00:00'))
            ->runNowWithSummary();

        $this->assertSame(1, $summary->unmatchedCount, 'The event matched no car, so it must be tallied as unmatched');
        $this->assertSame(
            0,
            $summary->counterFailureCount,
            'The dashboard counter increment must have succeeded against the real settings row'
        );

        $row = $this->db->query(
            'SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = 1'
        )->first();
        $this->assertSame(
            $this->originalUnmatchedCount + 1,
            (int) $row->unmatched_recipient_count,
            'er_verification_settings.unmatched_recipient_count must be incremented by exactly one'
        );

        $orphanRows = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE brevo_message_id = ?',
            [$messageId]
        )->first();
        $this->assertSame(
            0,
            (int) $orphanRows->cnt,
            'An unmatched recipient has no car to attach the event to, so no er_email_events row may be written'
        );
    }
}
