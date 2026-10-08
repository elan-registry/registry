<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2326: PHP does not expand `%{...}` in a
 * `.htaccess` `php_value`, so the #1768 `error_log %{ENV:PHP_ERROR_LOG}` line
 * wrote a web-readable log named `%{ENV:PHP_ERROR_LOG}` into the docroot.
 * See `docs/development/ENVIRONMENT.md`, "PHP Error Logging".
 *
 * @issue 2326
 * @link https://github.com/elan-registry/registry/issues/2326
 * @category regression
 */
#[Group('regression')]
final class Issue2326HtaccessErrorLogRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const HTACCESS = self::ROOT . '/.htaccess';

    private function htaccess(): string
    {
        $contents = file_get_contents(self::HTACCESS);
        $this->assertIsString($contents, '.htaccess must be readable');

        return $contents;
    }

    /**
     * Every .htaccess in the tree, because PHP writes the leaked file into the
     * directory of the failing script, so a subfolder line has the same effect.
     * The walk also reads untracked files, such as the users/ upstream, which a
     * CI checkout does not have.
     *
     * @return list<string> paths relative to the repo root
     */
    private function htaccessFiles(): array
    {
        $root = realpath(self::ROOT);
        $this->assertIsString($root, 'Repo root must resolve');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool =>
                    !in_array($file->getFilename(), ['.git', 'vendor', 'node_modules', '.phpstan-cache', 'db-backups', 'playwright-report'], true)
            )
        );

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getFilename() === '.htaccess') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        $this->assertContains('.htaccess', $files, 'The scan must include the root .htaccess');

        return $files;
    }

    /**
     * Returns the trimmed directive lines, without blank and comment lines.
     *
     * @return list<string>
     */
    private function directiveLines(string $contents): array
    {
        $lines = array_map('trim', preg_split('/\R/', $contents) ?: []);

        return array_values(preg_grep('/^[^#]/', $lines) ?: []);
    }

    /**
     * @param callable(string): bool $matches
     * @return list<string> "path: line" for each matching directive line
     */
    private function offendingLines(callable $matches): array
    {
        $offending = [];
        foreach ($this->htaccessFiles() as $path) {
            $contents = file_get_contents(self::ROOT . '/' . $path);
            $this->assertIsString($contents, "{$path} must be readable");
            foreach ($this->directiveLines($contents) as $line) {
                if ($matches($line)) {
                    $offending[] = "{$path}: {$line}";
                }
            }
        }

        return $offending;
    }

    public function testNoPhpIniDirectiveUsesServerVariableSyntax(): void
    {
        $offending = $this->offendingLines(
            static fn (string $line): bool =>
                preg_match('/^php_(admin_)?(value|flag)\s/i', $line) === 1
                && str_contains($line, '%{')
        );

        $this->assertSame(
            [],
            $offending,
            'PHP does not expand %{...} in php_value/php_flag. It uses the literal '
            . 'string, so error_log writes a web-readable file into the docroot (#2326).'
        );
    }

    public function testHtaccessDoesNotSetErrorLog(): void
    {
        $offending = $this->offendingLines(
            static fn (string $line): bool =>
                preg_match('/^php_(admin_)?value\s+error_log\b/i', $line) === 1
        );

        $this->assertSame(
            [],
            $offending,
            'The server-only .user.ini in each docroot sets error_log. A .htaccess '
            . 'value overrides it on LiteSpeed (#2326).'
        );
    }

    public function testLeakedEnvFilenameIsDenied(): void
    {
        $this->assertMatchesRegularExpression(
            '/^<FilesMatch\s+"\^%\\\\\{ENV">\s*\R\s*Require all denied\s*\R\s*<\/FilesMatch>/m',
            $this->htaccess(),
            '.htaccess must deny files named "%{ENV..." so a leaked error log is not served (#2326).'
        );
    }
}
