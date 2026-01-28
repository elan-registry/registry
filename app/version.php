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
    public static function get()
    {
        // Get version from static VERSION file
        $versionFile = dirname(__DIR__) . '/VERSION';
        $version = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : 'unknown';
        
        // Get deployment timestamp from file modification time or current time
        $deployTime = file_exists($versionFile) ? filemtime($versionFile) : time();
        $deployDate = new \DateTime('@' . $deployTime);
        $deployDate->setTimezone(new \DateTimeZone('PST'));
        
        return sprintf('%s (%s)', $version, $deployDate->format('Y-m-d H:i:s'));
    }
}

// Usage example:
// echo 'MyApplication ' . ApplicationVersion::get();
