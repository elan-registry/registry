<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * BrevoOverrideStub - byte-exact sweeper for the leaked Brevo override stub
 *
 * Two integration tests make `brevoReady()` return true by writing a 6-byte
 * file to `usersc/plugins/sendinblue/override.php`. That path is gitignored,
 * so `git status` never reveals a leaked copy, and a stub left behind by a
 * crashed or interrupted run silently changes the dev site's email routing.
 * Teardown/`finally` cleanup cannot cover a fatal, so the suite also sweeps
 * the path at bootstrap.
 *
 * The sweep is deliberately byte-exact: it removes only a file that is
 * precisely {@see self::CONTENT} and nothing else. A real override (a copy of
 * `override.RENAME.php`, 1,193 bytes) — or any hand-edited near-miss such as
 * `"<?php"`, `"<?php\r\n"` or `"<?php\n\n"` — is left untouched. Deleting
 * someone's real override would be far worse than leaving a stub behind.
 *
 * This class performs no output. When it deletes something it returns `true`
 * and the caller (`tests/bootstrap-integration.php`) emits the `NOTE:` to
 * STDERR. `sweep()` also returns `false` when the file matches the stub but
 * could not be deleted (e.g. an unwritable directory) — the caller uses
 * {@see self::matches()} to tell that case apart from "not our stub" and warn
 * about a confirmed leak that stays in place. Keeping the class side-effect-free
 * is what lets the unit tests call `sweep()`/`matches()` directly without
 * capturing or polluting output, and it leaves the wording and destination of
 * the note with the bootstrap that owns them.
 *
 * Test-support code: it deliberately requires no UserSpice or app bootstrap.
 *
 * @package Tests\Support
 * @see https://github.com/elan-registry/registry/issues/2166
 */
final class BrevoOverrideStub
{
    /**
     * The exact byte sequence the integration tests write as the stub.
     *
     * Single definition of the literal: the tests that create the stub and the
     * sweep that removes it must agree byte for byte, or the sweep stops working
     * silently.
     *
     * @var string
     */
    public const CONTENT = "<?php\n";

    /**
     * Whether the file at $path is byte-for-byte the stub.
     *
     * A chmod-0 (unreadable) file cannot have its contents confirmed, so it
     * cannot be reported as a match — this returns false for it, the same as
     * for a near-miss. That is a deliberate limitation, not a bug: this method
     * never warns, and there is no way to distinguish "unreadable stub" from
     * "unreadable non-stub" without reading it.
     *
     * Safe to call on a missing, unreadable, or concurrently-removed path:
     * those all return false without emitting a warning or throwing.
     *
     * @param string $path Absolute path to the candidate override file.
     * @return bool True only if the file exists and matches the stub exactly.
     */
    public static function matches(string $path): bool
    {
        // @ on is_file(): the path can vanish between this check and filesize()/
        // file_get_contents() below (another process racing this sweep). PHP
        // would otherwise emit a warning for a symlink or path component
        // disappearing mid-stat; the explicit false-check below is what
        // actually decides the outcome, not the suppression.
        if (!@is_file($path)) {
            return false;
        }

        // @ for the same concurrent-removal race as above: the file can vanish
        // between is_file() and filesize().
        $size = @filesize($path);
        if ($size !== strlen(self::CONTENT)) {
            return false;
        }

        if (!is_readable($path)) {
            return false;
        }

        // @ for the same race: the file can vanish between the readability
        // check and the actual read.
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        return $contents === self::CONTENT;
    }

    /**
     * Delete the file at $path only if it is byte-for-byte the stub.
     *
     * Safe to call on a missing, unreadable or concurrently-removed path: those
     * all return false without emitting a warning or throwing. Nothing is
     * printed on success — the caller decides whether to report the removal.
     *
     * Returns false both when $path is not our stub, and when it is our exact
     * stub but could not be deleted (unwritable directory, permissions, races
     * losing to another process). The caller distinguishes the two cases by
     * calling {@see self::matches()} itself when it needs to warn about a
     * confirmed-but-undeletable stub.
     *
     * @param string $path Absolute path to the candidate override file.
     * @return bool True only if the file matched the stub exactly and was deleted.
     */
    public static function sweep(string $path): bool
    {
        if (!self::matches($path)) {
            return false;
        }

        // @ for the same concurrent-removal race as matches(): the file can
        // vanish between matches() confirming it and this unlink() call.
        return is_writable(dirname($path)) && @unlink($path);
    }
}
