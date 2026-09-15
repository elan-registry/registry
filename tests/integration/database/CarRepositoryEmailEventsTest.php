<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration (real MySQL) tests for CarRepository's er_email_events methods
 * that a mocked unit test cannot meaningfully cover — the SQL itself is the
 * thing under test.
 *
 * countSoftBouncesSinceLastDelivered() is the query that decides whether a
 * car gets escalated to bounced (#1887's BrevoWebhookEventProcessor unit
 * tests only stub its return value, so a regression in the SQL itself — e.g.
 * COUNT(*) instead of COUNT(DISTINCT brevo_message_id), or a boundary error
 * in the "since last delivered" window — would ship undetected without this
 * file).
 *
 * insertEmailEvent()'s ON DUPLICATE KEY UPDATE clause is hand-written
 * specifically to avoid DB::insert()'s indiscriminate update mode (see that
 * method's own docblock) — a test that only sends identical payloads twice
 * cannot distinguish this from the simpler, wrong implementation it exists to
 * avoid, so this file sends a duplicate delivery with DIFFERENT reason/
 * occurred_at values and asserts those update while the identity columns do
 * not move.
 */
#[Group('integration')]
final class CarRepositoryEmailEventsTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $userId;
    private int $carId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser();
        $this->carId = $this->createTestCar($this->userId, [
            'email' => 'softbounce-' . uniqid() . '@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$this->carId]);
        }
        parent::tearDown();
    }

    private function carEmail(): string
    {
        $row = $this->db->query('SELECT email FROM cars WHERE id = ?', [$this->carId])->first();
        return (string) $row->email;
    }

    private function insertRawEvent(string $event, string $brevoMessageId, string $occurredAt): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, ?)',
            [$this->carId, $this->carEmail(), $event, $brevoMessageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    // --- countSoftBouncesSinceLastDelivered() -----------------------------

    #[Group('fast')]
    public function testCountsDistinctMessageIdsNotRawEventRows(): void
    {
        $email = $this->carEmail();

        // Three distinct send cycles...
        $this->insertRawEvent('soft_bounce', 'msg-1', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'msg-2', '2026-01-01 11:00:00');
        $this->insertRawEvent('soft_bounce', 'msg-3', '2026-01-01 12:00:00');
        // ...and a duplicate report of msg-1 (Brevo re-sending the same event).
        // A regression to COUNT(*) instead of COUNT(DISTINCT brevo_message_id)
        // would count this as a 4th cycle and escalate one cycle too early.
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE occurred_at = VALUES(occurred_at)',
            [$this->carId, $email, 'soft_bounce', 'msg-1', '2026-01-01 10:05:00']
        );

        $count = $this->repo->countSoftBouncesSinceLastDelivered($email);

        $this->assertSame(3, $count, 'Must count distinct brevo_message_id values, not raw event rows');
    }

    #[Group('fast')]
    public function testOnlyCountsCyclesAfterMostRecentDelivered(): void
    {
        $email = $this->carEmail();

        // Two soft bounces before a delivery...
        $this->insertRawEvent('soft_bounce', 'pre-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'pre-2', '2026-01-01 09:30:00');
        // ...then a successful delivery...
        $this->insertRawEvent('delivered', 'delivered-1', '2026-01-01 10:00:00');
        // ...then two more soft bounces after it.
        $this->insertRawEvent('soft_bounce', 'post-1', '2026-01-01 11:00:00');
        $this->insertRawEvent('soft_bounce', 'post-2', '2026-01-01 11:30:00');

        $count = $this->repo->countSoftBouncesSinceLastDelivered($email);

        $this->assertSame(
            2,
            $count,
            'A delivered event must reset the window — pre-delivery soft bounces must not count '
                . 'toward a fresh threshold check'
        );
    }

    #[Group('fast')]
    public function testCountsAllCyclesWhenNoDeliveredEventExistsYet(): void
    {
        $email = $this->carEmail();

        $this->insertRawEvent('soft_bounce', 'nodeliv-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('soft_bounce', 'nodeliv-2', '2026-01-01 10:00:00');
        $this->insertRawEvent('soft_bounce', 'nodeliv-3', '2026-01-01 11:00:00');

        $count = $this->repo->countSoftBouncesSinceLastDelivered($email);

        $this->assertSame(3, $count, 'With no delivered event on record, all soft-bounce cycles must count');
    }

    #[Group('fast')]
    public function testReturnsZeroForAnEmailWithNoSoftBounceHistory(): void
    {
        $count = $this->repo->countSoftBouncesSinceLastDelivered('never-bounced-' . uniqid() . '@example.com');

        $this->assertSame(0, $count);
    }

    // --- insertEmailEvent() dedup semantics --------------------------------

    #[Group('fast')]
    public function testDuplicateDeliveryUpdatesReasonAndOccurredAtButNotIdentityColumns(): void
    {
        $email = $this->carEmail();

        $firstCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'hard_bounce',
            'Mailbox full',
            'dup-msg-1',
            '2026-01-01 10:00:00'
        );
        $this->assertSame(1, $firstCount, 'A fresh insert must report rowCount() == 1');

        // Same car_id + brevo_message_id + event (the unique key) — a real
        // Brevo retry — but with DIFFERENT reason/occurred_at, so this test
        // can tell the ON DUPLICATE KEY UPDATE clause apart from a naive
        // re-insert: if it were DB::insert($table, $fields, true) instead
        // (the mode this method's docblock says NOT to use), car_id/email/
        // event/brevo_message_id would also be reassigned on conflict.
        $secondCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'hard_bounce',
            'Mailbox permanently full',
            'dup-msg-1',
            '2026-01-02 15:30:00'
        );
        $this->assertSame(2, $secondCount, "MySQL's rowCount() for a changed ON DUPLICATE KEY UPDATE row is 2");

        $rows = $this->db->query(
            'SELECT car_id, email, event, reason, brevo_message_id, occurred_at FROM er_email_events WHERE car_id = ?',
            [$this->carId]
        )->results();

        $this->assertCount(1, $rows, 'Exactly one row must exist — the unique index must have deduped, not appended');

        $row = $rows[0];
        $this->assertSame($this->carId, (int) $row->car_id, 'car_id must be unchanged by the update');
        $this->assertSame($email, $row->email, 'email must be unchanged by the update');
        $this->assertSame('hard_bounce', $row->event, 'event must be unchanged by the update');
        $this->assertSame('dup-msg-1', $row->brevo_message_id, 'brevo_message_id must be unchanged by the update');
        $this->assertSame('Mailbox permanently full', $row->reason, 'reason must be updated to the second payload\'s value');
        $this->assertSame('2026-01-02 15:30:00', $row->occurred_at, 'occurred_at must be updated to the second payload\'s value');
    }

    #[Group('fast')]
    public function testIdenticalDuplicateDeliveryReportsZeroRowsChanged(): void
    {
        $email = $this->carEmail();

        $this->repo->insertEmailEvent($this->carId, $email, 'delivered', null, 'idem-msg-1', '2026-01-01 10:00:00');

        // Byte-for-byte identical second delivery: MySQL's rowCount() is 0
        // for an ON DUPLICATE KEY UPDATE that changes nothing.
        $secondCount = $this->repo->insertEmailEvent(
            $this->carId,
            $email,
            'delivered',
            null,
            'idem-msg-1',
            '2026-01-01 10:00:00'
        );

        $this->assertSame(0, $secondCount);

        $rowCount = $this->db->query(
            'SELECT COUNT(*) AS cnt FROM er_email_events WHERE car_id = ? AND brevo_message_id = ?',
            [$this->carId, 'idem-msg-1']
        )->first();
        $this->assertSame(1, (int) $rowCount->cnt, 'Still exactly one row — 0 is a "nothing changed" signal, not a failure');
    }

    // --- deleteEmailEventsForCarIds() --------------------------------------

    #[Group('fast')]
    public function testEmptyArrayIsANoOpAndDoesNotThrow(): void
    {
        // The empty-array guard must never reach a "DELETE ... IN ()" query,
        // which is a fatal SQL syntax error — this is the account-deletion
        // path exercised whenever a car-less user is deleted.
        $result = $this->repo->deleteEmailEventsForCarIds([]);

        $this->assertSame(0, $result);
    }

    #[Group('fast')]
    public function testDeletesOnlyRowsForTheGivenCarIds(): void
    {
        $otherCarId = $this->createTestCar($this->userId, [
            'email' => 'other-' . uniqid() . '@example.com',
        ]);

        $this->repo->insertEmailEvent($this->carId, $this->carEmail(), 'delivered', null, 'target-msg', '2026-01-01 10:00:00');
        $this->db->query('SELECT email FROM cars WHERE id = ?', [$otherCarId]);
        $otherEmail = (string) $this->db->first()->email;
        $this->repo->insertEmailEvent($otherCarId, $otherEmail, 'delivered', null, 'other-msg', '2026-01-01 10:00:00');

        $deletedCount = $this->repo->deleteEmailEventsForCarIds([$this->carId]);

        $this->assertSame(1, $deletedCount);

        $remaining = $this->db->query('SELECT car_id FROM er_email_events WHERE car_id IN (?, ?)', [$this->carId, $otherCarId])->results();
        $this->assertCount(1, $remaining);
        $this->assertSame($otherCarId, (int) $remaining[0]->car_id);

        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }

    // --- deleteEmailEventsOlderThan() --------------------------------------

    #[Group('fast')]
    public function testDeletesOnlyRowsOlderThanCutoff(): void
    {
        $email = $this->carEmail();

        $this->insertRawEvent('delivered', 'old-msg', '2024-01-01 10:00:00');
        $this->insertRawEvent('delivered', 'recent-msg', '2026-01-01 10:00:00');

        $cutoff = new \DateTimeImmutable('2025-01-01 00:00:00');
        $deletedCount = $this->repo->deleteEmailEventsOlderThan($cutoff);

        $this->assertSame(1, $deletedCount);

        $remaining = $this->db->query(
            'SELECT brevo_message_id FROM er_email_events WHERE car_id = ?',
            [$this->carId]
        )->results();

        $this->assertCount(1, $remaining, 'Only the row older than the cutoff should be deleted');
        $this->assertSame('recent-msg', $remaining[0]->brevo_message_id);
    }

    #[Group('fast')]
    public function testReturnsZeroWhenNoRowsAreOlderThanCutoff(): void
    {
        $this->insertRawEvent('delivered', 'recent-msg', '2026-01-01 10:00:00');

        $cutoff = new \DateTimeImmutable('2024-01-01 00:00:00');
        $deletedCount = $this->repo->deleteEmailEventsOlderThan($cutoff);

        $this->assertSame(0, $deletedCount);
    }

    // --- findLatestEmailEventsByCarIds() ------------------------------------
    //
    // This method's correctness lives entirely in a hand-written self-join
    // (INNER JOIN against a MAX(occurred_at) GROUP BY subquery, with the
    // doubled IN({$placeholders}) parameter binding via array_merge($ids,
    // $ids)). Every unit test for it mocks DatabaseInterface::results() to
    // hand back the answer the join was supposed to compute, so the actual
    // SQL has never executed anywhere but here.

    #[Group('fast')]
    public function testReturnsOnlyTheMaxDatedRowForACarWithMultipleEvents(): void
    {
        $this->insertRawEvent('soft_bounce', 'multi-1', '2026-01-01 09:00:00');
        $this->insertRawEvent('hard_bounce', 'multi-2', '2026-01-02 10:00:00');
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$this->carId, $this->carEmail(), 'blocked', 'Mailbox full', 'multi-3', '2026-01-03 11:00:00']
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());

        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId]);

        $this->assertArrayHasKey($this->carId, $latest, 'The requested car id must be present as a key');
        $row = $latest[$this->carId];
        $this->assertSame('blocked', $row->event, 'Must return the row with the max occurred_at, not the first/last inserted');
        $this->assertSame('2026-01-03 11:00:00', $row->occurred_at);
        $this->assertSame('Mailbox full', $row->reason);
    }

    #[Group('fast')]
    public function testEachCarReturnsItsOwnLatestEventNotTheOtherCarsEvent(): void
    {
        $otherCarId = $this->createTestCar($this->userId, [
            'email' => 'other-latest-' . uniqid() . '@example.com',
        ]);
        $otherEmail = (string) $this->db->query('SELECT email FROM cars WHERE id = ?', [$otherCarId])->first()->email;

        // The two cars' latest events deliberately share an identical
        // occurred_at (2026-01-05). This is the hardest case for per-car
        // attribution: each car must still get its own event despite the tie.
        //
        // Note on what this does NOT prove. Dropping the JOIN's
        // `latest.car_id = e.car_id` predicate does not fail this test, and no
        // fixture can make it: the result is keyed by `e.car_id` and the query
        // also carries `WHERE e.car_id IN (...)`, so an unconstrained join only
        // yields duplicate rows (4 instead of 2) that collapse onto the same
        // correct key. That predicate is a redundancy/performance guard here,
        // not the thing standing between this test and a wrong answer.
        $this->insertRawEvent('delivered', 'car-a-old', '2026-01-01 08:00:00');
        $this->insertRawEvent('hard_bounce', 'car-a-new', '2026-01-05 08:00:00');

        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, ?)',
            [$otherCarId, $otherEmail, 'delivered', 'car-b-old', '2026-01-02 08:00:00']
        );
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, ?)',
            [$otherCarId, $otherEmail, 'soft_bounce', 'car-b-new', '2026-01-05 08:00:00']
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());

        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId, $otherCarId]);

        $this->assertArrayHasKey($this->carId, $latest);
        $this->assertArrayHasKey($otherCarId, $latest);
        $this->assertSame('hard_bounce', $latest[$this->carId]->event, "Car A must get its own latest event, not car B's");
        $this->assertSame('2026-01-05 08:00:00', $latest[$this->carId]->occurred_at);
        $this->assertSame('soft_bounce', $latest[$otherCarId]->event, "Car B must get its own latest event, not car A's");
        $this->assertSame('2026-01-05 08:00:00', $latest[$otherCarId]->occurred_at);

        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }

    #[Group('fast')]
    public function testCarIdWithNoEventsIsAbsentFromTheResultNotNull(): void
    {
        // $this->carId has zero rows in er_email_events for this test — no
        // insertRawEvent() call precedes this assertion.
        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId]);

        $this->assertArrayNotHasKey(
            $this->carId,
            $latest,
            'A car with no email events must be absent from the map, not present with a null value'
        );
    }

    #[Group('fast')]
    public function testEmptyCarIdsArrayReturnsEmptyArray(): void
    {
        $latest = $this->repo->findLatestEmailEventsByCarIds([]);

        $this->assertSame([], $latest);
    }
}
