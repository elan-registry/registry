<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/FakeBrevoBlockedContact.php';
require_once __DIR__ . '/../Support/FakeBrevoSuppressionSyncClient.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\DeliverabilityFlagLog;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatcher;
use ElanRegistry\Cron\BrevoSuppressionSyncJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\FakeBrevoBlockedContact;
use Tests\Support\FakeBrevoSuppressionSyncClient;

/**
 * #1923, #2358, #2359: BrevoSuppressionSyncJob against real SQL; only the Brevo client is fake.
 * Control flow is in tests/unit/cron/BrevoSuppressionSyncJobTest.php. This file
 * proves the cross-layer agreements a mocked repository cannot falsify.
 */
#[Group('integration')]
final class BrevoSuppressionSyncJobIntegrationTest extends IntegrationTestCase
{
    private const NOW = '2026-09-09 03:00:00';

    /** Brevo's blockedAt (Paris wall time, `Z` suffix, #2501) used by every contact seeded here. */
    private const BLOCKED_AT_BREVO = '2026-09-08T12:00:00.000Z';

    private CarRepository $repo;
    private EmailEventApplier $applier;
    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

    /** Original er_verification_settings.unmatched_recipient_count, restored in tearDown(). */
    private int $originalUnmatchedCount = 0;

    /** Highest logs.id before the test, so tearDown() removes only this test's log rows. */
    private int $logIdFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // The verification switch ships off. Since #2358 the job ignores it, so the tests run with it off.
        $this->db->query('SELECT enabled, unmatched_recipient_count FROM er_verification_settings WHERE id = 1');
        $verificationRow = $this->db->first();
        $this->originalVerificationEnabled = is_object($verificationRow) ? (bool) $verificationRow->enabled : false;
        // Shared singleton-row counter; restored so a bumped count cannot leak.
        $this->originalUnmatchedCount = is_object($verificationRow)
            ? (int) $verificationRow->unmatched_recipient_count
            : 0;
        $this->db->query('UPDATE er_verification_settings SET enabled = 0 WHERE id = 1');
        $this->logIdFloor = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM logs')->first()->m;

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));
    }

    protected function tearDown(): void
    {
        // The base class deletes the er_email_events rows of the test users.
        if ($this->databaseConnected) {
            $this->deleteOwnLogs();
            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ?, unmatched_recipient_count = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0, $this->originalUnmatchedCount]
            );
        }

        parent::tearDown();
    }

    /**
     * Remove the flag audit rows and log lines about this test's users. The base class removes no logs.
     * It matches by user ID and note text, so it never removes a row of a parallel run.
     */
    private function deleteOwnLogs(): void
    {
        $property = new ReflectionProperty(IntegrationTestCase::class, 'createdUserIds');
        /** @var list<int> $userIds */
        $userIds = $property->getValue($this);
        foreach ($userIds as $userId) {
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND (user_id = ? OR lognote LIKE ? OR lognote LIKE ?)',
                [$this->logIdFloor, $userId, "Owner {$userId} %", "%owner {$userId} %"]
            );
        }
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * @return array{0: int, 1: string} The car id and its email
     */
    private function createSuppressionCar(): array
    {
        $email = 'suppression-' . uniqid() . '@integration-test-1923.example.com';
        // The flags belong to the owner's profile, so each car gets its own
        // owner. Cars of one owner would share the flag. The owner's
        // users.email equals the car email, as in production (the verify hook
        // copies it to the cars). A flag applies only while its address
        // matches users.email (#2356).
        $ownerId = $this->createTestUser(['email' => $email], true);
        $carId = $this->createTestCar($ownerId, ['email' => $email]);

        return [$carId, $email];
    }

    /**
     * @param list<\Brevo\Client\Model\GetTransacBlockedContacts|null> $pages
     *        Handed to the fake client verbatim, one per expected fetch.
     */
    private function makeJob(array $pages): BrevoSuppressionSyncJob
    {
        return new BrevoSuppressionSyncJob(
            $this->db,
            $this->repo,
            new EmailEventMatcher($this->repo),
            $this->applier,
            new FakeBrevoSuppressionSyncClient($pages),
            new \DateTimeImmutable(self::NOW)
        );
    }

    // --- Assertion helpers -------------------------------------------------

    /**
     * The deliverability flags of the car's owner profile.
     */
    private function ownerFlagsForCar(int $carId): object
    {
        $row = $this->db->query(
            'SELECT p.email_bounced, p.email_bounced_address, p.email_blocked, p.email_blocked_address,
                    p.email_blocked_cause, p.email_opted_out, c.user_id
               FROM cars c INNER JOIN profiles p ON p.user_id = c.user_id
              WHERE c.id = ?',
            [$carId]
        )->first();

        $this->assertNotEmpty($row, "cars row {$carId} and its owner profile must exist");

        return $row;
    }

    /**
     * @return list<array{date: string, flag: string, old: int, new: int, source: string, acting_user_id: int}>
     */
    private function flagChanges(int $carId): array
    {
        return $this->repo->recentFlagChanges((int) $this->ownerFlagsForCar($carId)->user_id, 100);
    }

    /**
     * @return list<object> The er_email_events rows of the car's owner, oldest id first
     */
    private function eventRows(int $carId): array
    {
        return $this->db->query(
            'SELECT event, email, brevo_message_id, car_id FROM er_email_events
              WHERE user_id = (SELECT user_id FROM cars WHERE id = ?) ORDER BY id',
            [$carId]
        )->results();
    }

    private function countEventRows(int $carId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE user_id = (SELECT user_id FROM cars WHERE id = ?)',
            [$carId]
        )->first();

        return (int) $row->cnt;
    }

    /** The dashboard's running total of verification-tagged events with no user. */
    private function unmatchedRecipientCount(): int
    {
        $row = $this->db->query(
            'SELECT unmatched_recipient_count FROM er_verification_settings WHERE id = 1'
        )->first();

        $this->assertNotEmpty($row, 'er_verification_settings row 1 must exist');

        return (int) $row->unmatched_recipient_count;
    }

    // --- 1. Incremental path, end to end through execute() ------------------

    /**
     * execute() is protected; runNow() is the supported route. Proves the mapper's
     * output is an event EmailEventApplier escalates on.
     */
    public function testIncrementalRunFlagsMatchingCarAsBouncedAndRecordsEvent(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_BREVO),
            ]),
        ])->runNow();

        $flags = $this->ownerFlagsForCar($carId);
        $this->assertSame(1, (int) $flags->email_bounced, 'A hardBounce suppression must flag the owner bounced');
        $this->assertSame($email, $flags->email_bounced_address, 'The bounced address must be recorded');
        $this->assertSame(
            0,
            (int) $flags->email_blocked,
            'A hard bounce is not a block decision — email_blocked must stay unset'
        );
        $changes = $this->flagChanges($carId);
        $this->assertCount(1, $changes, 'One flag change writes one log row');
        $this->assertSame('email_bounced', $changes[0]['flag']);
        $this->assertSame('import', $changes[0]['source'], 'The suppression import passes the source import');

        $events = $this->eventRows($carId);
        $this->assertCount(1, $events, 'Exactly one event row must be written for the contact');
        $this->assertSame('blocked', $events[0]->event, "hardBounce must be recorded as 'blocked'");
        $this->assertSame($email, $events[0]->email);
        $this->assertNull($events[0]->car_id, 'An import event belongs to the owner, not to a car');
    }

    // --- Owner-level write (#2358) -------------------------------------------

    /** An owner with three cars gets one row for one contact, with car_id NULL. */
    public function testAContactForAnOwnerWithThreeCarsWritesOneRowWithCarIdNull(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        $ownerId = (int) $this->ownerFlagsForCar($carId)->user_id;
        $this->createTestCar($ownerId, ['email' => $email]);
        $this->createTestCar($ownerId, ['email' => $email]);

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $events = $this->eventRows($carId);
        $this->assertCount(1, $events, 'One contact is one row, not one row for each car');
        $this->assertNull($events[0]->car_id);
        $this->assertSame(1, $summary->matchedCount);
        $this->assertCount(1, $this->flagChanges($carId), 'The flag is written once for the owner');
    }

    // --- 2. Backfill path, end to end across several pages ------------------

    /**
     * runFullBackfill() is deliberately unreachable from run()/execute().
     */
    public function testFullBackfillFlagsCarsAcrossEveryPageAndReportsHonestCounts(): void
    {
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$unsubCarId, $unsubEmail] = $this->createSuppressionCar();

        // A short page stops the loop; the end-of-data test belongs to the unit suite.
        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason($unsubEmail, 'unsubscribedViaEmail', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(3, $summary->matchedCount, 'All three contacts matched a car and were newly flagged');
        $this->assertSame(0, $summary->unmatchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertSame(1, $summary->pagesFetched);
        $this->assertFalse($summary->backfillCapped);

        $this->assertSame(1, (int) $this->ownerFlagsForCar($bounceCarId)->email_bounced);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked);
        $this->assertSame('spam', $this->ownerFlagsForCar($spamCarId)->email_blocked_cause);
        $this->assertSame($spamEmail, $this->ownerFlagsForCar($spamCarId)->email_blocked_address);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($unsubCarId)->email_blocked);
        $this->assertSame('unsubscribed', $this->ownerFlagsForCar($unsubCarId)->email_blocked_cause);
    }

    // --- 3. Idempotence across two backfills --------------------------------

    /**
     * The deterministic synthetic message id must collide with er_email_events'
     * UNIQUE (user_id, brevo_message_id, event), and the pre-check must read the
     * previous run's write.
     */
    public function testRerunningTheBackfillDedupsEventsAndReportsAlreadyFlagged(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $contact = FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_BREVO);

        $first = $this->makeJob([FakeBrevoSuppressionSyncClient::page([$contact])])->runFullBackfill();
        $this->assertSame(1, $first->matchedCount, 'First run newly flags the car');
        $this->assertSame(0, $first->alreadyFlaggedCount);
        $this->assertSame(1, $this->countEventRows($carId), 'First run writes exactly one event row');

        $firstMessageId = $this->eventRows($carId)[0]->brevo_message_id;

        $second = $this->makeJob([FakeBrevoSuppressionSyncClient::page([$contact])])->runFullBackfill();

        $this->assertSame(
            1,
            $this->countEventRows($carId),
            'Re-importing the same suppression must dedup via the real UNIQUE constraint, not append a row'
        );
        $this->assertSame(
            $firstMessageId,
            $this->eventRows($carId)[0]->brevo_message_id,
            'The synthetic message id must be identical across runs — that is what makes the import idempotent'
        );

        $this->assertSame(0, $second->matchedCount, 'The second run did no new flag work');
        $this->assertSame(1, $second->alreadyFlaggedCount, 'The already-flagged pre-check must see the first run\'s write');
        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->email_bounced, 'The owner stays bounced after both runs');
        $this->assertCount(1, $this->flagChanges($carId), 'The second run changes nothing, so it logs nothing');
    }

    // --- #2502: an import after a webhook row for the same block -------------

    /**
     * @return array<string, array{string, string, string, string}> webhook event, reason code, import event, flag column
     */
    public static function webhookThenImportProvider(): array
    {
        return [
            'spam' => ['spam', 'contactFlaggedAsSpam', 'spam', 'email_blocked'],
            'hard bounce' => ['hard_bounce', 'hardBounce', 'blocked', 'email_bounced'],
            'unsubscribed' => ['unsubscribed', 'unsubscribedViaEmail', 'unsubscribed', 'email_blocked'],
        ];
    }

    #[DataProvider('webhookThenImportProvider')]
    public function testImportAfterAWebhookRowForTheSameBlockWritesNoSecondRow(
        string $webhookEvent,
        string $reasonCode,
        string $importEvent,
        string $flagColumn
    ): void {
        [$carId, $email] = $this->createSuppressionCar();
        $userId = (int) $this->ownerFlagsForCar($carId)->user_id;

        $this->applier->apply(
            \ElanRegistry\Car\EmailEventMatch::matched($userId, $carId, date('Y-m-d H:i:s', time() + 60)),
            $email,
            $webhookEvent,
            null,
            'webhook-msg-' . uniqid('', true),
            date('Y-m-d H:i:s', time() + 120),
            'webhook',
            EmailEventApplier::MODE_EVENT
        );
        $this->assertSame(1, $this->countEventRows($carId), 'Test setup: the webhook wrote its row');
        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->{$flagColumn}, 'Test setup: the webhook flagged the owner');

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, $reasonCode, null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $rows = $this->eventRows($carId);
        $this->assertCount(1, $rows, "The import of {$importEvent} must not add a second row for the same block");
        $this->assertSame($webhookEvent, $rows[0]->event);
        $this->assertSame(1, $summary->alreadyFlaggedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->{$flagColumn}, 'The skip keeps the flag');
    }

    // --- 4. Contact matching no user ---------------------------------------

    /**
     * Most suppressed addresses were never registry users. The dashboard
     * counter counts verification-tagged events only (#2359), and a
     * blocklist entry has no tag, so the counter does not change.
     */
    public function testUnmatchedContactIsTalliedAndWritesNoEventRow(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        $strangerEmail = 'stranger-' . uniqid() . '@integration-test-1923.example.com';

        $countBefore = $this->unmatchedRecipientCount();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($strangerEmail, 'hardBounce', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->unmatchedCount, 'The address with no user must be counted unmatched');
        $this->assertSame(1, $summary->matchedCount, 'The matched contact is unaffected by its neighbour');

        $stranded = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE email = ?',
            [$strangerEmail]
        )->first();
        $this->assertSame(0, (int) $stranded->cnt, 'An unmatched address must leave no event row behind');

        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->email_bounced);

        $this->assertSame(
            $countBefore,
            $this->unmatchedRecipientCount(),
            'An untagged blocklist entry must not change the dashboard unmatched-recipient counter'
        );
    }

    // --- User who owns no car (#2359) ----------------------------------------

    /** The flags belong to the owner's profile, so a user with no car gets the flag. */
    public function testUserWithNoCarGetsTheBlockFlag(): void
    {
        $email = 'carless-' . uniqid() . '@integration-test-1923.example.com';
        $userId = $this->createTestUser(['email' => $email], true);

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'unsubscribedViaEmail', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->matchedCount);
        $profile = $this->db->query(
            'SELECT email_blocked, email_blocked_address, email_blocked_cause FROM profiles WHERE user_id = ?',
            [$userId]
        )->first();
        $this->assertSame(1, (int) $profile->email_blocked);
        $this->assertSame($email, $profile->email_blocked_address);
        $this->assertSame('unsubscribed', $profile->email_blocked_cause);

        $event = $this->db->query(
            'SELECT event, car_id FROM er_email_events WHERE user_id = ?',
            [$userId]
        )->first();
        $this->assertSame('unsubscribed', $event->event);
        $this->assertNull($event->car_id);
    }

    /**
     * #2393 gap 2: the applier writes no flag for an owner with no profiles
     * row. The summary must count that contact as skipped, not matched.
     */
    public function testOwnerWithoutProfileRowCountsSkippedNotMatched(): void
    {
        $email = 'noprofile-' . uniqid() . '@integration-test-1923.example.com';
        $userId = $this->createTestUser(['email' => $email], false);
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt,
            'Test setup: the owner must have no profiles row'
        );

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'unsubscribedViaEmail', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->skippedCount, 'The skipped flag step counts as skipped');
        $this->assertSame(0, $summary->matchedCount, 'A skipped flag step is not a match');
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM profiles WHERE user_id = ?', [$userId])->first()->cnt,
            'The sync must not create a profiles row'
        );
    }

    // --- Address check and blockedAt fallback (#2359) -----------------------

    /** An invalid address is skipped before any match: no row, no flag. */
    public function testInvalidAddressesAreSkippedAndCounted(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        // 156 bytes: valid under FILTER_VALIDATE_EMAIL, so only the length rule rejects it.
        $tooLong = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 23) . '.com';

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($tooLong, 'hardBounce', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason('not-an-address', 'hardBounce', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(2, $summary->invalidAddressCount);
        $this->assertSame(1, $summary->matchedCount);
        $this->assertSame(3, $summary->contactsExamined);
        $this->assertSame(1, $this->countEventRows($carId));
        $stray = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE email IN (?, ?)',
            [$tooLong, 'not-an-address']
        )->first();
        $this->assertSame(0, (int) $stray->cnt, 'An invalid address writes no event row');
    }

    /** A wrong-typed `blockedAt` takes the run time as the event time. */
    public function testWrongTypedBlockedAtTakesTheRunTime(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'contactFlaggedAsSpam', null, 1757332800),
            ]),
        ])->runFullBackfill();

        $row = $this->db->query(
            'SELECT occurred_at FROM er_email_events WHERE user_id = (SELECT user_id FROM cars WHERE id = ?)',
            [$carId]
        )->first();
        $this->assertSame(self::NOW, $row->occurred_at);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->email_blocked);
    }

    // --- Import with no usable blockedAt after an admin clear (#2426) ---------

    /** An admin clear that happened before the run time (self::NOW). */
    private const CLEARED_AT = '2026-09-09 01:00:00';

    /** An admin clear that happened after the run time, as when the page was fetched before it. */
    private const CLEARED_AFTER_RUN = '2026-09-09 04:00:00';

    /** Earlier than every fixed clear time, so a seeded clear is for the current address. */
    private const ADDRESS_SINCE = '2026-08-01 00:00:00';

    /**
     * Set `email_address_since` on the car owner's profile. createTestUser() sets it to the real
     * clock, which is after every fixed clear time below. A clear counts for a fallback entry only
     * when it is after this time (#2482).
     */
    private function setAddressSince(int $carId, string $since): void
    {
        $this->db->query(
            'UPDATE profiles SET email_address_since = ? WHERE user_id = ?',
            [$since, (int) $this->ownerFlagsForCar($carId)->user_id]
        );
        $this->assertFalse($this->db->error(), 'Test setup: email_address_since: ' . $this->db->errorString());
    }

    /**
     * Seed the Clear Block row the way DeliverabilityFlagLog::record() writes it for source admin.
     * CarVerificationManager's Clear Block action writes the same row. Also sets
     * `email_address_since` to `$addressSince`.
     */
    private function seedClearBlock(int $carId, string $clearedAt, string $addressSince = self::ADDRESS_SINCE): void
    {
        $this->setAddressSince($carId, $addressSince);
        $this->assertTrue(
            (new DeliverabilityFlagLog())->record(
                0,
                (int) $this->ownerFlagsForCar($carId)->user_id,
                DeliverabilityFlagLog::FLAG_BLOCKED,
                1,
                0,
                DeliverabilityFlagLog::SOURCE_ADMIN,
                clearedAt: $clearedAt
            ),
            'Test setup: the Clear Block row must be written'
        );
    }

    /**
     * Set the Clear Bounced time on the owner's profile, as the Clear Bounced action leaves it.
     * Also sets `email_address_since` to `$addressSince`.
     */
    private function seedClearBounced(int $carId, string $clearedAt, string $addressSince = self::ADDRESS_SINCE): void
    {
        $this->setAddressSince($carId, $addressSince);
        $this->db->query(
            'UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?',
            [$clearedAt, (int) $this->ownerFlagsForCar($carId)->user_id]
        );
        $this->assertFalse($this->db->error(), 'Test setup: Clear Bounced: ' . $this->db->errorString());
    }

    /** @return list<string> The "no usable event date" log lines about the car's owner */
    private function noUsableDateLines(int $carId): array
    {
        $rows = $this->db->query(
            'SELECT lognote FROM logs WHERE id > ? AND user_id = ? AND lognote LIKE ? ORDER BY id',
            [$this->logIdFloor, (int) $this->ownerFlagsForCar($carId)->user_id, '%no usable event date%']
        )->results();

        return array_map(static fn (object $row): string => (string) $row->lognote, $rows);
    }

    /** Assert that the one skip line names the owner and the event and holds no address. */
    private function assertOneSkipLine(int $carId, string $event): void
    {
        $ownerId = (int) $this->ownerFlagsForCar($carId)->user_id;
        $lines = $this->noUsableDateLines($carId);
        $this->assertCount(1, $lines, 'The skip writes one log line');
        $this->assertSame(
            "Brevo: event \"{$event}\" for owner {$ownerId} not recorded: no usable event date and a clear for the current address is on record.",
            $lines[0]
        );
        $this->assertStringNotContainsString('@', $lines[0], 'The log line holds no address');
    }

    /** @return array<string, array{mixed}> */
    public static function noUsableBlockedAtProvider(): array
    {
        return [
            'null' => [null],
            'a string that is not a date' => ['garbage'],
            'an empty string' => [''],
            'an int' => [1757332800],
            'an array' => [['2026-09-08T12:00:00.000Z']],
            'one year after the run time' => ['2027-09-09T03:00:00.000Z'],
        ];
    }

    /**
     * A spam entry after Clear Block and a hard bounce after Clear Bounced. The run time is later than
     * both clears, so only the fallback marker keeps the flags clear.
     */
    #[DataProvider('noUsableBlockedAtProvider')]
    public function testNoUsableBlockedAtAfterClearsKeepsFlagsClear(mixed $blockedAt): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        $this->seedClearBlock($spamCarId, self::CLEARED_AT);
        $this->seedClearBounced($bounceCarId, self::CLEARED_AT);
        $spamChangesBefore = $this->flagChanges($spamCarId);
        $this->assertCount(1, $spamChangesBefore, 'Test setup: only the Clear Block row exists');

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, $blockedAt),
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, $blockedAt),
            ]),
        ])->runFullBackfill();

        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(2, $summary->skippedCount, 'Both entries are skipped');
        $this->assertSame(0, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked, 'Clear Block holds');
        $this->assertSame(0, (int) $this->ownerFlagsForCar($bounceCarId)->email_bounced, 'Clear Bounced holds');
        $this->assertSame(0, $this->countEventRows($spamCarId), 'No event row for the spam entry');
        $this->assertSame(0, $this->countEventRows($bounceCarId), 'No event row for the hard bounce');
        $this->assertSame($spamChangesBefore, $this->flagChanges($spamCarId), 'No flag-change row is added');
        $this->assertSame([], $this->flagChanges($bounceCarId));
        $this->assertOneSkipLine($spamCarId, 'spam');
        $this->assertOneSkipLine($bounceCarId, 'blocked');
    }

    /** A clear from before the address change does not guard the current address (#2482). */
    public function testNoUsableBlockedAtAfterOldClearsSetsBothFlags(): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        $oldClear = '2026-09-01 00:00:00';
        $this->seedClearBlock($spamCarId, $oldClear, '2026-09-05 00:00:00');
        $this->seedClearBounced($bounceCarId, $oldClear, '2026-09-05 00:00:00');

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, null),
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, null),
            ]),
        ])->runFullBackfill();

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(0, $summary->skippedCount);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($bounceCarId)->email_bounced);
        $this->assertSame(1, $this->countEventRows($spamCarId));
        $this->assertSame(1, $this->countEventRows($bounceCarId));
        $this->assertSame([], $this->noUsableDateLines($spamCarId));
        $this->assertSame([], $this->noUsableDateLines($bounceCarId));
    }

    /** now + 301 s is no usable date. now + 299 s is clock skew that the job uses as given. */
    public function testBlockedAtMoreThanFiveMinutesAheadIsNoUsableDate(): void
    {
        [$farCarId, $farEmail] = $this->createSuppressionCar();
        [$nearCarId, $nearEmail] = $this->createSuppressionCar();
        $this->seedClearBlock($farCarId, self::CLEARED_AT);
        $this->seedClearBlock($nearCarId, self::CLEARED_AT);
        $nowTimestamp = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason(
                    $farEmail,
                    'contactFlaggedAsSpam',
                    null,
                    FakeBrevoBlockedContact::blockedAtFor($nowTimestamp + 301)
                ),
                FakeBrevoBlockedContact::withReason(
                    $nearEmail,
                    'contactFlaggedAsSpam',
                    null,
                    FakeBrevoBlockedContact::blockedAtFor($nowTimestamp + 299)
                ),
            ]),
        ])->runFullBackfill();

        $this->assertSame(0, (int) $this->ownerFlagsForCar($farCarId)->email_blocked, '301 s ahead: no usable date, Clear Block holds');
        $this->assertSame(0, $this->countEventRows($farCarId));
        $this->assertOneSkipLine($farCarId, 'spam');

        $this->assertSame(1, (int) $this->ownerFlagsForCar($nearCarId)->email_blocked, '299 s ahead: used as given, after the clear');
        $events = $this->db->query(
            'SELECT occurred_at FROM er_email_events WHERE user_id = (SELECT user_id FROM cars WHERE id = ?)',
            [$nearCarId]
        )->results();
        $this->assertCount(1, $events);
        $this->assertSame('2026-09-09 03:04:59', $events[0]->occurred_at, 'The date is the given one, not the run time');
        $this->assertSame([], $this->noUsableDateLines($nearCarId));
    }

    /** With no clear on record, an entry with no usable date still sets the flag at the run time. */
    #[DataProvider('noClearProvider')]
    public function testNoUsableBlockedAtWithNoClearStillSetsTheFlag(mixed $blockedAt): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, $blockedAt),
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, $blockedAt),
            ]),
        ])->runFullBackfill();

        $this->assertSame(2, $summary->matchedCount);
        $spam = $this->ownerFlagsForCar($spamCarId);
        $this->assertSame(1, (int) $spam->email_blocked);
        $this->assertSame('spam', $spam->email_blocked_cause);
        $bounce = $this->ownerFlagsForCar($bounceCarId);
        $this->assertSame(1, (int) $bounce->email_bounced);
        foreach ([$spamCarId, $bounceCarId] as $carId) {
            $events = $this->eventRows($carId);
            $this->assertCount(1, $events);
            $this->assertSame([], $this->noUsableDateLines($carId), 'The skip line is for a clear on record only');
        }
        $times = $this->db->query(
            'SELECT email_blocked_at, email_bounced_at FROM profiles WHERE user_id IN (?, ?) ORDER BY user_id',
            [(int) $spam->user_id, (int) $bounce->user_id]
        )->results();
        $this->assertSame(self::NOW, $times[0]->email_blocked_at, 'The run time is the flag time');
        $this->assertSame(self::NOW, $times[1]->email_bounced_at, 'The run time is the flag time');
    }

    /** @return array<string, array{mixed}> */
    public static function noClearProvider(): array
    {
        return [
            'null' => [null],
            'a string that is not a date' => ['garbage'],
            'one year after the run time' => ['2027-09-09T03:00:00.000Z'],
        ];
    }

    /** Clear Bounced does not guard a spam entry, and Clear Block does not guard a hard bounce. */
    public function testBounceClearDoesNotGuardSpamAndBlockClearDoesNotGuardHardBounce(): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        $this->seedClearBounced($spamCarId, self::CLEARED_AT);
        $this->seedClearBlock($bounceCarId, self::CLEARED_AT);

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, null),
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, null),
            ]),
        ])->runFullBackfill();

        $this->assertSame(2, $summary->matchedCount);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked, 'Clear Bounced does not guard spam');
        $this->assertSame(1, (int) $this->ownerFlagsForCar($bounceCarId)->email_bounced, 'Clear Block does not guard a hard bounce');
        $this->assertSame([], $this->noUsableDateLines($spamCarId));
        $this->assertSame([], $this->noUsableDateLines($bounceCarId));
    }

    /** adminBlocked and the unsubscribe codes set the block flag, so Clear Block guards them. */
    public function testAdminBlockedAndUnsubscribedFollowTheBlockClearRule(): void
    {
        [$adminClearCar, $adminClearEmail] = $this->createSuppressionCar();
        [$unsubClearCar, $unsubClearEmail] = $this->createSuppressionCar();
        [$adminOpenCar, $adminOpenEmail] = $this->createSuppressionCar();
        [$unsubOpenCar, $unsubOpenEmail] = $this->createSuppressionCar();
        [$adminBounceClearCar, $adminBounceClearEmail] = $this->createSuppressionCar();
        $this->seedClearBlock($adminClearCar, self::CLEARED_AT);
        $this->seedClearBlock($unsubClearCar, self::CLEARED_AT);
        $this->seedClearBounced($adminBounceClearCar, self::CLEARED_AT);

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($adminClearEmail, 'adminBlocked', null, null),
                FakeBrevoBlockedContact::withReason($unsubClearEmail, 'unsubscribedViaEmail', null, 'garbage'),
                FakeBrevoBlockedContact::withReason($adminOpenEmail, 'adminBlocked', null, null),
                FakeBrevoBlockedContact::withReason($unsubOpenEmail, 'unsubscribedViaApi', null, null),
                FakeBrevoBlockedContact::withReason($adminBounceClearEmail, 'adminBlocked', null, null),
            ]),
        ])->runFullBackfill();

        $this->assertSame(0, (int) $this->ownerFlagsForCar($adminClearCar)->email_blocked, 'Clear Block guards adminBlocked');
        $this->assertSame(0, $this->countEventRows($adminClearCar));
        $this->assertOneSkipLine($adminClearCar, 'unsubscribed');
        $this->assertSame(0, (int) $this->ownerFlagsForCar($unsubClearCar)->email_blocked, 'Clear Block guards unsubscribed');
        $this->assertSame(0, $this->countEventRows($unsubClearCar));
        $this->assertOneSkipLine($unsubClearCar, 'unsubscribed');

        $adminOpen = $this->ownerFlagsForCar($adminOpenCar);
        $this->assertSame(1, (int) $adminOpen->email_blocked, 'With no clear, adminBlocked still blocks');
        $this->assertSame('blocklist', $adminOpen->email_blocked_cause);
        $this->assertSame(1, (int) $this->ownerFlagsForCar($unsubOpenCar)->email_blocked);
        $this->assertSame(
            1,
            (int) $this->ownerFlagsForCar($adminBounceClearCar)->email_blocked,
            'Clear Bounced does not guard adminBlocked'
        );
    }

    /**
     * The page is fetched (run time 03:00), then an admin clears (04:00), then the entries are applied.
     * An entry that was on the list before the clear must not undo it.
     */
    #[DataProvider('pageFetchedBeforeClearProvider')]
    public function testImportPageFetchedBeforeClearBlockDoesNotSetFlag(mixed $blockedAt): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        $this->seedClearBlock($spamCarId, self::CLEARED_AFTER_RUN);
        $this->seedClearBounced($bounceCarId, self::CLEARED_AFTER_RUN);

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, $blockedAt),
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, $blockedAt),
            ]),
        ])->runFullBackfill();

        $this->assertSame(0, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked);
        $this->assertSame(0, (int) $this->ownerFlagsForCar($bounceCarId)->email_bounced);
        // A spam entry with a date keeps its event row: the Clear Block bound stops the flag only.
        $this->assertSame(0, $this->countEventRows($bounceCarId), 'A hard bounce before Clear Bounced records nothing');
    }

    /** @return array<string, array{mixed}> */
    public static function pageFetchedBeforeClearProvider(): array
    {
        return [
            'a date before the clear' => [self::BLOCKED_AT_BREVO],
            'no date' => [null],
        ];
    }

    // --- Owner whose users.email moved after a block (#2356) ----------------

    /**
     * An owner blocked on an old address, whose users.email has moved on. The
     * car still holds the old address, as a car copy does until the verify hook runs.
     *
     * @return array{0: int, 1: int, 2: string, 3: string} Owner id, car id, old address, new address
     */
    private function createOwnerBlockedOnAnOldAddress(): array
    {
        $old = 'old-' . uniqid() . '@integration-test-1923.example.com';
        $new = 'new-' . uniqid() . '@integration-test-1923.example.com';
        $ownerId = $this->createTestUser(['email' => $old], true);
        $carId = $this->createTestCar($ownerId, ['email' => $old]);
        $this->setOwnerBlocked($ownerId, $old);
        $this->setUserEmailRaw($ownerId, $new);
        $this->assertFalse($this->repo->effectiveEmailFlags($ownerId)->blocked, 'Test setup: the old block must not apply');

        return [$ownerId, $carId, $old, $new];
    }

    /** The stored block is for the old address, so a contact for the new one is new work, not already flagged. */
    public function testContactForTheMovedUsersEmailCountsAsMatchedAndTheBlockApplies(): void
    {
        [$ownerId, $carId, , $new] = $this->createOwnerBlockedOnAnOldAddress();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($new, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->matchedCount, 'The block on the old address does not apply to the new one');
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertTrue($this->repo->effectiveEmailFlags($ownerId)->blocked, 'The block now applies to users.email');
        $this->assertSame($new, $this->ownerFlagsForCar($carId)->email_blocked_address);
    }

    /** The old address is only in the car copy, so its contact matches no owner and changes no flag. */
    public function testContactForTheOldAddressIsUnmatchedAndChangesNothing(): void
    {
        [$ownerId, $carId, $old] = $this->createOwnerBlockedOnAnOldAddress();
        $flagsBefore = $this->ownerFlagsForCar($carId);

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($old, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->unmatchedCount);
        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(0, $summary->alreadyFlaggedCount);
        $this->assertEquals($flagsBefore, $this->ownerFlagsForCar($carId), 'No flag column may change');
        $this->assertFalse($this->repo->effectiveEmailFlags($ownerId)->blocked);
        $this->assertSame(0, $this->countEventRows($carId), 'An unmatched contact writes no event row');
    }

    // --- 5. Reason-code mapping, proven through real SQL --------------------

    public function testSpamAndUnsubscribeCodesBothSuppressButRecordDistinctEvents(): void
    {
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$unsubCarId, $unsubEmail] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_BREVO),
                FakeBrevoBlockedContact::withReason($unsubEmail, 'unsubscribedViaApi', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $spamEvents = $this->eventRows($spamCarId);
        $this->assertCount(1, $spamEvents);
        $this->assertSame('spam', $spamEvents[0]->event, "contactFlaggedAsSpam must be recorded as 'spam'");
        $this->assertSame(1, (int) $this->ownerFlagsForCar($spamCarId)->email_blocked);
        $this->assertSame('spam', $this->ownerFlagsForCar($spamCarId)->email_blocked_cause);
        $this->assertSame(0, (int) $this->ownerFlagsForCar($spamCarId)->email_bounced, 'A spam complaint is not a bounce');

        $unsubEvents = $this->eventRows($unsubCarId);
        $this->assertCount(1, $unsubEvents);
        $this->assertSame('unsubscribed', $unsubEvents[0]->event, "An unsubscribe code must not be aliased to 'spam'");
        $this->assertSame(1, (int) $this->ownerFlagsForCar($unsubCarId)->email_blocked);
        $this->assertSame('unsubscribed', $this->ownerFlagsForCar($unsubCarId)->email_blocked_cause);
        $this->assertSame(0, (int) $this->ownerFlagsForCar($unsubCarId)->email_bounced);
    }

    /**
     * A human block at Brevo is a suppression decision, not proof the mailbox is dead.
     */
    public function testAdminBlockedSuppressesRatherThanBounces(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'adminBlocked', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $flags = $this->ownerFlagsForCar($carId);
        $this->assertSame(1, (int) $flags->email_blocked, 'adminBlocked must block');
        $this->assertSame('blocklist', $flags->email_blocked_cause, 'adminBlocked sets the blocklist cause (#2359)');
        $this->assertSame(0, (int) $flags->email_bounced, 'adminBlocked must NOT be treated as a dead mailbox');
        $this->assertSame('unsubscribed', $this->eventRows($carId)[0]->event, 'The event row stays unsubscribed');
    }

    /**
     * An admin-block row from before #2500 has the untagged ID, and the
     * duplicate check stops a tagged second row. So the import renames the
     * old row. Otherwise a `spam` profile cause shows "…and this address
     * unsubscribed".
     */
    public function testAdminBlockedImportRetagsTheOldUntaggedRow(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        $userId = (int) $this->ownerFlagsForCar($carId)->user_id;

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();
        $this->assertSame('spam', $this->ownerFlagsForCar($carId)->email_blocked_cause, 'Test setup: profile cause spam');

        $hash = md5($email . '|adminBlocked|' . self::BLOCKED_AT_BREVO);
        $this->db->query(
            "INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, 'unsubscribed', NULL, ?, '2026-09-08 03:00:00')",
            [$userId, $email, 'suppression-import-' . $hash]
        );
        $this->assertFalse($this->db->error(), 'Test setup: old admin-block row: ' . $this->db->errorString());
        $this->assertSame(['spam', 'unsubscribed'], $this->sortedCauses($userId, $email), 'Test setup: the old row reads as an unsubscribe');

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'adminBlocked', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $ids = array_column(array_filter(
            $this->eventRows($carId),
            static fn (object $row): bool => $row->event === 'unsubscribed'
        ), 'brevo_message_id');
        $this->assertSame([CarRepository::ADMIN_BLOCK_MESSAGE_ID_PREFIX . $hash], array_values($ids));
        $this->assertSame(['blocklist', 'spam'], $this->sortedCauses($userId, $email));
    }

    /** @return list<string> */
    private function sortedCauses(int $userId, string $email): array
    {
        $causes = $this->repo->ownerBlockEventCauses($userId, $email, null);
        sort($causes);

        return $causes;
    }

    public function testUnrecognizedReasonCodeChangesNothing(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'someFutureBrevoCode', null, self::BLOCKED_AT_BREVO),
            ]),
        ])->runFullBackfill();

        $this->assertSame(0, $summary->matchedCount);
        $this->assertSame(['unrecognized' => 1], $summary->reasonCodeCounts);

        $flags = $this->ownerFlagsForCar($carId);
        $this->assertSame(0, (int) $flags->email_bounced);
        $this->assertSame(0, (int) $flags->email_blocked);
        $this->assertSame(0, (int) $flags->email_opted_out);
        $this->assertSame([], $this->flagChanges($carId), 'An unmapped code must log no flag change');
        $this->assertSame(0, $this->countEventRows($carId), 'An unmapped code must write no event row');
    }
}
