<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2358: CarRepository::deleteEmailEventsOlderThan() on real rows.
 *
 * The cutoffs come from a fixed "now", so no test depends on the clock. A row
 * at the cutoff stays: the prune deletes rows with `occurred_at` before it.
 * The prune is global, so the shared `er_email_events` table must hold no row
 * of another test. Each test reads its own rows by message ID.
 */
#[Group('integration')]
final class CarRepositoryEmailEventPruneTest extends IntegrationTestCase
{
    private const NOW = '2026-10-13 12:00:00';

    /** Terminal events, as in EmailEventApplier::HARD_BOUNCE_EVENTS and SUPPRESSION_EVENTS. */
    private const TERMINAL_EVENTS = ['hard_bounce', 'blocked', 'invalid', 'invalid_email', 'spam', 'unsubscribed'];

    private CarRepository $repo;
    private int $userId;
    private int $carId;
    private \DateTimeImmutable $terminalCutoff;
    private \DateTimeImmutable $shortCutoff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->repo = new CarRepository($this->db);
        $this->userId = $this->createTestUser([], true);
        $this->carId = $this->createTestCar($this->userId, ['email' => 'prune-' . uniqid() . '@example.com']);
        // A new profile has email_address_since = now. The fixture events are in the past.
        $this->db->query('UPDATE profiles SET email_address_since = ? WHERE user_id = ?', ['2000-01-01 00:00:00', $this->userId]);

        $now = new \DateTimeImmutable(self::NOW);
        $this->terminalCutoff = $now->modify('-24 months');
        $this->shortCutoff = $now->modify('-90 days');

