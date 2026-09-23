<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2086: the verification tab's "Last suppression
 * sync run" row reads BrevoSuppressionSyncJob's `er_cron_job_runs` row via
 * CronJobRunsReader, mirroring the reconciliation status block above it
 * (#2054). Three details of that block are worth pinning independently of
 * the reconciliation block, because a naive edit (or a well-meaning
 * "simplification") could plausibly regress each one without breaking any
 * other test:
 *
 * - The probe must query BrevoSuppressionSyncJob::JOB_NAME specifically —
 *   copy-pasting the reconciliation block without updating the job name
 *   would silently show the wrong job's status.
 * - `$cronJobRunsReader ?? new CronJobRunsReader(dbi())` must reuse the
 *   reader constructed by the reconciliation probe above when available —
 *   reverting to an unconditional `new CronJobRunsReader(dbi())` would
 *   silently double a DB connection on every render of this tab.
 * - The try/catch around this probe is its own fault domain: a failure
 *   reading the suppression-sync row must degrade to
 *   CronJobEnabledState::UNREADABLE and must not blank out the
 *   reconciliation section rendered just above it.
 *
 * This test guards the file-content contract only — it asserts the probe is
 * genuinely wired to the right job, the reader-reuse pattern is intact, and
 * the fault-isolation exists, scoped specifically to the suppression-sync
 * block. It does not assert that CronJobRunsReader::status() or badgeFor()
 * themselves are correct (see CronJobRunsReaderTest / CronJobRunsReaderBadgeTest).
 *
 * Regexes are scoped with a "tempered greedy token" — `(?:(?!<marker>).)*?`
 * — the same technique used in Issue2085RegressionTest, so an assertion
 * intended for the suppression-sync block cannot be satisfied by the
 * sibling reconciliation or auto-send blocks that use near-identical code.
 *
 * @issue 2086
 * @link https://github.com/elan-registry/registry/issues/2086
 * @category regression
 */
#[Group('regression')]
final class Issue2086RegressionTest extends TestCase
{
    private string $targetFile;
    private string $content;

    /**
     * The suppression-sync probe block, isolated from the file so every
     * assertion below is scoped to it and cannot accidentally match the
     * sibling reconciliation or auto-send blocks. Bounded from the
     * "Suppression sync status probe" section comment through (but not
     * including) the following "Automatic-sending status probe" section
     * comment.
     */
    private string $suppressionSyncBlock;

    protected function setUp(): void
    {
        // tests/unit/regression/ is three levels below the project root
        $projectRoot = dirname(__DIR__, 3);
        $this->targetFile = $projectRoot . '/app/admin/includes/tab-verification.php';

        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);
        $this->content = $content;

        $sectionStart = strpos($content, '// Suppression sync status probe.');
        $sectionEnd = strpos($content, '// Automatic-sending status probe (#1885).');

        $this->assertIsInt(
            $sectionStart,
            'Expected to find the "Suppression sync status probe" section comment in '
                . 'tab-verification.php — if this comment was reworded, update this test\'s anchor'
        );
        $this->assertIsInt(
            $sectionEnd,
            'Expected to find the "Automatic-sending status probe (#1885)" section comment '
                . 'in tab-verification.php — if this comment was reworded, update this test\'s anchor'
        );
        $this->assertGreaterThan(
            $sectionStart,
            $sectionEnd,
            'The suppression-sync section must appear before the automatic-sending section'
        );

        $this->suppressionSyncBlock = substr($content, $sectionStart, $sectionEnd - $sectionStart);
    }

    /**
     * The probe must genuinely query BrevoSuppressionSyncJob::JOB_NAME via a
     * CronJobRunsReader ->status() call — not a hardcoded value and not
     * (via copy-paste from the reconciliation block) another job's name.
     */
    public function testSuppressionSyncJobNameIsProbedViaCronJobRunsReader(): void
    {
        $this->assertMatchesRegularExpression(
            '/->status\(\s*BrevoSuppressionSyncJob::JOB_NAME\s*\)/',
            $this->suppressionSyncBlock,
            'The suppression-sync block must call ->status(BrevoSuppressionSyncJob::JOB_NAME) — '
                . 'a hardcoded status or a copy-pasted call to a different job\'s JOB_NAME would '
                . 'silently show the wrong job\'s status in the "Last suppression sync run" row (#2086)'
        );
    }

    /**
     * The row's label must be present verbatim — the AC contract itself,
     * not merely that some probe exists somewhere in the file.
     */
    public function testLastSuppressionSyncRunLabelIsPresent(): void
    {
        $this->assertStringContainsString(
            'Last suppression sync run',
            $this->content,
            'tab-verification.php must render a "Last suppression sync run" row (#2086)'
        );
    }

    /**
     * The badge markup for this row must reference the suppression-sync
     * specific variable names, not merely reuse the reconciliation badge's
     * variables (which would silently show the wrong job's status) and not
     * some other unrelated badge var incidentally present in the file.
     */
    public function testBadgeMarkupReferencesSuppressionSyncVariables(): void
    {
        $labelPos = strpos($this->content, 'Last suppression sync run');
        $this->assertIsInt($labelPos, 'Expected to locate the "Last suppression sync run" label');

        // The badge markup immediately follows the <dt> label in the <dd>.
        $markupWindow = substr($this->content, $labelPos, 800);

        $this->assertMatchesRegularExpression(
            '/\$suppressionSyncBadgeClass/',
            $markupWindow,
            'The badge <span> rendered under "Last suppression sync run" must use '
                . '$suppressionSyncBadgeClass, not a variable belonging to a different probe block (#2086)'
        );
        $this->assertMatchesRegularExpression(
            '/\$suppressionSyncBadgeIcon/',
            $markupWindow,
            'The badge <span> rendered under "Last suppression sync run" must use '
                . '$suppressionSyncBadgeIcon, not a variable belonging to a different probe block (#2086)'
        );
        $this->assertMatchesRegularExpression(
            '/\$suppressionSyncBadgeText/',
            $markupWindow,
            'The badge <span> rendered under "Last suppression sync run" must use '
                . '$suppressionSyncBadgeText, not a variable belonging to a different probe block (#2086)'
        );
    }

    /**
     * `$suppressionSyncReader = $cronJobRunsReader ?? new CronJobRunsReader(dbi())`
     * must reuse the reader constructed by the reconciliation probe above
     * when it succeeded. Reverting this to an unconditional
     * `new CronJobRunsReader(dbi())` would silently open a second DB
     * connection for the same never-throwing reader on every render.
     */
    public function testSuppressionSyncReaderReusesCronJobRunsReaderWhenAvailable(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$suppressionSyncReader\s*=\s*\$cronJobRunsReader\s*\?\?\s*new\s+CronJobRunsReader\(\s*dbi\(\)\s*\)/',
            $this->suppressionSyncBlock,
            '$suppressionSyncReader must be assigned via '
                . '"$cronJobRunsReader ?? new CronJobRunsReader(dbi())" so it reuses the reader '
                . 'already constructed by the reconciliation probe above — an unconditional '
                . '"new CronJobRunsReader(dbi())" here would silently double a DB connection on '
                . 'every render of this tab (#2086)'
        );
    }

    /**
     * The suppression-sync probe must be wrapped in its own try/catch that
     * degrades to CronJobEnabledState::UNREADABLE on failure, scoped to
     * this block specifically — not merely that a try/catch exists
     * somewhere in the file (this file has several near-identical probe
     * blocks). Uses a tempered greedy token bounded by this block's own
     * try{...}catch{...} so the assertion cannot be satisfied by the
     * sibling reconciliation or auto-send catch blocks.
     */
    public function testSuppressionSyncProbeFailureDegradesToUnreadableWithinItsOwnCatchBlock(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$suppressionSyncState\s*=\s*CronJobEnabledState::UNREADABLE;'
                . '(?:(?!\$suppressionSyncBadge\s*=).)*?'
                . 'try\s*\{'
                . '(?:(?!\$suppressionSyncBadge\s*=).)*?'
                . '\}\s*catch\s*\(\s*\\\\Throwable\s*\$e\s*\)\s*\{'
                . '(?:(?!\$suppressionSyncBadge\s*=).)*?'
                . 'Suppression sync status probe failed'
                . '(?:(?!\$suppressionSyncBadge\s*=).)*?'
                . '\}/s',
            $this->content,
            'The suppression-sync probe must default $suppressionSyncState to '
                . 'CronJobEnabledState::UNREADABLE and be wrapped in its own try/catch (logging '
                . '"Suppression sync status probe failed") distinct from the reconciliation and '
                . 'auto-send probes\' catch blocks — a shared or missing catch here would either '
                . 'blank out the reconciliation section above on failure, or let an exception '
                . 'propagate and fatal the whole admin tab (#2086)'
        );
    }

    /**
     * The "last ran" timestamp must only render when
     * $suppressionSyncLastRunAt is non-null — asserting the conditional
     * exists (not merely that the variable is referenced anywhere) so a
     * regression that always renders the timestamp line (even as an empty
     * string) would be caught.
     */
    public function testLastRunTimestampIsGatedOnNonNullCheck(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$suppressionSyncLastRunAt\s*!==\s*null\s*\)\s*\{\s*\?>/',
            $this->content,
            'The "last ran" timestamp under "Last suppression sync run" must be gated on '
                . '$suppressionSyncLastRunAt !== null — rendering it unconditionally would break '
                . 'when the job has never run and $suppressionSyncLastRunAt is null (#2086)'
        );
    }
}
