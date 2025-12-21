<?php
declare(strict_types=1);

/**
 * DEPRECATED: Backup functionality has been refactored
 *
 * As of v2.9.2, all backup functionality has been moved into the BackupManager class
 * for better OOP architecture and maintainability.
 *
 * This file remains for backward compatibility and redirects to the new locations:
 * - Core logic: app/admin/includes/classes/BackupManager.php
 * - Compatibility wrapper: users/helpers/backup_functions.php (for legacy FIX scripts)
 *
 * @deprecated Since v2.9.2
 * @see BackupManager class for new code (OOP approach)
 * @see users/helpers/backup_functions.php for legacy function wrappers
 */

global $abs_us_root, $us_url_root;

// Include the compatibility wrapper which provides all the original functions
require_once $abs_us_root . $us_url_root . 'users/helpers/backup_functions.php';

