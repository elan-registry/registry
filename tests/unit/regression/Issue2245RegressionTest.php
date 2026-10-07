<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2245: pin the `BrevoDevOverride::hostOverride()`
 * call in each cron Brevo client.
 *
 * Both {@see \ElanRegistry\Cron\BrevoEventReconciliationClient} and
 * {@see \ElanRegistry\Cron\BrevoSuppressionSyncClient} must call
 * `BrevoDevOverride::hostOverride()` while they build the SDK
 * `Configuration`, apply the returned host with `setHost()`, and do this
 * before the SDK API client is constructed. Without that call, a local cron
 * run under `US_ENVIRONMENT=development` would build a `Configuration` that
 * always points at the real Brevo API, and mock-brevo would never see the
 * request (see `docs/development/EMAIL_SYSTEM.md`, "What routes to
 * mock-brevo today").
 *
 * Source inspection, not a behavioral test: the Brevo SDK ships inside the
 * gitignored sendinblue plugin (`usersc/plugins/sendinblue/vendor/`) and is
 * not on the unit suite's autoloader (see phpunit-unit.xml /
 * tests/bootstrap-unit.php, and the note at the foot of
 * BrevoEventReconciliationClientTest.php). A test that builds a real SDK
 * `Configuration` and reads its host back is not possible here without that
 * autoloader present. This test instead reads each client's own source,
 * strips comments with `token_get_all()` so a commented-out or
 * documented-only call cannot pass, isolates the method body that builds the
 * Configuration with `ReflectionMethod`, and asserts the call inside it —
 * the same technique tests/unit/cars/CarActionsSaveWiringTest.php uses for
 * source-level invariants that cannot be exercised directly.
 *
 * A prior version of this test searched the raw file text, so a call moved
 * into a comment, an `if (false && ...)` guard, or a docblock still made the
 * assertions pass. See `assertMethodBodyCallsBrevoDevOverride()` and its
 * mutation tests below.
 *
 * @issue 2245
 * @link https://github.com/elan-registry/registry/issues/2245
 * @category regression
 */
#[Group('regression')]
final class Issue2245RegressionTest extends TestCase
{
    private const SDK_API_CLASS_NEEDLE = 'new \Brevo\Client\Api\TransactionalEmailsApi(';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/issue2245_' . bin2hex(random_bytes(8));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    /**
     * @return array<string, array{string, string}> class name => [source
     *         path relative to repo root, method that builds the SDK
     *         Configuration]
     */
    public static function clientProvider(): array
    {
        return [
            'BrevoEventReconciliationClient' => [
                'usersc/classes/Cron/BrevoEventReconciliationClient.php',
                'fetchEvents',
            ],
            'BrevoSuppressionSyncClient' => [
                'usersc/classes/Cron/BrevoSuppressionSyncClient.php',
                'fetchBlockedContacts',
            ],
        ];
    }

    #[DataProvider('clientProvider')]
    public function testClientCallsBrevoDevOverrideWhileBuildingTheSdkConfiguration(
        string $relativePath,
        string $methodName
    ): void {
        $filePath = dirname(__DIR__, 3) . '/' . $relativePath;
        $this->assertFileExists($filePath, "Client source file must exist: {$relativePath}");

        $methodBody = $this->uncommentedMethodBody($filePath, $methodName);

        $failures = $this->findMethodBodyViolations($methodBody, $relativePath);
        $this->assertSame([], $failures, implode("\n", $failures));
    }

    /**
     * Real client files must pass every assertion the mutation tests below
     * check for failure. This pins that positive case directly, so a future
     * change to the shared assertion helper cannot silently make it a no-op
     * for both branches.
     */
    #[DataProvider('clientProvider')]
    public function testRealClientHasNoViolations(
        string $relativePath,
        string $methodName
    ): void {
        $filePath = dirname(__DIR__, 3) . '/' . $relativePath;
        $methodBody = $this->uncommentedMethodBody($filePath, $methodName);

        $this->assertSame([], $this->findMethodBodyViolations($methodBody, $relativePath));
    }

