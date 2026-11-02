<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 27-Reconcile-Brevo-Events.php must not show "Reconciliation completed"
 * when no poll ran.
 *
 * This test scans the source of the page. It fails when the green success
 * alert is not behind a condition that hides it for a not-configured run, and
 * for a failed pre-flight (`pollFailed` with no page fetched). A partial poll
 * failure keeps the counts.
 *
 * No test renders the result part of the page, because the page runs the
 * real reconciliation job. That is why this test reads the source.
 *
 * Every checker has a negative control: an in-memory string that holds the
 * defect. The control asserts that the checker flags it.
 *
 * @issue 2480
 * @link https://github.com/elan-registry/registry/issues/2480
 */
#[Group('regression')]
final class ReconcileBrevoEventsResultAlertSourceRegressionTest extends TestCase
{
    private const SCRIPT = 'app/admin/scripts/maintenance/27-Reconcile-Brevo-Events.php';

    private const SUCCESS_TEXT = 'Reconciliation completed:';

    private function code(): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::SCRIPT);
        $this->assertNotSame('', $source, 'The script must exist.');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * Return the alternative-syntax branch head nearest before the success text, whitespace-collapsed.
     */
    private static function successBranchHead(string $code): ?string
    {
        $position = strpos($code, self::SUCCESS_TEXT);
        if ($position === false) {
            return null;
        }
        $before = substr($code, 0, $position);
        if (preg_match_all('/<\?php\s+(?:if|elseif|else)\b[^?]*?\?>/', $before, $matches) === 0) {
            return null;
        }

        return (string) preg_replace('/\s+/', ' ', end($matches[0]));
    }

    /**
     * Tell whether a branch head shows the success alert only when the run polled at least one page.
     */
    private static function hidesSuccessWhenNoPollRan(string $head): bool
    {
        $compact = (string) preg_replace('/\s+/', '', $head);

        return $compact === '<?phpelseif(!($summary->pollFailed&&$summary->pagesFetched===0)):?>';
    }

    /**
     * Tell whether the branch head is the else arm of the not-configured check.
     */
    private static function followsNotConfiguredArm(string $code): bool
    {
        return preg_match(
            '/<\?php\s+if\s*\(\$summary->notConfigured\)\s*:\s*\?>(?:(?!<\?php\s+(?:if|endif)\b).)*?<\?php\s+elseif\s*\(!\(\$summary->pollFailed/s',
            $code
        ) === 1;
    }

    public function testSuccessAlertIsHiddenWhenAPreFlightFailureFetchedNoPage(): void
    {
        $head = self::successBranchHead($this->code());

        $this->assertNotNull($head, 'The page must show the "' . self::SUCCESS_TEXT . '" alert behind a branch.');
        $this->assertTrue(
            self::hidesSuccessWhenNoPollRan($head),
            "The success alert must be behind elseif (!(\$summary->pollFailed && \$summary->pagesFetched === 0)). Found: {$head}"
        );
    }

    public function testSuccessAlertIsTheElseArmOfTheNotConfiguredCheck(): void
    {
        $this->assertTrue(
            self::followsNotConfiguredArm($this->code()),
            'The success alert branch must follow the $summary->notConfigured arm, so a not-configured run shows no success.'
        );
    }

    // --- Negative controls ------------------------------------------------

    public function testNegativeControlPlainElseIsFound(): void
    {
        $code = '<?php if ($summary->notConfigured): ?>x<?php else: ?><div>' . self::SUCCESS_TEXT . '</div><?php endif; ?>';

        $head = self::successBranchHead($code);
        $this->assertSame('<?php else: ?>', $head);
        $this->assertFalse(self::hidesSuccessWhenNoPollRan((string) $head));
    }

    public function testNegativeControlPollFailedOnlyIsFound(): void
    {
        $this->assertFalse(self::hidesSuccessWhenNoPollRan('<?php elseif (!$summary->pollFailed): ?>'));
    }

    public function testNegativeControlSeparateIfIsFound(): void
    {
        $code = '<?php if ($summary->notConfigured): ?>x<?php endif; ?>'
            . '<?php if (true): ?>y<?php elseif (!($summary->pollFailed && $summary->pagesFetched === 0)): ?>';

        $this->assertFalse(self::followsNotConfiguredArm($code));
    }
}
