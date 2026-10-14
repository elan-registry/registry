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
use Tests\Support\FakeBrevoSuppressionSyncClient;

/**
 * #1923, #2358: BrevoSuppressionSyncJob against real SQL; only the Brevo client is fake.
 * Control flow is in tests/unit/cron/BrevoSuppressionSyncJobTest.php. This file
 * proves the cross-layer agreements a mocked repository cannot falsify.
 */
#[Group('integration')]
final class BrevoSuppressionSyncJobIntegrationTest extends IntegrationTestCase
{
    private const NOW = '2026-09-09 03:00:00';

    /** Brevo's UTC blockedAt used by every contact seeded here. */
    private const BLOCKED_AT_UTC = '2026-09-08T12:00:00.000Z';

    private CarRepository $repo;
    private EmailEventApplier $applier;
    /** Original er_verification_settings.enabled value, restored in tearDown(). */
    private bool $originalVerificationEnabled = false;

    /** Original er_verification_settings.unmatched_recipient_count, restored in tearDown(). */
    private int $originalUnmatchedCount = 0;

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

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));
    }

    protected function tearDown(): void
    {
        // The base class deletes the er_email_events rows of the test users.
        if ($this->databaseConnected) {
            $this->db->query(
                'UPDATE er_verification_settings SET enabled = ?, unmatched_recipient_count = ? WHERE id = 1',
                [$this->originalVerificationEnabled ? 1 : 0, $this->originalUnmatchedCount]
            );
        }

        parent::tearDown();
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

    /** The dashboard's running total of suppression contacts matching no car. */
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
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_UTC),
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
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_UTC),
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
                FakeBrevoBlockedContact::withReason($bounceEmail, 'hardBounce', null, self::BLOCKED_AT_UTC),
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_UTC),
                FakeBrevoBlockedContact::withReason($unsubEmail, 'unsubscribedViaEmail', null, self::BLOCKED_AT_UTC),
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

        $contact = FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_UTC);

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

    // --- 4. Contact matching no car ----------------------------------------

    /**
     * Most suppressed addresses were never registry cars. The counter tells an
     * operator how much of Brevo's list the registry does not recognize.
     */
    public function testUnmatchedContactIsTalliedAndWritesNoEventRow(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        $strangerEmail = 'stranger-' . uniqid() . '@integration-test-1923.example.com';

        $countBefore = $this->unmatchedRecipientCount();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($strangerEmail, 'hardBounce', null, self::BLOCKED_AT_UTC),
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', null, self::BLOCKED_AT_UTC),
            ]),
        ])->runFullBackfill();

        $this->assertSame(1, $summary->unmatchedCount, 'The address with no car must be counted unmatched');
        $this->assertSame(1, $summary->matchedCount, 'The matched contact is unaffected by its neighbour');

        $stranded = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE email = ?',
            [$strangerEmail]
        )->first();
        $this->assertSame(0, (int) $stranded->cnt, 'An unmatched address must leave no event row behind');

        $this->assertSame(1, (int) $this->ownerFlagsForCar($carId)->email_bounced);

        $this->assertSame(
            $countBefore + 1,
            $this->unmatchedRecipientCount(),
            'The one unmatched contact must increment the dashboard unmatched-recipient counter by exactly one'
        );
        $this->assertSame(
            0,
            $summary->counterFailureCount,
            'No counter increment may have failed'
        );
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
                FakeBrevoBlockedContact::withReason($new, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_UTC),
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
                FakeBrevoBlockedContact::withReason($old, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_UTC),
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
                FakeBrevoBlockedContact::withReason($spamEmail, 'contactFlaggedAsSpam', null, self::BLOCKED_AT_UTC),
                FakeBrevoBlockedContact::withReason($unsubEmail, 'unsubscribedViaApi', null, self::BLOCKED_AT_UTC),
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
                FakeBrevoBlockedContact::withReason($email, 'adminBlocked', null, self::BLOCKED_AT_UTC),
            ]),
        ])->runFullBackfill();

        $flags = $this->ownerFlagsForCar($carId);
        $this->assertSame(1, (int) $flags->email_blocked, 'adminBlocked must block');
        $this->assertSame('unsubscribed', $flags->email_blocked_cause);
        $this->assertSame(0, (int) $flags->email_bounced, 'adminBlocked must NOT be treated as a dead mailbox');
        $this->assertSame('unsubscribed', $this->eventRows($carId)[0]->event);
    }

    public function testUnrecognizedReasonCodeChangesNothing(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $summary = $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'someFutureBrevoCode', null, self::BLOCKED_AT_UTC),
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
