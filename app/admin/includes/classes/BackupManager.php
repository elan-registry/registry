<?php
declare(strict_types=1);

/**
 * BackupManager.php
 * Enhanced Backup Management for Admin Interface
 *
 * Integrates with existing FIX backup system while providing enhanced
 * retention management and schema operation integration
 * Part of Phase 1D: Integrated Backup System
 */

require_once __DIR__ . '/BackupException.php';

class BackupManager {
    private object $db;
    private \Closure $logger;
    private string $backupBaseDir;

    // Enhanced retention policies
    private $retentionPolicies = [
        'automated' => [
            'production' => 30,     // 30 days
            'development' => 7      // 7 days
        ],
        'manual' => [
            'production' => 90,     // 90 days
            'development' => 14     // 14 days
        ],
        'rollback' => [
            'production' => 60,     // 60 days
            'development' => 14     // 14 days
        ],
        'schema' => [
            'production' => 90,     // 90 days for schema changes
            'development' => 30     // 30 days for development
        ]
    ];

    /**
     * Constructor
     *
     * @param object $database Database connection object
     * @param string $backupDirectory Base directory for backups
     * @param int|null $userId User ID for logging (optional)
     *
     * Note: Constructors cannot declare return types in PHP
     */
    // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass
    public function __construct(object $database, string $backupDirectory, ?int $userId = null) {
        $this->db = $database;
        $this->backupBaseDir = rtrim($backupDirectory, '/') . '/';
        $this->logger = function($level, $category, $message) use ($userId) {
            if (function_exists('logger')) {
                logger($userId ?? 0, $category, $message);
            }
        };
    }

