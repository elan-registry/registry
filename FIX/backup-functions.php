<?php
/**
 * Standardized Backup Management Functions
 *
 * Provides consistent backup creation, cleanup, and management for FIX scripts.
 * Implements the standardized naming convention and directory structure.
 */

/**
 * Create a standardized backup file
 *
 * @param string $scriptName Script identifier (kebab-case)
 * @param array $tables Tables to backup
 * @param string $type Type of backup: 'automated', 'manual', 'rollback'
 * @param string $environment Environment: 'development', 'test', 'production'
 * @return string Path to created backup file
 * @throws Exception If backup creation fails
 */
function createStandardizedBackup($scriptName, $tables = [], $type = 'automated', $environment = 'development') {
    global $db, $abs_us_root, $us_url_root;

    // Validate parameters
    $validTypes = ['automated', 'manual', 'rollback'];
    $validEnvironments = ['development', 'test', 'production'];

    if (!in_array($type, $validTypes)) {
        throw new Exception("Invalid backup type: $type. Must be one of: " . implode(', ', $validTypes));
    }

    if (!in_array($environment, $validEnvironments)) {
        throw new Exception("Invalid environment: $environment. Must be one of: " . implode(', ', $validEnvironments));
    }

    // Generate standardized filename
    $timestamp = date('Ymd_His');
    $filename = "{$type}_{$scriptName}_{$environment}_{$timestamp}.sql";

    // Determine backup directory
    $backupDir = $abs_us_root . $us_url_root . "FIX/backups/{$type}/";

    // Ensure backup directory exists
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0755, true)) {
            throw new Exception("Failed to create backup directory: $backupDir");
        }
    }

    $backupPath = $backupDir . $filename;

    // Create backup content with metadata
    $backupContent = generateBackupMetadata($scriptName, $type, $environment, $tables);

    // Add table dumps
    if (!empty($tables)) {
        foreach ($tables as $table) {
            $backupContent .= generateTableDump($table);
        }
    }

    // Write backup file
    if (!file_put_contents($backupPath, $backupContent)) {
        throw new Exception("Failed to write backup file: $backupPath");
    }

    logBackupCreation($scriptName, $type, $environment, $backupPath);

    return $backupPath;
}

/**
 * Generate backup metadata header
 *
 * @param string $scriptName Script identifier
 * @param string $type Backup type
 * @param string $environment Environment
 * @param array $tables Tables being backed up
 * @return string SQL comment block with metadata
 */
function generateBackupMetadata($scriptName, $type, $environment, $tables) {
    $timestamp = date('Y-m-d H:i:s');
    $tableList = implode(', ', $tables);

    $retentionDays = getRetentionDays($type, $environment);
    $rollbackReady = !empty($tables) ? 'yes' : 'no';

    return "-- BACKUP METADATA\n" .
           "-- Type: {$type}\n" .
           "-- Script: {$scriptName}\n" .
           "-- Environment: {$environment}\n" .
           "-- Created: {$timestamp}\n" .
           "-- Tables: {$tableList}\n" .
           "-- Retention: {$retentionDays} days\n" .
           "-- Rollback-Ready: {$rollbackReady}\n" .
           "-- Generator: FIX Script Backup System v1.0\n" .
           "\n";
}

/**
 * Generate SQL dump for a table
 *
 * @param string $tableName Table to dump
 * @return string SQL dump content
 */
function generateTableDump($tableName) {
    global $db;

    try {
        // Get table structure
        $createResult = $db->query("SHOW CREATE TABLE `{$tableName}`");
        if ($createResult->count() === 0) {
            return "-- Warning: Table {$tableName} not found\n\n";
        }

        $createStatement = $createResult->first()->{'Create Table'};

        // Get table data
        $dataResult = $db->query("SELECT * FROM `{$tableName}`");

        $dump = "-- Dump for table: {$tableName}\n";
        $dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
        $dump .= $createStatement . ";\n\n";

        if ($dataResult->count() > 0) {
            $dump .= "-- Data for table: {$tableName}\n";

            foreach ($dataResult->results() as $row) {
                $values = [];
                foreach ($row as $value) {
                    if (is_null($value)) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . addslashes($value) . "'";
                    }
                }
                $dump .= "INSERT INTO `{$tableName}` VALUES (" . implode(', ', $values) . ");\n";
            }
        }

        return $dump . "\n";

    } catch (Exception $e) {
        return "-- Error backing up table {$tableName}: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Get retention days based on backup type and environment
 *
 * @param string $type Backup type
 * @param string $environment Environment
 * @return int Number of retention days
 */
function getRetentionDays($type, $environment) {
    $retentionPolicies = [
        'development' => [
            'automated' => 7,
            'manual' => 14,
            'rollback' => 14
        ],
        'test' => [
            'automated' => 14,
            'manual' => 30,
            'rollback' => 30
        ],
        'production' => [
            'automated' => 30,
            'manual' => 90,
            'rollback' => 60
        ]
    ];

    return $retentionPolicies[$environment][$type] ?? 30;
}

/**
 * Clean up old backup files based on retention policy
 *
 * @param int $retentionDays Override retention days (optional)
 * @return array Summary of cleanup actions
 */
function cleanupOldBackups($retentionDays = null) {
    global $abs_us_root, $us_url_root;

    $backupBaseDir = $abs_us_root . $us_url_root . 'FIX/backups/';
    $cleanupSummary = [
        'automated' => ['scanned' => 0, 'deleted' => 0],
        'manual' => ['scanned' => 0, 'deleted' => 0],
        'rollback' => ['scanned' => 0, 'deleted' => 0]
    ];

    $types = ['automated', 'manual', 'rollback'];

    foreach ($types as $type) {
        $typeDir = $backupBaseDir . $type . '/';

        if (!is_dir($typeDir)) {
            continue;
        }

        $files = glob($typeDir . '*');
        $cleanupSummary[$type]['scanned'] = count($files);

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $filename = basename($file);
            $environment = extractEnvironmentFromFilename($filename);

            $fileRetentionDays = $retentionDays ?? getRetentionDays($type, $environment);
            $cutoffTime = time() - ($fileRetentionDays * 24 * 60 * 60);

            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $cleanupSummary[$type]['deleted']++;
                    logBackupDeletion($filename, $type, $environment);
                }
            }
        }
    }

    return $cleanupSummary;
}

