<?php
declare(strict_types=1);

use ElanRegistry\Admin\BackupManager;
use ElanRegistry\ApiResponse;
use ElanRegistry\LogCategories;

/**
 * Backup Operations Handler
 *
 * Handles AJAX requests for backup management from the admin panel
 */

require_once '../../../../users/init.php';

// Set JSON response header
header('Content-Type: application/json');

if ($method !== 'POST') {
    ApiResponse::error('Method not allowed', 405)
        ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, "Method not allowed: {$method} on backup-operations")
        ->send();
}

// Security check
if (!securePage($php_self)) {
    ApiResponse::forbidden('Access denied')
        ->withLogging(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized backup operations access attempt')
        ->send();
}

if (!isAdmin()) {
    ApiResponse::forbidden('Administrator access required')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Editor attempted backup operation without admin role')
        ->send();
}

// CSRF protection for all POST operations
if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
    ApiResponse::forbidden('Invalid CSRF token')
        ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid CSRF token in backup operations')
        ->send();
}

// Get action — allow only word characters and hyphens (safe for routing, logging, and display)
$action = preg_replace('/[^\w\-]/', '', $_POST['action'] ?? '') ?? '';

try {
    // Initialize BackupManager with global configuration constant
    $backupDir = $abs_us_root . $us_url_root . BACKUP_BASE_DIR;
    // Cast user ID to int for strict type safety across different PHP/database configurations
    $backupManager = new BackupManager($db, $backupDir, (int)$user->data()->id);

    switch ($action) {
        case 'create_manual_backup':
            // Log backup initiation with details
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Manual backup initiated by {$user->data()->username}");

            // Get reason — strip control characters to prevent log injection; default to standard reason if empty
            $reason = preg_replace('/[\x00-\x1f\x7f]/', '', $_POST['reason'] ?? '') ?: 'Admin Panel Manual Backup';

            // Log the reason for debugging
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_DEBUG, "Backup reason: '{$reason}'");

            // Critical tables for backup
            $criticalTables = ['users', 'cars', 'profiles', 'settings', 'car_history', 'fix_script_runs'];

            // Log tables being backed up
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_DEBUG, "Tables to backup: " . implode(', ', $criticalTables));

            // Create backup
            $backupPath = $backupManager->createManualBackup(
                $reason,
                $criticalTables,
                ['user_id' => $user->data()->id, 'username' => $user->data()->username]
            );

            // Get file info
            $filename = basename($backupPath);
            $rawSize = filesize($backupPath);
            if ($rawSize === false) {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                    "filesize() failed for backup at {$backupPath} — backup was created but size is unavailable");
                $sizeFormatted = 'unknown size';
            } else {
                $sizeFormatted = formatBytes($rawSize);
            }

            ApiResponse::success('Backup created successfully')
                ->withDataArray([
                    'filename' => $filename,
                    'size' => $sizeFormatted,
                ])
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                    "Manual backup completed via API: {$filename} ({$sizeFormatted})")
                ->send(); // never returns

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
                        if (!is_file($file)) {
                            continue;
                        }
                        $rawSize = filesize($file);
                        $mtime = filemtime($file);
                        if ($rawSize === false || $mtime === false) {
                            continue;
                        }
                        $backups[$type][] = [
                            'filename' => basename($file),
                            'size' => $rawSize,
                            'size_formatted' => formatBytes($rawSize),
                            'created' => date('Y-m-d H:i:s', $mtime),
                            'age_hours' => round((time() - $mtime) / 3600, 1)
                        ];
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
                ->send(); // never returns

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

                    // Get retention policy from config.php constants (authoritative source)
                    $retentionDays = match($type) {
                        'automated' => BACKUP_RETENTION_AUTOMATED,
                        'manual'    => BACKUP_RETENTION_MANUAL,
                        'rollback'  => BACKUP_RETENTION_ROLLBACK,
                    };

                    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);

                    foreach ($files as $file) {
                        $mtime = is_file($file) ? filemtime($file) : false;
                        if ($mtime === false || $mtime >= $cutoffTime) {
                            continue;
                        }
                        $rawSize = filesize($file);
                        if ($rawSize === false) {
                            continue;
                        }
                        $filesToDelete[$type][] = [
                            'filename' => basename($file),
                            'size' => $rawSize,
                            'size_formatted' => formatBytes($rawSize),
                            'age_days' => round((time() - $mtime) / 86400, 1),
                            'created' => date('Y-m-d H:i:s', $mtime)
                        ];
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
                ->send(); // never returns

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
                ->send(); // never returns

        case 'delete_backup':
            // basename() strips ../ traversal sequences; realpath() below provides defense-in-depth against symlinks — both are required
            $filename = basename($_POST['filename'] ?? '');
            logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER, "Delete backup requested: {$filename}");

            // All validation branches below call ->send() which always calls exit.
            // No break statements: PHPStan types send() as never-returning, so a
            // break after send() would be flagged as unreachable dead code.
            if (empty($filename)) {
                ApiResponse::error('Backup filename required', 400)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, 'Delete backup: filename missing')
                    ->send();
            }

            // Validate backup file extension — only .sql files can be deleted
            if (!str_ends_with($filename, '.sql')) {
                ApiResponse::error('Invalid backup file type', 400)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                        "Delete backup: invalid file extension for '{$filename}'")
                    ->send();
            }

            // Find and delete the backup file from any of the three directories
            $deleted = false;
            $types = ['automated', 'manual', 'rollback'];

            $realBackupDir = realpath($backupDir);
            if ($realBackupDir === false) {
                ApiResponse::error('Backup directory unavailable', 500)
                    ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                        "Delete backup: realpath() failed for backup dir '{$backupDir}' — server misconfiguration")
                    ->send();
            }

            foreach ($types as $type) {
                $filepath = $backupDir . $type . '/' . $filename;

                if (file_exists($filepath)) {
                    $realpath = realpath($filepath);
                    if ($realpath === false || !str_starts_with($realpath, $realBackupDir . '/')) {
                        ApiResponse::error('Access denied', 403)
                            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                                "Delete backup: path traversal attempt blocked for '{$filename}'")
                            ->send();
                    }
                    if (unlink($realpath)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- path verified within backup directory
                        $deleted = true;
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_MANAGER,
                            "Backup deleted via API: {$filename} (type: {$type})");
                        break;
                    } else {
                        ApiResponse::error('Failed to delete backup file', 500)
                            ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR,
                                "Failed to unlink backup file: {$realpath}")
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
                ->send(); // never returns

        default:
            ApiResponse::error('Invalid action', 400)
                ->withLogging($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, "Invalid backup action: {$action}")
                ->send(); // never returns
    }

} catch (\Throwable $e) {
    // Log detailed error with stack trace
    $errorDetails = "Backup operation '{$action}' failed for user {$user->data()->username}\n";
    $errorDetails .= "Error: " . $e->getMessage() . "\n";
    $errorDetails .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    $errorDetails .= "Request action: " . ($action ?: 'none') . "\n";
    $errorDetails .= "Stack trace:\n" . $e->getTraceAsString();

    logger($user->data()->id, LogCategories::LOG_CATEGORY_BACKUP_ERROR, $errorDetails);

    ApiResponse::serverError('Backup operation failed')
        ->send();
}

/**
 * Format bytes to human-readable size
 *
 * @param int $bytes
 * @return string
 */
function formatBytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}
