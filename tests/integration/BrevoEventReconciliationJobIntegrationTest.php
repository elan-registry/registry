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
 * A Brevo client whose poll throws, to test the prune after a failed backfill.
 */
final class ThrowingBrevoEventReconciliationClient extends FakeBrevoEventReconciliationClient
{
    public function fetchEvents(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        int $limit,
        int $offset,
        string $eventType
    ): ?array {
        throw new \RuntimeException('Brevo is down (test)');
    }
}

/**
 * #1889, #2358, #2359: BrevoEventReconciliationJob with a real CarRepository,
 * EmailEventMatcher and EmailEventApplier, so the match, dedup and purge SQL
 * runs for real. Control flow is in
 * tests/unit/cron/BrevoEventReconciliationJobTest.php.
 *
 * An event is ours only when this environment sent its message ID. Most tests
 * seed a verification `sent` row (step (a) of the matcher). The owner joined
 * and got the address before the seeded send, so the date guards pass.
 *
 * @see https://github.com/elan-registry/registry/issues/1889
 * @see https://github.com/elan-registry/registry/issues/2359
 */
#[Group('integration')]
final class BrevoEventReconciliationJobIntegrationTest extends IntegrationTestCase
{
    /** Join date and address date of the test owner, before every seeded send. */
    private const OWNER_SINCE = '2026-01-01 00:00:00';

    /** Send time of the seeded `sent` rows and plugin rows. */
    private const SENT_AT = '2026-09-08 04:00:00';

    /** The job's "now" in most tests. */
    private const RUN_AT = '2026-09-09 03:00:00';

    private const PLUGIN_TABLE_SQL = 'CREATE TABLE IF NOT EXISTS plg_sendinblue_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        message_id VARCHAR(191) NOT NULL,
        recipient_email VARCHAR(255) NOT NULL,
        sender_email VARCHAR(255) NOT NULL,
        kind VARCHAR(32) NOT NULL,
        tags VARCHAR(255) NULL,
        status VARCHAR(32) NOT NULL,
        accepted_at DATETIME NOT NULL,
        delivered_at DATETIME NULL,
        last_event_at DATETIME NULL,
        last_checked_at DATETIME NULL,
        last_reason VARCHAR(255) NULL,
        UNIQUE KEY message_id (message_id),
        KEY recipient_accepted (recipient_email(128), accepted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    /** True when this class made the plugin table, so it must drop it. */
    private static bool $madePluginTable = false;

    private CarRepository $repo;
    private EmailEventApplier $applier;
    private int $userId;
    private int $carId;
    private string $carEmail;

    /** @var list<string> Message IDs of the plugin rows that a test made. */
    private array $pluginMessageIds = [];

    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

    /** Original er_verification_settings.unmatched_recipient_count, restored in tearDown(). */
    private int $originalUnmatchedCount = 0;

    public static function tearDownAfterClass(): void
    {
        if (self::$madePluginTable) {
            DB::getInstance()->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            self::$madePluginTable = false;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->pluginMessageIds = [];

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
        $this->userId = $this->createTestUser(['email' => $this->carEmail, 'join_date' => self::OWNER_SINCE], true);
        $this->setEmailAddressSinceSeen($this->userId, $this->carEmail, self::OWNER_SINCE);
        $this->carId = $this->createTestCar($this->userId, ['email' => $this->carEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ?, unmatched_recipient_count = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0, $this->originalUnmatchedCount]
            );
            foreach ($this->pluginMessageIds as $messageId) {
                $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
            }
        }
        parent::tearDown();
    }

    /**
     * @param list<object> $events Scripted events for the fake Brevo client
     */
    private function makeJob(array $events, ?\DateTimeImmutable $now = null): BrevoEventReconciliationJob
    {
        // A new matcher for each job: it keeps whether the plugin table exists.
        return new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            new EmailEventMatcher($this->repo),
            $this->applier,
            new FakeBrevoEventReconciliationClient($events),
            $now ?? new \DateTimeImmutable(self::RUN_AT)
        );
    }

