<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source test for the order of steps in usersc/scripts/after_user_deletion.php (#2358, #2419).
 *
 * The hook must call the privacy step first, so that no early return keeps
 * personal data. The integration test UserDeletionPrivacyStepTest runs the
 * findByOwner, no-session, no-noowner and live-user paths. No runtime test
 * forces the noowner lookup query to fail, and no runtime result shows whether
 * the step ran before or after the car cleanup transaction. So this test reads
 * the code text, with comments removed, and checks that the step call comes
 * before the noowner lookup and before the car cleanup.
 * AccountDeletionPrivacyStepTest covers the step behavior.
 */
#[Group('fast')]
final class AfterUserDeletionWiringTest extends TestCase
{
    private const HOOK = 'usersc/scripts/after_user_deletion.php';

    private string $code;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . self::HOOK);
        $this->assertIsString($source);

        // Comments are removed so that a word in a comment cannot satisfy or break an order check.
        $this->code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $this->code .= $token[1];
            } else {
                $this->code .= $token;
            }
        }
    }

    /**
     * Position of the first match of $needle, or a failed assertion.
     */
    private function posOf(string $code, string $needle): int
    {
        $pos = strpos($code, $needle);
        $this->assertNotFalse($pos, "The hook must contain: {$needle}");

        return $pos;
    }

    /** The hook calls the step once, and this call is the hook's only privacy work. */
    public function testHookCallsThePrivacyStep(): void
    {
        $this->assertSame(
            1,
            preg_match_all('/\(new AccountDeletionPrivacyStep\(\$db, \$repo\)\)->run\(\$id\);/', $this->code),
            'The hook must call (new AccountDeletionPrivacyStep($db, $repo))->run($id) once.'
        );
    }

    /** The call comes before the noowner lookup early return and before the car cleanup. */
    public function testPrivacyStepRunsBeforeTheNoownerLookupAndTheCarCleanup(): void
    {
        $call = $this->posOf($this->code, '(new AccountDeletionPrivacyStep(');

        $this->assertLessThan(
            $this->posOf($this->code, "'SELECT id FROM users WHERE username = ?'"),
            $call,
            'The privacy step must run before the noowner lookup early return.'
        );
        $this->assertLessThan(
            $this->posOf($this->code, '$inTransaction(function'),
            $call,
            'The privacy step must run before the car cleanup transaction.'
        );
    }
}
