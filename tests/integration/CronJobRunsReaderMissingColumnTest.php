<?php

declare(strict_types=1);

require_once __DIR__ . '/database/MigrationSandbox.php';

use ElanRegistry\Cron\CronJobEnabledState;
use ElanRegistry\Cron\CronJobRunsReader;
use ElanRegistry\Database\DbAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * CronJobRunsReader on a host where er_cron_job_runs.last_failure_at does not
 * exist (a deploy before migration 20260922171500).
 *
 * The table is a copy of the shared one in a MigrationSandbox schema, with the
 * column dropped there. The shared schema gets no DDL. The reader runs through
 * a UserSpice DB connection to the sandbox, because the bug depends on that
 * class: it uses emulated prepares, so a missing column comes back through
 * error(), not as a PDOException.
 *
 * Not in the `migration` group: this tests reader code that stays in use after
 * the migration.
 */
#[Group('integration')]
final class CronJobRunsReaderMissingColumnTest extends TestCase
{
    private const JOB_NAME = 'brevo_reconciliation';

    private ?MigrationSandbox $sandbox = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = (new MigrationSandbox())->create();
        $sandboxName = $this->sandbox->name();
        $sharedName = MigrationSandbox::env('DB_NAME');

        $pdo = $this->sandbox->pdo();
        $pdo->exec("CREATE TABLE `{$sandboxName}`.er_cron_job_runs LIKE `{$sharedName}`.er_cron_job_runs");
        $pdo->exec("ALTER TABLE `{$sandboxName}`.er_cron_job_runs DROP COLUMN last_failure_at");
        $pdo->prepare(
            'INSERT INTO er_cron_job_runs (job_name, enabled, last_run_at, created_at) VALUES (?, 1, ?, NOW())'
        )->execute([self::JOB_NAME, '2026-09-01 02:00:00']);
    }

    protected function tearDown(): void
    {
        $this->sandbox?->drop();
        $this->sandbox = null;

        parent::tearDown();
    }

    /**
     * With emulated prepares, DB::query() reports the missing column through
     * error(), not a PDOException; the first fallback assumed a throw and
     * never ran. Only a real connection can show this.
     */
    public function testStatusDegradesGracefullyWhenTheColumnIsGenuinelyAbsent(): void
    {
        $this->assertNotNull($this->sandbox);
        $reader = new CronJobRunsReader(new DbAdapter(DB::getDB($this->sandbox->name())));

        $status = $reader->status(self::JOB_NAME);

        $this->assertSame(
            CronJobEnabledState::ENABLED,
            $status['state'],
            'An absent last_failure_at must NOT blank the row out as UNREADABLE — the tab has to keep'
            . ' rendering every job\'s status, just without failure detection'
        );
        $this->assertInstanceOf(
            DateTimeImmutable::class,
            $status['lastRunAt'],
            'The retry must still return the two original columns'
        );
        $this->assertNull($status['lastFailureAt'], 'An absent column reads as "never failed"');

        $this->assertSame(
            'Ran',
            CronJobRunsReader::badgeFor(
                $status['state'],
                $status['lastRunAt'],
                $status['lastFailureAt']
            )['text'],
            'The badge must degrade to its pre-migration behaviour, not to "Status unavailable"'
        );
    }
}