    /** Record a verification send of this message ID to the test owner (step (a)). */
    private function seedSentRow(string $messageId): void
    {
        $this->repo->insertEmailEvent($this->carId, $this->carEmail, 'sent', null, $messageId, self::SENT_AT);
    }

    /** Record a plugin row for this message ID (step (b)). Makes the plugin table when it is absent. */
    private function seedPluginRow(string $messageId, string $recipient): void
    {
        if (!$this->repo->sendinblueMessagesTableExists()) {
            $this->db->query(self::PLUGIN_TABLE_SQL);
            $this->assertFalse($this->db->error(), 'Test setup: plugin table: ' . $this->db->errorString());
            self::$madePluginTable = true;
        }
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'accepted', ?)",
            [$messageId, $recipient, self::SENT_AT]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    /** Count the owner's event rows for a message ID, without the `sent` row. */
    private function countEventRows(string $brevoMessageId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM er_email_events
              WHERE user_id = ? AND brevo_message_id = ? AND event != 'sent'",
            [$this->userId, $brevoMessageId]
        )->first();

        return (int) $row->cnt;
    }

    // --- Dedup across two execute() calls (simulating a re-processed night) --

    /** An event polled again inside the 48-hour window must dedup to one row. */
    public function testSameEventAcrossTwoRunsDedupsToOneRow(): void
    {
        $this->seedSentRow('reconcile-dedup-msg');
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'delivered',
            messageId: 'reconcile-dedup-msg',
            date: '2026-09-08T12:00:00Z',
            reason: null
        );

        $this->makeJob([$event])->runNow();
        $this->assertSame(1, $this->countEventRows('reconcile-dedup-msg'), 'First run must record exactly one row');

        $this->makeJob([$event])->runNow();

        $this->assertSame(
            1,
            $this->countEventRows('reconcile-dedup-msg'),
            'Reprocessing the same event on a subsequent run must dedup, not append a second row'
        );
    }

    /** Dedup holds when the full apply() escalation runs twice. */
    public function testSameHardBounceEventAcrossTwoRunsDedupsAndStaysBouncedOnTheOwnerProfile(): void
    {
        $this->seedSentRow('reconcile-bounce-msg');
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'hardBounces',
            messageId: 'reconcile-bounce-msg',
            date: '2026-09-08T12:00:00Z',
            reason: 'mailbox unavailable'
        );

        $this->makeJob([$event])->runNow();
        $this->makeJob([$event])->runNow();

        $rows = $this->db->query(
            "SELECT event, car_id FROM er_email_events WHERE user_id = ? AND brevo_message_id = ? AND event != 'sent'",
            [$this->userId, 'reconcile-bounce-msg']
        )->results();
        $this->assertCount(1, $rows, 'A duplicated hard bounce must still dedup to one row');
        $this->assertSame('hard_bounce', $rows[0]->event, 'The row uses the webhook event name');
        $this->assertSame($this->carId, (int) $rows[0]->car_id, 'Step (a) gives the car of the sent row');

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

    /** An owner with three cars gets one row for one event. */
    public function testAnEventForAnOwnerWithThreeCarsWritesOneRow(): void
    {
        $this->createTestCar($this->userId, ['email' => $this->carEmail]);
        $this->createTestCar($this->userId, ['email' => $this->carEmail]);
        $this->seedSentRow('reconcile-three-cars');

        $summary = $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'delivered',
                messageId: 'reconcile-three-cars',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNowWithSummary();

        $this->assertSame(1, $this->countEventRows('reconcile-three-cars'), 'One event is one row, not one row for each car');
        $this->assertSame(1, $summary->matchedCount, 'The summary counts owners, not cars');
    }

    // --- Ours or not (#2359) ---------------------------------------------------

    /** Mail of the other environment on the shared Brevo account. */
    public function testAnEventWhoseMessageIdThisEnvironmentDidNotSendWritesNothing(): void
    {
        $summary = $this->makeJob([
            new FakeBrevoEvent(
                email: $this->carEmail,
                event: 'hardBounces',
                messageId: 'reconcile-not-ours-' . uniqid(),
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNowWithSummary();

        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->unmatchedCount);
        $flags = $this->getOwnerEmailFlags($this->userId);
        $this->assertSame('0', (string) $flags['email_bounced'], 'An address match alone sets no flag');
    }

    /**
     * The webhook dropped the bounce because the plugin row did not exist yet.
     * The first run also finds no row. The next run, after the row exists,
     * applies the bounce through step (b), with car_id NULL.
     */
    public function testAHardBounceThatArrivedBeforeItsMessageRowIsAppliedByTheNextRun(): void
    {
        $messageId = 'reconcile-early-bounce-' . uniqid();
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'hardBounces',
            messageId: $messageId,
            tag: 'password_reset',
            date: '2026-09-08T12:00:00Z'
        );

        $this->makeJob([$event])->runNow();
        $this->assertSame(0, $this->countEventRows($messageId), 'No message row yet: the event is not ours');

        $this->seedPluginRow($messageId, $this->carEmail);
        $this->makeJob([$event])->runNow();

        $rows = $this->db->query(
            'SELECT event, car_id FROM er_email_events WHERE user_id = ? AND brevo_message_id = ?',
            [$this->userId, $messageId]
        )->results();
        $this->assertCount(1, $rows);
        $this->assertSame('hard_bounce', $rows[0]->event);
        $this->assertNull($rows[0]->car_id, 'Step (b) does not tell the car');
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($this->userId)['email_bounced']);
    }

    /** A replayed bounce on a message sent before Clear Bounced leaves the flag clear. */
    public function testAReplayedBounceAfterClearBouncedLeavesTheFlagClear(): void
    {
        $this->seedSentRow('reconcile-replay-after-clear');
        $event = new FakeBrevoEvent(
            email: $this->carEmail,
            event: 'hardBounces',
            messageId: 'reconcile-replay-after-clear',
            date: '2026-09-08T12:00:00Z'
        );

        $this->makeJob([$event])->runNow();
        $this->assertSame('1', (string) $this->getOwnerEmailFlags($this->userId)['email_bounced']);

        $this->repo->clearProfileBounced($this->userId);
        $this->assertSame('0', (string) $this->getOwnerEmailFlags($this->userId)['email_bounced']);

        $this->makeJob([$event])->runNow();

        $this->assertSame(
            '0',
            (string) $this->getOwnerEmailFlags($this->userId)['email_bounced'],
            'The message was sent before the clear, so the replay sets no flag'
        );
    }

    // --- The verification switch does not stop the job (#2358) ----------------

    public function testExecuteRunsWithTheSwitchOffAndWithTheSwitchOn(): void
    {
        foreach ([0, 1] as $switch) {
            $this->db->query('UPDATE er_verification_settings SET enabled = ? WHERE id = 1', [$switch]);
            $messageId = 'reconcile-switch-' . $switch;
            $this->seedSentRow($messageId);

            $this->makeJob([
                new FakeBrevoEvent(
                    email: $this->carEmail,
                    event: 'delivered',
                    messageId: $messageId,
                    date: '2026-09-08T12:00:00Z'
                ),
            ])->runNow();

            $this->assertSame(1, $this->countEventRows($messageId), "switch={$switch}: the job must run");
        }
    }

    /** With the switch off, the job applies a bounce that the webhook dropped. */
    public function testAHardBounceIsAppliedWithTheSwitchOff(): void
    {
        $this->seedSentRow('reconcile-switch-off-bounce');

        // run() claims the guard, and a claim is due only 20 hours after the
        // last one. A claim of an earlier run (of this test, or of the cron
        // container) would make run() a quiet no-op. So the test makes the job
        // due, and puts the old value back.
        $row = $this->db->query(
            'SELECT last_run_at FROM er_cron_job_runs WHERE job_name = ?',
            [BrevoEventReconciliationJob::JOB_NAME]
        )->first();
        $this->assertIsObject($row, 'Setup: the job has a seeded er_cron_job_runs row');
        $this->db->query(
            'UPDATE er_cron_job_runs SET last_run_at = NULL WHERE job_name = ?',
            [BrevoEventReconciliationJob::JOB_NAME]
        );

        try {
            $this->makeJob([
                new FakeBrevoEvent(
                    email: $this->carEmail,
                    event: 'hardBounces',
                    messageId: 'reconcile-switch-off-bounce',
                    date: '2026-09-08T12:00:00Z'
                ),
            ])->run();
        } finally {
            $this->db->query(
                'UPDATE er_cron_job_runs SET last_run_at = ? WHERE job_name = ?',
                [$row->last_run_at, BrevoEventReconciliationJob::JOB_NAME]
            );
        }

        $this->assertSame('1', (string) $this->getOwnerEmailFlags($this->userId)['email_bounced']);
    }

    // --- Retention prune ------------------------------------------------------

    /**
     * The prune keeps a terminal event 24 months and any other event 90 days. It runs with
     * the verification switch off (setUp leaves it off).
     */
    public function testThePruneDeletesRowsPastBothCutoffsWithTheSwitchOff(): void
    {
        $now = new \DateTimeImmutable(self::RUN_AT);
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
            new EmailEventMatcher($this->repo),
            $this->applier,
            new ThrowingBrevoEventReconciliationClient(),
            new \DateTimeImmutable(self::RUN_AT)
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

    // --- Unmatched recipient counter (#2085, #2359) ----------------------------

    /**
     * A verification event of our message, for an address that no user holds,
     * increments the real DB counter and writes no row.
     */
    public function testUnmatchedVerificationRecipientIncrementsTheDashboardCounterInTheDatabase(): void
    {
        $messageId = 'reconcile-unmatched-' . uniqid();
        $this->seedSentRow($messageId);

        $summary = $this->makeJob([
            new FakeBrevoEvent(
                email: 'reconcile-unmatched-' . uniqid() . '@example.com',
                event: 'delivered',
                messageId: $messageId,
                tag: 'car_verification',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNowWithSummary();

        $this->assertSame(1, $summary->unmatchedCount, 'The event matched no user, so it must be tallied as unmatched');
        $this->assertSame(
            0,
            $summary->counterFailureCount,
            'The dashboard counter increment must have succeeded against the real settings row'
        );
        $this->assertSame($this->originalUnmatchedCount + 1, $this->unmatchedCounter());
        $this->assertSame(0, $this->countAllEventRows($messageId), 'An unmatched recipient writes no event row');
    }

    public function testUnmatchedNonVerificationRecipientIsNotCounted(): void
    {
        $messageId = 'reconcile-unmatched-other-' . uniqid();
        $this->seedSentRow($messageId);

        $summary = $this->makeJob([
            new FakeBrevoEvent(
                email: 'reconcile-unmatched-' . uniqid() . '@example.com',
                event: 'delivered',
                messageId: $messageId,
                tag: 'password_reset',
                date: '2026-09-08T12:00:00Z'
            ),
        ])->runNowWithSummary();

        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame($this->originalUnmatchedCount, $this->unmatchedCounter());
        $this->assertSame(0, $this->countAllEventRows($messageId));
    }

    private function unmatchedCounter(): int
    {
        return (int) $this->db->query(
            'SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = 1'
        )->first()->unmatched_recipient_count;
    }

    /** Count the event rows of every user for a message ID, without `sent` rows. */
    private function countAllEventRows(string $messageId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM er_email_events WHERE brevo_message_id = ? AND event != 'sent'",
            [$messageId]
        )->first()->cnt;
    }
}
