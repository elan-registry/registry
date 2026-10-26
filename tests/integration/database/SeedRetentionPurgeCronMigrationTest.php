<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #2384: the `crons` row seeded by migration
 * 20261025000000_seed_retention_purge_cron. Reads the applied
 * schema only; never runs the migration.
 */
#[Group('integration')]
#[Group('migration')]
final class SeedRetentionPurgeCronMigrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'retention_purge';

    private const CRON_FILE = 'retention_purge.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
        $this->requireMigrationApplied();
    }

    private function requireMigrationApplied(): void
    {
        $this->db->query(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = ? LIMIT 1",
            [self::JOB_NAME]
        );
        $row = $this->db->error() ? null : $this->db->first();

        if (!is_object($row)) {
            $this->markTestSkipped(
                'Migration 20261025000000_seed_retention_purge_cron has not been'
                . ' applied — the er_cron_job_runs row for "' . self::JOB_NAME . '" does not exist.'
                . ' Run: composer migrate'
            );
        }
    }

    #[Group('fast')]
    public function testSeededCronsRowFileMatchesTheRealShimUnderUsersCron(): void
    {
        $this->db->query('SELECT file, active FROM crons WHERE file = ?', [self::CRON_FILE]);
        $row = $this->db->first();

        $this->assertIsObject($row, 'The seeded crons row must exist');
        $this->assertSame(self::CRON_FILE, $row->file);
        $this->assertSame(1, (int) $row->active, 'The crons row must be active=1 so the dispatcher includes the shim');

        $this->db->query('SELECT enabled FROM er_cron_job_runs WHERE job_name = ?', [self::JOB_NAME]);
        $jobRow = $this->db->first();
        $this->assertIsObject($jobRow);
        $this->assertSame(1, (int) $jobRow->enabled, 'The privacy purge is seeded on, not paused');

        // cron.php resolves `file` under users/cron/ only, so a wrong value leaves
        // the job silently unrunnable.
        $shimPath = dirname(__DIR__, 3) . '/users/cron/' . self::CRON_FILE;
        $this->assertFileExists(
            $shimPath,
            'The seeded crons.file value must resolve to a real file under users/cron/'
            . ' — cron.php\'s dispatcher can only ever run a job placed there'
        );
    }
}
