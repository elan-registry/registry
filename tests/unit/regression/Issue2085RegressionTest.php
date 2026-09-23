<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2085 (AC1): the verification tab's "Unmatched
 * recipients" row must genuinely reflect
 * VerificationSettings::unmatchedRecipientCount() — a live probe of Brevo
 * webhook events, nightly reconciliation, and suppression-sync signals whose
 * recipient matched no car — rather than a static or hardcoded value, and
 * its badge must render a warning (not a false "all clear" success) whenever
 * that count is above zero.
 *
 * This test guards the file-content contract only — it asserts the probe is
 * actually wired in and the exact threshold comparison used to color the
 * badge, not that VerificationSettings::unmatchedRecipientCount() itself
 * returns a correct count (that belongs to VerificationSettingsTest) or that
 * a null/unreadable read renders correctly (a template-only branch not
 * covered by a dedicated unit elsewhere; see testUnreadableStateIsHandled()
 * below for the narrow content-assertion this test adds for it).
 *
 * The badge-class ternary here is inline in the PHP template, not extracted
 * into a standalone helper the way CronJobRunsReader::badgeFor() was for the
 * reconciliation-status row (#2054, see CronJobRunsReaderBadgeTest) — so
 * unlike that row, this test can only pin the threshold logic by asserting on
 * the literal comparison operator present in the source. This is deliberately
 * narrow: it would not catch every possible mistake, but it does catch the
 * two failure modes the PR review flagged as unguarded — the ternary being
 * inverted (success or warning swapped) or the threshold being changed from
 * a strict boundary at zero (e.g. to `>= 0` or `< 0`), either of which would
 * silently defeat "the entire operational value of the feature": a rising
 * count no longer visibly turning the badge amber.
 *
 * @issue 2085
 * @link https://github.com/elan-registry/registry/issues/2085
 * @category regression
 */
#[Group('regression')]
final class Issue2085RegressionTest extends TestCase
{
    private string $targetFile;
    private string $content;

    protected function setUp(): void
    {
        // tests/unit/regression/ is three levels below the project root
        $projectRoot = dirname(__DIR__, 3);
        $this->targetFile = $projectRoot . '/app/admin/includes/tab-verification.php';

        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);
        $this->content = $content;
    }

    /**
     * The row must be backed by a real read of
     * VerificationSettings::unmatchedRecipientCount() — a hardcoded value or
     * a probe that was quietly dropped would leave the row looking wired
     * without actually reflecting live data.
     */
    public function testUnmatchedRecipientCountProbeIsReferenced(): void
    {
        $this->assertStringContainsString(
            'unmatchedRecipientCount()',
            $this->content,
            'tab-verification.php must call VerificationSettings::unmatchedRecipientCount() to '
                . 'source the "Unmatched recipients" row (#2085 AC1) — a hardcoded or removed '
                . 'probe would misrepresent live Brevo signal drift as always healthy'
        );
    }

    /**
     * The row's label must be present verbatim — this is the AC1 UI contract
     * itself, not merely that some probe exists somewhere in the file.
     */
    public function testUnmatchedRecipientsLabelIsPresent(): void
    {
        $this->assertStringContainsString(
            'Unmatched recipients',
            $this->content,
            'tab-verification.php must render an "Unmatched recipients" row (#2085 AC1)'
        );
    }

    /**
     * Pins the exact badge-coloring threshold: a positive count must render
     * the warning badge class, and only a positive count. This is an inline
     * template comparison (no extracted, independently unit-testable helper
     * exists for it — see class docblock), so the only way to guard against
     * an inverted or off-by-one threshold is to assert on the literal
     * comparison text.
     *
     * Regexp (not a plain substring) so the assertion survives incidental
     * reformatting (e.g. added whitespace around the ternary) while still
     * failing if `>` is swapped for `>=`, `<`, or the branches are reversed.
     */
    public function testUnmatchedCountBadgeThresholdIsStrictlyGreaterThanZero(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$vsUnmatchedCount\s*>\s*0\s*\?\s*\'text-bg-warning\'\s*:\s*\'text-bg-success\'/',
            $this->content,
            'The "Unmatched recipients" badge must use a strict > 0 comparison mapping to '
                . 'text-bg-warning (and 0 to text-bg-success) — an inverted ternary, a >= 0 or '
                . '< 0 threshold, or a swapped badge class would silently defeat the entire '
                . 'operational value of this counter: a rising count no longer visibly warning '
                . 'the admin (#2085 PR review finding)'
        );
    }

    /**
     * A failed/unreadable read must not silently render as the healthy "0,
     * success" state — that would hide the exact fault condition (query
     * error, missing row, malformed stored value) this counter exists to
     * surface.
     *
     * A bare `assertStringContainsString('text-bg-danger', ...)` is not
     * sufficient here: that string already appears elsewhere in this file for
     * unrelated badges (Brevo-not-configured, auto-send counts unavailable),
     * so such an assertion would keep passing even if this row's own danger
     * branch were deleted or replaced with a green zero — precisely the
     * regression this test exists to catch (found in PR review: the original
     * version of this test was vacuous for exactly that reason). Instead this
     * asserts the danger badge appears specifically inside an
     * `if ($vsUnmatchedUnreadable)` conditional block, tying the wiring
     * (variable to badge class) together rather than just the wording.
     */
    public function testUnreadableStateIsHandledDistinctlyFromZero(): void
    {
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$vsUnmatchedUnreadable\s*\)\s*\{\s*\?>'
                . '(?:(?!<\?php\s*\}\s*else).)*?'
                . 'text-bg-danger'
                . '(?:(?!<\?php\s*\}\s*else).)*?'
                . '<\?php\s*\}\s*else/s',
            $this->content,
            'tab-verification.php must render a distinct (danger) badge specifically inside the '
                . '$vsUnmatchedUnreadable branch when the unmatched-recipient counter could not be '
                . 'read — a bare string-presence check on "text-bg-danger" is not enough, since that '
                . 'class already exists elsewhere in this file for unrelated badges; collapsing '
                . '"unreadable" into the same success badge as a genuine zero would hide probe '
                . 'failures from admins'
        );
    }
}
