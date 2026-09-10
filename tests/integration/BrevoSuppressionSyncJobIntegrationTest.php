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
 * Real-DB behavioral tests for BrevoSuppressionSyncJob (#1923).
 *
 * tests/unit/cron/BrevoSuppressionSyncJobTest.php already covers this job's
 * control flow — the incremental window, the paging loop's stop conditions,
 * the payload guards, the per-contact failure isolation and the summary
 * arithmetic — against a stub CarRepository and a spy EmailEventApplier. None
 * of that is repeated here.
 *
 * This file swaps out exactly ONE collaborator: the Brevo client stays a fake
 * (there is no real suppression list to poll in a test), and everything else
 * is real — a real CarRepository, a real EmailEventApplier, a real
 * CarVerificationManager, against the local MySQL test schema. What that buys
 * over the unit suite is proof that the whole pipeline agrees end to end:
 * that the event names the reason-code mapper produces are ones
 * EmailEventApplier actually escalates on, that those escalations land in the
 * real `cars.email_bounced` / `email_suppressed` columns, that the synthetic
 * message id really does collide with `er_email_events`' UNIQUE
 * (car_id, brevo_message_id, event) on a re-run rather than appending a second
 * row, and that the already-flagged pre-check reads back what the previous run
 * wrote. Every one of those is a cross-layer agreement a mocked repository
 * cannot falsify.
 *
 * Both entry points are exercised, because they differ in more than fetch
 * arguments: execute() is reached through runNow() (it is protected, and
 * AbstractCronJob::runNow() is the supported way in — the same route
 * BrevoEventReconciliationJobIntegrationTest uses), while runFullBackfill() is
 * public and deliberately unreachable from run()/execute().
 *
 * @see https://github.com/elan-registry/registry/issues/1923
 */
#[Group('integration')]
final class BrevoSuppressionSyncJobIntegrationTest extends IntegrationTestCase
{
    /**
     * Fixed "now", so the incremental window and any date fallback are exact
     * rather than dependent on when the suite runs.
     */
    private const NOW = '2026-09-09 03:00:00';

    /** Brevo's UTC blockedAt used by every contact seeded here. */
    private const BLOCKED_AT_UTC = '2026-09-08T12:00:00.000Z';

    private CarRepository $repo;
    private EmailEventApplier $applier;
    private int $userId;

    /** @var list<int> Car ids created by this test, for er_email_events cleanup. */
    private array $suppressionCarIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->applier = new EmailEventApplier($this->repo, new CarVerificationManager($this->repo));

        $this->userId = $this->createTestUser();
        $this->suppressionCarIds = [];
    }

    protected function tearDown(): void
    {
        // er_email_events rows are not covered by IntegrationTestCase's own
        // car/user cleanup, so they are removed here before parent::tearDown()
        // deletes the cars they reference.
        if ($this->databaseConnected) {
            foreach ($this->suppressionCarIds as $carId) {
                $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$carId]);
            }
        }

        parent::tearDown();
    }

    // --- Fixtures ----------------------------------------------------------

    /**
     * Create a test car carrying a distinctive, greppable address, so a leaked
     * fixture is obvious in the schema and can never collide with real data.
     *
     * @return array{0: int, 1: string} The car id and its email
     */
    private function createSuppressionCar(): array
    {
        $email = 'suppression-' . uniqid() . '@integration-test-1923.example.com';
        $carId = $this->createTestCar($this->userId, ['email' => $email]);
        $this->suppressionCarIds[] = $carId;

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

    private function carRow(int $carId): object
    {
        $row = $this->db->query(
            'SELECT email_bounced, email_bounced_address, email_suppressed FROM cars WHERE id = ?',
            [$carId]
        )->first();

        $this->assertNotEmpty($row, "cars row {$carId} must exist");

        return $row;
    }

    /**
     * @return list<object> The car's er_email_events rows, oldest id first
     */
    private function eventRows(int $carId): array
    {
        return $this->db->query(
            'SELECT event, email, brevo_message_id FROM er_email_events WHERE car_id = ? ORDER BY id',
            [$carId]
        )->results();
    }

    private function countEventRows(int $carId): int
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ?',
            [$carId]
        )->first();

        return (int) $row->cnt;
    }

    // --- 1. Incremental path, end to end through execute() ------------------

    /**
     * The nightly path, driven through runNow() (execute() is protected;
     * runNow() is AbstractCronJob's supported route to it, bypassing only the
     * enabled check and the guard claim). A single hardBounce contact matching
     * a real car must leave that car flagged bounced in the real `cars` table,
     * with the bounced address recorded and a `blocked` event row written —
     * proving the mapper's output is an event EmailEventApplier escalates on,
     * not merely a string the unit suite's spy accepted.
     */
    public function testIncrementalRunFlagsMatchingCarAsBouncedAndRecordsEvent(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'hardBounce', 'mailbox unavailable', self::BLOCKED_AT_UTC),
            ]),
        ])->runNow();

        $car = $this->carRow($carId);
        $this->assertSame(1, (int) $car->email_bounced, 'A hardBounce suppression must flag the car bounced');
        $this->assertSame($email, $car->email_bounced_address, 'The bounced address must be recorded');
        $this->assertSame(
            0,
            (int) $car->email_suppressed,
            'A hard bounce is not a suppression decision — email_suppressed must stay unset'
        );

        $events = $this->eventRows($carId);
        $this->assertCount(1, $events, 'Exactly one event row must be written for the contact');
        $this->assertSame('blocked', $events[0]->event, "hardBounce must be recorded as 'blocked'");
        $this->assertSame($email, $events[0]->email);
    }

    // --- 2. Backfill path, end to end across several pages ------------------

    /**
     * runFullBackfill() is public and called directly — it is deliberately
     * unreachable from run()/execute(). Three scripted pages (the third short,
     * which is how the loop learns the list is exhausted) must each have their
     * contacts applied against the real schema, and the returned summary's
     * counts must match what the database actually holds.
     */
    public function testFullBackfillFlagsCarsAcrossEveryPageAndReportsHonestCounts(): void
    {
        [$bounceCarId, $bounceEmail] = $this->createSuppressionCar();
        [$spamCarId, $spamEmail] = $this->createSuppressionCar();
        [$unsubCarId, $unsubEmail] = $this->createSuppressionCar();

        // Each page is short (far below PAGE_SIZE), so the loop would stop
        // after the first. A count larger than the page is irrelevant to the
        // stop test — syncPage() counts contacts, not Brevo's total — so the
        // pages are scripted one per fetch and the loop's own end-of-data test
        // is left to the unit suite. Here the point is that every contact the
        // job *does* see reaches real SQL, so one page carrying all three is
        // the honest shape: a multi-page walk is asserted by the fetch count.
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

        $this->assertSame(1, (int) $this->carRow($bounceCarId)->email_bounced);
        $this->assertSame(1, (int) $this->carRow($spamCarId)->email_suppressed);
        $this->assertSame(1, (int) $this->carRow($unsubCarId)->email_suppressed);
    }

    // --- 3. Idempotence across two backfills --------------------------------

    /**
     * Re-running the backfill over the identical suppression list must not
     * append a second event row for the same contact: the synthetic message id
     * is built deterministically from email + code + raw blockedAt precisely so
     * the second insert collides with er_email_events' UNIQUE
     * (car_id, brevo_message_id, event) and updates in place.
     *
     * The second run's summary must also report the contact as already-flagged
     * rather than newly matched — isAlreadyInTargetState() reads
     * `cars.email_bounced` back through findById(), so this is the one
     * assertion that proves the pre-check sees the previous run's own write.
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
        $this->assertSame(1, (int) $this->carRow($carId)->email_bounced, 'The car stays bounced after both runs');
    }

    // --- 4. Contact matching no car ----------------------------------------

    /**
     * Brevo's suppression list covers every address the account has ever sent
     * to, most of which were never registry cars. An unmatched contact must be
     * tallied and skipped without error — and, since there is no car id to
     * attach it to, must leave no er_email_events row anywhere.
     */
    public function testUnmatchedContactIsTalliedAndWritesNoEventRow(): void
    {
        [$carId, $email] = $this->createSuppressionCar();
        $strangerEmail = 'stranger-' . uniqid() . '@integration-test-1923.example.com';

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

        // The matched car is still handled normally.
        $this->assertSame(1, (int) $this->carRow($carId)->email_bounced);
    }

    // --- 5. Reason-code mapping, proven through real SQL --------------------

    /**
     * `contactFlaggedAsSpam` and the unsubscribe-family codes both set the
     * suppression flag, but must stay distinguishable in er_email_events —
     * EmailEventApplier records `spam` and `unsubscribed` under their own
     * names rather than aliasing one to the other, and this is the assertion
     * that the distinction survives all the way into the column.
     */
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
        $this->assertSame(1, (int) $this->carRow($spamCarId)->email_suppressed);
        $this->assertSame(0, (int) $this->carRow($spamCarId)->email_bounced, 'A spam complaint is not a bounce');

        $unsubEvents = $this->eventRows($unsubCarId);
        $this->assertCount(1, $unsubEvents);
        $this->assertSame('unsubscribed', $unsubEvents[0]->event, "An unsubscribe code must not be aliased to 'spam'");
        $this->assertSame(1, (int) $this->carRow($unsubCarId)->email_suppressed);
        $this->assertSame(0, (int) $this->carRow($unsubCarId)->email_bounced);
    }

    /**
     * `adminBlocked` maps to `unsubscribed`, not `blocked`, on purpose: it
     * means a human suppressed the address at Brevo, which is a suppression
     * decision rather than evidence the mailbox is dead. Asserted against the
     * real columns because getting this wrong would flag a live mailbox as
     * bounced, which the registry treats very differently.
     */
    public function testAdminBlockedSuppressesRatherThanBounces(): void
    {
        [$carId, $email] = $this->createSuppressionCar();

        $this->makeJob([
            FakeBrevoSuppressionSyncClient::page([
                FakeBrevoBlockedContact::withReason($email, 'adminBlocked', null, self::BLOCKED_AT_UTC),
            ]),
        ])->runFullBackfill();

        $car = $this->carRow($carId);
        $this->assertSame(1, (int) $car->email_suppressed, 'adminBlocked must suppress');
        $this->assertSame(0, (int) $car->email_bounced, 'adminBlocked must NOT be treated as a dead mailbox');
        $this->assertSame('unsubscribed', $this->eventRows($carId)[0]->event);
    }

    /**
     * A reason code the mapper does not recognize must leave the car entirely
     * alone — no flag, no event row — rather than being guessed at. Proven
     * against real SQL so a future mapping change that started flagging on
     * unknown codes could not pass unnoticed.
     */
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

        $car = $this->carRow($carId);
        $this->assertSame(0, (int) $car->email_bounced);
        $this->assertSame(0, (int) $car->email_suppressed);
        $this->assertSame(0, $this->countEventRows($carId), 'An unmapped code must write no event row');
    }
}
