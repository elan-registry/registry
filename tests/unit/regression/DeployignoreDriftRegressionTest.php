<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Issue #2190: `.deployignore` drifted from the repo.
 * Stale entries stayed after their files were removed, and new dev files
 * deployed because no one added them. This guard compares `git ls-files`
 * with `.deployignore` and the three lists below, so each new top-level
 * entry needs a decision in the same PR.
 * See `docs/development/DEPLOYMENT.md`.
 *
 * Needs `git`. The host, CI, and the Docker app image have it. When git cannot
 * run, the test fails. It does not skip.
 *
 * @issue 2190
 * @link https://github.com/elan-registry/registry/issues/2190
 * @category regression
 */
#[Group('regression')]
final class DeployignoreDriftRegressionTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const DEPLOYIGNORE = self::ROOT . '/.deployignore';

    /**
     * Tracked top-level entries that ship to the servers. fnmatch() patterns.
     *
     * @var list<string>
     */
    private const DEPLOYED = [
        'app/',
        'docs/',
        'users/',
        'usersc/',
        'backups/',
        'error/',
        'userimages/',
        'index.php',
        'z_us_root.php',
        'phinx.php',
        '.htaccess',
        'robots.txt',
        'llms.txt',
        'favicon.ico',
        'apple-touch-icon*.png',
    ];

    /**
     * `.deployignore` entries that git does not track. They exist only on the
     * servers, or are gitignored but kept so a force-added file does not
     * deploy.
     *
     * @var list<string>
     */
    private const SERVER_ONLY = [
        'node_modules/',
        'usersc/plugins/db_explainer/',
        'docs/plans/',
    ];

    /**
     * Server data and dependencies that cleanup must never delete. #1479:
     * a `.deployignore` entry wiped the backups directory on deploy.
     *
     * @var list<string>
     */
    private const PROTECTED = [
        'backups/',
        'db-backups/',
        'userimages/',
        'vendor/',
        'users/',
        'VERSION',
        '.env',
    ];

    public function testRepoHasNoDeployignoreDrift(): void
    {
        $tracked = $this->trackedPaths('git ls-files');
        $lines = $this->deployignoreLines();
        $entries = $this->deployignoreEntries($lines);

        $violations = array_merge(
            $this->carriageReturnLines($lines),
            $this->unlistedTopLevelEntries($tracked, $entries, self::DEPLOYED),
            $this->staleDeployignoreEntries($tracked, $entries, self::SERVER_ONLY),
            $this->protectedPathEntries($entries, self::PROTECTED),
        );

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * @return array<string, array{0: string, 1: list<list<string>>, 2: list<string>}>
     *         rule method, its arguments, expected message fragments
     */
    public static function driftCaseProvider(): array
    {
        return [
            'new tracked top-level file is on no list' => [
                'unlistedTopLevelEntries',
                [['index.php', 'new-tool.config.js'], [], ['index.php']],
                ['`new-tool.config.js`', '.deployignore', 'DEPLOYED'],
            ],
            '.deployignore entry exists nowhere' => [
                'staleDeployignoreEntries',
                [['index.php', 'tests/FooTest.php'], ['tests/', 'gone.json'], []],
                ['`gone.json`', '.deployignore', 'SERVER_ONLY'],
            ],
            '.deployignore entry matches only whole path segments' => [
                'staleDeployignoreEntries',
                [['index.php', 'tests/FooTest.php'], ['test'], []],
                ['`test`', '.deployignore', 'SERVER_ONLY'],
            ],
            '.deployignore holds backups/' => [
                'protectedPathEntries',
                [['backups/'], ['backups/']],
                ['`backups/`', '.deployignore', '#1479'],
            ],
            '.deployignore holds a prefix of a protected path' => [
                'protectedPathEntries',
                [['vendor/'], ['vendor/bin/']],
                ['`vendor/`', '`vendor/bin/`', '.deployignore', '#1479'],
            ],
            '.deployignore holds a path under a protected path' => [
                'protectedPathEntries',
                [['backups/automated/'], ['backups/']],
                ['`backups/automated/`', '`backups/`', '.deployignore', '#1479'],
            ],
            '.deployignore holds a protected path with a leading ./' => [
                'protectedPathEntries',
                [['./backups/'], ['backups/']],
                ['`./backups/`', '`backups/`', '.deployignore', '#1479'],
            ],
            '.deployignore holds a protected path with a leading /' => [
                'protectedPathEntries',
                [['/vendor/'], ['vendor/']],
                ['`/vendor/`', '`vendor/`', '.deployignore', '#1479'],
            ],
            '.deployignore has CRLF line endings' => [
                'carriageReturnLines',
                [["# comment\r", "node_modules/\r", 'tests/']],
                ['.deployignore', 'lines 1, 2', 'xargs', 'LF line endings'],
            ],
        ];
    }

    /**
     * @param list<list<string>> $arguments
     * @param list<string> $fragments
     */
    #[DataProvider('driftCaseProvider')]
    public function testRuleReportsDrift(string $rule, array $arguments, array $fragments): void
    {
        // PHP drops surplus arguments silently, so a malformed case would still pass.
        $this->assertCount((new ReflectionMethod($this, $rule))->getNumberOfParameters(), $arguments);

        /** @var list<string> $violations */
        $violations = $this->{$rule}(...$arguments);

        $this->assertCount(1, $violations, implode("\n", $violations));
        foreach ($fragments as $fragment) {
            $this->assertStringContainsString($fragment, $violations[0]);
        }
    }

    /**
     * `users/` is protected, but `usersc/` and its subpaths are not under it.
     */
    public function testProtectedRuleMatchesWholePathSegments(): void
    {
        $this->assertSame([], $this->protectedPathEntries(['usersc/', 'usersc/plugins/ai_prompts/'], ['users/']));
        $this->assertCount(1, $this->protectedPathEntries(['users'], ['users/']));
    }

    public function testGitRunnerFailsWhenCommandCannotRun(): void
    {
        try {
            $this->trackedPaths('git-command-that-does-not-exist-2190 ls-files');
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('git-command-that-does-not-exist-2190', $failure->getMessage());
            $this->assertStringContainsString('needs git', $failure->getMessage());

            return;
        }

        $this->fail('trackedPaths() must fail when the git command cannot run.');
    }

    /**
     * Runs the command from the repo root. Fails the test when the command
     * cannot run, exits non-zero, or prints nothing.
     *
     * @return list<string>
     */
    private function trackedPaths(string $command): array
    {
        $output = [];
        $exitCode = 0;
        exec('cd ' . escapeshellarg(self::ROOT) . " && {$command} 2>&1", $output, $exitCode);

        if ($exitCode !== 0 || $output === []) {
            $this->fail(
                "`{$command}` exited {$exitCode} with no file list (" . implode(' ', $output) . '). '
                . "The .deployignore guard needs git: `{$command}` must run from the repo root."
            );
        }

        return $output;
    }

    /**
     * Splits .deployignore on `\n` only, so a `\r` stays at the line end for
     * carriageReturnLines(). `file(..., FILE_IGNORE_NEW_LINES)` removes `\r\n`.
     *
     * @return list<string>
     */
    private function deployignoreLines(): array
    {
        $contents = file_get_contents(self::DEPLOYIGNORE);
        $this->assertIsString($contents, '.deployignore must be readable');

        return explode("\n", $contents);
    }

    /**
     * Parses .deployignore lines: trim each line, drop blank lines and `#`
     * comments.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function deployignoreEntries(array $lines): array
    {
        return array_values(array_filter(
            array_map('trim', $lines),
            static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')
        ));
    }

    /**
     * Rule 1: each tracked top-level entry is in .deployignore or DEPLOYED.
     *
     * @param list<string> $tracked
     * @param list<string> $entries .deployignore entries
     * @param list<string> $deployed fnmatch() patterns
     * @return list<string> violation messages
     */
    private function unlistedTopLevelEntries(array $tracked, array $entries, array $deployed): array
    {
        $ignored = array_flip(array_map(static fn (string $entry): string => rtrim($entry, '/'), $entries));

        $topLevel = array_unique(array_map(static function (string $path): string {
            $slash = strpos($path, '/');

            return $slash === false ? $path : substr($path, 0, $slash + 1);
        }, $tracked));

        $violations = [];
        foreach ($topLevel as $entry) {
            if (isset($ignored[rtrim($entry, '/')])) {
                continue;
            }
            foreach ($deployed as $pattern) {
                if (fnmatch($pattern, $entry)) {
                    continue 2;
                }
            }
            $violations[] = "`{$entry}` is tracked but on no list — add it to .deployignore, "
                . 'or to DEPLOYED in DeployignoreDriftRegressionTest.';
        }

        return $violations;
    }

    /**
     * Rule 2: each .deployignore entry names a tracked file, a directory that
     * holds tracked files, or a SERVER_ONLY entry.
     *
     * @param list<string> $tracked
     * @param list<string> $entries .deployignore entries
     * @param list<string> $serverOnly
     * @return list<string> violation messages
     */
    private function staleDeployignoreEntries(array $tracked, array $entries, array $serverOnly): array
    {
        $violations = [];
        foreach ($entries as $entry) {
            if (in_array($entry, $serverOnly, true)) {
                continue;
            }
            $directory = rtrim($entry, '/') . '/';
            foreach ($tracked as $path) {
                if ($path === $entry || str_starts_with($path, $directory)) {
                    continue 2;
                }
            }
            $violations[] = "`{$entry}` is in .deployignore but matches no tracked path — remove it from "
                . '.deployignore, or add it to SERVER_ONLY in DeployignoreDriftRegressionTest.';
        }

        return $violations;
    }

    /**
     * CRLF check: no .deployignore line ends with `\r`. The post-receive hook
     * passes each line to xargs with the `\r` kept, so the path matches no
     * file and cleanup deletes nothing.
     *
     * @param list<string> $lines .deployignore lines without the `\n`
     * @return list<string> violation messages
     */
    private function carriageReturnLines(array $lines): array
    {
        $lineNumbers = array_keys(array_filter(
            $lines,
            static fn (string $line): bool => str_ends_with($line, "\r")
        ));
        if ($lineNumbers === []) {
            return [];
        }

        $numbers = implode(', ', array_map(static fn (int $index): int => $index + 1, $lineNumbers));

        return [
            ".deployignore has CRLF line endings on lines {$numbers}. The post-receive hook passes "
            . 'each line to xargs with the `\r`, so deploy cleanup deletes nothing — convert '
            . '.deployignore to LF line endings.',
        ];
    }

    /**
     * Rule 3: no .deployignore entry equals a protected path, is a
     * path-segment prefix of one, or is under one. `users/` covers `users/x`,
     * not `usersc/`. A leading `./` or `/` on the entry is ignored.
     *
     * @param list<string> $entries .deployignore entries
     * @param list<string> $protected
     * @return list<string> violation messages
     */
    private function protectedPathEntries(array $entries, array $protected): array
    {
        $violations = [];
        foreach ($entries as $entry) {
            $entryPath = rtrim((string) preg_replace('#^(\./|/)+#', '', $entry), '/');
            foreach ($protected as $protectedPath) {
                $target = rtrim($protectedPath, '/');
                if (
                    $target === $entryPath
                    || str_starts_with($target, $entryPath . '/')
                    || str_starts_with($entryPath, $target . '/')
                ) {
                    $violations[] = "`{$entry}` in .deployignore would delete all or part of the protected path "
                        . "`{$protectedPath}` on deploy (#1479) — remove it from .deployignore.";
                }
            }
        }

        return $violations;
    }
}
