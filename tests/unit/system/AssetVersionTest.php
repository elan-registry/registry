<?php

declare(strict_types=1);

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
 * the bootstrap). Therefore these tests target the resolution logic directly
 * via resolveAssetVersion(), a private helper that replicates the exact
 * ternary expression from config.php.
 *
 * Four required scenarios are covered:
 *   - VERSION file present with a clean version string
 *   - VERSION file absent (fallback to 'dev')
 *   - VERSION file with invalid content — allow-list rejects it (fallback to 'dev')
 *   - VERSION file with surrounding whitespace (trim applied)
 *
 * Source code inspection tests verify that config.php implements the contract
 * correctly so the tests serve as executable documentation of the requirement.
 *
 * MAINTENANCE NOTE: When the resolution expression in config.php is modified,
 * update resolveAssetVersion() to match or the scenario tests will exercise
 * stale logic. The source-inspection tests guard only token presence, not
 * full expression equivalence.
 *
 * @issue 1126
 */
#[Group('system')]
#[Group('asset-version')]
class AssetVersionTest extends TestCase
{
    /**
     * Replicates the exact resolution logic from usersc/includes/config.php:
     *
     *   if (file_exists($versionFilePath)) {
     *       $contents = file_get_contents($versionFilePath);
     *       $raw = ($contents !== false) ? trim($contents) : '';
     *   } else { $raw = ''; }
     *   return (preg_match('/^[a-zA-Z0-9.\-]+$/', $raw) === 1) ? $raw : 'dev';
     *
     * Testing this helper against all scenarios verifies the logic without
     * loading config.php multiple times (which would cause a constant-
     * redefinition fatal because backup constants are already defined by
     * bootstrap-unit.php). error_log() side effects are intentionally omitted.
     */
    private static function resolveAssetVersion(string $versionFilePath): string
    {
        if (file_exists($versionFilePath)) {
            $contents = file_get_contents($versionFilePath);
            $raw = ($contents !== false) ? trim($contents) : '';
        } else {
            $raw = '';
        }
        return (preg_match('/^[a-zA-Z0-9.\-]+$/', $raw) === 1) ? $raw : 'dev';
    }

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

