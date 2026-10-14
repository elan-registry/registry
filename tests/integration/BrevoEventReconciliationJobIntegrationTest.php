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
 * A Brevo client whose poll throws, to test the prune after a failed backfill.
 */
final class ThrowingBrevoEventReconciliationClient extends FakeBrevoEventReconciliationClient
{
    public function fetchEvents(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $limit,
        int $offset
    ): ?array {
        throw new \RuntimeException('Brevo is down (test)');
    }
}

/**
 * #1889, #2358: BrevoEventReconciliationJob with a real CarRepository and
 * EmailEventApplier, so the dedup and purge SQL runs for real. Control flow
 * is in tests/unit/cron/BrevoEventReconciliationJobTest.php.
 *
 * An event belongs to the owner. It is one row with `car_id` NULL, whatever
 * the number of the owner's cars.
 *
 * @see https://github.com/elan-registry/registry/issues/1889
 */
#[Group('integration')]
final class BrevoEventReconciliationJobIntegrationTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private EmailEventApplier $applier;
    private int $userId;
    private string $carEmail;

    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

    /** Original er_verification_settings.unmatched_recipient_count, restored in tearDown(). */
    private int $originalUnmatchedCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // The verification switch ships off. Since #2358 the reconciliation job
        // ignores it, so most tests leave it off. testExecuteRunsWithTheSwitchOn
        // turns it on. Also restore unmatched_recipient_count: tables are not
        // reset between tests.
        $this->db->query('SELECT enabled, unmatched_recipient_count FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        $this->originalUnmatchedCount = is_object($verificationRow)
            ? (int) $verificationRow->unmatched_recipient_count
            : 0;
        $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));

        // Events match the owner's users.email, so the owner holds the address.
        $this->carEmail = 'reconcile-' . uniqid() . '@example.com';
        $this->userId = $this->createTestUser(['email' => $this->carEmail], true);
        $this->createTestCar($this->userId, ['email' => $this->carEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
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
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE user_id = ? AND brevo_message_id = ?',
            [$this->userId, $brevoMessageId]
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
                . 'insertOwnerEmailEvent()\'s real ON DUPLICATE KEY UPDATE, not append a second row'
        );
    }

    /** Dedup holds when the full apply() escalation runs twice. */
    public function testSameHardBounceEventAcrossTwoRunsDedupsAndStaysBouncedOnTheOwnerProfile(): void
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

        $flags = $this->getOwnerEmailFlags($this->userId);
        $this->assertSame('1', (string) $flags['email_bounced'], 'The owner must still be flagged bounced after both runs');
        $this->assertSame(
            strtolower($this->carEmail),
            $flags['email_bounced_address'],
            'The bounced address is the address of the event'
        );
        $this->assertNotNull($flags['email_bounced_at'], 'A bounce sets email_bounced_at');

        $changes = $this->repo->recentFlagChanges($this->userId, 100);
        $this->assertCount(1, $changes, 'The second run changes nothing, so it writes no second log row');
        $this->assertSame('email_bounced', $changes[0]['flag']);
        $this->assertSame('reconciliation', $changes[0]['source'], 'The reconciliation job passes its own source');
        $this->assertSame(0, $changes[0]['acting_user_id'], 'A delivery event has no acting user');
    }

    // --- Owner-level write (#2358) -------------------------------------------

    /** An owner with three cars gets one row for one event, with car_id NULL. */
    public function testAnEventForAnOwnerWithThreeCarsWritesOneRowWithCarIdNull(): void
    {
        $this->createTestCar($this->userId, ['email' => $this->carEmail]);
        $this->createTestCar($this->userId, ['email' => $this->carEmail]);

        $summary = $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'reconcile-three-cars',
                date: '2026-09-08T12:00:00Z'
            ),
        ], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNowWithSummary();

        $rows = $this->db->query(
            'SELECT user_id, car_id FROM er_email_events WHERE brevo_message_id = ?',
            ['reconcile-three-cars']
        )->results();
        $this->assertCount(1, $rows, 'One event is one row, not one row for each car');
        $this->assertSame($this->userId, (int) $rows[0]->user_id);
        $this->assertNull($rows[0]->car_id);
        $this->assertSame(1, $summary->matchedCount, 'The summary counts owners, not cars');
    }

    // --- The verification switch does not stop the job (#2358) ----------------

    public function testExecuteRunsWithTheSwitchOffAndWithTheSwitchOn(): void
    {
        foreach ([0, 1] as $switch) {
            $this->db->query('UPDATE er_verification_settings SET enabled = ? WHERE id = 1', [$switch]);
            $messageId = 'reconcile-switch-' . $switch;

            $this->makeJob([
                new FakeBrevoEvent(
                    email: $this->carEmail,
                    event: 'delivered',
                    messageId: $messageId,
                    date: '2026-09-08T12:00:00Z'
                ),
            ], new \DateTimeImmutable('2026-09-09 03:00:00'))->runNow();

            $this->assertSame(1, $this->countEventRows($messageId), "switch={$switch}: the job must run");
        }
    }

    // --- Retention prune ------------------------------------------------------

    /**
     * The prune keeps a terminal event 24 months and any other event 90 days. It runs with
     * the verification switch off (setUp leaves it off).
     */
    public function testThePruneDeletesRowsPastBothCutoffsWithTheSwitchOff(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 03:00:00');
        foreach ([
            ['delivered', 'reconcile-purge-old', '2024-08-09 03:00:00'],
            ['delivered', 'reconcile-purge-100d', '2026-05-31 03:00:00'],
            ['delivered', 'reconcile-purge-recent', '2026-08-09 03:00:00'],
            ['hard_bounce', 'reconcile-purge-terminal-old', '2024-08-09 03:00:00'],
            ['hard_bounce', 'reconcile-purge-terminal-100d', '2026-05-31 03:00:00'],
        ] as [$event, $messageId, $at]) {
            $this->repo->insertOwnerEmailEvent($this->userId, $this->carEmail, $event, null, $messageId, $at);
        }
        $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');

        $this->makeJob([], $now)->runNow();

        $remaining = array_map(
            static fn (object $row): string => (string) $row->brevo_message_id,
            $this->db->query(
                'SELECT brevo_message_id FROM er_email_events WHERE user_id = ? ORDER BY brevo_message_id',
                [$this->userId]
            )->results()
        );
        $this->assertSame(
            ['reconcile-purge-recent', 'reconcile-purge-terminal-100d'],
            $remaining,
            'A delivered row past 90 days and a terminal row past 24 months go. A terminal row inside 24 months stays.'
        );
    }

    public function testThePruneRunsAfterAFailedBackfillAndTheBackfillFailureStillSurfaces(): void
    {
        $now = new \DateTimeImmutable('2026-09-09 03:00:00');
        $this->repo->insertOwnerEmailEvent(
            $this->userId,
            $this->carEmail,
            'delivered',
            null,
            'reconcile-prune-after-failure',
            '2026-05-31 03:00:00'
        );
        $job = new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            $this->applier,
            new ThrowingBrevoEventReconciliationClient(),
            $now
        );

        try {
            $job->runNowWithSummary();
            $this->fail('The backfill failure must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Brevo is down', $e->getMessage());
        }

        $this->assertSame(
            0,
            $this->countEventRows('reconcile-prune-after-failure'),
            'The prune must run in finally, after the failed backfill'
        );
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
