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
    private const HTACCESS = __DIR__ . '/../../../.htaccess';

    private function htaccess(): string
    {
        $contents = file_get_contents(self::HTACCESS);
        $this->assertIsString($contents, '.htaccess must be readable');

        return $contents;
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

    public function testNoPhpIniDirectiveUsesServerVariableSyntax(): void
    {
        $offending = array_values(array_filter(
            $this->directiveLines($this->htaccess()),
            static fn (string $line): bool =>
                preg_match('/^php_(admin_)?(value|flag)\s/i', $line) === 1
                && str_contains($line, '%{')
        ));

        $this->assertSame(
            [],
            $offending,
            'PHP does not expand %{...} in php_value/php_flag. It uses the literal '
            . 'string, so error_log writes a web-readable file into the docroot (#2326).'
        );
    }

    public function testHtaccessDoesNotSetErrorLog(): void
    {
        $offending = array_values(array_filter(
            $this->directiveLines($this->htaccess()),
            static fn (string $line): bool =>
                preg_match('/^php_(admin_)?value\s+error_log\b/i', $line) === 1
        ));

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
