<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoEvent.php';
require_once __DIR__ . '/../Support/FakeBrevoEventReconciliationClient.php';
require_once __DIR__ . '/../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../Support/FakeBrevoSuppressionSyncClient.php';

use ElanRegistry\AppConstants;
use ElanRegistry\Car\BrevoWebhookEventProcessor;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Car\ProcessingResult;
use ElanRegistry\Cron\BrevoEventReconciliationJob;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use ElanRegistry\Owner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoEvent;
use Tests\Support\FakeBrevoEventReconciliationClient;
use Tests\Support\FakeBrevoSuppressionSyncClient;

/**
 * #2359: the webhook, the reconciliation job and the suppression import give
 * the same profile result for the same event, and the flag rules hold on each
 * path.
 *
 * Three drivers feed one real `CarRepository`, `EmailEventMatcher` and
 * `EmailEventApplier` (a real database; only Brevo's side is scripted):
 *
 * - webhook: `BrevoWebhookEventProcessor::process()` with a decoded payload.
 * - reconciliation: `BrevoEventReconciliationJob::runNowWithSummary()` with a
 *   fake statistics API.
 * - import: `BrevoSuppressionSyncJob` with a fake blocklist.
 *
 * Every owner joined and got the address before the seeded verification send,
 * so the date guards pass. All times are relative to the real clock, because
 * the webhook and the reconciliation job treat an event time more than five
 * minutes after receipt as missing.
 *
 * The verification switch is off for the whole class. The three paths must
 * ignore it (criterion "With the verification switch off ...").
 *
 * @see https://github.com/elan-registry/registry/issues/2359
 */
#[Group('integration')]
final class BrevoEventPathParityIntegrationTest extends IntegrationTestCase
{
    /** Join date and address date of every test owner, before every seeded send. */
    private const OWNER_SINCE = '2026-01-01 00:00:00';

    /** The date of a flag that an earlier event set. */
    private const OLD_FLAG_AT = '2026-09-01 00:00:00';

    /** The plugin's message table. The test schema may not have it. */
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

    private static bool $madePluginTable = false;

    /** @var list<string> Plugin rows that this test made. */
    private array $pluginMessageIds = [];

    private const WEBHOOK = 'webhook';
    private const RECONCILIATION = 'reconciliation';
    private const IMPORT = 'import';

    private CarRepository $repo;
    private EmailEventApplier $applier;
    private CarVerificationManager $manager;

    /** The event time of a test, in Unix seconds. One hour before the test started. */
    private int $eventTs;

    /** @var list<int> Owners that this test made, for the log cleanup. */
    private array $ownerIds = [];

    private bool $originalVerificationEnabled = false;
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
        $this->ownerIds = [];
        $this->pluginMessageIds = [];
        $this->makePluginTable();

        $row = $this->db->query('SELECT enabled, unmatched_recipient_count FROM er_verification_settings WHERE id = 1')->first();
        $this->originalVerificationEnabled = is_object($row) && (bool) $row->enabled;
        $this->originalUnmatchedCount = is_object($row) ? (int) $row->unmatched_recipient_count : 0;
        $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');
        $this->assertFalse($this->db->error(), 'Test setup: the switch must go off');