    /**
     * Create backup before schema operations
     *
     * @param string $operation Operation name (e.g., "Schema Maintenance")
     * @param array $tables Array of table names to backup (defaults to critical tables)
     * @return string Path to the created backup file
     * @throws BackupException If backup creation fails
     */
    public function createSchemaBackup(string $operation, array $tables = []): string {
        try {
            // Default tables for schema operations
            if (empty($tables)) {
                $tables = ['settings', 'users', 'cars', 'car_user', 'profiles'];
            }

            $scriptName = 'schema-' . strtolower(str_replace([' ', '_'], '-', $operation));
            $backupPath = $this->createStandardizedBackup($scriptName, $tables, 'automated', 'development');

            ($this->logger)(1, 'BackupManager', "Schema backup created for operation '{$operation}': {$backupPath}");

            return $backupPath;

        } catch (BackupException $e) {
            ($this->logger)(1, 'BackupError', "Schema backup failed for operation '{$operation}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create manual backup with enhanced metadata
     *
     * @param string $reason Reason for backup (becomes part of filename)
     * @param array $tables Array of table names to backup (defaults to critical tables)
     * @param array $metadata Optional metadata for enhanced logging
     * @return string Path to the created backup file
     * @throws BackupException If backup creation fails
     */
    public function createManualBackup(string $reason, array $tables = [], array $metadata = []): string {
        try {
            // Default to all critical tables if none specified
            if (empty($tables)) {
                $tables = $this->getCriticalTables();
            }

            $scriptName = 'manual-' . strtolower(str_replace([' ', '_'], '-', $reason));
            $backupPath = $this->createStandardizedBackup($scriptName, $tables, 'manual', 'development');

            // Log with enhanced metadata
            $logMessage = "Manual backup created: {$reason}";
            if (!empty($metadata)) {
                $logMessage .= ' | Metadata: ' . json_encode($metadata);
            }

            ($this->logger)(1, 'BackupManager', $logMessage);

            return $backupPath;

        } catch (BackupException $e) {
            ($this->logger)(1, 'BackupError', "Manual backup failed for '{$reason}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get backup statistics with enhanced details
     *
     * @return array Array containing backup statistics including:
     *               - Basic statistics (count, total_size per type)
     *               - Retention analysis (within_policy, approaching_expiry, expired)
     *               - Health score (0-100)
     *               - Recommendations array
     * @throws BackupException If statistics calculation fails
     */
    public function getEnhancedBackupStatistics(): array {
        try {
            // Use internal statistics calculation
            $basicStats = $this->calculateBasicStatistics();

            // Enhance with retention analysis
            $stats = $basicStats;
            $stats['retention_analysis'] = $this->analyzeRetention();
            $stats['health_score'] = $this->calculateHealthScore($stats);
            $stats['recommendations'] = $this->generateRecommendations($stats);

            return $stats;

        } catch (BackupException $e) {
            ($this->logger)(1, 'BackupError', 'Failed to get enhanced backup statistics: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Analyze backup retention status across all backup types.
     *
     * Examines automated, manual, and rollback backups to determine retention compliance.
     * Categorizes backups as within policy, approaching expiry, or expired based on
     * configured retention policies and current timestamp.
     *
     * @return array Retention analysis with keys 'automated', 'manual', 'rollback' containing
     *              status counts and oldest/newest file timestamps
     */
    private function analyzeRetention(): array {
        $analysis = [];
        $now = time();

        foreach (['automated', 'manual', 'rollback'] as $type) {
            $typeDir = $this->backupBaseDir . $type . '/';
            $analysis[$type] = [
                'within_policy' => 0,
                'approaching_expiry' => 0, // Within 7 days of expiry
                'expired' => 0,
                'oldest_file' => null,
                'newest_file' => null
            ];

            if (is_dir($typeDir)) {
                $files = glob($typeDir . '*.sql');
                $retentionDays = $this->retentionPolicies[$type]['development']; // Default to development
                $expiryTime = $now - ($retentionDays * 24 * 60 * 60);
                $warningTime = $now - (($retentionDays - 7) * 24 * 60 * 60);

                foreach ($files as $file) {
                    $fileTime = filemtime($file);

                    if ($fileTime < $expiryTime) {
                        $analysis[$type]['expired']++;
                    } elseif ($fileTime < $warningTime) {
                        $analysis[$type]['approaching_expiry']++;
                    } else {
                        $analysis[$type]['within_policy']++;
                    }

                    // Track oldest and newest
                    if ($analysis[$type]['oldest_file'] === null || $fileTime < filemtime($analysis[$type]['oldest_file'])) {
                        $analysis[$type]['oldest_file'] = $file;
                    }
                    if ($analysis[$type]['newest_file'] === null || $fileTime > filemtime($analysis[$type]['newest_file'])) {
                        $analysis[$type]['newest_file'] = $file;
                    }
                }
            }
        }

        return $analysis;
    }

    /**
     * Calculate overall backup system health score.
     *
     * Evaluates backup system health based on retention compliance, replication status,
     * and storage metrics. Deducts points for expired backups, approaching expiry dates,
     * missing replication targets, and excessive storage usage.
     *
     * @param array $stats Statistics array containing retention_analysis, replication,
     *                     and storage data
     *
     * @return int Health score from 0-100, where 100 indicates optimal health
     */
    private function calculateHealthScore(array $stats): int {
        $score = 100;

        // Deduct points for retention issues
        foreach (['automated', 'manual', 'rollback'] as $type) {
            if (isset($stats['retention_analysis'][$type])) {
                $expired = $stats['retention_analysis'][$type]['expired'];
                $approaching = $stats['retention_analysis'][$type]['approaching_expiry'];

                // Deduct 10 points per expired backup type, 5 points for approaching expiry
                if ($expired > 0) {
                    $score -= 10;
                }
                if ($approaching > 0) {
                    $score -= 5;
                }
            }
        }

        // Deduct points for excessive storage usage (over 1GB)
        $totalSize = ($stats['automated']['total_size'] ?? 0) +
                    ($stats['manual']['total_size'] ?? 0) +
                    ($stats['rollback']['total_size'] ?? 0);

        if ($totalSize > (1024 * 1024 * 1024)) { // 1GB
            $score -= 15;
        }

        // Minimum score of 0
        return max(0, $score);
    }

    /**
     * Generate backup system recommendations.
     *
     * Analyzes backup statistics and produces actionable recommendations for system
     * administrators. Covers retention cleanup, replication issues, and storage
     * optimization based on current conditions.
     *
     * @param array $stats Statistics array containing retention_analysis, replication,
     *                     storage data, and health score
     *
     * @return array Array of recommendation strings describing actions to improve
     *              backup system health
     */
    private function generateRecommendations(array $stats): array {
        $recommendations = [];

        // Check retention analysis
        if (isset($stats['retention_analysis'])) {
            $totalExpired = array_sum(array_column($stats['retention_analysis'], 'expired'));
            if ($totalExpired > 0) {
                $recommendations[] = "Run backup cleanup to remove {$totalExpired} expired backup files";
            }

            $totalApproaching = array_sum(array_column($stats['retention_analysis'], 'approaching_expiry'));
            if ($totalApproaching > 0) {
                $recommendations[] = "{$totalApproaching} backup files will expire within 7 days";
            }
        }

        // Check storage usage
        $totalSize = ($stats['automated']['total_size'] ?? 0) +
                    ($stats['manual']['total_size'] ?? 0) +
                    ($stats['rollback']['total_size'] ?? 0);

        if ($totalSize > (500 * 1024 * 1024)) { // 500MB
            $sizeGB = round($totalSize / (1024 * 1024 * 1024), 2);
            $recommendations[] = "Consider cleanup - backup storage is {$sizeGB}GB";
        }

        // Check backup frequency
        if (isset($stats['automated']['count']) && $stats['automated']['count'] === 0) {
            $recommendations[] = "No automated backups found - consider running FIX scripts to generate backups";
        }

        return $recommendations;
    }

    /**
     * Get list of critical tables for backup
     */
    private function getCriticalTables(): array {
        return [
            'users',
            'cars',
            'car_user',
            'profiles',
            'settings',
            'car_history',
            'fix_script_runs'
        ];
    }

    /**
     * Calculate basic statistics if getBackupStatistics function not available
     */
    private function calculateBasicStatistics(): array {
        $stats = [];

        foreach (['automated', 'manual', 'rollback'] as $type) {
            $typeDir = $this->backupBaseDir . $type . '/';
            $stats[$type] = [
                'count' => 0,
                'total_size' => 0
            ];

            if (is_dir($typeDir)) {
                $files = glob($typeDir . '*.sql');
                $stats[$type]['count'] = count($files);

                foreach ($files as $file) {
                    $stats[$type]['total_size'] += filesize($file);
                }
            }
        }

        return $stats;
    }

    /**
     * Enhanced cleanup with detailed reporting
     *
     * @return array Array with cleanup statistics including:
     *               - automated/manual/rollback: {scanned, deleted} counts
     *               - health_score_before: Health score before cleanup
     *               - health_score_after: Health score after cleanup
     *               - health_improvement: Points of improvement
     * @throws BackupException If cleanup fails
     */
    public function performEnhancedCleanup(): array {
        try {
            // Get before statistics
            $beforeStats = $this->getEnhancedBackupStatistics();

            // Perform cleanup
            $cleanupResult = $this->cleanupOldBackups();

            // Get after statistics
            $afterStats = $this->getEnhancedBackupStatistics();

            // Calculate improvements
            $result = $cleanupResult;
            $result['health_score_before'] = $beforeStats['health_score'];
            $result['health_score_after'] = $afterStats['health_score'];
            $result['health_improvement'] = $afterStats['health_score'] - $beforeStats['health_score'];

            ($this->logger)(1, 'BackupManager', 'Enhanced cleanup completed - Health score improved by ' . $result['health_improvement'] . ' points');

            return $result;

        } catch (BackupException $e) {
            ($this->logger)(1, 'BackupError', 'Enhanced cleanup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify backup integrity
     *
     * @param string $backupPath Full path to backup file
     * @return array Array with verification results:
     *               - valid (bool): Whether backup is valid
     *               - error (string): Error message if invalid
     *               - file_size (int): Size in bytes (if valid)
     *               - created_at (string): Creation timestamp (if valid)
     *               - age_hours (float): Age in hours (if valid)
     */
    public function verifyBackupIntegrity(string $backupPath): array {
        if (!file_exists($backupPath)) {
            return ['valid' => false, 'error' => 'Backup file not found'];
        }

        try {
            // Basic file checks
            $fileSize = filesize($backupPath);
            if ($fileSize === 0) {
                return ['valid' => false, 'error' => 'Backup file is empty'];
            }

            // Check if it's a valid SQL file by reading first few lines
            $handle = fopen($backupPath, 'r');
            $header = fread($handle, 1024);
            fclose($handle);

            // Look for SQL markers
            if (!preg_match('/CREATE|INSERT|DROP|ALTER/i', $header)) {
                return ['valid' => false, 'error' => 'File does not appear to contain valid SQL'];
            }

            return [
                'valid' => true,
                'file_size' => $fileSize,
                'created_at' => date('Y-m-d H:i:s', filemtime($backupPath)),
                'age_hours' => round((time() - filemtime($backupPath)) / 3600, 1)
            ];

        } catch (BackupException $e) {
            return ['valid' => false, 'error' => 'Verification failed: ' . $e->getMessage()];
        }
    }

    /**
     * Create a standardized backup file (PRIVATE - moved from FIX/backup-functions.php)
     *
     * @param string $scriptName Script identifier (kebab-case)
     * @param array $tables Tables to backup
     * @param string $type Type of backup: 'automated', 'manual', 'rollback'
     * @param string $environment Environment: 'development', 'test', 'production'
     * @return string Path to created backup file
     * @throws BackupException If backup creation fails
     */
    private function createStandardizedBackup(string $scriptName, array $tables = [], string $type = 'automated', string $environment = 'development'): string {
        // Validate parameters
        $validTypes = ['automated', 'manual', 'rollback'];
        $validEnvironments = ['development', 'test', 'production'];

        if (!in_array($type, $validTypes)) {
            throw new BackupException("Invalid backup type: $type. Must be one of: " . implode(', ', $validTypes));
        }

        if (!in_array($environment, $validEnvironments)) {
            throw new BackupException("Invalid environment: $environment. Must be one of: " . implode(', ', $validEnvironments));
        }

        // Generate standardized filename
        $timestamp = date('Ymd_His');
        $filename = "{$type}_{$scriptName}_{$environment}_{$timestamp}.sql";

        // Determine backup directory
        $backupDir = $this->backupBaseDir . "{$type}/";

        // Ensure backup directory exists
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0755, true)) {
                throw new BackupException("Failed to create backup directory: $backupDir");
            }
        }

        $backupPath = $backupDir . $filename;

        // Create backup content with metadata
        $backupContent = $this->generateBackupMetadata($scriptName, $type, $environment, $tables);

        // Add table dumps
        if (!empty($tables)) {
            foreach ($tables as $table) {
                $backupContent .= $this->generateTableDump($table);
            }
        }

        // Write backup file
        if (!file_put_contents($backupPath, $backupContent)) {
            throw new BackupException("Failed to write backup file: $backupPath");
        }

        $this->logBackupEvent('created', $scriptName, $type, $environment, $backupPath);

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
    private function generateBackupMetadata(string $scriptName, string $type, string $environment, array $tables): string {
        $timestamp = date('Y-m-d H:i:s');
        $tableList = implode(', ', $tables);

        $retentionDays = $this->getRetentionDays($type, $environment);
        $rollbackReady = !empty($tables) ? 'yes' : 'no';

        return "-- BACKUP METADATA\n" .
               "-- Type: {$type}\n" .
               "-- Script: {$scriptName}\n" .
               "-- Environment: {$environment}\n" .
               "-- Created: {$timestamp}\n" .
               "-- Tables: {$tableList}\n" .
               "-- Retention: {$retentionDays} days\n" .
               "-- Rollback-Ready: {$rollbackReady}\n" .
               "-- Generator: BackupManager v2.0\n" .
               "\n";
    }

    /**
     * Generate SQL dump for a table.
     *
     * Creates a complete SQL dump including table structure (CREATE TABLE)
     * and data (INSERT statements). Validates table names to prevent SQL
     * injection and handles missing tables gracefully with warnings.
     *
     * @param string $tableName Table name to dump (validated against injection)
     * @return string Complete SQL dump with CREATE and INSERT statements
     * @throws BackupException If table name contains invalid characters or SQL structure retrieval fails
     */
    private function generateTableDump(string $tableName): string {
        try {
            // Validate table name to prevent SQL injection
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                throw new BackupException("Invalid table name: {$tableName}");
            }

            // Get table structure
            $createResult = $this->db->query("SHOW CREATE TABLE `{$tableName}`");
            if ($createResult->count() === 0) {
                ($this->logger)(1, 'BackupWarning', "Table {$tableName} not found during backup");
                return "-- Warning: Table {$tableName} not found\n\n";
            }

            $createStatement = $createResult->first()->{'Create Table'};

            // Get table data
            $dataResult = $this->db->query("SELECT * FROM `{$tableName}`");

            $dump = "-- Dump for table: {$tableName}\n";
            $dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
            $dump .= $createStatement . ";\n\n";

            if ($dataResult->count() > 0) {
                $dump .= "-- Data for table: {$tableName}\n";

                foreach ($dataResult->results() as $row) {
                    $values = [];
                    foreach ($row as $columnName => $value) {
                        if (is_null($value)) {
                            $values[] = 'NULL';
                        } else {
                            // Convert to string and escape
                            $stringValue = (string)$value;
                            $escapedValue = addslashes($stringValue);
                            $values[] = "'{$escapedValue}'";
                        }
                    }
                    $dump .= "INSERT INTO `{$tableName}` VALUES (" . implode(', ', $values) . ");\n";
                }
            }

            return $dump . "\n";

        } catch (BackupException $e) {
            $errorMsg = "Error backing up table {$tableName}: " . $e->getMessage();
            ($this->logger)(1, 'BackupError', $errorMsg);

            // Re-throw critical errors, return warning comment for non-critical
            if (strpos($e->getMessage(), 'Invalid table name') !== false) {
                throw $e;
            }

            return "-- {$errorMsg}\n\n";
        }
    }

    /**
     * Get retention days based on backup type and environment
     *
     * @param string $type Backup type
     * @param string $environment Environment
     * @return int Number of retention days
     */
    private function getRetentionDays(string $type, string $environment): int {
        // Use class retention policies
        if (isset($this->retentionPolicies[$type][$environment])) {
            return $this->retentionPolicies[$type][$environment];
        }

        // Fallback retention policies (same as original function)
        $fallbackPolicies = [
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

        return $fallbackPolicies[$environment][$type] ?? 30;
    }

    /**
     * Clean up old backup files based on retention policy
     *
     * @param int|null $retentionDays Override retention days (optional)
     * @return array Summary of cleanup actions
     */
    private function cleanupOldBackups(?int $retentionDays = null): array {
        $cleanupSummary = [
            'automated' => ['scanned' => 0, 'deleted' => 0],
            'manual' => ['scanned' => 0, 'deleted' => 0],
            'rollback' => ['scanned' => 0, 'deleted' => 0]
        ];

        $types = ['automated', 'manual', 'rollback'];

        foreach ($types as $type) {
            $typeDir = $this->backupBaseDir . $type . '/';

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
                $environment = $this->extractEnvironmentFromFilename($filename);

                $fileRetentionDays = $retentionDays ?? $this->getRetentionDays($type, $environment);
                $cutoffTime = time() - ($fileRetentionDays * 24 * 60 * 60);

                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $cleanupSummary[$type]['deleted']++;
                        $this->logBackupEvent('deleted', $filename, $type, $environment, $file);
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
    private function extractEnvironmentFromFilename(string $filename): string {
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
     * @param \DateTime|null $beforeDate Find backup before this date (optional)
     * @return string|null Path to backup file or null if not found
     */
    private function findBackupForRollback(string $scriptName, string $environment, ?\DateTime $beforeDate = null): ?string {
        $backupDirs = [
            $this->backupBaseDir . 'automated/',
            $this->backupBaseDir . 'manual/',
            $this->backupBaseDir . 'rollback/'
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
     * Log backup event (creation or deletion)
     *
     * @param string $action 'created' or 'deleted'
     * @param string $scriptName Script identifier
     * @param string $type Backup type
     * @param string $environment Environment
     * @param string $backupPath Path to backup file
     */
    private function logBackupEvent(string $action, string $scriptName, string $type, string $environment, string $backupPath): void {
        $logMessage = sprintf(
            "[%s] Backup %s - Script: %s, Type: %s, Environment: %s, File: %s",
            date('Y-m-d H:i:s'),
            $action,
            $scriptName,
            $type,
            $environment,
            basename($backupPath)
        );

        error_log($logMessage);

        // Also log via UserSpice logger if available
        ($this->logger)(1, 'BackupManager', $logMessage);
    }
}