    /**
     * A `hostOverride()` call written only inside a `/* ... *\/` or `//`
     * comment must not satisfy the regression check.
     */
    public function testCommentedOutOverrideCallFails(): void
    {
        $mutatedPath = $this->mutateRealSource(
            'usersc/classes/Cron/BrevoEventReconciliationClient.php',
            'if ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride()) {'
                . "\n                \$credentials->setHost(\$hostOverride);\n            }",
            '// if ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride()) {'
                . "\n            //     \$credentials->setHost(\$hostOverride);\n            // }"
        );

        $methodBody = $this->uncommentedMethodBody($mutatedPath, 'fetchEvents');
        $failures = $this->findMethodBodyViolations($methodBody, 'mutated');

        $this->assertNotSame([], $failures, 'A commented-out hostOverride() call must be rejected.');
    }

    /**
     * A call guarded by a constant-false condition never runs. The
     * assertion must reject it even though the text `hostOverride()` and
     * `setHost(` both still appear in the method body.
     */
    public function testConstantFalseGuardedCallFails(): void
    {
        $mutatedPath = $this->mutateRealSource(
            'usersc/classes/Cron/BrevoEventReconciliationClient.php',
            'if ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride()) {',
            'if (false && ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride())) {'
        );

        $methodBody = $this->uncommentedMethodBody($mutatedPath, 'fetchEvents');
        $failures = $this->findMethodBodyViolations($methodBody, 'mutated');

        $this->assertNotSame([], $failures, 'A call guarded by "if (false && ...)" must be rejected.');
    }

    /**
     * The override must be looked up, and its host applied, before the SDK
     * API client is constructed. Moving the whole block after construction
     * must fail even though every needle is still present in the method.
     */
    public function testOverrideAfterSdkConstructionFails(): void
    {
        $overrideBlock = 'if ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride()) {'
            . "\n                \$credentials->setHost(\$hostOverride);\n            }";

        $original = file_get_contents(dirname(__DIR__, 3) . '/usersc/classes/Cron/BrevoEventReconciliationClient.php');
        $this->assertIsString($original);

        // Remove the override block from its original position, then splice
        // it back in immediately after the TransactionalEmailsApi
        // construction closes.
        $withoutOverride = str_replace($overrideBlock . "\n\n", '', $original);
        $this->assertNotSame($original, $withoutOverride, 'Fixture setup: could not remove the override block.');

        $constructionEnd = '                $credentials' . "\n            );\n";
        $insertPos = strpos($withoutOverride, $constructionEnd);
        $this->assertIsInt($insertPos, 'Fixture setup: could not locate the end of the SDK construction call.');
        $insertPos += strlen($constructionEnd);

        $mutatedSource = substr($withoutOverride, 0, $insertPos)
            . "\n            " . $overrideBlock . "\n"
            . substr($withoutOverride, $insertPos);

        $mutatedPath = $this->writeMutatedSource($mutatedSource);

        $methodBody = $this->uncommentedMethodBody($mutatedPath, 'fetchEvents');
        $failures = $this->findMethodBodyViolations($methodBody, 'mutated');

        $this->assertNotSame(
            [],
            $failures,
            'A hostOverride() lookup/setHost() applied AFTER the SDK API client is constructed must be rejected.'
        );
    }

