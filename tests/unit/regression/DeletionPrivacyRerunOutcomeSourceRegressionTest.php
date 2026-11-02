<?php

declare(strict_types=1);

use ElanRegistry\AccountDeletionPrivacyOutcome;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 30-Rerun-Deletion-Privacy-Step.php must handle each privacy step outcome.
 *
 * This test scans the source of the page. It fails when:
 *
 * - The page has no arm for `AccountDeletionPrivacyOutcome::CommitUnknown`,
 *   or that arm does not log "transaction state unknown" under
 *   `LOG_CATEGORY_FIX_SCRIPT_ERROR`.
 * - The "Committed" result is in a bare `else`. A new outcome would then show
 *   "Committed" for a run that did not commit.
 * - An outcome case has no arm.
 *
 * The integration test RerunDeletionPrivacyStepScriptTest runs the real page
 * with the real step, so it cannot make the commit throw. That is why this
 * test reads the source.
 *
 * Every checker has a negative control: an in-memory string that holds the
 * defect. The control asserts that the checker flags it.
 *
 * @issue 2515
 * @link https://github.com/elan-registry/registry/issues/2515
 */
#[Group('regression')]
final class DeletionPrivacyRerunOutcomeSourceRegressionTest extends TestCase
{
    private const SCRIPT = 'app/admin/scripts/maintenance/30-Rerun-Deletion-Privacy-Step.php';

    private const COMMITTED_LOG = 'Deletion privacy step rerun committed';

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
     * Return the body of the CommitUnknown arm, or null when there is none.
     */
    private static function commitUnknownArm(string $code): ?string
    {
        $found = preg_match(
            '/elseif \(\$result->outcome === AccountDeletionPrivacyOutcome::CommitUnknown\) \{(.*?)\}\s*elseif/s',
            $code,
            $matches
        );

        return $found === 1 ? $matches[1] : null;
    }

    /**
     * Return the branch head that opens the arm with the Committed log line.
     */
    private static function committedArmHead(string $code): ?string
    {
        $position = strpos($code, self::COMMITTED_LOG);
        if ($position === false) {
            return null;
        }
        $before = substr($code, 0, $position);
        if (preg_match_all('/\}\s*else(?:if\s*\((?:[^()]|\([^()]*\))*\))?\s*\{/', $before, $matches) === 0) {
            return null;
        }

        return (string) preg_replace('/\s+/', ' ', end($matches[0]));
    }

    /**
     * Tell whether each bare `else` in the code throws a LogicException.
     */
    private static function everyBareElseThrows(string $code): bool
    {
        $bare = preg_match_all('/\}\s*else\s*\{/', $code);
        // Statements may come before the throw (for example a log line), but
        // no block may open: the throw must be in the else block itself.
        $throwing = preg_match_all('/\}\s*else\s*\{[^{}]*?throw new \\\\LogicException\(/', $code);

        return $bare === $throwing;
    }

    public function testCommitUnknownArmLogsTransactionStateUnknownAsAFixScriptError(): void
    {
        $arm = self::commitUnknownArm($this->code());

        $this->assertNotNull($arm, 'The page must have an arm for AccountDeletionPrivacyOutcome::CommitUnknown.');
        $this->assertMatchesRegularExpression(
            '/logger\(\$adminId, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,\s*"[^"]*transaction state unknown/',
            $arm,
            'The CommitUnknown arm must log "transaction state unknown" under LOG_CATEGORY_FIX_SCRIPT_ERROR.'
        );
    }

    public function testCommittedResultIsInItsOwnArmNotABareElse(): void
    {
        $this->assertSame(
            '} elseif ($result->outcome === AccountDeletionPrivacyOutcome::Committed) {',
            self::committedArmHead($this->code())
        );
    }

    public function testAnyBareElseThrowsALogicException(): void
    {
        $this->assertTrue(
            self::everyBareElseThrows($this->code()),
            'A bare else must throw a LogicException, so a new outcome never shows a result.'
        );
    }

    public function testEveryOutcomeHasAnArm(): void
    {
        $code = $this->code();
        foreach (AccountDeletionPrivacyOutcome::cases() as $outcome) {
            $this->assertStringContainsString(
                '$result->outcome === AccountDeletionPrivacyOutcome::' . $outcome->name . ')',
                $code,
                "The page has no arm for AccountDeletionPrivacyOutcome::{$outcome->name}."
            );
        }
    }

    // --- Negative controls ------------------------------------------------

    public function testNegativeControlMissingCommitUnknownArmIsFound(): void
    {
        $code = 'if ($result->outcome === AccountDeletionPrivacyOutcome::Refused) { x(); }'
            . ' elseif ($result->outcome === AccountDeletionPrivacyOutcome::RolledBack) { y(); } else { z(); }';

        $this->assertNull(self::commitUnknownArm($code));
    }

    public function testNegativeControlBareElseForCommittedIsFound(): void
    {
        $code = '} elseif ($result->outcome === AccountDeletionPrivacyOutcome::RolledBack) { y(); '
            . '} else { logger($adminId, 1, sprintf(\'' . self::COMMITTED_LOG . ' for user %d\', 1)); }';

        $this->assertSame('} else {', self::committedArmHead($code));
    }

    public function testNegativeControlBareElseWithoutThrowIsFound(): void
    {
        $this->assertFalse(self::everyBareElseThrows('if ($a) { x(); } else { y(); }'));
        $this->assertTrue(self::everyBareElseThrows('if ($a) { x(); } else { throw new \LogicException(\'no arm\'); }'));
        $this->assertTrue(self::everyBareElseThrows('if ($a) { x(); } else { logger(1, 2, sprintf(\'%s\', 3)); throw new \LogicException(\'no arm\'); }'));
        $this->assertFalse(self::everyBareElseThrows('if ($a) { x(); } else { y(); } if ($b) { throw new \LogicException(\'later\'); }'));
    }
}
