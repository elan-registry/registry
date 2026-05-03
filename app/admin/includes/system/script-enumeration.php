<?php

declare(strict_types=1);

/**
 * Enumerate valid runnable PHP script files in an admin scripts directory.
 *
 * Filters out directories, non-PHP files, template/index stubs, and
 * backup- or test-prefixed filenames that are not meant to be run directly.
 * Returns an empty array without error if $directory does not exist.
 *
 * @param string $directory Absolute path to the directory to scan.
 * @return array<string> Unsorted list of valid PHP filenames (basenames only); callers are responsible for sorting.
 */
function enumerateScriptFiles(string $directory): array
{
    $directory = rtrim($directory, '/') . '/';
    $allItems = is_dir($directory) ? (scandir($directory) ?: []) : [];
    $scripts  = [];

    foreach ($allItems as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (is_dir($directory . $item) || pathinfo($item, PATHINFO_EXTENSION) !== 'php') {
            continue;
        }
        if (in_array($item, ['index.php', '_TEMPLATE_Fix-Script.php', 'backup-functions.php'], true)) {
            continue;
        }
        if (preg_match('/^(backup_|rollback_|.*_backup_|test-)/', $item)) {
            continue;
        }
        $scripts[] = $item;
    }

    return $scripts;
}
