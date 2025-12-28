<?php
declare(strict_types=1);

/**
 * Backup Functions Compatibility Layer
 *
 * Provides backward-compatible standalone functions for legacy FIX scripts.
 * All functionality is delegated to the BackupManager class.
 *
 * @deprecated Use BackupManager class directly for new code
 * @see app/admin/includes/classes/BackupManager.php
 * @since v2.9.2
 */

// Ensure BackupManager class is available
require_once __DIR__ . '/../../app/admin/includes/classes/BackupManager.php';

// Define backup directory constant to avoid duplication
define('BACKUP_DIR_PATH', 'FIX/backups/');

/**
 * Create standardized backup (compatibility wrapper)
 *
 * @param string $scriptName Script identifier (kebab-case)
 * @param array $tables Tables to backup
 * @param string $type Type of backup: 'automated', 'manual', 'rollback'
 * @return string Path to created backup file
 * @throws Exception If backup creation fails
 * @deprecated Use BackupManager class directly
 */
function createStandardizedBackup($scriptName, $tables = [], $type = 'automated') {
    global $db, $abs_us_root, $us_url_root;

    // Create BackupManager instance
    $backupDir = $abs_us_root . $us_url_root . BACKUP_DIR_PATH;
    $backupManager = new BackupManager($db, $backupDir);

    // Delegate to BackupManager based on type
    if ($type === 'manual') {
        return $backupManager->createManualBackup($scriptName, $tables);
    } else {
        // For automated/rollback, create schema backup
        return $backupManager->createSchemaBackup($scriptName, $tables);
    }
}

/**
 * Get backup statistics (compatibility wrapper)
 *
 * @return array Backup statistics by type
 * @deprecated Use BackupManager::getEnhancedBackupStatistics() instead
 */
function getBackupStatistics() {
    global $db, $abs_us_root, $us_url_root;

    $backupDir = $abs_us_root . $us_url_root . BACKUP_DIR_PATH;
    $backupManager = new BackupManager($db, $backupDir);

    $stats = $backupManager->getEnhancedBackupStatistics();

    // Return just the basic stats for backward compatibility
    return [
        'automated' => $stats['automated'] ?? ['count' => 0, 'total_size' => 0],
        'manual' => $stats['manual'] ?? ['count' => 0, 'total_size' => 0],
        'rollback' => $stats['rollback'] ?? ['count' => 0, 'total_size' => 0]
    ];
}

/**
 * Clean up old backup files (compatibility wrapper)
 *
 * @return array Summary of cleanup actions
 * @deprecated Use BackupManager::performEnhancedCleanup() instead
 */
function cleanupOldBackups() {
    global $db, $abs_us_root, $us_url_root;

    $backupDir = $abs_us_root . $us_url_root . BACKUP_DIR_PATH;
    $backupManager = new BackupManager($db, $backupDir);

    return $backupManager->performEnhancedCleanup();
}

