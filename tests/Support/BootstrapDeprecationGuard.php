<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use RuntimeException;

/**
 * BootstrapDeprecationGuard - fail the run on a deprecation that committed
 * code raises while a PHPUnit bootstrap loads it (#2491)
 *
 * `failOnDeprecation` covers deprecations during tests only. PHPUnit does not
 * see one that a file raises while the bootstrap requires it, so #2490
 * (`users/init.php`, loaded by tests/bootstrap-integration.php) passed every
 * run. Wrap the bootstrap requires:
 *
 * ```php
 * BootstrapDeprecationGuard::start($projectRoot);
 * require_once $projectRoot . '/users/init.php';
 * BootstrapDeprecationGuard::stop();
 * ```
 *
 * The handler records a deprecation only when the file that raises it is
 * committed (see committedPhpFiles()). It passes every other error to the
 * handler that was active before start(). Untracked upstream code is out of
 * scope for the same reason as `ignoreIndirectDeprecations`: this repo cannot
 * fix it.
 *
 * The handler records and does not throw, because the integration bootstrap
 * catches every Throwable from users/init.php. stop() throws after that catch.
 *
 * See tests/README.md, "Deprecations Fail the Run".
 */
final class BootstrapDeprecationGuard
{
    private static bool $active = false;

    private static string $root = '';

    /** @var array<string, true> relative path => true */
    private static array $committed = [];

    /** @var callable|null */
    private static $previous = null;

    /** @var list<string> */
    private static array $recorded = [];

    /** @var array<string, list<string>> project root => committedPhpFiles() result */
    private static array $committedCache = [];

    /**
     * Every PHP file tracked in git, outside tests/, as paths relative to the
     * project root. Issue2491RegressionTest uses the same list to check that
     * each phpunit*.xml `<source>` covers it. The list is read once per
     * process: the tracked set does not change during a run.
     *
     * @return list<string>
     * @throws RuntimeException when git fails or lists no file, so a missing
     *                          git cannot turn the guard into a silent no-op
     */
    public static function committedPhpFiles(string $projectRoot): array
    {
        if (isset(self::$committedCache[$projectRoot])) {
            return self::$committedCache[$projectRoot];
        }

        $output = [];
        $status = 0;
        exec('git -C ' . escapeshellarg($projectRoot) . " ls-files -- '*.php' 2>&1", $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("git ls-files failed in {$projectRoot} (exit {$status}): " . implode("\n", $output));
        }

        $files = array_values(array_filter(
            $output,
            static fn (string $path): bool => $path !== '' && !str_starts_with($path, 'tests/')
        ));
        if ($files === []) {
            throw new RuntimeException("git ls-files listed no PHP file outside tests/ in {$projectRoot}");
        }

        return self::$committedCache[$projectRoot] = $files;
    }

    /**
     * @param list<string>|null $committedFiles relative paths in scope. Null
     *                                          reads them from committedPhpFiles().
     *                                          Tests pass a fixture list.
     * @throws LogicException   when the guard is already active
     * @throws RuntimeException when the root does not exist, or from
     *                          committedPhpFiles()
     */
    public static function start(string $projectRoot, ?array $committedFiles = null): void
    {
        if (self::$active) {
            throw new LogicException('BootstrapDeprecationGuard::start() called twice without stop()');
        }

        $root = realpath($projectRoot);
        if ($root === false) {
            throw new RuntimeException("Project root does not exist: {$projectRoot}");
        }

        self::$root = $root;
        self::$committed = array_fill_keys($committedFiles ?? self::committedPhpFiles($root), true);
        self::$recorded = [];
        self::$previous = set_error_handler(self::handle(...));
        self::$active = true;
    }

    /**
     * Restore the previous handler. Then throw if a committed file raised a
     * deprecation since start().
     *
     * @throws LogicException   when the guard is not active
     * @throws RuntimeException that lists each recorded deprecation
     */
    public static function stop(): void
    {
        if (!self::$active) {
            throw new LogicException('BootstrapDeprecationGuard::stop() called without start()');
        }

        restore_error_handler();
        self::$active = false;
        self::$previous = null;

        if (self::$recorded !== []) {
            throw new RuntimeException(
                "A committed file raised a deprecation while the PHPUnit bootstrap loaded it (#2491):\n"
                    . implode("\n", self::$recorded)
            );
        }
    }

    private static function handle(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        // The @ operator masks error_reporting(). PHPUnit does not fail on a
        // suppressed deprecation either.
        if (
            ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED)
            && (error_reporting() & $errno) !== 0
            && self::isCommitted($errfile)
        ) {
            self::$recorded[] = "{$errfile}:{$errline} {$errstr}";
            return true;
        }

        if (self::$previous === null) {
            return false;
        }

        // A handler that returns nothing still handles the error. Only false
        // passes it on to PHP.
        return (self::$previous)($errno, $errstr, $errfile, $errline) !== false;
    }

    private static function isCommitted(string $file): bool
    {
        $real = realpath($file);
        if ($real === false || !str_starts_with($real, self::$root . '/')) {
            return false;
        }

        return isset(self::$committed[substr($real, strlen(self::$root) + 1)]);
    }
}
