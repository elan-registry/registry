<?php
declare(strict_types=1);

/**
 * Backup Operations Handler
 *
 * Handles AJAX requests for backup management from the admin panel
 */

require_once '../../../../users/init.php';

// Set JSON response header
header('Content-Type: application/json');

// Security check
if (!securePage($php_self)) {
    ApiResponse::forbidden('Access denied')
        ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized backup operations access attempt')
        ->send();
}

// Only administrators can perform backup operations
if (!hasPerm([2], $user->data()->id)) {
    ApiResponse::forbidden('Administrator access required')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY,
            'Non-admin attempted backup operations')
        ->send();
}

// CSRF protection for all POST operations
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in backup operations')
        ->send();
}

// Get action
$action = $_POST['action'] ?? '';

try {
    // Initialize BackupManager with global configuration constant
    $backupDir = $abs_us_root . $us_url_root . BACKUP_BASE_DIR;
    // Cast user ID to int for strict type safety across different PHP/database configurations
    $backupManager = new BackupManager($db, $backupDir, (int)$user->data()->id);

    switch ($action) {
        case 'create_manual_backup':
            // Log backup initiation with details
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Manual backup initiated by {$user->data()->username}");

            // Get reason (default to Admin Panel Manual Backup)
            $reason = $_POST['reason'] ?? 'Admin Panel Manual Backup';

            // Log the reason for debugging
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_DEBUG, "Backup reason: '{$reason}'");

            // Critical tables for backup
            $criticalTables = ['users', 'cars', 'car_user', 'profiles', 'settings', 'car_history', 'fix_script_runs'];

            // Log tables being backed up
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_DEBUG, "Tables to backup: " . implode(', ', $criticalTables));

            // Create backup
            try {
                $backupPath = $backupManager->createManualBackup(
                    $reason,
                    $criticalTables,
                    ['user_id' => $user->data()->id, 'username' => $user->data()->username]
                );
            } catch (Exception $e) {
                // Log specific error from BackupManager
                logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, "BackupManager threw exception: " . $e->getMessage() . " in " . $e->getFile() . " at line " . $e->getLine());
                throw $e; // Re-throw to be caught by outer try-catch
            }

            // Get file info
            $filename = basename($backupPath);
            $filesize = filesize($backupPath);
            $sizeFormatted = formatBytes($filesize);

            // Log successful backup
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Manual backup completed: {$filename} ({$sizeFormatted})");

            ApiResponse::success('Backup created successfully')
                ->withDataArray([
                    'filename' => $filename,
                    'size' => $sizeFormatted,
                    'path' => $backupPath
                ])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    "Manual backup completed via API: {$filename} ({$sizeFormatted})")
                ->send();
            break;

        case 'list_backups':
            // Log backup list request
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Backup list requested by {$user->data()->username}");

            $backups = [
                'automated' => [],
                'manual' => [],
                'rollback' => []
            ];

            // Get backups from each directory
            foreach (['automated', 'manual', 'rollback'] as $type) {
                $typeDir = $backupDir . $type . '/';

                if (is_dir($typeDir)) {
                    // Get both .sql and .php backup files
                    $sqlFiles = glob($typeDir . '*.sql');
                    $phpFiles = glob($typeDir . '*.php');
                    $files = array_merge($sqlFiles ?: [], $phpFiles ?: []);

                    foreach ($files as $file) {
                        if (is_file($file)) {
                            $backups[$type][] = [
                                'filename' => basename($file),
                                'size' => filesize($file),
                                'size_formatted' => formatBytes(filesize($file)),
                                'created' => date('Y-m-d H:i:s', filemtime($file)),
                                'age_hours' => round((time() - filemtime($file)) / 3600, 1)
                            ];
                        }
                    }

                    // Sort by created date, newest first
                    usort($backups[$type], function($a, $b) {
                        return strtotime($b['created']) - strtotime($a['created']);
                    });
                }
            }

            // Get statistics
            $stats = $backupManager->getEnhancedBackupStatistics();

            ApiResponse::success('Backup list retrieved')
                ->withDataArray([
                    'backups' => $backups,
                    'statistics' => [
                        'automated' => $stats['automated'],
                        'manual' => $stats['manual'],
                        'rollback' => $stats['rollback'],
                        'health_score' => $stats['health_score']
                    ]
                ])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    'Backup list retrieved via API')
                ->send();
            break;

        case 'preview_cleanup':
            // Get list of files that would be deleted without actually deleting them
            $filesToDelete = [
                'automated' => [],
                'manual' => [],
                'rollback' => []
            ];

            foreach (['automated', 'manual', 'rollback'] as $type) {
                $typeDir = $backupDir . $type . '/';

                if (is_dir($typeDir)) {
                    // Get both .sql and .php backup files
                    $sqlFiles = glob($typeDir . '*.sql');
                    $phpFiles = glob($typeDir . '*.php');
                    $files = array_merge($sqlFiles ?: [], $phpFiles ?: []);

                    // Get retention policy for this type
                    $retentionDays = 7; // Default
                    if ($type === 'automated') {
                        $retentionDays = 7; // development
                    } elseif ($type === 'manual') {
                        $retentionDays = 14; // development
                    } elseif ($type === 'rollback') {
                        $retentionDays = 14; // development
                    }

                    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);

                    foreach ($files as $file) {
                        if (is_file($file) && filemtime($file) < $cutoffTime) {
                            $filesToDelete[$type][] = [
                                'filename' => basename($file),
                                'size' => filesize($file),
                                'size_formatted' => formatBytes(filesize($file)),
                                'age_days' => round((time() - filemtime($file)) / 86400, 1),
                                'created' => date('Y-m-d H:i:s', filemtime($file))
                            ];
                        }
                    }
                }
            }

            // Calculate totals
            $totalFiles = count($filesToDelete['automated']) +
                         count($filesToDelete['manual']) +
                         count($filesToDelete['rollback']);

            ApiResponse::success('Cleanup preview generated')
                ->withDataArray([
                    'files' => $filesToDelete,
                    'total_count' => $totalFiles
                ])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    "Cleanup preview: {$totalFiles} files to delete")
                ->send();
            break;

        case 'cleanup_backups':
            // Log cleanup initiation
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Backup cleanup initiated by {$user->data()->username}");

            // Perform enhanced cleanup
            $cleanupResult = $backupManager->performEnhancedCleanup();

            // Calculate totals
            $totalScanned = $cleanupResult['automated']['scanned'] +
                           $cleanupResult['manual']['scanned'] +
                           $cleanupResult['rollback']['scanned'];
            $totalDeleted = $cleanupResult['automated']['deleted'] +
                           $cleanupResult['manual']['deleted'] +
                           $cleanupResult['rollback']['deleted'];

            // Log cleanup results
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Backup cleanup completed: {$totalDeleted} of {$totalScanned} files deleted (Automated: {$cleanupResult['automated']['deleted']}, Manual: {$cleanupResult['manual']['deleted']}, Rollback: {$cleanupResult['rollback']['deleted']})");

            ApiResponse::success("Cleanup completed: {$totalDeleted} of {$totalScanned} files deleted")
                ->withDataArray([
                    'cleanup' => $cleanupResult,
                    'totals' => [
                        'scanned' => $totalScanned,
                        'deleted' => $totalDeleted
                    ]
                ])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    "Backup cleanup completed via API: {$totalDeleted}/{$totalScanned} deleted")
                ->send();
            break;

        case 'delete_backup':
            // Log delete request
            $filename = $_POST['filename'] ?? '';
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Delete backup requested: {$filename}");

            if (empty($filename)) {
                ApiResponse::error('Backup filename required', 400)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, 'Delete backup: filename missing')
                    ->send();
            }

            // Find and delete the backup file from any of the three directories
            $deleted = false;
            $types = ['automated', 'manual', 'rollback'];

            foreach ($types as $type) {
                $filepath = $backupDir . $type . '/' . basename($filename);

                if (file_exists($filepath)) {
                    if (unlink($filepath)) {
                        $deleted = true;
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                            "Backup deleted via API: {$filename} (type: {$type})");
                        break;
                    } else {
                        ApiResponse::error('Failed to delete backup file', 500)
                            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                                "Failed to unlink backup file: {$filepath}")
                            ->send();
                    }
                }
            }

            if (!$deleted) {
                ApiResponse::error('Backup file not found', 404)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                        "Backup file not found for deletion: {$filename}")
                    ->send();
            }

            ApiResponse::success('Backup deleted successfully')
                ->withDataArray(['filename' => $filename])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    "Backup deletion completed via API: {$filename}")
                ->send();
            break;

        default:
            ApiResponse::error('Invalid action', 400)
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, "Invalid backup action: {$action}")
                ->send();
            break;
    }

} catch (Exception $e) {
    // Log detailed error with stack trace
    $errorDetails = "Backup operation '{$action}' failed for user {$user->data()->username}\n";
    $errorDetails .= "Error: " . $e->getMessage() . "\n";
    $errorDetails .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    $errorDetails .= "Request params: " . json_encode($_POST) . "\n";
    $errorDetails .= "Stack trace:\n" . $e->getTraceAsString();

    logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, $errorDetails);

    ApiResponse::serverError($e->getMessage())
        ->withDataArray([
            'error_details' => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
                'action' => $action
            ]
        ])
        ->send();
}

/**
 * Format bytes to human-readable size
 *
 * @param int $bytes
 * @return string
 */
function formatBytes($bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}