/**
 * Extract environment from standardized filename
 *
 * @param string $filename Backup filename
 * @return string Environment (defaults to 'development')
 */
function extractEnvironmentFromFilename($filename) {
    if (preg_match('/_(development|test|production)_/', $filename, $matches)) {
        return $matches[1];
    }
    return 'development';
}

/**
 * Find backup file for rollback purposes
 *
 * @param string $scriptName Script identifier
 * @param string $environment Environment
 * @param DateTime|null $beforeDate Find backup before this date (optional)
 * @return string|null Path to backup file or null if not found
 */
function findBackupForRollback($scriptName, $environment, $beforeDate = null) {
    global $abs_us_root, $us_url_root;

    $backupDirs = [
        $abs_us_root . $us_url_root . 'FIX/backups/automated/',
        $abs_us_root . $us_url_root . 'FIX/backups/manual/',
        $abs_us_root . $us_url_root . 'FIX/backups/rollback/'
    ];

    $candidates = [];

    foreach ($backupDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $pattern = "*_{$scriptName}_{$environment}_*.sql";
        $files = glob($dir . $pattern);

        foreach ($files as $file) {
            $mtime = filemtime($file);

            if ($beforeDate && $mtime >= $beforeDate->getTimestamp()) {
                continue;
            }

            $candidates[] = [
                'file' => $file,
                'time' => $mtime
            ];
        }
    }

    if (empty($candidates)) {
        return null;
    }

    // Sort by modification time, newest first
    usort($candidates, function($a, $b) {
        return $b['time'] - $a['time'];
    });

    return $candidates[0]['file'];
}

/**
 * Log backup creation event
 *
 * @param string $scriptName Script identifier
 * @param string $type Backup type
 * @param string $environment Environment
 * @param string $backupPath Path to backup file
 */
function logBackupCreation($scriptName, $type, $environment, $backupPath) {
    $logMessage = sprintf(
        "[%s] Backup created - Script: %s, Type: %s, Environment: %s, File: %s",
        date('Y-m-d H:i:s'),
        $scriptName,
        $type,
        $environment,
        basename($backupPath)
    );

    error_log($logMessage);
}

/**
 * Log backup deletion event
 *
 * @param string $filename Deleted filename
 * @param string $type Backup type
 * @param string $environment Environment
 */
function logBackupDeletion($filename, $type, $environment) {
    $logMessage = sprintf(
        "[%s] Backup deleted - File: %s, Type: %s, Environment: %s (retention cleanup)",
        date('Y-m-d H:i:s'),
        $filename,
        $type,
        $environment
    );

    error_log($logMessage);
}

/**
 * Get backup statistics
 *
 * @return array Backup statistics by type
 */
function getBackupStatistics() {
    global $abs_us_root, $us_url_root;

    $backupBaseDir = $abs_us_root . $us_url_root . 'FIX/backups/';
    $stats = [
        'automated' => ['count' => 0, 'total_size' => 0],
        'manual' => ['count' => 0, 'total_size' => 0],
        'rollback' => ['count' => 0, 'total_size' => 0]
    ];

    $types = ['automated', 'manual', 'rollback'];

    foreach ($types as $type) {
        $typeDir = $backupBaseDir . $type . '/';

        if (!is_dir($typeDir)) {
            continue;
        }

        $files = glob($typeDir . '*');

        foreach ($files as $file) {
            if (is_file($file)) {
                $stats[$type]['count']++;
                $stats[$type]['total_size'] += filesize($file);
            }
        }
    }

    return $stats;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_once '../users/init.php';

    header('Content-Type: application/json');

    if (!securePage($_SERVER['PHP_SELF'])) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $action = $_POST['action'];

    try {
        switch ($action) {
            case 'backup_tables':
                // Parse table names from comma-separated string
                $tablesParam = $_POST['tables'] ?? '';
                $tables = array_map('trim', explode(',', $tablesParam));

                // Validate table names (alphanumeric and underscore only)
                foreach ($tables as $table) {
                    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                        throw new Exception("Invalid table name: {$table}");
                    }
                }

                // Create backup
                $backupPath = createStandardizedBackup(
                    '14-update-admin-page-permissions',
                    $tables,
                    'automated',
                    'development'
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Backup created successfully',
                    'backup_path' => basename($backupPath)
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
