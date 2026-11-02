<?php

declare(strict_types=1);

use ElanRegistry\AssetVersionResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ASSET_VERSION constant resolution logic (GitHub issue #1126).
 *
 * ASSET_VERSION is a PHP constant defined once at runtime via define() in
 * usersc/includes/config.php. PHP constants cannot be redefined between tests
 * in the same process, and config.php is not loaded by bootstrap-unit.php
 * (it requires $abs_us_root and redefines backup constants already set by
 * the bootstrap). To keep the resolution logic itself unit-testable despite
 * that constraint, it lives in ElanRegistry\AssetVersionResolver::resolve()
 * (#1598) — a pure, PSR-4-autoloaded function that takes no dependency on
 * $abs_us_root/$us_url_root or any BACKUP_* constant, so it loads standalone
 * in the unit tier. config.php calls it and wraps the result in define().
 * The behavioral tests below call AssetVersionResolver::resolve() directly,
 * so they exercise the exact same code path production runs — no drift risk.
 *
 * Four required scenarios are covered:
 *   - VERSION file present with a clean version string
 *   - VERSION file absent (fallback to 'dev')
 *   - VERSION file with invalid content — allow-list rejects it (fallback to 'dev')
 *   - VERSION file with surrounding whitespace (trim applied)
 *
 * Source code inspection tests verify that config.php wires up the resolver
 * correctly — the one thing that can't be verified behaviorally, since
 * config.php itself still can't be loaded standalone in this tier. These
 * inspection tests guard only the exact substrings asserted — a maintainer
 * must keep the assertions tied to the literal statement being verified
 * (e.g. the full `define(...)` call, not just its parts appearing
 * independently anywhere in the file), or the guard becomes hollow.
 *
 * The file_get_contents()-fails branch (error_log() + 'dev' fallback) is
 * exercised behaviorally via a custom stream wrapper (AvrUnreadableStream,
 * declared below this class) rather than a real filesystem path — a
 * directory or permission-denied file doesn't reliably make
 * file_get_contents() return false across platforms/PHP versions (verified:
 * a directory returns '' on this PHP version, not false), so no real path
 * can portably drive that branch.
 *
 * @issue 1126
 * @issue 1598
 */
#[Group('system')]
#[Group('asset-version')]
class AssetVersionTest extends TestCase
{
    /**
     * Writes content to a unique temporary file and returns the path.
     * Callers must unlink the file when done (use try/finally).
     */
    private function writeTempVersionFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/AssetVersionTest_' . uniqid() . '_VERSION';
        file_put_contents($path, $content);
        return $path;
    }

    // -------------------------------------------------------------------
    // Scenario 1: VERSION file present — version string returned as-is
    // -------------------------------------------------------------------

    public function test_assetVersionResolver_versionFilePresent_returnsVersionString(): void
    {
        $path = $this->writeTempVersionFile('v2.25.7');

        try {
            $this->assertSame('v2.25.7', AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Scenario 2: VERSION file absent — fallback to 'dev'
    // -------------------------------------------------------------------

    public function test_assetVersionResolver_versionFileAbsent_returnsDev(): void
    {
        // Construct a path guaranteed not to exist.
        $path = sys_get_temp_dir() . '/AssetVersionTest_absent_' . uniqid() . '_VERSION';
        $this->assertFileDoesNotExist($path, 'Precondition: temp path must not exist');

        $this->assertSame('dev', AssetVersionResolver::resolve($path));
    }

    // -------------------------------------------------------------------
    // Scenario 3: VERSION file with invalid content — allow-list rejects it
    // -------------------------------------------------------------------

    public function test_assetVersionResolver_versionFileWithInvalidContent_returnsDev(): void
    {
        $path = $this->writeTempVersionFile('<script>alert(1)</script>');

        try {
            $this->assertSame('dev', AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    /**
     * Internal whitespace (e.g. a second line appended by a botched deploy
     * script) survives trim() — since trim() only strips the ends — and must
     * still be rejected by the allow-list regex. Distinct from Scenario 4
     * below, which covers whitespace trim() *does* fully remove.
     */
    public function test_assetVersionResolver_versionFileWithInternalWhitespace_returnsDev(): void
    {
        $path = $this->writeTempVersionFile("v2.25.7\nleftover-line");

        try {
            $this->assertSame('dev', AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Scenario 4: VERSION file with surrounding whitespace — trim applied
    // -------------------------------------------------------------------

    public function test_assetVersionResolver_versionFileWithLeadingAndTrailingWhitespace_returnsTrimmedString(): void
    {
        $path = $this->writeTempVersionFile("  v2.25.7\n");

        try {
            $this->assertSame('v2.25.7', AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Data provider: all common whitespace / line-ending patterns
    // -------------------------------------------------------------------

    /**
     * Shell scripts and editors can produce various whitespace patterns in
     * a VERSION file. trim() must handle all of them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function whitespaceVariants(): array
    {
        return [
            'trailing newline only'           => ["v2.25.7\n",      'v2.25.7'],
            'leading spaces and trailing LF'  => ["  v2.25.7\n",   'v2.25.7'],
            'tab-padded on both sides'        => ["\tv2.25.7\t",   'v2.25.7'],
            'Windows CRLF line ending'        => ["v2.25.7\r\n",   'v2.25.7'],
            'leading and trailing spaces'     => ["  v2.25.7  ",   'v2.25.7'],
            'git-describe with build suffix'  => ["v2.25.6-13-g16246bf2\n", 'v2.25.6-13-g16246bf2'],
        ];
    }

    /**
     * trim() must strip all common whitespace and line-ending patterns,
     * including the git-describe output format actually written by the
     * deploy hook on this project.
     */
    #[DataProvider('whitespaceVariants')]
    public function test_assetVersionResolver_stripsVariousWhitespacePatterns(
        string $fileContent,
        string $expected
    ): void {
        $path = $this->writeTempVersionFile($fileContent);

        try {
            $this->assertSame($expected, AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Source code inspection: verify config.php implements the contract
    // -------------------------------------------------------------------

    /**
     * config.php must define ASSET_VERSION directly from
     * AssetVersionResolver::resolve() rather than reimplementing the
     * resolution logic inline. Also covers config.php defining the
     * ASSET_VERSION constant at all — a config.php that dropped the
     * define() (or renamed the constant) would fail this same assertion,
     * so a separate "defines the constant" test would be strictly weaker
     * and redundant with this one. This is the one part of the contract
     * that can't be verified behaviorally, since config.php itself still can't be
     * loaded standalone in the unit tier (see class docblock). The resolution
     * logic itself (file_exists/trim/allow-list/'dev' fallback) is covered
     * directly by the behavioral tests above, which call
     * AssetVersionResolver::resolve() for real.
     *
     * Asserts the combined `define('ASSET_VERSION', AssetVersionResolver::resolve(`
     * statement rather than checking `define('ASSET_VERSION'` and
     * `AssetVersionResolver::resolve(` as two independent substrings —
     * independent checks would both still pass against a config.php that
     * regressed to `define('ASSET_VERSION', 'dev');` with the resolver call
     * left dangling elsewhere in the file.
     */
    public function test_configPhp_callsAssetVersionResolver(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            "define('ASSET_VERSION', AssetVersionResolver::resolve(",
            $content,
            "config.php must define ASSET_VERSION directly from AssetVersionResolver::resolve()"
        );
    }

    /**
     * The VERSION file path must be built from $abs_us_root . $us_url_root so
     * it resolves to the project root on every environment. $abs_us_root alone
     * has no trailing slash, so omitting $us_url_root produces a broken path
     * (e.g. /var/www/htmlVERSION) and the constant always falls back to 'dev'.
     *
     * Asserts the exact concatenation statement rather than checking
     * '$abs_us_root', '$us_url_root', and "'VERSION'" as three independent
     * substrings — independent checks would still pass even if this line
     * were deleted or rewritten, since $abs_us_root/$us_url_root are also
     * used to build other constants earlier in the file and 'VERSION' could
     * appear in a comment (the same hollow-guard failure mode the sibling
     * inspection tests in this class guard against).
     */
    public function test_configPhp_buildsVersionFilePathFromAbsUsRootAndUsUrlRoot(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            "\$_versionFile = \$abs_us_root . \$us_url_root . 'VERSION';",
            $content,
            "config.php must build the VERSION file path from \$abs_us_root . \$us_url_root . 'VERSION'"
        );
    }

    /**
     * The $_versionFile helper variable must be unset after use to avoid
     * leaking it into the global scope. Asserts the exact `unset($_versionFile)`
     * statement rather than checking '$_versionFile' and 'unset(' as two
     * independent substrings — independent checks would both still pass if
     * the unset() call were deleted entirely, since '$_versionFile' also
     * appears in the variable's own assignment earlier in the file.
     */
    public function test_configPhp_unsetsHelperVariablesAfterUse(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            'unset($_versionFile)',
            $content,
            "config.php must unset \$_versionFile to prevent global-scope leakage"
        );
    }

    /**
     * An empty VERSION file (created but not written by a failed deploy hook)
     * must fall back to 'dev'. The '+' quantifier in the allow-list regex
     * requires at least one character, so an empty trimmed string is rejected.
     */
    public function test_assetVersionResolver_emptyVersionFile_returnsDev(): void
    {
        $path = $this->writeTempVersionFile('');

        try {
            $this->assertSame('dev', AssetVersionResolver::resolve($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Template regression: every call site must include ?v=ASSET_VERSION
    // -------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateFilesWithAssetVersionTag(): array
    {
        $root = dirname(__DIR__, 3);
        return [
            'index.php (root)'         => [$root . '/index.php'],
            'footer.php'               => [$root . '/usersc/includes/footer.php'],
            'join.php'                 => [$root . '/usersc/join.php'],
            'user_settings.php'        => [$root . '/usersc/user_settings.php'],
            'cars/index.php'           => [$root . '/app/owner/cars/index.php'],
            'cars/details.php'         => [$root . '/app/owner/cars/details.php'],
            'cars/_location_map_scripts.php' => [$root . '/app/views/cars/_location_map_scripts.php'],
            'cars/edit.php'            => [$root . '/app/owner/cars/edit.php'],
            'reports/statistics.php'   => [$root . '/app/owner/reports/statistics.php'],
            'admin/index.php'          => [$root . '/app/admin/index.php'],
            'admin/maintenance.php'    => [$root . '/app/admin/maintenance.php'],
        ];
    }

    /**
     * Every template that loads a first-party minified asset must append
     * ?v=<?= ASSET_VERSION ?>. A merge conflict or refactor that strips the
     * suffix from any file would restore stale-asset delivery for all users.
     */
    #[DataProvider('templateFilesWithAssetVersionTag')]
    public function test_templateFile_containsAssetVersionQueryParameter(string $path): void
    {
        $content = (string) file_get_contents($path);
        $this->assertStringContainsString(
            '?v=<?= ASSET_VERSION ?>',
            $content,
            basename($path) . ' must append ?v=<?= ASSET_VERSION ?> to its first-party asset tags'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function templateFilesWithMaplibreTags(): array
    {
        $root = dirname(__DIR__, 3);
        return [
            'cars/_location_map_scripts.php' => [$root . '/app/views/cars/_location_map_scripts.php'],
            'reports/statistics.php'         => [$root . '/app/owner/reports/statistics.php'],
            'account.php'                    => [$root . '/usersc/account.php'],
        ];
    }

    /**
     * The test above passes when any one tag in a file has the query. The
     * MapLibre bundle and CSS each need their own query: without it, a
     * returning browser keeps a bundle from an earlier MapLibre release, and
     * that bundle loads the new worker, which it does not match.
     */
    #[DataProvider('templateFilesWithMaplibreTags')]
    public function test_templateFile_maplibreTagsCarryAssetVersion(string $path): void
    {
        $content = (string) file_get_contents($path);
        foreach (['usersc/js/maplibre-gl.min.js', 'usersc/css/maplibre-gl.css'] as $asset) {
            $this->assertGreaterThan(0, substr_count($content, $asset), basename($path) . " must load $asset");
            $this->assertSame(
                substr_count($content, $asset),
                substr_count($content, $asset . '?v=<?= ASSET_VERSION ?>'),
                basename($path) . " must append ?v=<?= ASSET_VERSION ?> to each $asset reference"
            );
        }
    }

    /**
     * Drives resolve() through the file_get_contents() === false branch for
     * real, using a stream wrapper whose stream_open() always refuses —
     * file_exists() (backed by url_stat()) reports the path exists, but
     * file_get_contents() genuinely returns false, unlike a directory path
     * (which returns '' on this platform/PHP version, taking the *success*
     * branch with empty content instead — verified directly; an earlier
     * version of this test used a directory and was consequently hollow).
     * Redirects error_log()'s destination via ini_set() so the real log
     * side effect can be asserted, not just inspected as source text — this
     * also catches the error_log() call being relocated to the wrong branch,
     * which no string-inspection assertion can detect (proven by mutation:
     * moving the call to the success branch left every literal-string
     * assertion intact but fails this test's log-content assertion).
     */
    public function test_assetVersionResolver_fileGetContentsFails_logsErrorAndReturnsDev(): void
    {
        if (!in_array('avrfail', stream_get_wrappers(), true)) {
            stream_wrapper_register('avrfail', AvrUnreadableStream::class);
        }

        $logFile = sys_get_temp_dir() . '/AssetVersionTest_errorlog_' . uniqid() . '.log';
        $previousErrorLog = ini_set('error_log', $logFile);

        try {
            $result = @AssetVersionResolver::resolve('avrfail://VERSION');

            $this->assertSame('dev', $result);
            $this->assertStringContainsString(
                '[ElanRegistry] ASSET_VERSION: file_get_contents() failed for avrfail://VERSION',
                (string) @file_get_contents($logFile),
                "AssetVersionResolver must error_log() the ASSET_VERSION read failure so deploy-hook failures are visible in server logs"
            );
        } finally {
            ini_set('error_log', $previousErrorLog);
            stream_wrapper_unregister('avrfail');
            if (file_exists($logFile)) {
                unlink($logFile);
            }
        }
    }

    /**
     * config.php must be syntactically valid PHP so that it can be included
     * by the application without parse errors.
     */
    public function test_configPhp_isValidPhp(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $output = [];
        $returnCode = 0;
        exec("php -l " . escapeshellarg($configFile), $output, $returnCode);

        $this->assertSame(0, $returnCode, 'config.php must pass PHP syntax check (php -l)');
    }
}

/**
 * Stream wrapper backing the 'avrfail://' protocol used by
 * AssetVersionTest::test_assetVersionResolver_fileGetContentsFails_logsErrorAndReturnsDev().
 * url_stat() reports the path exists (so file_exists() is true) but
 * stream_open() always refuses (so file_get_contents() genuinely returns
 * false) — the one combination a real filesystem path can't portably
 * produce, which is why this test doesn't use a directory or a
 * permission-denied file.
 */
final class AvrUnreadableStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100000, 'size' => 0];
    }
}
