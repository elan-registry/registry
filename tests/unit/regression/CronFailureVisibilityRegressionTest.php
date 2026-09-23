<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the verification tab's cron-failure visibility wiring.
 *
 * The underlying behaviour is unit-tested where it lives —
 * CronJobRunsReaderBadgeTest covers every branch of the failure badge,
 * CronJobFailureLogReaderTest covers the log summary, and
 * CronJobFailureRoundTripTest proves the write-then-read path against real
 * MySQL. What none of those can catch is the template silently ceasing to
 * *use* them: badgeFor()'s third argument is optional (deliberately, so
 * pre-existing callers keep working), so a call that drops it still compiles,
 * still passes every unit test, and quietly restores the exact bug this work
 * removed — a crashed job rendering the green "Ran" badge. Likewise the log
 * summary could be probed and then never rendered.
 *
 * So this guards the file-content contract only, in the same narrow spirit as
 * Issue2085RegressionTest for the row beside it: that each probe is actually
 * passed through to the render, not that the values are correct.
 *
 * @category regression
 * @issue 2148
 * @link https://github.com/elan-registry/registry/issues/2148
 */
#[Group('regression')]
final class CronFailureVisibilityRegressionTest extends TestCase
{
    private string $content;

    protected function setUp(): void
    {
        // tests/unit/regression/ is three levels below the project root.
        $targetFile = dirname(__DIR__, 3) . '/app/admin/includes/tab-verification.php';

        $this->assertFileExists($targetFile);
        $content = file_get_contents($targetFile);
        $this->assertIsString($content);
        $this->content = $content;
    }

    /**
     * Every badgeFor() call must pass a failure timestamp. The parameter is
     * optional by design, so dropping it is silent — and a job whose
     * execute() throws nightly would go straight back to advertising a green
     * "Ran" badge with a timestamp from minutes ago.
     *
     * @return array<string, array{string}>
     */
    public static function jobBadgeVariables(): array
    {
        return [
            'reconciliation' => ['reconciliation'],
            'suppression sync' => ['suppressionSync'],
            'automatic sending' => ['autoSend'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('jobBadgeVariables')]
    public function testEachJobsBadgePassesItsFailureTimestamp(string $prefix): void
    {
        $this->assertMatchesRegularExpression(
            '/badgeFor\(\s*\$' . $prefix . 'State\s*,\s*\$' . $prefix . 'LastRunAt\s*,\s*\$'
                . $prefix . 'LastFailureAt\s*(?:,\s*)?\)/s',
            $this->content,
            "The {$prefix} badge must pass its lastFailureAt through to badgeFor() — the third argument"
                . ' is optional, so omitting it silently restores the green "Ran" badge for a crashed run'
        );
    }

    /**
     * Each probe must actually read `lastFailureAt` off status(). A probe that
     * still calls status() but stops reading the key leaves the variable at
     * its null initializer, which reads as "never failed" — passing the
     * assertion above while surfacing nothing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('jobBadgeVariables')]
    public function testEachProbeReadsLastFailureAtFromStatus(string $prefix): void
    {
        $this->assertMatchesRegularExpression(
            '/\$' . $prefix . 'LastFailureAt\s*=\s*\$' . $prefix . 'Status\[\'lastFailureAt\'\]/',
            $this->content,
            "The {$prefix} probe must read lastFailureAt from its status() result"
        );
    }

    /**
     * The compounding half of the bug: the three last_*_count columns are
     * written only at the end of a successful execute(), so when the most
     * recent run threw they are a previous run's numbers displayed next to a
     * fresh "last ran" timestamp — a clean run that never happened. The render
     * must qualify them.
     */
    public function testStaleCountsAreQualifiedWhenTheLastRunFailed(): void
    {
        $this->assertStringContainsString(
            '$autoSendLastRunFailed',
            $this->content,
            'tab-verification.php must derive whether the most recent automatic run failed'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$autoSendLastRunFailed\s*\)\s*\{\s*\?>'
                . '(?:(?!<\?php\s*\}).)*?'
                . 'From an earlier successful run'
                . '(?:(?!<\?php\s*\}).)*?'
                . '<\?php\s*\}/s',
            $this->content,
            'The "Last run results" counts must carry an explicit note, inside the $autoSendLastRunFailed'
                . ' branch, saying they predate the failed run — unqualified they assert a clean run that'
                . ' did not happen'
        );
    }

    /**
     * LOG_CATEGORY_CRON_JOB_FAILURE was written from five places in this
     * subsystem and read from none, while several of its own messages told the
     * operator to "check the system log". The summary must be both probed and
     * rendered.
     */
    public function testCronFailureLogSummaryIsProbedAndRendered(): void
    {
        $this->assertStringContainsString(
            'recentFailures()',
            $this->content,
            'tab-verification.php must read the cron failure log via CronJobFailureLogReader'
        );
        $this->assertStringContainsString(
            'Cron job failures',
            $this->content,
            'The summary must be rendered under a labelled row, not merely read'
        );
        $this->assertStringContainsString(
            '$cronFailureCount',
            $this->content,
            'The rendered row must be driven by the probe result'
        );
    }

    /**
     * An unreadable summary must never render as the reassuring zero a healthy
     * read shows — "no failures" is the one reading that tells an operator to
     * stop looking. Asserted as a wiring relationship (the danger badge inside
     * the null branch) rather than a bare string presence, since
     * `text-bg-danger` already appears elsewhere in this file for unrelated
     * badges and a presence check would stay green if this branch were
     * deleted — the vacuous-assertion trap Issue2085RegressionTest documents.
     */
    public function testUnreadableFailureSummaryIsDistinctFromZero(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$cronFailureCount\s*===\s*null\s*\)\s*\{\s*\?>'
                . '(?:(?!<\?php\s*\}\s*else).)*?'
                . 'text-bg-danger'
                . '(?:(?!<\?php\s*\}\s*else).)*?'
                . '<\?php\s*\}\s*else/s',
            $this->content,
            'A null (unreadable) failure count must render a distinct danger badge inside its own branch —'
                . ' collapsing it into the healthy zero would turn a broken log read into a clean bill of health'
        );
    }
}
