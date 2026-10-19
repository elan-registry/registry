<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarVerificationManager;
use ElanRegistry\Car\EmailEventApplier;
use ElanRegistry\Car\EmailEventMatch;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrency tests for the event step of EmailEventApplier (#2359).
 *
 * Each worker is its own PHP process (tests/Support/email-event-apply-worker.php)
 * with its own database connection. The test holds the owner's `profiles` row
 * lock on a third connection to make the order of events deterministic:
 *
 * - R5-AR1: Clear Bounced commits after the match step has read the user and
 *   before the event transaction takes the lock. The event must set no flag.
 * - The event step takes the `profiles` lock first. A worker must wait while
 *   another connection holds that lock.
 * - Two soft bounces for one owner, run in parallel, give the same result as
 *   the same two in sequence.
 *
 * The plugin table is not needed: the workers match by a `sent` row (step (a)).
 *
 * @see https://github.com/elan-registry/registry/issues/2359
 */
#[Group('integration')]
#[Group('concurrency')]
final class EmailEventApplierConcurrencyTest extends IntegrationTestCase
{
    private const LONG_AGO = '2020-01-01 00:00:00';
    private const WAIT_SECONDS = 20;

    /** @var list<array{process: resource, pipes: array<int, resource>, buffer: string}> */
    private array $workers = [];

    private ?PDO $lockConnection = null;

    private int $logIdFloor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->logIdFloor = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM logs')->first()->m;
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $status = proc_get_status($worker['process']);
            if ($status['running']) {
                proc_terminate($worker['process']);
            }
            foreach ($worker['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($worker['process']);
        }
        $this->workers = [];
        if ($this->lockConnection instanceof PDO && $this->lockConnection->inTransaction()) {
            $this->lockConnection->rollBack();
        }
        $this->lockConnection = null;

        if ($this->databaseConnected) {
            // By user ID and note text, so a row of a parallel run stays.
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

        parent::tearDown();
    }

    // --- Fixtures --------------------------------------------------------

    private function makeOwner(?string $bounceClearedAt = null): int
    {
        $userId = $this->createTestUser(['join_date' => self::LONG_AGO], true);
        $this->db->query(
            'UPDATE profiles SET email_address_since = ?, email_bounce_cleared_at = ? WHERE user_id = ?',
            [self::LONG_AGO, $bounceClearedAt, $userId]
        );

        return $userId;
    }

    private function emailOf(int $userId): string
    {
        return (string) $this->db->query('SELECT email FROM users WHERE id = ?', [$userId])->first()->email;
    }

    private function seedSentRow(int $userId, string $messageId, string $at): void
    {
        $this->db->query(
            "INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, 'sent', NULL, ?, ?)",
            [$userId, $this->emailOf($userId), $messageId, $at]
        );
        $this->assertFalse($this->db->error(), 'Test setup: sent row: ' . $this->db->errorString());
    }

    /** A second connection, not the one of the test process and not one of the workers. */
    private function lockConnection(): PDO
    {
        if ($this->lockConnection === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_NAME']);
            $this->lockConnection = new PDO($dsn, (string) $_ENV['DB_USER'], (string) $_ENV['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $this->lockConnection->exec("SET SESSION innodb_lock_wait_timeout = 30");
        }

        return $this->lockConnection;
    }

    /**
     * @param array<string, mixed> $config
     * @return int Worker index
     */
    private function startWorker(array $config): int
    {
        foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $var) {
            $value = $_ENV[$var] ?? getenv($var);
            if ($value !== false && $value !== '') {
                putenv("{$var}={$value}");
            }
        }
        $process = proc_open(
            ['php', '-d', 'display_errors=stderr', __DIR__ . '/../Support/email-event-apply-worker.php', json_encode($config, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process, 'Test setup: the worker must start');
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $this->workers[] = ['process' => $process, 'pipes' => $pipes, 'buffer' => ''];

        return array_key_last($this->workers);
    }

    /** Read what the worker printed so far. */
    private function drain(int $index): string
    {
        $chunk = stream_get_contents($this->workers[$index]['pipes'][1]);
        if (is_string($chunk)) {
            $this->workers[$index]['buffer'] .= $chunk;
        }

        return $this->workers[$index]['buffer'];
    }

    private function waitForOutput(int $index, string $needle): bool
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;
        while (microtime(true) < $deadline) {
            if (str_contains($this->drain($index), $needle)) {
                return true;
            }
            usleep(50000);
        }

        return false;
    }

    /** @return array<string, mixed> The decoded RESULT line */
    private function workerResult(int $index): array
    {
        $this->assertTrue($this->waitForOutput($index, 'RESULT:'), 'The worker must finish. Output: ' . $this->drain($index));
        foreach (explode("\n", $this->drain($index)) as $line) {
            if (str_starts_with($line, 'RESULT:')) {
                /** @var array<string, mixed> */
                return json_decode(substr($line, 7), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $this->fail('No RESULT line');
    }

    /** @return list<object> */
    private function eventRows(int $userId, string $event): array
    {
        return $this->db->query(
            'SELECT * FROM er_email_events WHERE user_id = ? AND event = ? ORDER BY id',
            [$userId, $event]
        )->results();
    }

    private function bounced(int $userId): string
    {
        return (string) $this->getOwnerEmailFlags($userId)['email_bounced'];
    }

    private function bounceAuditRows(int $userId): int
    {
        return $this->countMatchingLogs('EmailBounced', "Owner {$userId} email_bounced changed from 0 to 1%");
    }

    // --- R5-AR1 -----------------------------------------------------------

    public function testClearBouncedCommittedAfterTheMatchStepAndBeforeTheEventTransactionLeavesNoFlag(): void
    {
        $userId = $this->makeOwner();
        $address = $this->emailOf($userId);
        $messageId = 'conc-clear-' . uniqid('', true);
        $this->seedSentRow($userId, $messageId, '2026-10-10 08:00:00');

        $index = $this->startWorker([
            'match' => true, 'pause' => true, 'user_id' => $userId, 'address' => $address,
            'event' => 'hard_bounce', 'message_id' => $messageId, 'occurred_at' => '2026-10-12 00:00:00',
        ]);
        $this->assertTrue($this->waitForOutput($index, 'PAUSED'), 'The worker must finish the match step. Output: ' . $this->drain($index));

        // The match step has read the user. Clear Bounced commits now, on another connection.
        $pdo = $this->lockConnection();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT 1 FROM profiles WHERE user_id = ? FOR UPDATE')->execute([$userId]);
        $pdo->prepare('UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?')->execute(['2026-10-11 00:00:00', $userId]);
        $pdo->commit();

        fwrite($this->workers[$index]['pipes'][0], "go\n");
        $result = $this->workerResult($index);

        $this->assertTrue($result['matched']);
        $this->assertFalse($result['is_new'], 'The event step must write no row');
        $this->assertSame([], $this->eventRows($userId, 'hard_bounce'));
        $this->assertSame('0', $this->bounced($userId), 'The flag must stay clear after Clear Bounced');
    }

    public function testWithoutAClearBouncedBetweenMatchAndLockTheSameEventSetsTheFlag(): void
    {
        // The control of the test above: the same flow, with no Clear Bounced, sets the flag.
        $userId = $this->makeOwner();
        $address = $this->emailOf($userId);
        $messageId = 'conc-control-' . uniqid('', true);
        $this->seedSentRow($userId, $messageId, '2026-10-10 08:00:00');

        $index = $this->startWorker([
            'match' => true, 'pause' => true, 'user_id' => $userId, 'address' => $address,
            'event' => 'hard_bounce', 'message_id' => $messageId, 'occurred_at' => '2026-10-12 00:00:00',
        ]);
        $this->assertTrue($this->waitForOutput($index, 'PAUSED'), $this->drain($index));
        fwrite($this->workers[$index]['pipes'][0], "go\n");

        $this->assertTrue($this->workerResult($index)['is_new']);
        $this->assertSame('1', $this->bounced($userId));
    }

    // --- Lock order -------------------------------------------------------

    public function testTheEventStepWaitsForTheProfilesLockAndThenSeesWhatTheHolderCommitted(): void
    {
        $userId = $this->makeOwner();
        $pdo = $this->lockConnection();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT 1 FROM profiles WHERE user_id = ? FOR UPDATE')->execute([$userId]);

        $index = $this->startWorker([
            'match' => false, 'pause' => false, 'user_id' => $userId, 'address' => $this->emailOf($userId),
            'event' => 'hard_bounce', 'message_id' => 'conc-lock-' . uniqid('', true),
            'message_time' => '2026-10-10 08:00:00', 'occurred_at' => '2026-10-12 00:00:00',
        ]);
        $this->assertTrue($this->waitForOutput($index, 'MATCHED'), 'The worker must reach the event step. Output: ' . $this->drain($index));
        usleep(1500000);
        $this->assertStringNotContainsString('RESULT:', $this->drain($index), 'The event step must wait for the profiles lock');
        $this->assertSame([], $this->eventRows($userId, 'hard_bounce'), 'No event row may be visible before the lock holder commits');

        $pdo->prepare('UPDATE profiles SET email_bounce_cleared_at = ? WHERE user_id = ?')->execute(['2026-10-11 00:00:00', $userId]);
        $pdo->commit();

        $result = $this->workerResult($index);
        $this->assertFalse($result['is_new'], 'The guard must read the value that the lock holder committed');
        $this->assertSame('0', $this->bounced($userId));
    }

    // --- Two soft bounces in parallel -------------------------------------

    private function seedSoftBounce(int $userId, string $messageId, string $at): void
    {
        $this->db->query(
            "INSERT INTO er_email_events (user_id, car_id, email, event, reason, brevo_message_id, occurred_at)
             VALUES (?, NULL, ?, 'soft_bounce', NULL, ?, ?)",
            [$userId, $this->emailOf($userId), $messageId, $at]
        );
    }

    public function testTwoSoftBouncesInParallelGiveTheResultOfTheSameTwoInSequence(): void
    {
        // Sequence: an owner with one earlier soft bounce cycle gets two more.
        $sequential = $this->makeOwner();
        $this->seedSoftBounce($sequential, 'seq-0-' . uniqid(), '2026-10-12 08:00:00');
        $repo = new CarRepository($this->db);
        $applier = new EmailEventApplier($repo, new CarVerificationManager($repo));
        foreach (['seq-1' => '2026-10-12 09:00:00', 'seq-2' => '2026-10-12 10:00:00'] as $id => $at) {
            $applier->apply(
                EmailEventMatch::matched($sequential, null, '2026-10-12 08:30:00'),
                $this->emailOf($sequential),
                'soft_bounce',
                null,
                $id . '-' . uniqid(),
                $at,
                'webhook',
                EmailEventApplier::MODE_EVENT
            );
        }

        // Parallel: the same start, with two workers released at the same moment.
        $parallel = $this->makeOwner();
        $this->seedSoftBounce($parallel, 'par-0-' . uniqid(), '2026-10-12 08:00:00');
        $pdo = $this->lockConnection();
        $pdo->beginTransaction();
        $pdo->prepare('SELECT 1 FROM profiles WHERE user_id = ? FOR UPDATE')->execute([$parallel]);
        $workers = [];
        foreach (['par-1' => '2026-10-12 09:00:00', 'par-2' => '2026-10-12 10:00:00'] as $id => $at) {
            $workers[] = $this->startWorker([
                'match' => false, 'pause' => false, 'user_id' => $parallel, 'address' => $this->emailOf($parallel),
                'event' => 'soft_bounce', 'message_id' => $id . '-' . uniqid('', true),
                'message_time' => '2026-10-12 08:30:00', 'occurred_at' => $at,
            ]);
        }
        foreach ($workers as $index) {
            $this->assertTrue($this->waitForOutput($index, 'MATCHED'), $this->drain($index));
        }
        usleep(1000000);
        $pdo->commit();
        foreach ($workers as $index) {
            $this->assertTrue($this->workerResult($index)['is_new']);
        }

        $this->assertSame('1', $this->bounced($sequential), 'Test setup: the third cycle escalates in sequence');
        $this->assertSame($this->bounced($sequential), $this->bounced($parallel), 'Parallel must give the same flag');
        $this->assertCount(3, $this->eventRows($parallel, 'soft_bounce'));
        $this->assertSame(1, $this->bounceAuditRows($sequential));
        $this->assertSame(1, $this->bounceAuditRows($parallel), 'Exactly one escalation, one audit row');
        $flags = $this->getOwnerEmailFlags($parallel);
        $this->assertContains((string) $flags['email_bounced_at'], ['2026-10-12 09:00:00', '2026-10-12 10:00:00']);
    }
}
