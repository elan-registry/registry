<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2054: the verification tab's "Last
 * reconciliation run" row previously rendered the static placeholder
 * "Not yet implemented (#1889)" regardless of the reconciliation cron job's
 * real status. The fix wires in CronJobRunsReader to read the job's actual
 * `er_cron_job_runs` row and render a status badge from it.
 *
 * This test guards the file-content contract only — it asserts the
 * placeholder string is gone and the reader class is actually referenced,
 * not that the badge logic itself is correct. The state/timestamp → badge
 * mapping itself is covered by CronJobRunsReaderBadgeTest, and the
 * state/lastRunAt read behind it by CronJobRunsReaderTest.
 *
 * @issue 2054
 * @link https://github.com/elan-registry/registry/issues/2054
 * @category regression
 */
#[Group('regression')]
final class Issue2054RegressionTest extends TestCase
{
    private string $targetFile;

    protected function setUp(): void
    {
        // tests/unit/regression/ is three levels below the project root
        $projectRoot = dirname(__DIR__, 3);
        $this->targetFile = $projectRoot . '/app/admin/includes/tab-verification.php';
    }

    /**
     * The placeholder text must be gone — leaving it in place (even
     * alongside working reconciliation status logic) would mean the tab
     * still misrepresents reconciliation as unimplemented.
     */
    public function testPlaceholderTextIsAbsent(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);

        $this->assertStringNotContainsString(
            'Not yet implemented (#1889)',
            $content,
            'tab-verification.php must no longer render the reconciliation placeholder text — '
                . 'the reconciliation status is now read from CronJobRunsReader (#2054)'
        );
    }

    /**
     * The fix must actually be wired in, not just have the placeholder text
     * removed — CronJobRunsReader must be referenced so the reconciliation
     * row reflects a real read of er_cron_job_runs.
     */
    public function testCronJobRunsReaderIsReferenced(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);

        $this->assertStringContainsString(
            'CronJobRunsReader',
            $content,
            'tab-verification.php must reference CronJobRunsReader to source the reconciliation '
                . 'status row — removing the placeholder text alone would leave the row unimplemented (#2054)'
        );
    }
}
