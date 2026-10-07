<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Integration (real MySQL) tests for
 * CarRepository::findLatestEmailEventPerCarWithPrecedence().
 *
 * The method's correctness lives in its hand-written self-join (a LEFT JOIN
 * against a per-car MAX(occurred_at) 'sent' subquery, plus an ORDER BY that
 * ranks terminal events ahead of a later-timestamp non-terminal event). A
 * mocked unit test (tests/unit/cars/services/CarRepositoryEmailEventPrecedenceTest.php)
 * can only stub the SQL's answer; it cannot prove the SQL itself produces
 * that answer. This file seeds real rows and asserts on the method's actual
 * return value.
 */
#[Group('integration')]
final class CarRepositoryEmailEventPrecedenceTest extends IntegrationTestCase
{
    private CarRepository $repo;
    private int $userId;
    private int $carId;

    /** Every terminal event name the production method treats as precedence-winning. */
    private const TERMINAL_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email', 'spam', 'unsubscribed'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser();
        $this->carId = $this->createTestCar($this->userId, [
            'email' => 'precedence-' . uniqid() . '@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$this->carId]);
        }
        parent::tearDown();
    }

    private function carEmail(int $carId): string
    {
        $row = $this->db->query('SELECT email FROM cars WHERE id = ?', [$carId])->first();
        return (string) $row->email;
    }

