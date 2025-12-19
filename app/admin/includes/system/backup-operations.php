<?php
declare(strict_types=1);

/**
 * Backup Operations Handler
 *
 * Handles AJAX requests for backup management from the admin panel
 */

require_once '../../../../users/init.php';
require_once '../classes/BackupManager.php';

// Set JSON response header
header('Content-Type: application/json');

// Security check
if (!securePage($_SERVER['PHP_SELF'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Only administrators can perform backup operations
if (!hasPerm([2], $user->data()->id)) {
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

// Get action
$action = $_POST['action'] ?? '';

try {
    // Initialize BackupManager
    $backupDir = $abs_us_root . $us_url_root . 'FIX/backups/';
    $backupManager = new BackupManager($db, $backupDir, $user->data()->id);

    switch ($action) {
        case 'create_manual_backup':
            // Log backup initiation with details
            logger($user->data()->id, 'BackupManager', "Manual backup initiated by {$user->data()->username}");

            // Get reason (default to Admin Panel Manual Backup)
            $reason = $_POST['reason'] ?? 'Admin Panel Manual Backup';

            // Log the reason for debugging
            logger($user->data()->id, 'BackupDebug', "Backup reason: '{$reason}'");

            // Critical tables for backup
            $criticalTables = ['users', 'cars', 'car_user', 'profiles', 'settings', 'car_history', 'fix_script_runs'];

            // Log tables being backed up
            logger($user->data()->id, 'BackupDebug', "Tables to backup: " . implode(', ', $criticalTables));

            // Create backup
            try {
                $backupPath = $backupManager->createManualBackup(
                    $reason,
                    $criticalTables,
                    ['user_id' => $user->data()->id, 'username' => $user->data()->username]
                );
            } catch (Exception $e) {
                // Log specific error from BackupManager
                logger($user->data()->id, 'BackupError', "BackupManager threw exception: " . $e->getMessage() . " in " . $e->getFile() . " at line " . $e->getLine());
                throw $e; // Re-throw to be caught by outer try-catch
            }

            // Get file info
            $filename = basename($backupPath);
            $filesize = filesize($backupPath);
            $sizeFormatted = formatBytes($filesize);

            // Log successful backup
            logger($user->data()->id, 'BackupManager', "Manual backup completed: {$filename} ({$sizeFormatted})");

            echo json_encode([
                'success' => true,
                'message' => 'Backup created successfully',
                'filename' => $filename,
                'size' => $sizeFormatted,
                'path' => $backupPath
            ]);
            break;

        case 'list_backups':
            // Log backup list request
            logger($user->data()->id, 'BackupManager', "Backup list requested by {$user->data()->username}");

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

            echo json_encode([
                'success' => true,
                'backups' => $backups,
                'statistics' => [
                    'automated' => $stats['automated'],
                    'manual' => $stats['manual'],
                    'rollback' => $stats['rollback'],
                    'health_score' => $stats['health_score']
                ]
            ]);
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

            echo json_encode([
                'success' => true,
                'files' => $filesToDelete,
                'total_count' => $totalFiles
            ]);
            break;

        case 'cleanup_backups':
            // Log cleanup initiation
            logger($user->data()->id, 'BackupManager', "Backup cleanup initiated by {$user->data()->username}");

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
            logger($user->data()->id, 'BackupManager', "Backup cleanup completed: {$totalDeleted} of {$totalScanned} files deleted (Automated: {$cleanupResult['automated']['deleted']}, Manual: {$cleanupResult['manual']['deleted']}, Rollback: {$cleanupResult['rollback']['deleted']})");

            echo json_encode([
                'success' => true,
                'message' => "Cleanup completed: {$totalDeleted} of {$totalScanned} files deleted",
                'cleanup' => $cleanupResult,
                'totals' => [
                    'scanned' => $totalScanned,
                    'deleted' => $totalDeleted
                ]
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }

} catch (Exception $e) {
    // Log detailed error with stack trace
    $errorDetails = "Backup operation '{$action}' failed for user {$user->data()->username}\n";
    $errorDetails .= "Error: " . $e->getMessage() . "\n";
    $errorDetails .= "File: " . $e->getFile() . " (Line " . $e->getLine() . ")\n";
    $errorDetails .= "Request params: " . json_encode($_POST) . "\n";
    $errorDetails .= "Stack trace:\n" . $e->getTraceAsString();

    logger($user->data()->id, 'BackupError', $errorDetails);

    // Also log to PHP error log for debugging
    error_log("BackupManager Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    error_log("Stack trace: " . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_details' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'action' => $action
        ]
    ]);
}

/**
 * Format bytes to human-readable size
 *
 * @param int $bytes
 * @return string
 */
function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, 2) . ' ' . $units[$pow];
}
