<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * #1885: the `crons` row seeded by migration
 * 20260916000001_seed_send_verification_batch_cron. Reads the applied
 * schema only; never runs the migration.
 */
#[Group('integration')]
#[Group('migration')]
final class SeedSendVerificationBatchCronMigrationTest extends IntegrationTestCase
{
    private const JOB_NAME = 'send_verification_batch';

    private const CRON_FILE = 'send_verification_batch.php';

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
                'Migration 20260916000001_seed_send_verification_batch_cron has not been'
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
