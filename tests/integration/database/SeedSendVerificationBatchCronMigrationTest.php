<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\Group;

/**
 * Integration test for the `crons` row seeded by migration
 * 20260916000001_seed_send_verification_batch_cron (#1885).
 *
 * This is the only test that proves the seeded `crons.file` value names a
 * real file under users/cron/. cron.php's dispatcher resolves a job's `file`
 * column against that directory only, so a seeded value that does not match
 * the shim on disk would leave the job silently unrunnable. The seeded
 * er_cron_job_runs row is exercised separately by
 * CronJobGuardIntegrationTest::testClaimRoundTripAgainstSeededSendVerificationBatchRow().
 *
 * Reads the already-applied state of the test schema only — it never runs
 * the migration or alters the schema.
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

    /**
     * Skip unless migration 20260916000001 has been applied to the test schema.
     */
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

        // cron.php's dispatcher resolves `file` under users/cron/ only (see
        // this repo's CLAUDE.md "cron.php's dispatcher hard-codes that
        // directory as the only path it will resolve a job's file column
        // against") — prove the seeded value actually names a file that
        // exists there, not merely a plausible-looking string.
        $shimPath = dirname(__DIR__, 3) . '/users/cron/' . self::CRON_FILE;
        $this->assertFileExists(
            $shimPath,
            'The seeded crons.file value must resolve to a real file under users/cron/'
            . ' — cron.php\'s dispatcher can only ever run a job placed there'
        );
    }
}