    /**
     * A comment placed above the method, containing every searched string,
     * must not satisfy the check once the real call is deleted — proving the
     * check is scoped to the method body, not the whole file.
     */
    public function testDecoyCommentAboveMethodWithCallDeletedFails(): void
    {
        $overrideBlock = 'if ($hostOverride = \ElanRegistry\Email\BrevoDevOverride::hostOverride()) {'
            . "\n                \$credentials->setHost(\$hostOverride);\n            }";

        $original = file_get_contents(dirname(__DIR__, 3) . '/usersc/classes/Cron/BrevoEventReconciliationClient.php');
        $this->assertIsString($original);

        $withoutOverride = str_replace($overrideBlock, '// call deleted', $original);
        $this->assertNotSame($original, $withoutOverride, 'Fixture setup: could not remove the override block.');

        $decoyComment = '/**' . "\n"
            . ' * \Brevo\Client\Configuration::getDefaultConfiguration() '
            . '\ElanRegistry\Email\BrevoDevOverride::hostOverride() $credentials->setHost($hostOverride)'
            . "\n" . ' */' . "\n";

        $mutatedSource = str_replace(
            'class BrevoEventReconciliationClient',
            $decoyComment . 'class BrevoEventReconciliationClient',
            $withoutOverride
        );

        $mutatedPath = $this->writeMutatedSource($mutatedSource);

        $methodBody = $this->uncommentedMethodBody($mutatedPath, 'fetchEvents');
        $failures = $this->findMethodBodyViolations($methodBody, 'mutated');

        $this->assertNotSame(
            [],
            $failures,
            'A decoy comment above the method must not substitute for the real call inside the method body.'
        );
    }

    /**
     * Apply one text replacement to a real client file's source and write it
     * to a temp file. Used to build mutated fixtures without ever touching
     * the real source files. The class/namespace are left as-is — the
     * fixture is never `require`d or instantiated, only re-read as text by
     * {@see uncommentedMethodBody()}, so there is no class-redeclaration
     * risk against the real, autoloaded class.
     */
    private function mutateRealSource(string $relativePath, string $search, string $replace): string
    {
        $original = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        $this->assertIsString($original, "Could not read {$relativePath} for mutation.");
        $this->assertStringContainsString($search, $original, 'Fixture setup: search text not found in source.');

        return $this->writeMutatedSource(str_replace($search, $replace, $original));
    }

    private function writeMutatedSource(string $source): string
    {
        static $counter = 0;
        $counter++;

        $path = $this->tempDir . '/mutated_' . $counter . '.php';
        file_put_contents($path, $source);

        return $path;
    }

    /**
     * Strip comments from the file at $filePath with `token_get_all()`, then
     * return the source text of the named method's body, located with a
     * token scan for its `function` keyword and matching braces — not
     * Reflection, since these fixtures are read as text and never `require`d
     * or class-loaded.
     */
    private function uncommentedMethodBody(string $filePath, string $methodName): string
    {
        $content = file_get_contents($filePath);
        $this->assertIsString($content, "Could not read {$filePath}");

        $uncommented = $this->stripComments($content);
        $tokens = token_get_all($uncommented);

        $methodTokenIndex = null;
        foreach ($tokens as $index => $token) {
            if (
                is_array($token)
                && $token[0] === T_STRING
                && $token[1] === $methodName
                && $this->isPrecededByFunctionKeyword($tokens, $index)
            ) {
                $methodTokenIndex = $index;
                break;
            }
        }
        $this->assertIsInt($methodTokenIndex, "Could not locate function {$methodName}() in {$filePath}");

        // Walk forward from the method name to its opening brace, then track
        // brace depth to find the matching closing brace.
        $openBraceIndex = null;
        for ($i = $methodTokenIndex; $i < count($tokens); $i++) {
            if ($tokens[$i] === '{') {
                $openBraceIndex = $i;
                break;
            }
        }
        $this->assertIsInt($openBraceIndex, "Could not locate the body of {$methodName}() in {$filePath}");

        $depth = 0;
        $closeBraceIndex = null;
        for ($i = $openBraceIndex; $i < count($tokens); $i++) {
            if ($tokens[$i] === '{') {
                $depth++;
            } elseif ($tokens[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $closeBraceIndex = $i;
                    break;
                }
            }
        }
        $this->assertIsInt($closeBraceIndex, "Could not locate the end of {$methodName}()'s body in {$filePath}");

        $body = '';
        for ($i = $openBraceIndex; $i <= $closeBraceIndex; $i++) {
            $body .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        return $body;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isPrecededByFunctionKeyword(array $tokens, int $stringTokenIndex): bool
    {
        for ($i = $stringTokenIndex - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if ($token === '(' || (is_array($token) && $token[0] === T_WHITESPACE)) {
                continue;
            }
            return is_array($token) && $token[0] === T_FUNCTION;
        }

        return false;
    }

    /**
     * Remove T_COMMENT and T_DOC_COMMENT tokens, replacing each with a
     * single space so line numbers are preserved for single-line comments
     * and byte offsets stay close enough for the rest of this file's needs.
     * Multi-line comments collapse to one space, shifting later line
     * numbers — acceptable here because every mutation test's comment
     * fixtures are single-line, and the real files' doc comments sit above
     * the methods under test, not inside them.
     */
    private function stripComments(string $source): string
    {
        $tokens = token_get_all($source);
        $output = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;
                if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                    $output .= str_repeat("\n", substr_count($text, "\n"));
                    continue;
                }
                $output .= $text;
            } else {
                $output .= $token;
            }
        }

