<?php

declare(strict_types=1);

/**
 * ApplicationVersion class
 *
 * Provides a static method to get the current git commit hash and date for versioning.
 * Useful for displaying application version info in the UI or logs.
 */
class ApplicationVersion
{
    /**
     * Returns the current version from VERSION file with deployment timestamp.
     * @return string Version string
     */
    public static function get(): string
    {
        $versionFile = dirname(__DIR__) . '/VERSION';

        if (file_exists($versionFile)) {
            $version    = trim((string) file_get_contents($versionFile));
            $deployTime = filemtime($versionFile);
        } else {
            // Last-resort fallback: VERSION is absent when git hooks have not been configured
            // (e.g. fresh clone before running setup-git-hooks.sh, CI environments, GitHub
            // Actions). The command is a hardcoded string with no user input — safe to use.
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
            $gitVersion = trim((string) shell_exec('git describe --tags --always 2>/dev/null'));
            $version    = $gitVersion !== '' ? $gitVersion : 'unknown';
            $deployTime = time();
        }

        $deployDate = new \DateTime('@' . $deployTime);
        $deployDate->setTimezone(new \DateTimeZone('PST'));

        return sprintf('%s (%s)', $version, $deployDate->format('Y-m-d H:i:s'));
    }
}

// Usage example:
// echo 'MyApplication ' . ApplicationVersion::get();