    private function insertRawEvent(int $carId, string $event, string $brevoMessageId, string $occurredAt): void
    {
        $this->db->query(
            'INSERT INTO er_email_events (car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, NULL, ?, ?)',
            [$carId, $this->carEmail($carId), $event, $brevoMessageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    // --- Precedence: terminal event wins despite an earlier timestamp ------

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalEventProvider(): array
    {
        $cases = [];
        foreach (self::TERMINAL_EVENTS as $event) {
            $cases[$event] = [$event];
        }
        return $cases;
    }

    #[DataProvider('terminalEventProvider')]
    public function testTerminalEventWinsOverALaterDeliveredInTheSameCycle(string $terminalEvent): void
    {
        // Send cycle starts at T+0 (the 'sent' row).
        $this->insertRawEvent($this->carId, 'sent', 'cycle-sent', '2026-05-01 00:00:00');
        // Terminal event at T+0 (same instant as sent is fine — >= cycle_start).
        $this->insertRawEvent($this->carId, $terminalEvent, 'cycle-terminal', '2026-05-01 00:00:00');
        // 'delivered' arrives LATER (T+10 minutes) in the same cycle.
        $this->insertRawEvent($this->carId, 'delivered', 'cycle-delivered', '2026-05-01 00:10:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame(
            $terminalEvent,
            $result[$this->carId]->event,
            "Terminal event '{$terminalEvent}' at the earlier timestamp must win over the later 'delivered'"
        );
        $this->assertSame('cycle-terminal', $result[$this->carId]->brevo_message_id);
    }

    public function testDeliveredWinsWhenNoTerminalEventExistsInTheCycle(): void
    {
        $this->insertRawEvent($this->carId, 'sent', 'nodeath-sent', '2026-05-02 00:00:00');
        $this->insertRawEvent($this->carId, 'delivered', 'nodeath-delivered', '2026-05-02 00:05:00');
        $this->insertRawEvent($this->carId, 'opened', 'nodeath-opened', '2026-05-02 00:10:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame(
            'opened',
            $result[$this->carId]->event,
            'With no terminal event in the cycle, the most recent event by occurred_at must win'
        );
    }

    // --- Empty-array short-circuit ------------------------------------------

    public function testEmptyCarIdsArrayReturnsEmptyArrayWithoutQuerying(): void
    {
        // Seed a row so an unguarded query could plausibly return something —
        // the empty-array branch must return [] before any SQL executes.
        $this->insertRawEvent($this->carId, 'delivered', 'empty-guard', '2026-05-03 00:00:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([]);

        $this->assertSame([], $result);
    }

    // --- Wrong-typed / out-of-range elements --------------------------------

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function invalidCarIdsProvider(): array
    {
        return [
            'non-int element (string)' => [[1, 'two']],
            'negative element' => [[1, -1]],
            'zero element' => [[1, 0]],
            'string-numeric element' => [[1, '2']],
        ];
    }

    #[DataProvider('invalidCarIdsProvider')]
    public function testThrowsCarValidationExceptionForWrongTypedOrOutOfRangeElement(array $carIds): void
    {
        $this->expectException(\ElanRegistry\Exceptions\CarValidationException::class);

        $this->repo->findLatestEmailEventPerCarWithPrecedence($carIds);
    }

    // --- Cycle scoping: an old cycle's terminal event must not leak forward -

    public function testOldCyclesTerminalEventDoesNotLeakIntoANewerCycle(): void
    {
        // Cycle 1: sent at T+0, hard_bounce at T+5 — car bounced on the first send.
        $this->insertRawEvent($this->carId, 'sent', 'cycle1-sent', '2026-06-01 00:00:00');
        $this->insertRawEvent($this->carId, 'hard_bounce', 'cycle1-bounce', '2026-06-01 00:05:00');

        // Cycle 2: a brand-new 'sent' row (the car was re-sent to later, e.g. after
        // being re-verified as deliverable) at T+1 day, with only a 'delivered'
        // event after it — no terminal event in THIS cycle.
        $this->insertRawEvent($this->carId, 'sent', 'cycle2-sent', '2026-06-02 00:00:00');
        $this->insertRawEvent($this->carId, 'delivered', 'cycle2-delivered', '2026-06-02 00:05:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame(
            'delivered',
            $result[$this->carId]->event,
            "Cycle 1's hard_bounce must not leak into cycle 2 — the car's most recent send cycle has no terminal event"
        );
        $this->assertSame('cycle2-delivered', $result[$this->carId]->brevo_message_id);
    }

    public function testNewestCycleScopingUsesTheLatestSentRowNotBrevoMessageId(): void
    {
        // Three 'sent' rows (three send cycles), each with its own local
        // message id (CarVerificationSendService writes `local:<hash>`, which
        // never matches a webhook's brevo_message_id — this is why cycle
        // scoping is done by MAX(occurred_at) WHERE event = 'sent', not by
        // matching message ids).
        $this->insertRawEvent($this->carId, 'sent', 'local:cycle-a', '2026-07-01 00:00:00');
        $this->insertRawEvent($this->carId, 'hard_bounce', 'webhook-cycle-a-bounce', '2026-07-01 00:05:00');

        $this->insertRawEvent($this->carId, 'sent', 'local:cycle-b', '2026-07-10 00:00:00');
        $this->insertRawEvent($this->carId, 'hard_bounce', 'webhook-cycle-b-bounce', '2026-07-10 00:05:00');

        // Latest cycle: a fresh 'sent' row with no terminal event after it.
        $this->insertRawEvent($this->carId, 'sent', 'local:cycle-c', '2026-07-20 00:00:00');
        $this->insertRawEvent($this->carId, 'delivered', 'webhook-cycle-c-delivered', '2026-07-20 00:05:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame(
            'delivered',
            $result[$this->carId]->event,
            'Scoping must use the latest sent-row TIMESTAMP as the cycle boundary, not any brevo_message_id '
                . 'match — two older cycles bounced, but the newest cycle (after the 3rd sent row) only delivered'
        );
    }

    public function testCarWithNoSentRowUsesAllOfItsEvents(): void
    {
        // No 'sent' row at all for this car (e.g. only a suppression-import
        // row) — the method's docblock states this falls back to using every
        // event for the car, which still means the terminal event wins.
        $this->insertRawEvent($this->carId, 'delivered', 'nosend-delivered', '2026-08-01 00:00:00');
        $this->insertRawEvent($this->carId, 'spam', 'nosend-spam', '2026-08-02 00:00:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame('spam', $result[$this->carId]->event);
    }

    public function testCarWithNoEventsAtAllIsAbsentFromTheResult(): void
    {
        // $this->carId has zero rows in er_email_events — no insertRawEvent() call precedes this.
        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayNotHasKey(
            $this->carId,
            $result,
            'A car with no events at all must be absent from the map, not present with a null value'
        );
    }

    public function testEachCarGetsItsOwnCycleScopedResultNotAnotherCarsEvent(): void
    {
        $otherCarId = $this->createTestCar($this->userId, [
            'email' => 'precedence-other-' . uniqid() . '@example.com',
        ]);

        // Car A: bounced in its current cycle.
        $this->insertRawEvent($this->carId, 'sent', 'carA-sent', '2026-09-01 00:00:00');
        $this->insertRawEvent($this->carId, 'hard_bounce', 'carA-bounce', '2026-09-01 00:05:00');

        // Car B: delivered cleanly in its current cycle.
        $this->insertRawEvent($otherCarId, 'sent', 'carB-sent', '2026-09-01 00:00:00');
        $this->insertRawEvent($otherCarId, 'delivered', 'carB-delivered', '2026-09-01 00:05:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId, $otherCarId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertArrayHasKey($otherCarId, $result);
        $this->assertSame('hard_bounce', $result[$this->carId]->event, "Car A must get its own result, not car B's");
        $this->assertSame('delivered', $result[$otherCarId]->event, "Car B must get its own result, not car A's");

        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }

    /**
     * Pins the mid-run regression caught during this issue's own development
     * (see docs/plans/issues/issue-1896-verification-dashboard.md's
     * Implementation Checklist: an in-progress edit transiently dropped the
     * outer `WHERE e.car_id IN (...)` scoping and cross-joined the cycle
     * subquery, which would have returned every car's latest event
     * regardless of the requested $carIds).
     *
     * testEachCarGetsItsOwnCycleScopedResultNotAnotherCarsEvent() above calls
     * the method with BOTH seeded cars at once — a leak that returns every
     * car's event regardless of $carIds would still pass that test, because
     * car B's key being present looks identical to "both cars correctly
     * present." This test instead requests car A ALONE and asserts car B is
     * absent from the result, which only a true leak (not a correct two-car
     * lookup) can fail.
     */
    public function testRequestingOneCarAloneExcludesAnotherCarsEventFromTheResult(): void
    {
        $otherCarId = $this->createTestCar($this->userId, [
            'email' => 'precedence-leak-check-' . uniqid() . '@example.com',
        ]);

        // Car A (requested): bounced in its current cycle.
        $this->insertRawEvent($this->carId, 'sent', 'leakA-sent', '2026-10-01 00:00:00');
        $this->insertRawEvent($this->carId, 'hard_bounce', 'leakA-bounce', '2026-10-01 00:05:00');

        // Car B (NOT requested): also has events, so an unguarded query could
        // plausibly return it too.
        $this->insertRawEvent($otherCarId, 'sent', 'leakB-sent', '2026-10-01 00:00:00');
        $this->insertRawEvent($otherCarId, 'delivered', 'leakB-delivered', '2026-10-01 00:05:00');

        $result = $this->repo->findLatestEmailEventPerCarWithPrecedence([$this->carId]);

        $this->assertArrayHasKey($this->carId, $result);
        $this->assertSame('hard_bounce', $result[$this->carId]->event);
        $this->assertArrayNotHasKey(
            $otherCarId,
            $result,
            'Car B was not in the requested $carIds list and must be absent from the result — a leaked '
            . 'WHERE clause or a cross-joined cycle subquery would return every car\'s latest event '
            . 'regardless of what was requested, and this is the one assertion that distinguishes that '
            . 'from a correct single-car lookup'
        );

        $this->db->query('DELETE FROM er_email_events WHERE car_id = ?', [$otherCarId]);
        $this->deleteTestCar($otherCarId);
    }
}