        $this->repo = new CarRepository($this->db);
        $this->manager = new CarVerificationManager($this->repo);
        $this->applier = new EmailEventApplier($this->repo, $this->manager);
        $this->eventTs = time() - 3600;
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            foreach ($this->pluginMessageIds as $messageId) {
                $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id = ?', [$messageId]);
            }
            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ?, unmatched_recipient_count = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0, $this->originalUnmatchedCount]
            );
            foreach ($this->ownerIds as $ownerId) {
                // The flag-change audit rows and the event log lines of this owner.
                $this->db->query('DELETE FROM logs WHERE user_id = ? OR lognote LIKE ?', [$ownerId, "Owner {$ownerId} %"]);
            }
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @return array{userId: int, carId: int, email: string}
     */
    private function makeOwner(string $label, bool $withCar = true): array
    {
        $email = $label . '-' . uniqid() . '@example.com';
        $userId = $this->createTestUser(['email' => $email, 'join_date' => self::OWNER_SINCE], true);
        $this->ownerIds[] = $userId;
        $this->setEmailAddressSinceSeen($userId, $email, self::OWNER_SINCE);
        $carId = $withCar ? $this->createTestCar($userId, ['email' => $email]) : 0;

        return ['userId' => $userId, 'carId' => $carId, 'email' => $email];
    }

    private function makePluginTable(): void
    {
        $exists = (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plg_sendinblue_messages'"
        )->first()->cnt > 0;
        if ($exists) {
            return;
        }
        $this->db->query(self::PLUGIN_TABLE_SQL);
        $this->assertFalse($this->db->error(), 'Test setup: plugin table: ' . $this->db->errorString());
        self::$madePluginTable = true;
    }

    /** Record a message that only the plugin knows (step (b) of the matcher): no `sent` row. */
    private function seedPluginRow(string $messageId, string $email, int $acceptedTs): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', 'accepted', ?)",
            [$messageId, $email, $this->wall($acceptedTs)]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
        $this->pluginMessageIds[] = $messageId;
    }

    /** Record a verification send to the owner's address (step (a) of the matcher). */
    private function seedSentRow(int $carId, string $email, string $messageId, int $sentTs): void
    {
        $written = $this->repo->insertEmailEvent($carId, $email, 'sent', null, $messageId, date(AppConstants::DATETIME_FORMAT, $sentTs));
        $this->assertSame(1, $written, 'Test setup: the sent row must be written');
    }

    private function wall(int $ts): string
    {
        return date(AppConstants::DATETIME_FORMAT, $ts);
    }

    private function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    /**
     * Drive one event through the webhook processor
     */
    private function driveWebhook(string $email, string $messageId, string $event, int $ts, string $tag = 'password_reset'): ProcessingResult
    {
        $processor = new BrevoWebhookEventProcessor(new EmailEventMatcher($this->repo), $this->applier);

        return $processor->process([
            'email' => $email,
            'event' => $event,
            'message-id' => $messageId,
            'ts_event' => $ts,
            'reason' => 'parity reason',
            'tags' => [$tag],
        ]);
    }

    /**
     * Drive one event through the reconciliation job
     *
     * `runNow()` checks `refusesWhileSwitchOff()`, which this job does not
     * override, so the job runs with the switch off on every path. A test of
     * the switch passes `$viaRunNow`, so the call goes through that check,
     * and reads the result from the database.
     *
     * @return int The number of events that the job applied, or 0 for `runNow()` (it returns no summary)
     */
    private function driveReconciliation(string $email, string $messageId, string $apiType, int $ts, bool $viaRunNow = false): int
    {
        $job = new BrevoEventReconciliationJob(
            $this->db,
            $this->repo,
            new EmailEventMatcher($this->repo),
            $this->applier,
            new FakeBrevoEventReconciliationClient([
                new FakeBrevoEvent(
                    email: $email,
                    event: $apiType,
                    messageId: $messageId,
                    tag: 'password_reset',
                    date: $this->iso($ts),
                    reason: 'parity reason'
                ),
            ])
        );

        if ($viaRunNow) {
            $job->runNow();

            return 0;
        }

        return $job->runNowWithSummary()->matchedCount;
    }

    /**
     * Build the import job for one blocklist entry
     *
     * @param FakeBrevoSuppressionSyncClient|null $client Out: the job's client
     * @param-out FakeBrevoSuppressionSyncClient $client
     */
    private function importJob(string $email, string $reasonCode, int $blockedTs, ?FakeBrevoSuppressionSyncClient &$client = null): BrevoSuppressionSyncJob
    {
        $client = new FakeBrevoSuppressionSyncClient([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, $reasonCode, 'parity reason', $this->iso($blockedTs)),
            ]),
        ]);

        return new BrevoSuppressionSyncJob(
            $this->db,
            $this->repo,
            new EmailEventMatcher($this->repo),
            $this->applier,
            $client,
            new DateTimeImmutable()
        );
    }

    /**
     * The profile columns that the three paths must agree on
     *
     * @return array<string, string|null>
     */
    private function snapshot(int $userId): array
    {
        $row = $this->db->query(
            'SELECT email_bounced, email_bounced_address, email_bounced_at,
                    email_blocked, email_blocked_address, email_blocked_at, email_blocked_cause,
                    email_opted_out
               FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();
        $this->assertIsObject($row, 'The owner must have a profile');

        $snapshot = [];
        foreach ((array) $row as $column => $value) {
            $snapshot[(string) $column] = $value === null ? null : (string) $value;
        }

        return $snapshot;
    }

    /**
     * Replace the owner's own address and the event time, so that snapshots of different owners compare
     *
     * @param array<string, string|null> $snapshot
     * @return array<string, string|null>
     */
    private function normalizeSnapshot(array $snapshot, string $email): array
    {
        $normalized = [];
        foreach ($snapshot as $column => $value) {
            $normalized[$column] = match (true) {
                $value === strtolower($email) => '{address}',
                $value === $this->wall($this->eventTs) => '{eventTime}',
                default => $value,
            };
        }

        return $normalized;
    }

    private function eventRowCount(int $userId, string $messageId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS cnt FROM er_email_events WHERE user_id = ? AND brevo_message_id = ? AND event != 'sent'",
            [$userId, $messageId]
        )->first()->cnt;
    }

    // ------------------------------------------------------------------
    // Same event, same result, on all three paths
    // ------------------------------------------------------------------

    /**
     * Expected profile columns use the placeholders {address} and {eventTime}.
     * An import code of null means that the import has no entry for the event.
     *
     * @return array<string, array{string, string, ?string, array<string, string|null>}>
     */
    public static function sameEventProvider(): array
    {
        $clean = [
            'email_bounced' => '0', 'email_bounced_address' => null, 'email_bounced_at' => null,
            'email_blocked' => '0', 'email_blocked_address' => null, 'email_blocked_at' => null,
            'email_blocked_cause' => null, 'email_opted_out' => '0',
        ];
        $bounced = array_merge($clean, [
            'email_bounced' => '1', 'email_bounced_address' => '{address}', 'email_bounced_at' => '{eventTime}',
        ]);
        $blocked = static fn (string $cause): array => array_merge($clean, [
            'email_blocked' => '1', 'email_blocked_address' => '{address}', 'email_blocked_at' => '{eventTime}',
            'email_blocked_cause' => $cause,
        ]);

        return [
            'hard bounce' => ['hard_bounce', 'hardBounces', 'hardBounce', $bounced],
            'blocked' => ['blocked', 'blocked', null, $bounced],
            'invalid address' => ['invalid_email', 'invalid', null, $bounced],
            'spam complaint' => ['spam', 'spam', 'contactFlaggedAsSpam', $blocked('spam')],
            'unsubscribe' => ['unsubscribed', 'unsubscribed', 'unsubscribedViaEmail', $blocked('unsubscribed')],
            'delivered' => ['delivered', 'delivered', null, $clean],
            'first soft bounce' => ['soft_bounce', 'softBounces', null, $clean],
        ];
    }

    /**
     * @param array<string, string|null> $expected
     */
    #[DataProvider('sameEventProvider')]
    public function testSameEventGivesTheSameProfileResultOnEveryPath(
        string $webhookEvent,
        string $apiType,
        ?string $importCode,
        array $expected
    ): void {
        $snapshots = [];

        $web = $this->makeOwner('parity-web');
        $this->seedSentRow($web['carId'], $web['email'], 'parity-web-msg', $this->eventTs - 3600);
        $result = $this->driveWebhook($web['email'], 'parity-web-msg', $webhookEvent, $this->eventTs);
        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result, 'The webhook must reach the applier');
        $snapshots[self::WEBHOOK] = $this->normalizeSnapshot($this->snapshot($web['userId']), $web['email']);
        $this->assertSame(1, $this->eventRowCount($web['userId'], 'parity-web-msg'), 'The webhook records the event');

        $rec = $this->makeOwner('parity-rec');
        $this->seedSentRow($rec['carId'], $rec['email'], 'parity-rec-msg', $this->eventTs - 3600);
        $this->assertSame(1, $this->driveReconciliation($rec['email'], 'parity-rec-msg', $apiType, $this->eventTs), 'The job must apply the event');
        $snapshots[self::RECONCILIATION] = $this->normalizeSnapshot($this->snapshot($rec['userId']), $rec['email']);
        $this->assertSame(1, $this->eventRowCount($rec['userId'], 'parity-rec-msg'), 'The job records the event');

        if ($importCode !== null) {
            $imp = $this->makeOwner('parity-imp');
            $this->importJob($imp['email'], $importCode, $this->eventTs)->runNow();
            $snapshots[self::IMPORT] = $this->normalizeSnapshot($this->snapshot($imp['userId']), $imp['email']);
        }

        // The expected result is a literal, so it is independent of the code under test.
        foreach ($snapshots as $path => $snapshot) {
            $this->assertSame($expected, $snapshot, "The {$path} path gives the wrong profile result");
        }
        $this->assertContains(self::WEBHOOK, array_keys($snapshots));
        $this->assertContains(self::RECONCILIATION, array_keys($snapshots));
    }

    // ------------------------------------------------------------------
    // Each reason code of the suppression import
    // ------------------------------------------------------------------

    /**
     * Brevo reason code => [event row name, bounced, blocked, cause]
     *
     * @return array<string, array{string, ?string, string, string, ?string}>
     */
    public static function reasonCodeProvider(): array
    {
        return [
            'hardBounce' => ['hardBounce', 'blocked', '1', '0', null],
            'contactFlaggedAsSpam' => ['contactFlaggedAsSpam', 'spam', '0', '1', 'spam'],
            'unsubscribedViaEmail' => ['unsubscribedViaEmail', 'unsubscribed', '0', '1', 'unsubscribed'],
            'unsubscribedViaMA' => ['unsubscribedViaMA', 'unsubscribed', '0', '1', 'unsubscribed'],
            'unsubscribedViaApi' => ['unsubscribedViaApi', 'unsubscribed', '0', '1', 'unsubscribed'],
            'adminBlocked' => ['adminBlocked', 'unsubscribed', '0', '1', 'blocklist'],
            'a code that Brevo adds later' => ['someFutureBrevoCode', null, '0', '0', null],
        ];
    }

    #[DataProvider('reasonCodeProvider')]
    public function testEachReasonCodeOfTheImportSetsItsFlagAndCause(
        string $code,
        ?string $expectedEvent,
        string $expectedBounced,
        string $expectedBlocked,
        ?string $expectedCause
    ): void {
        $owner = $this->makeOwner('reason');

        $this->importJob($owner['email'], $code, $this->eventTs)->runNow();

        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame($expectedBounced, $snapshot['email_bounced'], "{$code}: email_bounced");
        $this->assertSame($expectedBlocked, $snapshot['email_blocked'], "{$code}: email_blocked");
        $this->assertSame($expectedCause, $snapshot['email_blocked_cause'], "{$code}: email_blocked_cause");
        $rows = $this->db->query('SELECT event FROM er_email_events WHERE user_id = ?', [$owner['userId']])->results();
        if ($expectedEvent === null) {
            $this->assertSame([], $rows, 'An unrecognized code must write no event row');
        } else {
            $this->assertSame([$expectedEvent], array_map(static fn (object $r): string => (string) $r->event, $rows));
        }
    }

    /** Criterion "A blocklisted address of a user who owns no car sets email_blocked". */
    public function testBlocklistedAddressOfAUserWithNoCarSetsBlocked(): void
    {
        $owner = $this->makeOwner('nocar', withCar: false);
        $this->assertSame(0, $owner['carId']);

        $this->importJob($owner['email'], 'contactFlaggedAsSpam', $this->eventTs)->runNow();

        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_blocked']);
        $this->assertSame('spam', $snapshot['email_blocked_cause']);
    }

    // ------------------------------------------------------------------
    // A flag stored for an old address
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}> Path, flag kind
     */
    public static function oldAddressProvider(): array
    {
        $cases = [];
        foreach ([self::WEBHOOK, self::RECONCILIATION, self::IMPORT] as $path) {
            foreach (['bounce', 'block'] as $kind) {
                $cases["{$path} {$kind}"] = [$path, $kind];
            }
        }

        return $cases;
    }

    /**
     * The owner holds a flag for an old address. That flag does not apply to the
     * current address. An event for the current address gives the owner the
     * flag, and the flag's address, date and cause describe the new event.
     */
    #[DataProvider('oldAddressProvider')]
    public function testFlagStoredForAnOldAddressIsReplacedByAnEventForTheCurrentAddress(string $path, string $kind): void
    {
        $owner = $this->makeOwner('oldaddr');
        $old = 'old-address-' . uniqid() . '@example.com';
        if ($kind === 'bounce') {
            $this->setOwnerBounced($owner['userId'], $old, self::OLD_FLAG_AT);
        } else {
            $this->setOwnerBlocked($owner['userId'], $old, self::OLD_FLAG_AT, 'unsubscribed');
        }
        $flags = $this->repo->effectiveEmailFlags($owner['userId']);
        $this->assertFalse($kind === 'bounce' ? $flags->bounced : $flags->blocked, 'Setup: the old flag must not apply to the current address');

        $messageId = 'oldaddr-msg-' . uniqid();
        $this->seedSentRow($owner['carId'], $owner['email'], $messageId, $this->eventTs - 3600);
        $webhookEvent = $kind === 'bounce' ? 'hard_bounce' : 'spam';
        switch ($path) {
            case self::WEBHOOK:
                $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $this->driveWebhook($owner['email'], $messageId, $webhookEvent, $this->eventTs));
                break;
            case self::RECONCILIATION:
                $this->assertSame(1, $this->driveReconciliation($owner['email'], $messageId, $kind === 'bounce' ? 'hardBounces' : 'spam', $this->eventTs));
                break;
            default:
                // An entry inside the 48-hour window of the nightly run.
                $this->importJob($owner['email'], $kind === 'bounce' ? 'hardBounce' : 'contactFlaggedAsSpam', $this->eventTs, $client)->runNow();
                $this->assertNotNull($client->fetchArgs[0]['start'], 'The import must run the incremental window, not the full backfill');
                $this->assertGreaterThanOrEqual(time() - 48 * 3600, $this->eventTs, 'The entry must be inside the 48-hour window');
        }

        $snapshot = $this->snapshot($owner['userId']);
        $address = strtolower($owner['email']);
        if ($kind === 'bounce') {
            $this->assertSame('1', $snapshot['email_bounced']);
            $this->assertSame($address, $snapshot['email_bounced_address'], 'The flag names the current address');
            $this->assertSame($this->wall($this->eventTs), $snapshot['email_bounced_at'], 'The flag has the date of the new event');
        } else {
            $this->assertSame('1', $snapshot['email_blocked']);
            $this->assertSame($address, $snapshot['email_blocked_address']);
            $this->assertSame($this->wall($this->eventTs), $snapshot['email_blocked_at']);
            $this->assertSame('spam', $snapshot['email_blocked_cause'], 'The flag has the cause of the new event');
        }
        $this->assertTrue(
            $kind === 'bounce'
                ? $this->repo->effectiveEmailFlags($owner['userId'])->bounced
                : $this->repo->effectiveEmailFlags($owner['userId'])->blocked,
            'The flag now applies to the current address'
        );
    }

    // ------------------------------------------------------------------
    // The verification switch is off
    // ------------------------------------------------------------------

    /**
     * The webhook drops a hard bounce for a message that has no `sent` row yet.
     * With the switch off, the next reconciliation run applies it.
     */
    public function testReconciliationAppliesABounceThatTheWebhookDroppedWithTheSwitchOff(): void
    {
        $enabled = (int) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled;
        $this->assertSame(0, $enabled, 'Setup: the verification switch must be off');

        $owner = $this->makeOwner('dropped');
        $messageId = 'dropped-msg-' . uniqid();

        $first = $this->driveWebhook($owner['email'], $messageId, 'hard_bounce', $this->eventTs);
        $this->assertSame(ProcessingResult::IGNORED, $first, 'No sent row yet: the webhook drops the event');
        $this->assertSame('0', $this->snapshot($owner['userId'])['email_bounced']);
        $this->assertSame(0, $this->eventRowCount($owner['userId'], $messageId));

        $this->seedSentRow($owner['carId'], $owner['email'], $messageId, $this->eventTs - 600);
        // runNow() checks the switch gate. This job does not refuse, so it
        // runs with the switch off.
        $this->driveReconciliation($owner['email'], $messageId, 'hardBounces', $this->eventTs, viaRunNow: true);

        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_bounced'], 'The job applies the dropped bounce');
        $this->assertSame(strtolower($owner['email']), $snapshot['email_bounced_address']);
        $this->assertSame(1, $this->eventRowCount($owner['userId'], $messageId));
    }

    /**
     * Real password-reset and owner-contact mail has no `sent` row. Only the
     * plugin's row matches it (step (b)).
     */
    public function testWebhookBounceOfAPluginOnlyMessageSetsBouncedAndRecordsNoCar(): void
    {
        $owner = $this->makeOwner('plugin-only-bounce');
        $messageId = 'plugin-only-bounce-' . uniqid();
        $this->seedPluginRow($messageId, $owner['email'], $this->eventTs - 600);

        $result = $this->driveWebhook($owner['email'], $messageId, 'hard_bounce', $this->eventTs, 'password_reset');

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_bounced']);
        $this->assertSame(strtolower($owner['email']), $snapshot['email_bounced_address']);
        $this->assertSame($this->wall($this->eventTs), $snapshot['email_bounced_at']);
        $rows = $this->db->query(
            "SELECT car_id FROM er_email_events WHERE user_id = ? AND brevo_message_id = ? AND event != 'sent'",
            [$owner['userId'], $messageId]
        )->results();
        $this->assertCount(1, $rows, 'The event row must exist');
        $this->assertNull($rows[0]->car_id, 'A plugin-only match has no car');
    }

    public function testWebhookSpamOfAPluginOnlyMessageSetsBlockedWithTheSwitchOff(): void
    {
        $enabled = (int) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled;
        $this->assertSame(0, $enabled, 'Setup: the verification switch must be off');
        $owner = $this->makeOwner('plugin-only-spam');
        $messageId = 'plugin-only-spam-' . uniqid();
        $this->seedPluginRow($messageId, $owner['email'], $this->eventTs - 600);

        $result = $this->driveWebhook($owner['email'], $messageId, 'spam', $this->eventTs, 'owner_contact');

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_blocked']);
        $this->assertSame('spam', $snapshot['email_blocked_cause']);
    }

    public function testImportSetsBlockedForANewBlocklistEntryWithTheSwitchOff(): void
    {
        $enabled = (int) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled;
        $this->assertSame(0, $enabled, 'Setup: the verification switch must be off');
        $owner = $this->makeOwner('blocklist');

        $this->importJob($owner['email'], 'adminBlocked', $this->eventTs)->runNow();

        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_blocked']);
        $this->assertSame('blocklist', $snapshot['email_blocked_cause']);
    }

    // ------------------------------------------------------------------
    // Criterion 50: a blocked address A, a change to B, and back to A
    // ------------------------------------------------------------------

    /**
     * Make an owner whose address A had a bounce and a block. A confirmed change
     * to B clears both. Then the owner returns to A.
     *
     * @param string $returnPath `owner_update` is the email_act == 0 path (and the admin owner edit),
     *                           which sets `email_address_since` and `email_address_seen`.
     *                           `admin_form` is the upstream admin user form, which writes only `users.email`.
     * @return array{userId: int, carId: int, email: string, other: string}
     */
    private function ownerWhoReturnedToAFromB(string $returnPath): array
    {
        $owner = $this->makeOwner('abc');
        $userId = $owner['userId'];
        $this->setOwnerBlocked($userId, strtolower($owner['email']), self::OLD_FLAG_AT, 'unsubscribed');
        $this->setOwnerBounced($userId, strtolower($owner['email']), self::OLD_FLAG_AT);
        $this->assertTrue($this->repo->effectiveEmailFlags($userId)->blocked, 'Setup: A is blocked');

        $other = 'moved-to-' . uniqid() . '@example.com';
        $this->setUserEmailRaw($userId, $other);
        $this->assertTrue($this->manager->clearFlagsForConfirmedEmailChange($userId, $userId), 'The confirmed change to B must clear the flags');
        $afterClear = $this->snapshot($userId);
        $this->assertSame('0', $afterClear['email_blocked']);
        $this->assertSame('0', $afterClear['email_bounced']);

        if ($returnPath === 'owner_update') {
            $this->assertTrue((new Owner($userId))->update(['id' => $userId, 'email' => $owner['email']]));
        } else {
            $this->setUserEmailRaw($userId, $owner['email']);
        }
        $owner['other'] = $other;

        return $owner;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function returnPathProvider(): array
    {
        return [
            'email_act == 0 path (Owner::update)' => ['owner_update'],
            'admin user form (users.email only)' => ['admin_form'],
        ];
    }

    #[DataProvider('returnPathProvider')]
    public function testFullBackfillSetsBlockedAgainWhenTheOwnerReturnsToABlockedAddress(string $returnPath): void
    {
        $owner = $this->ownerWhoReturnedToAFromB($returnPath);
        $oldBlockedTs = time() - 20 * 86400;

        // The nightly import covers 48 hours and finds nothing this old. The full backfill has no window.
        $summary = $this->importJob($owner['email'], 'unsubscribedViaEmail', $oldBlockedTs)->runNowWithSummary();

        $this->assertSame(1, $summary->matchedCount, 'The backfill must apply the entry');
        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_blocked'], 'The backfill sets email_blocked again');
        $this->assertSame(strtolower($owner['email']), $snapshot['email_blocked_address']);
        $this->assertSame('unsubscribed', $snapshot['email_blocked_cause']);
        $this->assertSame($this->wall($oldBlockedTs), $snapshot['email_blocked_at']);
    }

    /**
     * The bounce is not restored from Brevo's old entry. Its date is before
     * the confirmed change that cleared the bounce. The next send to A
     * restores it.
     */
    #[DataProvider('returnPathProvider')]
    public function testBlockedEventOfTheNextSendToTheReturnedAddressSetsBounced(string $returnPath): void
    {
        $owner = $this->ownerWhoReturnedToAFromB($returnPath);

        $backfill = $this->importJob($owner['email'], 'hardBounce', time() - 20 * 86400)->runNowWithSummary();
        $this->assertSame(1, $backfill->matchedCount + $backfill->alreadyFlaggedCount, 'The backfill must read the entry');
        $this->assertSame('0', $this->snapshot($owner['userId'])['email_bounced'], 'An entry older than the clear does not restore the bounce');

        $messageId = 'next-send-' . uniqid();
        $this->seedSentRow($owner['carId'], $owner['email'], $messageId, time());
        $result = $this->driveWebhook($owner['email'], $messageId, 'blocked', time());

        $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $result);
        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_bounced'], 'The blocked event of the next send sets email_bounced');
        $this->assertSame(strtolower($owner['email']), $snapshot['email_bounced_address']);
    }

    // ------------------------------------------------------------------
    // Criterion "A spam or unsubscribe before the latest Clear Block"
    // ------------------------------------------------------------------

    /**
     * Write the Clear Block audit row (`new = 0`, source `admin`) with a fixed time
     */
    private function seedClearBlockRow(int $userId, int $clearTs): void
    {
        $this->assertTrue(
            (new DeliverabilityFlagLog())->record(0, $userId, DeliverabilityFlagLog::FLAG_BLOCKED, 1, 0, DeliverabilityFlagLog::SOURCE_ADMIN),
            'Setup: the Clear Block row must be written'
        );
        $this->db->query(
            'UPDATE logs SET logdate = ? WHERE lognote = ?',
            [$this->wall($clearTs), "Owner {$userId} email_blocked changed from 1 to 0 (source: admin)"]
        );
        $this->assertSame($this->wall($clearTs), $this->repo->latestFlagClearAt($userId, 'email_blocked', 'admin'));
    }

    /**
     * @return array<string, array{string, string}> Path, event
     */
    public static function clearBlockProvider(): array
    {
        $cases = [];
        foreach ([self::WEBHOOK, self::RECONCILIATION] as $path) {
            foreach (['spam', 'unsubscribed'] as $event) {
                $cases["{$path} {$event}"] = [$path, $event];
            }
        }

        return $cases;
    }

    #[DataProvider('clearBlockProvider')]
    public function testEventBeforeTheLatestClearBlockLeavesTheBlockClearAndALaterEventSetsIt(string $path, string $event): void
    {
        $owner = $this->makeOwner('clearblock');
        $clearTs = time() - 3600;
        $this->seedClearBlockRow($owner['userId'], $clearTs);

        $drive = function (string $messageId, int $sentTs, int $eventTs) use ($path, $event, $owner): void {
            $this->seedSentRow($owner['carId'], $owner['email'], $messageId, $sentTs);
            if ($path === self::WEBHOOK) {
                $this->assertSame(ProcessingResult::MATCHED_AND_RECORDED, $this->driveWebhook($owner['email'], $messageId, $event, $eventTs));
            } else {
                $this->assertSame(1, $this->driveReconciliation($owner['email'], $messageId, $event, $eventTs));
            }
        };

        $drive('before-clear-msg', $clearTs - 7200, $clearTs - 1800);

        $this->assertSame(1, $this->eventRowCount($owner['userId'], 'before-clear-msg'), 'The earlier event is still recorded');
        $this->assertSame('0', $this->snapshot($owner['userId'])['email_blocked'], 'An event before the Clear Block does not set the block');

        $drive('after-clear-msg', $clearTs + 300, $clearTs + 600);

        $snapshot = $this->snapshot($owner['userId']);
        $this->assertSame('1', $snapshot['email_blocked'], 'A later event sets the block');
        $this->assertSame($event, $snapshot['email_blocked_cause']);
        $this->assertSame($this->wall($clearTs + 600), $snapshot['email_blocked_at']);
    }
}