    public function test_resolveAssetVersion_versionFilePresent_returnsVersionString(): void
    {
        $path = $this->writeTempVersionFile('v2.25.7');

        try {
            $this->assertSame('v2.25.7', self::resolveAssetVersion($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Scenario 2: VERSION file absent — fallback to 'dev'
    // -------------------------------------------------------------------

    public function test_resolveAssetVersion_versionFileAbsent_returnsDev(): void
    {
        // Construct a path guaranteed not to exist.
        $path = sys_get_temp_dir() . '/AssetVersionTest_absent_' . uniqid() . '_VERSION';
        $this->assertFileDoesNotExist($path, 'Precondition: temp path must not exist');

        $this->assertSame('dev', self::resolveAssetVersion($path));
    }

    // -------------------------------------------------------------------
    // Scenario 3: VERSION file with invalid content — allow-list rejects it
    // -------------------------------------------------------------------

    public function test_resolveAssetVersion_versionFileWithInvalidContent_returnsDev(): void
    {
        $path = $this->writeTempVersionFile('<script>alert(1)</script>');

        try {
            $this->assertSame('dev', self::resolveAssetVersion($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Scenario 4: VERSION file with surrounding whitespace — trim applied
    // -------------------------------------------------------------------

    public function test_resolveAssetVersion_versionFileWithLeadingAndTrailingWhitespace_returnsTrimmedString(): void
    {
        $path = $this->writeTempVersionFile("  v2.25.7\n");

        try {
            $this->assertSame('v2.25.7', self::resolveAssetVersion($path));
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
    public function test_resolveAssetVersion_stripsVariousWhitespacePatterns(
        string $fileContent,
        string $expected
    ): void {
        $path = $this->writeTempVersionFile($fileContent);

        try {
            $this->assertSame($expected, self::resolveAssetVersion($path));
        } finally {
            unlink($path);
        }
    }

    // -------------------------------------------------------------------
    // Source code inspection: verify config.php implements the contract
    // -------------------------------------------------------------------

    /**
     * config.php must define ASSET_VERSION. Removing or renaming the constant
     * would silently break all asset URL cache-busting.
     */
    public function test_configPhp_definesAssetVersionConstant(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';

        $this->assertFileExists($configFile, 'config.php must exist at usersc/includes/config.php');

        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            "define('ASSET_VERSION'",
            $content,
            "config.php must define the ASSET_VERSION constant"
        );
    }

    /**
     * The resolution expression must use file_exists(), file_get_contents(),
     * trim(), an allow-list regex, and the 'dev' fallback — all parts are
     * required for the feature to work correctly and safely.
     */
    public function test_configPhp_resolutionExpressionUsesFileExistsTrimAllowListAndDevFallback(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            'file_exists',
            $content,
            "config.php must use file_exists() to check for the VERSION file"
        );
        $this->assertStringContainsString(
            'file_get_contents',
            $content,
            "config.php must use file_get_contents() to read the VERSION file"
        );
        $this->assertStringContainsString(
            'trim(',
            $content,
            "config.php must apply trim() to strip whitespace from the VERSION file contents"
        );
        $this->assertStringContainsString(
            'preg_match',
            $content,
            "config.php must validate VERSION content against an allow-list regex"
        );
        $this->assertStringContainsString(
            "'dev'",
            $content,
            "config.php must fall back to 'dev' when the VERSION file is absent, empty, or invalid"
        );
    }

    /**
     * The VERSION file path must be built from $abs_us_root . $us_url_root so
     * it resolves to the project root on every environment. $abs_us_root alone
     * has no trailing slash, so omitting $us_url_root produces a broken path
     * (e.g. /var/www/htmlVERSION) and the constant always falls back to 'dev'.
     */
    public function test_configPhp_buildsVersionFilePathFromAbsUsRootAndUsUrlRoot(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            '$abs_us_root',
            $content,
            "ASSET_VERSION path must use \$abs_us_root"
        );
        $this->assertStringContainsString(
            '$us_url_root',
            $content,
            "ASSET_VERSION path must include \$us_url_root as the directory separator between document root and 'VERSION'"
        );
        $this->assertStringContainsString(
            "'VERSION'",
            $content,
            "config.php must reference the 'VERSION' filename"
        );
    }

    /**
     * All helper variables ($_versionFile, $_rawVersion) must be unset after use
     * to avoid leaking intermediate variables into the global scope.
     */
    public function test_configPhp_unsetsHelperVariablesAfterUse(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            '$_versionFile',
            $content,
            "config.php must reference \$_versionFile for VERSION path construction"
        );
        $this->assertStringContainsString(
            'unset(',
            $content,
            "config.php must unset helper variables after use to prevent global-scope leakage"
        );
        $this->assertStringContainsString(
            '$_rawVersion',
            $content,
            "config.php must unset \$_rawVersion to prevent global-scope leakage"
        );
    }

    /**
     * An empty VERSION file (created but not written by a failed deploy hook)
     * must fall back to 'dev'. The '+' quantifier in the allow-list regex
     * requires at least one character, so an empty trimmed string is rejected.
     */
    public function test_resolveAssetVersion_emptyVersionFile_returnsDev(): void
    {
        $path = $this->writeTempVersionFile('');

        try {
            $this->assertSame('dev', self::resolveAssetVersion($path));
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
     * config.php must log a warning via error_log() when file_get_contents()
     * fails for an existing VERSION file. Silently falling back to 'dev' in
     * that case would make deploy-hook failures invisible in server logs.
     */
    public function test_configPhp_logsErrorWhenFileGetContentsFails(): void
    {
        $configFile = dirname(__DIR__, 3) . '/usersc/includes/config.php';
        $content = (string) file_get_contents($configFile);

        $this->assertStringContainsString(
            'error_log(',
            $content,
            "config.php must call error_log() to make VERSION read failures visible in server logs"
        );
        $this->assertStringContainsString(
            'ASSET_VERSION: file_get_contents',
            $content,
            "config.php error_log() message must identify the ASSET_VERSION context for diagnosability"
        );
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