        $leftover = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events')->first()->cnt;
        $this->assertSame(0, $leftover, 'The prune is global. Another test left rows in er_email_events.');
    }

    private function insertEvent(
        ?int $carId,
        string $event,
        string $messageId,
        string $occurredAt,
        string $email = 'prune@example.com',
        ?int $userId = null
    ): void {
        $this->db->query(
            'INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, ?, ?, ?, NULL, ?, ?)',
            [$userId ?? $this->userId, $carId, $email, $event, $messageId, $occurredAt]
        );
        $this->assertFalse($this->db->error(), 'Failed to seed er_email_events row: ' . $this->db->errorString());
    }

    private function prune(): int
    {
        return $this->repo->deleteEmailEventsOlderThan($this->terminalCutoff, $this->shortCutoff);
    }

    private function exists(string $messageId): bool
    {
        return (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events WHERE brevo_message_id = ?', [$messageId])->first()->cnt === 1;
    }

    private function emailOf(string $messageId): string
    {
        return (string) $this->db->query('SELECT email FROM er_email_events WHERE brevo_message_id = ?', [$messageId])->first()->email;
    }

    private function secondsFrom(\DateTimeImmutable $cutoff, int $seconds): string
    {
        return $cutoff->modify(($seconds >= 0 ? '+' : '') . $seconds . ' seconds')->format('Y-m-d H:i:s');
    }

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
    public function testATerminalEventIsKeptJustInsideAndDeletedJustOutsideTheTwentyFourMonthCutoff(string $event): void
    {
        $this->insertEvent(null, $event, 'term-out', $this->secondsFrom($this->terminalCutoff, -1));
        $this->insertEvent(null, $event, 'term-at', $this->secondsFrom($this->terminalCutoff, 0));
        $this->insertEvent(null, $event, 'term-in', $this->secondsFrom($this->terminalCutoff, 1));

        $deleted = $this->prune();

        $this->assertSame(1, $deleted);
        $this->assertFalse($this->exists('term-out'), "{$event} older than 24 months must go");
        $this->assertTrue($this->exists('term-at'), 'A row at the cutoff stays');
        $this->assertTrue($this->exists('term-in'));
    }

    #[DataProvider('terminalEventProvider')]
    public function testATerminalEventOlderThanNinetyDaysButInsideTwentyFourMonthsIsKept(string $event): void
    {
        $this->insertEvent(null, $event, 'term-long', $this->secondsFrom($this->shortCutoff, -86400 * 100));

        $this->assertSame(0, $this->prune());
        $this->assertTrue($this->exists('term-long'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shortEventProvider(): array
    {
        return [
            'soft_bounce'    => ['soft_bounce'],
            'delivered'      => ['delivered'],
            'unique_opened'  => ['unique_opened'],
            'unknown event'  => ['totally_new_brevo_event'],
        ];
    }

    #[DataProvider('shortEventProvider')]
    public function testAnEventOfAShortLivedTypeIsKeptJustInsideAndDeletedJustOutsideTheNinetyDayCutoff(string $event): void
    {
        $this->insertEvent(null, $event, 'short-out', $this->secondsFrom($this->shortCutoff, -1));
        $this->insertEvent(null, $event, 'short-at', $this->secondsFrom($this->shortCutoff, 0));
        $this->insertEvent(null, $event, 'short-in', $this->secondsFrom($this->shortCutoff, 1));

        $deleted = $this->prune();

        $this->assertSame(1, $deleted);
        $this->assertFalse($this->exists('short-out'));
        $this->assertTrue($this->exists('short-at'));
        $this->assertTrue($this->exists('short-in'));
    }

    public function testASentRowWithoutACarIsDeletedAfterNinetyDaysEvenWhenItIsTheLatestOne(): void
    {
        // Both rows are past 90 days. The second is the latest sent row with car_id NULL.
        // A rule that kept the latest sent row of the NULL group would keep it.
        $this->insertEvent(null, 'sent', 'sent-nocar-old-1', $this->secondsFrom($this->shortCutoff, -86400));
        $this->insertEvent(null, 'sent', 'sent-nocar-old-2', $this->secondsFrom($this->shortCutoff, -1));

        $this->assertSame(2, $this->prune());
        $this->assertFalse($this->exists('sent-nocar-old-1'));
        $this->assertFalse($this->exists('sent-nocar-old-2'), 'A sent row with car_id NULL is never the latest row of a car');
    }

    public function testTheLatestSentRowOfACarStaysAndLosesItsAddressAfterNinetyDays(): void
    {
        $this->insertEvent($this->carId, 'sent', 'local:old-1', $this->secondsFrom($this->shortCutoff, -86400 * 30), 'first@example.com');
        $this->insertEvent($this->carId, 'sent', 'local:latest', $this->secondsFrom($this->shortCutoff, -86400 * 10), 'latest@example.com');

        $changed = $this->prune();

        $this->assertSame(2, $changed, 'One older sent row deleted, one latest sent row cleared');
        $this->assertFalse($this->exists('local:old-1'));
        $this->assertTrue($this->exists('local:latest'), 'The latest sent row starts the car\'s send cycle');
        $this->assertSame('', $this->emailOf('local:latest'), 'No address stays in the row');
        $row = $this->db->query('SELECT user_id, car_id, occurred_at FROM er_email_events WHERE brevo_message_id = ?', ['local:latest'])->first();
        $this->assertSame($this->userId, (int) $row->user_id);
        $this->assertSame($this->carId, (int) $row->car_id);
    }

    public function testTheKeptCycleStartStillShowsOnTheDashboardAfterThePrune(): void
    {
        $this->insertEvent($this->carId, 'sent', 'local:cycle', $this->secondsFrom($this->shortCutoff, -86400 * 10), 'cycle@example.com');
        // The verification delivery carries its car (#2359). A car-less delivered row does not show.
        $this->insertEvent($this->carId, 'delivered', 'brevo-late', $this->secondsFrom($this->shortCutoff, 1));

        $this->prune();

        $latest = $this->repo->findLatestEmailEventsByCarIds([$this->carId]);
        $this->assertSame('delivered', $latest[$this->carId]->event, 'The delivered row is after the kept cycle start');
        $this->assertTrue($this->exists('local:cycle'));
    }

    public function testASentRowInsideNinetyDaysKeepsItsAddress(): void
    {
        $this->insertEvent($this->carId, 'sent', 'local:fresh', $this->secondsFrom($this->shortCutoff, 60), 'fresh@example.com');

        $this->assertSame(0, $this->prune());
        $this->assertSame('fresh@example.com', $this->emailOf('local:fresh'));
    }

    public function testTwoSentRowsWithTheSameTimeKeepTheHigherId(): void
    {
        $when = $this->secondsFrom($this->shortCutoff, -86400 * 5);
        $this->insertEvent($this->carId, 'sent', 'local:tie-low', $when, 'low@example.com');
        $this->insertEvent($this->carId, 'sent', 'local:tie-high', $when, 'high@example.com');

        $changed = $this->prune();

        $this->assertSame(2, $changed);
        $this->assertFalse($this->exists('local:tie-low'));
        $this->assertTrue($this->exists('local:tie-high'));
        $this->assertSame('', $this->emailOf('local:tie-high'));
    }

    public function testTheLatestSentRowIsKeptForEachCar(): void
    {
        $otherCar = $this->createTestCar($this->userId, ['email' => 'prune-b-' . uniqid() . '@example.com']);
        $this->insertEvent($this->carId, 'sent', 'local:a-old', $this->secondsFrom($this->shortCutoff, -86400 * 20));
        $this->insertEvent($this->carId, 'sent', 'local:a-latest', $this->secondsFrom($this->shortCutoff, -86400 * 10));
        $this->insertEvent($otherCar, 'sent', 'local:b-only', $this->secondsFrom($this->shortCutoff, -86400 * 40));

        $this->prune();

        $this->assertFalse($this->exists('local:a-old'));
        $this->assertTrue($this->exists('local:a-latest'));
        $this->assertTrue($this->exists('local:b-only'), 'The other car\'s only sent row is its latest');
    }

    /**
     * A merge moves `car_id` only. Owner A's newer `sent` row lands on owner B's
     * car. The reader starts B's cycle at B's own latest `sent` row, so the
     * prune must keep that row and not A's.
     */
    public function testAfterAMergeThePruneKeepsTheCurrentOwnersCycleStartAndDeletesThePreviousOwnersRow(): void
    {
        $ownerB = $this->userId;
        $carB = $this->carId;
        $ownerA = $this->createTestUser([], true);
        $carA = $this->createTestCar($ownerA, ['email' => 'prune-a-' . uniqid() . '@example.com']);
        $this->insertEvent($carB, 'sent', 'local:b-cycle', $this->secondsFrom($this->shortCutoff, -86400 * 20), 'b@example.com', $ownerB);
        $this->insertEvent($carA, 'sent', 'local:a-newer', $this->secondsFrom($this->shortCutoff, -86400 * 10), 'a@example.com', $ownerA);
        $this->insertEvent($carB, 'delivered', 'brevo-b-late', $this->secondsFrom($this->shortCutoff, 60), 'b@example.com', $ownerB);
        $this->assertTrue($this->repo->transferEmailEvents($carA, $carB), 'Test setup: the merge moves the events');
        $this->assertSame(
            'delivered',
            $this->repo->findLatestEmailEventsByCarIds([$carB])[$carB]->event,
            'Test setup: before the prune, the chip of car B shows its cycle'
        );

        $this->prune();

        $this->assertTrue($this->exists('local:b-cycle'), "B's cycle-start row stays");
        $this->assertSame('', $this->emailOf('local:b-cycle'), 'The kept row is past 90 days, so it loses its address');
        $this->assertFalse($this->exists('local:a-newer'), "A's row on B's car follows the 90-day rule");
        $latest = $this->repo->findLatestEmailEventsByCarIds([$carB]);
        $this->assertArrayHasKey($carB, $latest, 'The chip of car B still shows its cycle');
        $this->assertSame('delivered', $latest[$carB]->event);
    }

    /**
     * A transfer changes `cars.user_id` and no event row. The new owner's latest
     * `sent` row starts the cycle and stays. The old owner's row goes after 90 days.
     */
    public function testAfterATransferThePruneKeepsTheNewOwnersLatestSentAndDeletesTheOldOwnersOldSent(): void
    {
        $oldOwner = $this->userId;
        $newOwner = $this->createTestUser([], true);
        $this->insertEvent($this->carId, 'sent', 'local:old-owner', $this->secondsFrom($this->shortCutoff, -86400 * 20), 'old@example.com', $oldOwner);
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$newOwner, $this->carId]);
        $this->assertFalse($this->db->error(), 'Test setup: transfer: ' . $this->db->errorString());
        $this->insertEvent($this->carId, 'sent', 'local:new-owner', $this->secondsFrom($this->shortCutoff, -86400 * 10), 'new@example.com', $newOwner);

        $this->prune();

        $this->assertTrue($this->exists('local:new-owner'), "The new owner's latest sent row starts the cycle");
        $this->assertSame('', $this->emailOf('local:new-owner'));
        $this->assertFalse($this->exists('local:old-owner'), "The old owner's sent row follows the 90-day rule");
        $this->assertSame('sent', $this->repo->findLatestEmailEventsByCarIds([$this->carId])[$this->carId]->event);
    }

    /**
     * The new owner has no `sent` row yet. The old owner's `sent` row is the
     * car's only one, but it belongs to another owner, so it starts no cycle
     * of the current owner and follows the 90-day rule. A keep rule that
     * ignored `cars.user_id` would keep it.
     */
    public function testAfterATransferWithNoNewSendThePruneDeletesTheOldOwnersOnlySentRow(): void
    {
        $oldOwner = $this->userId;
        $newOwner = $this->createTestUser([], true);
        $this->insertEvent($this->carId, 'sent', 'local:old-owner-only', $this->secondsFrom($this->shortCutoff, -86400 * 20), 'old@example.com', $oldOwner);
        $this->db->query('UPDATE cars SET user_id = ? WHERE id = ?', [$newOwner, $this->carId]);
        $this->assertFalse($this->db->error(), 'Test setup: transfer: ' . $this->db->errorString());

        $this->assertSame(1, $this->prune());

        $this->assertFalse($this->exists('local:old-owner-only'), "The old owner's sent row is not the new owner's cycle start");
    }

    public function testASoftBounceWindowStillResetsAfterADeliveredRowInsideNinetyDays(): void
    {
        $email = 'window@example.com';
        $this->insertEvent(null, 'soft_bounce', 'w-1', $this->secondsFrom($this->shortCutoff, 86400 * 10), $email);
        $this->insertEvent(null, 'delivered', 'w-d', $this->secondsFrom($this->shortCutoff, 86400 * 20), $email);
        $this->insertEvent(null, 'soft_bounce', 'w-2', $this->secondsFrom($this->shortCutoff, 86400 * 30), $email);

        $this->assertSame(0, $this->prune(), 'All three rows are inside 90 days');

        $this->assertSame(1, $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email));
    }

    public function testSoftBouncesFarApartDoNotReachTheEscalationThresholdAfterThePrune(): void
    {
        $email = 'escalate@example.com';
        // Two old bounces, a delivered row between them and the recent bounces, then two recent bounces.
        $this->insertEvent(null, 'soft_bounce', 'e-old-1', $this->secondsFrom($this->shortCutoff, -86400 * 100), $email);
        $this->insertEvent(null, 'soft_bounce', 'e-old-2', $this->secondsFrom($this->shortCutoff, -86400 * 60), $email);
        $this->insertEvent(null, 'delivered', 'e-d', $this->secondsFrom($this->shortCutoff, -86400 * 30), $email);
        $this->insertEvent(null, 'soft_bounce', 'e-new-1', $this->secondsFrom($this->shortCutoff, 86400 * 10), $email);
        $this->insertEvent(null, 'soft_bounce', 'e-new-2', $this->secondsFrom($this->shortCutoff, 86400 * 20), $email);

        $this->prune();

        $this->assertFalse($this->exists('e-d'), 'Test setup: the delivered row is past 90 days and the prune deletes it');
        $this->assertFalse($this->exists('e-old-1'));
        $this->assertFalse($this->exists('e-old-2'));
        $this->assertSame(
            2,
            $this->repo->countSoftBouncesSinceLastDelivered($this->userId, $email),
            'A soft bounce past 90 days goes with the delivered row. Four bounces would reach the threshold of 3.'
        );
    }

    public function testASecondRunChangesNoRow(): void
    {
        $this->insertEvent(null, 'delivered', 'twice-old', $this->secondsFrom($this->shortCutoff, -100));
        $this->insertEvent($this->carId, 'sent', 'local:twice', $this->secondsFrom($this->shortCutoff, -100), 'twice@example.com');
        $this->insertEvent(null, 'spam', 'twice-term-old', $this->secondsFrom($this->terminalCutoff, -100));
        $this->insertEvent(null, 'delivered', 'twice-new', $this->secondsFrom($this->shortCutoff, 100));

        $this->assertSame(3, $this->prune(), 'Two deleted rows and one cleared address');
        $this->assertSame(0, $this->prune(), 'The second run changes no row');
    }

    public function testSwappedCutoffsThrowAndDeleteNothing(): void
    {
        $this->insertEvent(null, 'delivered', 'swap-1', $this->secondsFrom($this->shortCutoff, -100));

        try {
            $this->repo->deleteEmailEventsOlderThan($this->shortCutoff, $this->terminalCutoff);
            $this->fail('Swapped cutoffs must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('terminal cutoff', $e->getMessage());
        }
        try {
            $this->repo->deleteEmailEventsOlderThan($this->shortCutoff, $this->shortCutoff);
            $this->fail('Equal cutoffs must throw');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($this->exists('swap-1'));
    }

    public function testThePruneRunsOnTwentyThousandRowsWithoutErrorAndFinishesQuickly(): void
    {
        $this->generateRows(20000);

        $start = microtime(true);
        $this->prune();
        $seconds = microtime(true) - $start;

        $this->assertFalse($this->db->error(), 'No SQL error (1093) on the self-referencing delete: ' . $this->db->errorString());
        $this->assertLessThan(30.0, $seconds, 'The prune must finish well inside the cron window');
        $this->assertSame(0, $this->prune(), 'The second run changes no row');
    }

    /**
     * Generate rows with one INSERT ... SELECT on a sequence, half past the cutoffs.
     */
    private function generateRows(int $count): void
    {
        $this->db->query(
            "INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             SELECT ?, IF(n % 5 = 0, ?, NULL), 'gen@example.com',
                    ELT(1 + n % 4, 'delivered', 'soft_bounce', 'hard_bounce', 'sent'),
                    NULL, CONCAT('gen-', n),
                    DATE_SUB(?, INTERVAL (n % 400) DAY)
               FROM (
                    SELECT a.n + 10 * b.n + 100 * c.n + 1000 * d.n + 10000 * e.n AS n
                      FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a,
                           (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b,
                           (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) c,
                           (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) d,
                           (SELECT 0 n UNION SELECT 1) e
                    ) seq
              WHERE n < ?",
            [$this->userId, $this->carId, self::NOW, $count]
        );
        $this->assertFalse($this->db->error(), 'Test setup: ' . $this->db->errorString());
        $this->assertSame($count, (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_email_events')->first()->cnt);
    }
}
