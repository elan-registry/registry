<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #1974: two bugs found and fixed by manually
 * hitting the real users/cron/cron.php against local MAMP during this
 * issue's own implementation — neither was caught by any unit or
 * integration test, since both are about cron.php's real execution
 * environment/control flow, which no test in this suite exercises directly.
 *
 * Bug 1: `new VerificationSettings($db)` fataled with a TypeError — cron.php's
 * `$db` global is a plain `\DB` instance, not the `DatabaseInterface` the
 * constructor requires. Every other production caller (app/admin/index.php,
 * tab-verification.php) uses `dbi()`, the established memoized
 * DatabaseInterface adapter. This means the fix (removing the unconditional
 * per-hit log line) would have made cron.php fatal on every single hit.
 *
 * Bug 2: the `recordCronRequest()` call was originally placed BEFORE the
 * `cron_ip` denial check, so a denied hit would still record a "successful"
 * cron request — contradicting VerificationSettings::lastCronRequestAt()'s
 * own documented guarantee that no write path exists from a denied hit.
 *
 * @issue 1974
 * @link https://github.com/elan-registry/registry/issues/1974
 * @category regression
 */
#[Group('regression')]
final class Issue1974RegressionTest extends TestCase
{
    private string $targetFile;

    protected function setUp(): void
    {
        // tests/unit/regression/ is three levels below the project root
        $projectRoot = dirname(__DIR__, 3);
        $this->targetFile = $projectRoot . '/users/cron/cron.php';
    }

    /**
     * cron.php must construct VerificationSettings with dbi(), not the raw
     * $db global — the raw \DB instance does not satisfy the
     * DatabaseInterface-typed constructor and fatals with a TypeError.
     */
    public function testVerificationSettingsIsConstructedWithDbiNotRawDb(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);

        $this->assertStringContainsString(
            'new \ElanRegistry\Car\VerificationSettings(dbi())',
            $content,
            'cron.php must construct VerificationSettings with dbi() — passing the raw $db global '
                . '(a plain \DB instance) fatals with a TypeError, since the constructor requires '
                . 'DatabaseInterface (#1974)'
        );
    }

    /**
     * recordCronRequest() must be called strictly after the cron_ip denial
     * check's die statement, never before it — a denied hit must never
     * record a "successful" cron request.
     */
    public function testRecordCronRequestIsCalledAfterDenialCheck(): void
    {
        $this->assertFileExists($this->targetFile);
        $content = file_get_contents($this->targetFile);
        $this->assertIsString($content);

        // Anchored on the DENIED log line itself, not a bare "die;" — that
        // string is unique to the denial branch and is the one thing #1974
        // deliberately kept, so it can't drift if an unrelated die() is added
        // elsewhere in the file. A bare "die;" anchor would pass vacuously if
        // an earlier die() were ever added above the cron_ip block.
        $denialPos = strpos($content, 'Cron request DENIED');
        $recordCallPos = strpos($content, 'recordCronRequest()');

        $this->assertNotFalse($denialPos, 'cron_ip denial check\'s DENIED log line not found in cron.php');
        $this->assertNotFalse($recordCallPos, 'recordCronRequest() call not found in cron.php');
        $this->assertSame(
            1,
            substr_count($content, 'recordCronRequest()'),
            'recordCronRequest() must be called exactly once in cron.php — a second call site could '
                . 'hide a regression behind the first, already-correct one'
        );
        $this->assertGreaterThan(
            $denialPos,
            $recordCallPos,
            'recordCronRequest() must be called after the cron_ip denial check\'s DENIED log line — '
                . 'calling it earlier records a cron request even for hits the allowlist rejects (#1974)'
        );
    }
}
