<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2490: PHP 8.5 deprecations in project code, and one PHP version
 * across Docker, CI, Composer and PHPStan.
 *
 * The host moved test and production to PHP 8.5 without notice. The
 * deprecation notice for PDO::MYSQL_ATTR_INIT_COMMAND in users/init.php
 * printed before the response headers, so redirects and JSON responses broke.
 * Docker and CI still ran PHP 8.4, so no environment showed the defect.
 *
 * Part 1 scans project-owned PHP code (comments and inline HTML excluded) for
 * the three deprecated forms. Part 2 fails when the version files name different minor
 * versions, so a partial version change fails the unit suite.
 *
 * @issue 2490
 * @link https://github.com/elan-registry/registry/issues/2490
 */
#[Group('regression')]
final class Issue2490RegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const PHP_MINOR = '8.5';

    /**
     * Project-owned PHP roots. users/ and parts of usersc/ are upstream (see
     * USERSC_UPSTREAM), so only their project-owned parts are listed.
     * vendor/ and node_modules/ are skipped. The repo-root PHP files are
     * added in projectPhpFiles().
     */
    private const SCAN_ROOTS = [
        'app', 'usersc', 'scripts', 'tests', 'database', 'tools', 'error', 'stubs', 'docs',
        'users/init.php', 'users/cron',
        'usersc/plugins/hooker/hooks', 'usersc/plugins/ai_prompts/custom_prompts',
        'usersc/widgets/settings', 'usersc/widgets/tools',
        'usersc/templates/customizer/file_nav_custom.php', 'usersc/templates/customizer/assets/child_themes',
    ];

    /**
     * Upstream (mostly gitignored) code that the usersc root skips. A finding
     * there goes upstream, and skipping it keeps the local scan close to the
     * CI scan. The project-owned parts are separate SCAN_ROOTS.
     */
    private const USERSC_UPSTREAM = ['/usersc/plugins/', '/usersc/widgets/', '/usersc/templates/customizer/'];

    /** @var array<string, string> label => pattern on code with comments removed */
    private const DEPRECATED_FORMS = [
        'PDO::MYSQL_ATTR_ constant (use Pdo\\Mysql::ATTR_)' => '/\bPDO::MYSQL_ATTR_/i',
        'curl_close() call (a no-op, deprecated in 8.5)'    => '/\bcurl_close\s*\(/i',
        '$http_response_header (use http_get_last_response_headers())' => '/\$http_response_header\b/',
    ];

    public function testProjectCodeHasNoDeprecatedPhp85Forms(): void
    {
        $files = $this->projectPhpFiles();
        $this->assertGreaterThan(100, count($files), 'The scan found too few files. Check SCAN_ROOTS.');

        $hits = [];
        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source, "Cannot read {$file}");
            foreach (self::findDeprecatedForms($source) as $label) {
                $hits[] = substr($file, strlen(realpath(self::ROOT)) + 1) . ': ' . $label;
            }
        }

        $this->assertSame([], $hits, "PHP 8.5 deprecated forms in project code:\n" . implode("\n", $hits));
    }

    /**
     * The scan above passes if the option is deleted, not replaced. Without
     * the option, UserSpice INSERTs fail under strict sql_mode.
     */
    public function testInitPhpSetsSqlModeWithPdoMysqlConstant(): void
    {
        $init = (string) file_get_contents(self::ROOT . '/users/init.php');

        $this->assertMatchesRegularExpression(
            '/^\s*Pdo\\\\Mysql::ATTR_INIT_COMMAND\s*=>\s*"SET SESSION sql_mode = \'\'",$/m',
            $init
        );
    }

    public function testScanFindsEachDeprecatedFormInCodeButNotInComments(): void
    {
        $code = <<<'PHP'
            <?php
            $o = [\PDO::MYSQL_ATTR_INIT_COMMAND => ''];
            curl_close($ch);
            $h = $http_response_header;
            PHP;
        $this->assertSame(array_keys(self::DEPRECATED_FORMS), self::findDeprecatedForms($code));

        $comments = <<<'PHP'
            <?php
            // PDO::MYSQL_ATTR_INIT_COMMAND, curl_close($ch)
            /** $http_response_header */
            ?>
            Prose: PDO::MYSQL_ATTR_INIT_COMMAND, curl_close($ch), $http_response_header
            PHP;
        $this->assertSame([], self::findDeprecatedForms($comments));
    }

    public function testVersionFilesNameTheSamePhpMinor(): void
    {
        $dockerfile = (string) file_get_contents(self::ROOT . '/docker/Dockerfile');
        $this->assertSame(1, preg_match('/^FROM php:(\d+\.\d+)-apache$/m', $dockerfile, $m), 'Dockerfile FROM');
        $versions = ['docker/Dockerfile FROM' => $m[1]];

        foreach (['tests.yml', 'static-analysis.yml'] as $workflow) {
            $yaml = (string) file_get_contents(self::ROOT . '/.github/workflows/' . $workflow);
            preg_match_all('/php-version:\s*"?(\d+\.\d+)"?/', $yaml, $all);
            $this->assertNotEmpty($all[1], "{$workflow} has no php-version");
            foreach ($all[1] as $i => $version) {
                $versions["{$workflow} php-version #" . ($i + 1)] = $version;
            }
        }

        $composer = json_decode((string) file_get_contents(self::ROOT . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($composer);
        $platform = (string) ($composer['config']['platform']['php'] ?? '');
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $platform, 'config.platform.php');
        $versions['composer.json config.platform.php'] = implode('.', array_slice(explode('.', $platform), 0, 2));

        $lock = json_decode((string) file_get_contents(self::ROOT . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($lock);
        $this->assertSame($platform, $lock['platform-overrides']['php'] ?? null, 'composer.lock platform-overrides.php: run composer update --lock');

        $require = (string) ($composer['require']['php'] ?? '');
        $this->assertMatchesRegularExpression('/^>=\d+\.\d+$/', $require, 'require.php');
        $versions['composer.json require.php'] = substr($require, 2);

        $neon = (string) file_get_contents(self::ROOT . '/phpstan.neon');
        $this->assertSame(1, preg_match('/^\s*phpVersion:\s*(\d)(\d{2})00\s*$/m', $neon, $n), 'phpstan.neon phpVersion');
        $versions['phpstan.neon phpVersion'] = $n[1] . '.' . (int) $n[2];

        $expected = array_fill_keys(array_keys($versions), self::PHP_MINOR);
        $this->assertSame($expected, $versions, 'Each version file must name PHP ' . self::PHP_MINOR);
    }

    /**
     * @return list<string> labels of the deprecated forms found in code (not comments)
     */
    private static function findDeprecatedForms(string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            // Inline HTML is prose (docs pages, prompt Markdown), not code.
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $found = [];
        foreach (self::DEPRECATED_FORMS as $label => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function projectPhpFiles(): array
    {
        $root = (string) realpath(self::ROOT);
        $self = (string) realpath(__FILE__);

        $files = glob($root . '/*.php');
        $this->assertIsArray($files, 'glob() failed for the repo-root PHP files');
        foreach (self::SCAN_ROOTS as $relative) {
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = $file->getPathname();
                $inPath = substr($name, strlen($root));
                if ($relative === 'usersc' && self::startsWithAny($inPath, self::USERSC_UPSTREAM)) {
                    continue;
                }
                if (str_ends_with($name, '.php') && $name !== $self
                    && preg_match('#/(vendor|node_modules)/#', $inPath) !== 1) {
                    $files[] = $name;
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
