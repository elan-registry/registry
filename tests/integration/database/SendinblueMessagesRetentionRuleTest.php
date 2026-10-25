<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Exceptions\RetentionPurgeException;
use ElanRegistry\Retention\SendinblueMessagesRetentionRule;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;
use Tests\Support\SendinblueMessagesTable;

/**
 * #2384: SendinblueMessagesRetentionRule against the real plugin table and
 * er_email_events.
 *
 * The rule computes its cutoff from the PHP `$now` that the test passes in.
 * The boundary rows use the same `$now`, not MySQL NOW(), so the case does
 * not depend on time zones.
 *
 * A class that makes the plugin table drops it in tearDownAfterClass(). The
 * "table absent" test renames the table and renames it back, so it is safe
 * when the table was there before.
 */
#[Group('integration')]
final class SendinblueMessagesRetentionRuleTest extends IntegrationTestCase
{
    private const PREFIX = 'ret2384-';

    private const WINDOW_DAYS = 90;

    /** True when this class made the plugin table. */
    private static bool $madeTable = false;

    private DateTimeImmutable $now;

    private int $userId = 0;

    private int $carId = 0;

    private string $ownerEmail = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$madeTable) {
            DB::getInstance()->query('DROP TABLE IF EXISTS plg_sendinblue_messages');
            self::$madeTable = false;
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        if (SendinblueMessagesTable::createIfMissing($this->db)) {
            self::$madeTable = true;
        }
        $this->deleteFixtureRows();

        $foreign = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages')->first()->cnt;
        if ($foreign > 0) {
            $this->markTestSkipped('plg_sendinblue_messages holds rows that this test did not write. Exact counts would be wrong.');
        }

        $this->now = new DateTimeImmutable();
        $this->ownerEmail = 'ret2384-' . uniqid() . '@example.com';
        $this->userId = $this->createTestUser(['email' => $this->ownerEmail, 'join_date' => '2000-01-01 00:00:00'], true);
        $this->setEmailAddressSinceSeen($this->userId, $this->ownerEmail, '2000-01-01 00:00:00');
        $this->carId = $this->createTestCar($this->userId, ['email' => $this->ownerEmail]);
    }

    protected function tearDown(): void
    {
        if ($this->databaseConnected) {
            // The table may be renamed if a test failed half way.
            $this->db->query('RENAME TABLE ret2384_plg_sendinblue_messages_away TO plg_sendinblue_messages');
            $this->deleteFixtureRows();
        }
        parent::tearDown();
    }

    private function deleteFixtureRows(): void
    {
        $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id LIKE ?', [self::PREFIX . '%']);
    }

    private function rule(?\ElanRegistry\DatabaseInterface $db = null): SendinblueMessagesRetentionRule
    {
        return new SendinblueMessagesRetentionRule($db ?? $this->db, self::WINDOW_DAYS);
    }

    private function cutoff(): DateTimeImmutable
    {
        return $this->now->modify('-' . self::WINDOW_DAYS . ' days');
    }

    private function seedMessage(string $messageId, string $acceptedAt, string $recipient = 'someone@example.com', string $tags = 'verification'): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, ?, 'registry@example.test', 'transactional', ?, 'accepted', ?)",
            [self::PREFIX . $messageId, $recipient, $tags, $acceptedAt]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
    }

    private function seedEvent(string $brevoMessageId, string $event = 'delivered'): void
    {
        $id = $this->repo()->insertEmailEvent($this->carId, $this->ownerEmail, $event, null, $brevoMessageId, '2026-01-02 00:00:00');
        $this->assertGreaterThan(0, $id, 'Test setup: the event row must be written');
    }

    private function repo(): CarRepository
    {
        return new CarRepository($this->db);
    }

    /** @return array<string, mixed>|null */
    private function messageRow(string $messageId): ?array
    {
        $row = $this->db->query(
            'SELECT message_id, recipient_email, tags FROM plg_sendinblue_messages WHERE message_id = ?',
            [self::PREFIX . $messageId]
        )->first(true);

        return $row === [] ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(): array
    {
        return $this->db->query(
            'SELECT message_id, recipient_email, tags, accepted_at FROM plg_sendinblue_messages ORDER BY message_id'
        )->results(true);
    }

    private function daysAgo(int $days): string
    {
        return $this->now->modify("-{$days} days")->format('Y-m-d H:i:s');
    }

    public function testAnOldRowWithNoEventIsDeletedAndNewerRowsStay(): void
    {
        $this->seedMessage('old', $this->daysAgo(91));
        $this->seedMessage('new', $this->daysAgo(89));

        $result = $this->rule()->purge($this->now);

        $this->assertFalse($result->isSkipped());
        $this->assertSame(1, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertNull($this->messageRow('old'), 'A row older than 90 days with no event must go');
        $this->assertSame('someone@example.com', $this->messageRow('new')['recipient_email'] ?? null, 'A row of 89 days must stay untouched');
    }

    public function testTheCutoffIsExactToOneSecond(): void
    {
        $cutoff = $this->cutoff();
        $this->seedMessage('just-before', $cutoff->modify('-1 second')->format('Y-m-d H:i:s'));
        $this->seedMessage('at-cutoff', $cutoff->format('Y-m-d H:i:s'));
        $this->seedMessage('just-after', $cutoff->modify('+1 second')->format('Y-m-d H:i:s'));

        $result = $this->rule()->purge($this->now);

        $this->assertSame(1, $result->deleted);
        $this->assertNull($this->messageRow('just-before'), 'One second older than the cutoff must go');
        $this->assertNotNull($this->messageRow('at-cutoff'), 'A row at the cutoff must stay: the compare is strict');
        $this->assertNotNull($this->messageRow('just-after'), 'One second newer than the cutoff must stay');
    }

    public function testAnOldRowNamedByAnEventKeepsItsRowAndLosesItsAddress(): void
    {
        $this->seedMessage('named', $this->daysAgo(91), 'old-address@example.com', 'send-owner-email');
        $this->seedEvent(self::PREFIX . 'named');

        $result = $this->rule()->purge($this->now);

        $this->assertSame(0, $result->deleted);
        $this->assertSame(1, $result->cleared);
        $row = $this->messageRow('named');
        $this->assertNotNull($row, 'A row that an event names must stay');
        $this->assertSame('', $row['recipient_email'], 'The address must be cleared');
        $this->assertSame(self::PREFIX . 'named', $row['message_id']);
        $this->assertSame('send-owner-email', $row['tags'], 'The tags must not change');
    }

    public function testANewerRowNamedByAnEventKeepsItsAddress(): void
    {
        $this->seedMessage('newer-named', $this->daysAgo(89), 'keep-me@example.com');
        $this->seedEvent(self::PREFIX . 'newer-named');

        $result = $this->rule()->purge($this->now);

        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertSame('keep-me@example.com', $this->messageRow('newer-named')['recipient_email'] ?? null);
    }

    public function testAMessageIdThatDiffersOnlyInCaseIsNotNamedByTheEventAndIsDeleted(): void
    {
        $this->seedMessage('CaseMsg', $this->daysAgo(91));
        $this->seedEvent(self::PREFIX . 'casemsg');

        $result = $this->rule()->purge($this->now);

        $this->assertSame(1, $result->deleted, 'The compare is case-sensitive, and it must not raise a collation error');
        $this->assertNull($this->messageRow('CaseMsg'));
    }

    public function testASecondRunWithTheSameNowChangesNothing(): void
    {
        $this->seedMessage('old', $this->daysAgo(91));
        $this->seedMessage('named', $this->daysAgo(91), 'a@example.com');
        $this->seedMessage('new', $this->daysAgo(10));
        $this->seedEvent(self::PREFIX . 'named');

        $first = $this->rule()->purge($this->now);
        $this->assertSame(1, $first->deleted);
        $this->assertSame(1, $first->cleared);
        $before = $this->snapshot();
        $this->assertCount(2, $before, 'Test setup: two rows must remain');

        $second = $this->rule()->purge($this->now);

        $this->assertSame(0, $second->deleted);
        $this->assertSame(0, $second->cleared);
        $this->assertSame($before, $this->snapshot());
    }

    public function testAMissingTableGivesASkipAndNoException(): void
    {
        $this->db->query('RENAME TABLE plg_sendinblue_messages TO ret2384_plg_sendinblue_messages_away');
        $this->assertFalse($this->db->error(), 'Test setup: rename: ' . $this->db->errorString());

        try {
            $result = $this->rule()->purge($this->now);
        } finally {
            $this->db->query('RENAME TABLE ret2384_plg_sendinblue_messages_away TO plg_sendinblue_messages');
            $this->assertFalse($this->db->error(), 'Test cleanup: the table must come back: ' . $this->db->errorString());
        }

        $this->assertTrue($result->isSkipped());
        $this->assertSame(0, $result->deleted);
        $this->assertSame(0, $result->cleared);
        $this->assertSame('sendinblue_messages', $result->ruleName);
    }

    public function testAFailedTableCheckThrows(): void
    {
        $failing = new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return $this->simulateFailure('simulated lock wait timeout');
            }
        };

        try {
            $this->rule($failing)->purge($this->now);
            $this->fail('A failed table check must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('table check failed', $e->getMessage());
            $this->assertSame('table check', $e->step(), 'The job log line prints the step');
            $this->assertSame('HY000', $e->sqlState(), 'The job log line prints the SQLSTATE');
        }
    }

    public function testAFailedDeleteThrows(): void
    {
        $this->seedMessage('del-fail', $this->daysAgo(91));
        $failing = new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return str_starts_with(ltrim($sql), 'DELETE')
                    ? $this->simulateFailure('simulated lock wait timeout')
                    : parent::query($sql, $params);
            }
        };

        try {
            $this->rule($failing)->purge($this->now);
            $this->fail('A failed delete must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('delete failed', $e->getMessage());
        }
        $this->assertNotNull($this->messageRow('del-fail'), 'The failed delete must leave the row');
    }

    public function testAFailedClearThrowsAfterTheDeleteRan(): void
    {
        $this->seedMessage('clr-gone', $this->daysAgo(91));
        $this->seedMessage('clr-kept', $this->daysAgo(91));
        $this->seedEvent(self::PREFIX . 'clr-kept');
        $failing = new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return str_starts_with(ltrim($sql), 'UPDATE')
                    ? $this->simulateFailure('simulated lock wait timeout')
                    : parent::query($sql, $params);
            }
        };

        try {
            $this->rule($failing)->purge($this->now);
            $this->fail('A failed clear must throw');
        } catch (RetentionPurgeException $e) {
            $this->assertStringContainsString('clear failed', $e->getMessage());
        }
        $this->assertNull($this->messageRow('clr-gone'), 'The delete ran before the clear failed');
        $this->assertSame('someone@example.com', $this->messageRow('clr-kept')['recipient_email'] ?? null);
    }

    public function testAKeptRowStillHidesAnOwnerContactSoftBounceFromTheCount(): void
    {
        $old = $this->daysAgo(91);
        $this->seedMessage('contact', $old, $this->ownerEmail, 'send-owner-email');
        $this->seedMessage('verify', $old, $this->ownerEmail, 'verification');
        $this->seedEvent(self::PREFIX . 'contact', 'soft_bounce');
        $this->seedEvent(self::PREFIX . 'verify', 'soft_bounce');
        $this->assertSame(
            1,
            $this->repo()->countSoftBouncesSinceLastDelivered($this->userId, $this->ownerEmail),
            'Test setup: only the non-owner-contact bounce counts before the purge'
        );

        $result = $this->rule()->purge($this->now);

        $this->assertSame(0, $result->deleted);
        $this->assertSame(2, $result->cleared);
        $this->assertSame(
            1,
            $this->repo()->countSoftBouncesSinceLastDelivered($this->userId, $this->ownerEmail),
            'The owner-contact bounce must still not count after the purge. The non-owner-contact bounce (control) still counts.'
        );
    }
}
