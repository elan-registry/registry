<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';
require_once __DIR__ . '/../Support/RecordingHealthcheckTransport.php';

use ElanRegistry\Cron\HealthcheckPinger;
use ElanRegistry\Cron\RetentionPurgeJob;
use ElanRegistry\DatabaseInterface;
use ElanRegistry\LogCategories;
use ElanRegistry\Retention\RetentionPolicy;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\PassThroughDatabase;
use Tests\Support\RecordingHealthcheckTransport;
use Tests\Support\SendinblueMessagesTable;

/**
 * #2384: RetentionPurgeJob::run() with the real policy rules, the real
 * `retention_purge` guard row, and the real plugin table. #2419: the
 * er_deleted_user_addresses backlog rule fails the run.
 *
 * Snapshots and restores the seeded `retention_purge` row and the
 * verification switch. A class that makes the plugin table drops it.
 */
#[Group('integration')]
final class RetentionPurgeJobIntegrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'retention_purge';

    private const PREFIX = 'ret2384job-';

    private const OLD_RUN = '2026-01-01 00:00:00';

    /** Far above any real users.id. Not negative: the column can be unsigned. */
    private const HOLDING_USER_ID = 2000241903;

    private static bool $madeTable = false;

    private ?object $originalRow = null;

    private bool $originalSwitch = false;

    private int $logFloor = 0;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private RecordingHealthcheckTransport $transport;

    private DateTimeImmutable $now;

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

        // Set before any skip or fail: tearDown deletes the log rows above this floor.
        $this->logFloor = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) AS m FROM logs')->first()->m;

        $tableCheck = $this->db->query(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            ['er_deleted_user_addresses']
        );
        if ($tableCheck->count() === 0) {
            $this->markTestSkipped('er_deleted_user_addresses does not exist. Run: composer migrate (20261023)');
        }

        $this->originalRow = $this->db->query(
            'SELECT enabled, last_run_at, last_failure_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        )->first() ?: null;
        $this->assertIsObject(
            $this->originalRow,
            "er_cron_job_runs must hold a seeded '" . self::JOB_NAME . "' row. Run: composer migrate (20261025000000_seed_retention_purge_cron)"
        );
        $this->originalSwitch = (bool) $this->db->query('SELECT enabled FROM er_verification_settings WHERE id = 1')->first()->enabled;

        if (SendinblueMessagesTable::createIfMissing($this->db)) {
            self::$madeTable = true;
        }
        $this->deleteFixtureRows();

        // The job runs the real rule over the whole table, so it would purge
        // rows that this test did not write.
        $foreign = (int) $this->db->query('SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages')->first()->cnt;
        if ($foreign > 0) {
            $this->markTestSkipped('plg_sendinblue_messages holds rows that this test did not write. The job would purge them.');
        }

        // A stale holding row that this test did not write would fail every
        // run. A fresh one does not change the outcome. A stale one is a
        // leaked fixture, so the test fails and does not skip.
        $staleForeign = (string) $this->db->query(
            'SELECT COALESCE(GROUP_CONCAT(user_id ORDER BY user_id), \'\') AS ids FROM er_deleted_user_addresses
             WHERE deleted_at < NOW() - INTERVAL 24 HOUR AND user_id <> ?',
            [self::HOLDING_USER_ID]
        )->first()->ids;
        if ($staleForeign !== '') {
            $this->fail(
                "er_deleted_user_addresses holds stale rows that this test did not write (user IDs {$staleForeign}). "
                . 'The backlog rule would fail every run. Remove the leaked fixture rows: '
                . "DELETE FROM er_deleted_user_addresses WHERE user_id IN ({$staleForeign})"
            );
        }

        // The pinger sends a ping only when both values are set.
        foreach (['HEALTHCHECKS_PING_KEY' => 'testkey', 'HEALTHCHECKS_SLUG_SUFFIX' => '-test'] as $name => $value) {
            $this->originalEnv[$name] = getenv($name);
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        $this->transport = new RecordingHealthcheckTransport();
        $this->now = new DateTimeImmutable();
        $this->setRow(true);
        $this->setSwitch(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
            unset($_ENV[$name]);
        }
        $this->originalEnv = [];

        if ($this->databaseConnected) {
            $this->db->query('RENAME TABLE ret2384job_plg_sendinblue_messages_away TO plg_sendinblue_messages');
            $this->deleteFixtureRows();
            if ($this->originalRow !== null) {
                $this->db->query(
                    'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = ? WHERE job_name = ?',
                    [
                        (int) $this->originalRow->enabled,
                        $this->originalRow->last_run_at ?: null,
                        $this->originalRow->last_failure_at ?: null,
                        self::JOB_NAME,
                    ]
                );
            }
            $this->setSwitch($this->originalSwitch);
            $this->db->query(
                'DELETE FROM logs WHERE id > ? AND logtype IN (?, ?, ?)',
                [
                    $this->logFloor,
                    LogCategories::LOG_CATEGORY_RETENTION_PURGE,
                    LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE,
                    LogCategories::LOG_CATEGORY_HEALTHCHECK_PING,
                ]
            );
        }
        parent::tearDown();
    }

    private function deleteFixtureRows(): void
    {
        $this->db->query('DELETE FROM plg_sendinblue_messages WHERE message_id LIKE ?', [self::PREFIX . '%']);
        $this->db->query('DELETE FROM er_deleted_user_addresses WHERE user_id = ?', [self::HOLDING_USER_ID]);
    }

    private function setRow(bool $enabled): void
    {
        $this->db->query(
            'UPDATE er_cron_job_runs SET enabled = ?, last_run_at = ?, last_failure_at = NULL WHERE job_name = ?',
            [$enabled ? 1 : 0, self::OLD_RUN, self::JOB_NAME]
        );
        $this->assertFalse($this->db->error(), 'Test setup: row: ' . $this->db->errorString());
    }

    private function setSwitch(bool $on): void
    {
        $this->db->query('UPDATE er_verification_settings SET enabled = ? WHERE id = 1', [$on ? 1 : 0]);
        $this->assertFalse($this->db->error(), 'Test setup: switch: ' . $this->db->errorString());
    }

    private function seedOldMessage(string $suffix): void
    {
        $this->db->query(
            "INSERT INTO plg_sendinblue_messages (message_id, recipient_email, sender_email, kind, tags, status, accepted_at)
             VALUES (?, 'someone@example.com', 'registry@example.test', 'transactional', 'verification', 'accepted', ?)",
            [self::PREFIX . $suffix, $this->now->modify('-91 days')->format('Y-m-d H:i:s')]
        );
        $this->assertFalse($this->db->error(), 'Test setup: plugin row: ' . $this->db->errorString());
    }

    private function messageCount(string $suffix): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM plg_sendinblue_messages WHERE message_id = ?',
            [self::PREFIX . $suffix]
        )->first()->cnt;
    }

    private function makeJob(?DatabaseInterface $ruleDb = null): RetentionPurgeJob
    {
        return new RetentionPurgeJob(
            $this->db,
            RetentionPolicy::rules($ruleDb ?? $this->db),
            $this->now,
            new HealthcheckPinger($this->transport)
        );
    }

    /** @return array{last_run_at: ?string, last_failure_at: ?string} */
    private function rowState(): array
    {
        $row = $this->db->query(
            'SELECT last_run_at, last_failure_at FROM er_cron_job_runs WHERE job_name = ?',
            [self::JOB_NAME]
        )->first();

        return [
            'last_run_at' => $row->last_run_at ? (string) $row->last_run_at : null,
            'last_failure_at' => $row->last_failure_at ? (string) $row->last_failure_at : null,
        ];
    }

    /** Count log rows that this test wrote, by category and note pattern. */
    private function newLogCount(string $logtype, string $notePattern): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS cnt FROM logs WHERE id > ? AND logtype = ? AND lognote LIKE ?',
            [$this->logFloor, $logtype, $notePattern]
        )->first()->cnt;
    }

    private function assertOneSuccessPing(): void
    {
        $this->assertSame(
            ['https://hc-ping.com/testkey/retention-purge-test'],
            $this->transport->urls,
            'One success ping and no /fail ping'
        );
    }

    public function testRunWithTheSwitchOffPurgesAndStampsTheRow(): void
    {
        $this->seedOldMessage('off');
        $this->setSwitch(false);

        $this->makeJob()->run();

        $this->assertSame(0, $this->messageCount('off'), 'The job must purge with the switch off');
        $state = $this->rowState();
        $this->assertNotSame(self::OLD_RUN, $state['last_run_at'], 'The claim must stamp last_run_at');
        $this->assertNull($state['last_failure_at']);
        $this->assertOneSuccessPing();
        $this->assertSame(
            1,
            $this->newLogCount(LogCategories::LOG_CATEGORY_RETENTION_PURGE, "Retention rule 'sendinblue_messages':%deleted%")
        );
        $this->assertSame(0, $this->newLogCount(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, '%'));
    }

    public function testRunWithTheSwitchOnPurgesToo(): void
    {
        $this->seedOldMessage('on');
        $this->setSwitch(true);

        $this->makeJob()->run();

        $this->assertSame(0, $this->messageCount('on'));
        $this->assertNull($this->rowState()['last_failure_at']);
        $this->assertOneSuccessPing();
    }

    public function testAMissingTableLogsOneSkipLineAndIsNotAFailure(): void
    {
        $this->db->query('RENAME TABLE plg_sendinblue_messages TO ret2384job_plg_sendinblue_messages_away');
        $this->assertFalse($this->db->error(), 'Test setup: rename: ' . $this->db->errorString());

        try {
            $this->makeJob()->run();
        } finally {
            $this->db->query('RENAME TABLE ret2384job_plg_sendinblue_messages_away TO plg_sendinblue_messages');
            $this->assertFalse($this->db->error(), 'Test cleanup: the table must come back: ' . $this->db->errorString());
        }

        $this->assertSame(
            1,
            $this->newLogCount(LogCategories::LOG_CATEGORY_RETENTION_PURGE, "Retention rule 'sendinblue_messages' skipped:%")
        );
        $this->assertSame(
            1,
            $this->newLogCount(LogCategories::LOG_CATEGORY_RETENTION_PURGE, "Retention rule 'deleted_user_addresses_backlog': 0 deleted, 0 cleared.")
        );
        $this->assertSame(2, $this->newLogCount(LogCategories::LOG_CATEGORY_RETENTION_PURGE, '%'), 'The skip line and the backlog line only');
        $state = $this->rowState();
        $this->assertNotSame(self::OLD_RUN, $state['last_run_at']);
        $this->assertNull($state['last_failure_at']);
        $this->assertOneSuccessPing();
        $this->assertSame(0, $this->newLogCount(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, '%'));
    }

    public function testAnotherDatabaseErrorStampsTheFailureAndPingsFail(): void
    {
        $this->seedOldMessage('error');
        $failing = new class ($this->db) extends PassThroughDatabase {
            public function query(string $sql, array $params = []): static
            {
                return str_contains($sql, 'plg_sendinblue_messages')
                    ? $this->simulateFailure('simulated lock wait timeout')
                    : parent::query($sql, $params);
            }
        };

        $this->makeJob($failing)->run();

        $this->assertSame(1, $this->messageCount('error'), 'A failed rule must delete nothing');
        $this->assertNotNull($this->rowState()['last_failure_at'], 'The run must stamp last_failure_at');
        $this->assertSame(['https://hc-ping.com/testkey/retention-purge-test/fail'], $this->transport->urls);
        $this->assertSame(
            1,
            $this->newLogCount(LogCategories::LOG_CATEGORY_CRON_JOB_FAILURE, "Cron job 'retention_purge' failed:%")
        );
        $this->assertSame(
            1,
            $this->newLogCount(
                LogCategories::LOG_CATEGORY_RETENTION_PURGE,
                "Retention rule 'sendinblue_messages' failed:% (step: delete, SQLSTATE: HY000)"
            ),
            'The line must name the failed step and SQLSTATE of the real rule'
        );
    }

    public function testAStaleHoldingRowFailsTheRunAndTheOtherRuleStillPurges(): void
    {
        $this->seedOldMessage('backlog');
        // MySQL NOW(), as the trigger writes deleted_at with it.
        $this->db->query(
            "INSERT INTO er_deleted_user_addresses (user_id, email, email_new, deleted_at)
             VALUES (?, 'held-2419@example.com', NULL, NOW() - INTERVAL 25 HOUR)",
            [self::HOLDING_USER_ID]
        );
        $this->assertFalse($this->db->error(), 'Test setup: holding row: ' . $this->db->errorString());

        $this->makeJob()->run();

        $this->assertSame(0, $this->messageCount('backlog'), 'The sendinblue rule must still run');
        $this->assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) AS cnt FROM er_deleted_user_addresses WHERE user_id = ?', [self::HOLDING_USER_ID])->first()->cnt,
            'The backlog rule must keep the holding row for the rerun script'
        );
        $this->assertNotNull($this->rowState()['last_failure_at'], 'The run must stamp last_failure_at');
        $this->assertSame(['https://hc-ping.com/testkey/retention-purge-test/fail'], $this->transport->urls);
        $this->assertSame(
            1,
            $this->newLogCount(
                LogCategories::LOG_CATEGORY_RETENTION_PURGE,
                '%user IDs ' . self::HOLDING_USER_ID . '. Run app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php for each user ID.'
            ),
            'The log must name the user ID and the remedy'
        );
        $this->assertSame(
            1,
            $this->newLogCount(
                LogCategories::LOG_CATEGORY_RETENTION_PURGE,
                "Retention rule 'deleted_user_addresses_backlog' failed:% (step: stale holding rows, SQLSTATE: none)"
            )
        );
        $this->assertSame(0, $this->newLogCount(LogCategories::LOG_CATEGORY_RETENTION_PURGE, '%@%'), 'No log line may hold an address');
    }

    public function testADisabledJobPurgesNothing(): void
    {
        $this->seedOldMessage('disabled');
        $this->setRow(false);

        $this->makeJob()->run();

        $this->assertSame(1, $this->messageCount('disabled'));
        $this->assertSame(self::OLD_RUN, $this->rowState()['last_run_at'], 'A disabled job must not claim');
        $this->assertSame([], $this->transport->urls);
    }
}
