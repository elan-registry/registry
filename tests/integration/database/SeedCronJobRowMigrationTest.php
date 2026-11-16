<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #1885, #2384: the `crons` and `er_cron_job_runs` rows that the seed-cron
 * migrations write. Reads the applied schema only; never runs a migration.
 */
#[Group('integration')]
#[Group('migration')]
final class SeedCronJobRowMigrationTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{string, string, string, ?int}>
     */
    public static function seededJobProvider(): array
    {
        return [
            // Seeded paused (enabled=0). An operator turns it on, so its value is not fixed.
            'send_verification_batch (#1885)' => [
                'send_verification_batch',
                'send_verification_batch.php',
                '20260916000001_seed_send_verification_batch_cron',
                null,
            ],
            'retention_purge (#2384)' => [
                'retention_purge',
                'retention_purge.php',
                '20261025000000_seed_retention_purge_cron',
                1,
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();
    }

    private function requireMigrationApplied(string $jobName, string $migration): void
    {
        $this->db->query(
            "SELECT job_name FROM er_cron_job_runs WHERE job_name = ? LIMIT 1",
            [$jobName]
        );
        $row = $this->db->error() ? null : $this->db->first();

        if (!is_object($row)) {
            $this->markTestSkipped(
                "Migration {$migration} has not been"
                . ' applied — the er_cron_job_runs row for "' . $jobName . '" does not exist.'
                . ' Run: composer migrate'
            );
        }
    }

    #[DataProvider('seededJobProvider')]
    #[Group('fast')]
    public function testSeededCronsRowFileMatchesTheRealShimUnderUsersCron(
        string $jobName,
        string $cronFile,
        string $migration,
        ?int $expectedEnabled
    ): void {
        $this->requireMigrationApplied($jobName, $migration);

        $this->db->query('SELECT file, active FROM crons WHERE file = ?', [$cronFile]);
        $row = $this->db->first();

        $this->assertIsObject($row, 'The seeded crons row must exist');
        $this->assertSame($cronFile, $row->file);
        $this->assertSame(1, (int) $row->active, 'The crons row must be active=1 so the dispatcher includes the shim');

        if ($expectedEnabled !== null) {
            $this->db->query('SELECT enabled FROM er_cron_job_runs WHERE job_name = ?', [$jobName]);
            $jobRow = $this->db->first();
            $this->assertIsObject($jobRow);
            $this->assertSame($expectedEnabled, (int) $jobRow->enabled, "{$jobName} must be seeded with enabled = {$expectedEnabled}");
        }

        // cron.php resolves `file` under users/cron/ only, so a wrong value leaves
        // the job silently unrunnable.
        $shimPath = dirname(__DIR__, 3) . '/users/cron/' . $cronFile;
        $this->assertFileExists(
            $shimPath,
            'The seeded crons.file value must resolve to a real file under users/cron/'
            . ' — cron.php\'s dispatcher can only ever run a job placed there'
        );
    }
}