        return $output;
    }

    /**
     * Run every #2245 assertion against a comment-stripped method body and
     * return a list of failure messages (empty when the body is compliant).
     *
     * @return list<string>
     */
    private function findMethodBodyViolations(string $methodBody, string $label): array
    {
        $failures = [];

        $configurationNeedle = '\Brevo\Client\Configuration::getDefaultConfiguration()';
        $overrideNeedle = '\ElanRegistry\Email\BrevoDevOverride::hostOverride()';

        $configurationPos = strpos($methodBody, $configurationNeedle);
        if ($configurationPos === false) {
            $failures[] = "{$label} must build the SDK Configuration in this method.";
            return $failures;
        }

        $sdkConstructionPos = strpos($methodBody, self::SDK_API_CLASS_NEEDLE);
        if ($sdkConstructionPos === false) {
            $failures[] = "{$label} must construct the SDK API client in this method.";
            return $failures;
        }

        $overridePos = strpos($methodBody, $overrideNeedle);
        if ($overridePos === false) {
            $failures[] = "REGRESSION (#2245): {$label} must call BrevoDevOverride::hostOverride() while it "
                . 'builds the SDK Configuration. Without this call, a local cron run under '
                . 'US_ENVIRONMENT=development would always call the real Brevo API instead of mock-brevo.';
            return $failures;
        }

        if ($overridePos < $configurationPos) {
            $failures[] = "REGRESSION (#2245): {$label} must call BrevoDevOverride::hostOverride() AFTER "
                . 'building the base Configuration, so the override host can replace it before the SDK client '
                . 'is constructed.';
        }

        if ($overridePos > $sdkConstructionPos) {
            $failures[] = "REGRESSION (#2245): {$label} must call BrevoDevOverride::hostOverride() and apply "
                . 'its result BEFORE the SDK API client is constructed, not after.';
        }

        if (!preg_match('/\$credentials->setHost\(\$hostOverride\)/', $methodBody)) {
            $failures[] = "REGRESSION (#2245): {$label} must apply the overridden host to the Configuration "
                . 'with setHost(), not merely look it up and discard it.';
        } elseif (strpos($methodBody, '$credentials->setHost($hostOverride)') > $sdkConstructionPos) {
            $failures[] = "REGRESSION (#2245): {$label} must apply setHost() BEFORE the SDK API client is "
                . 'constructed, not after.';
        }

        // Reject a call sitting behind a constant-false guard immediately in
        // front of it on the same statement/line, e.g. "if (false && ...)".
        // Kept simple per the review finding: only look at the text
        // directly preceding the override call within the same line.
        $lineStart = strrpos(substr($methodBody, 0, $overridePos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $linePrefix = substr($methodBody, $lineStart, $overridePos - $lineStart);

        if (preg_match('/if\s*\(\s*false\b/', $linePrefix) || preg_match('/false\s*&&/', $linePrefix)) {
            $failures[] = "REGRESSION (#2245): {$label} must not guard the hostOverride() call with a "
                . 'constant-false condition — the call would never run.';
        }

        return $failures;
    }
}
