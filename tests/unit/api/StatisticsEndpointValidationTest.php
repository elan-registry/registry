<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Source-level pins for the `tab` parameter validation in
 * app/api/shared/statistics.php.
 *
 * The endpoint requires users/init.php and exits via ApiResponse::send(), so
 * it cannot be executed inside PHPUnit. These tests assert, against the
 * comment-stripped live source, that:
 *
 *  - an empty/missing `tab` is rejected with a 400 before the switch; and
 *  - the `switch ($tab)` has a default branch that rejects unknown tabs
 *    with a 400, alongside the four known tab cases.
 *
 * The data layer behind the endpoint is covered against a real database by
 * tests/integration/StatisticsApiTest.php.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('api')]
final class StatisticsEndpointValidationTest extends TestCase
{
    private const ENDPOINT_PATH = __DIR__ . '/../../../app/api/shared/statistics.php';

    private const VALID_TABS = ['geographic', 'production', 'colors', 'quality'];

    /**
     * Endpoint source with comments and docblocks removed, so a commented-out
     * guard cannot satisfy the assertions.
     */
    private function endpointCode(): string
    {
        $source = file_get_contents(self::ENDPOINT_PATH);
        $this->assertIsString($source, 'app/api/shared/statistics.php must be readable');

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
     * A missing/empty tab must be rejected with 400 (and sent) before dispatch.
     */
    public function testEmptyTabIsRejectedWith400BeforeSwitch(): void
    {
        $code = $this->endpointCode();

        $matched = preg_match(
            '/if\s*\(\s*empty\(\s*\$tab\s*\)\s*\)\s*\{\s*ApiResponse::error\(\s*[\'"][^\'"]+[\'"]\s*,\s*400\s*\)[^;]*->send\(\);/',
            $code,
            $match,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(
            1,
            $matched,
            'statistics.php must reject an empty/missing tab with ApiResponse::error(..., 400)->send() inside if (empty($tab))'
        );

        $switchPos = strpos($code, 'switch ($tab)');
        $this->assertNotFalse($switchPos, 'statistics.php must dispatch on switch ($tab)');
        $this->assertLessThan(
            $switchPos,
            $match[0][1],
            'The empty-tab 400 guard must run before switch ($tab)'
        );
    }

    /**
     * An unknown tab must fall through to a default branch that sends a 400.
     */
    public function testSwitchDefaultRejectsUnknownTabWith400(): void
    {
        $code = $this->endpointCode();

        $this->assertMatchesRegularExpression(
            '/switch\s*\(\s*\$tab\s*\)\s*\{.*default\s*:\s*ApiResponse::error\(\s*[\'"][^\'"]+[\'"]\s*,\s*400\s*\)[^;]*->send\(\);/s',
            $code,
            'switch ($tab) must have a default branch that sends ApiResponse::error(..., 400) for unknown tabs'
        );
    }

    /**
     * Each of the four valid tabs must have its own case.
     */
    public function testSwitchHandlesEveryKnownTab(): void
    {
        $code = $this->endpointCode();

        foreach (self::VALID_TABS as $tab) {
            $this->assertStringContainsString("case '{$tab}':", $code, "switch (\$tab) must handle tab '{$tab}'");
        }
    }
}
