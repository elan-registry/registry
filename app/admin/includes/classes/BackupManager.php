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

class BackupManager {
    private $db;
    private $logger;
    private $backupBaseDir;

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

    public function __construct($database, $backupDirectory, $userId = null) {
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
     */
    public function createSchemaBackup(string $operation, array $tables = []): string {
        if (!function_exists('createStandardizedBackup')) {
            throw new Exception('FIX backup system not available');
        }

        try {
            // Default tables for schema operations
            if (empty($tables)) {
                $tables = ['settings', 'users', 'cars', 'car_user', 'profiles'];
            }

            $backupPath = createStandardizedBackup(
                'schema-' . strtolower(str_replace([' ', '_'], '-', $operation)),
                $tables,
                'automated',
                'development'
            );

            ($this->logger)(1, 'BackupManager', "Schema backup created for operation '{$operation}': {$backupPath}");

            return $backupPath;

        } catch (Exception $e) {
            ($this->logger)(1, 'BackupError', "Schema backup failed for operation '{$operation}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create manual backup with enhanced metadata
     */
    public function createManualBackup(string $reason, array $tables = [], array $metadata = []): string {
        if (!function_exists('createStandardizedBackup')) {
            throw new Exception('FIX backup system not available');
        }

        try {
            // Default to all critical tables if none specified
            if (empty($tables)) {
                $tables = $this->getCriticalTables();
            }

            $backupPath = createStandardizedBackup(
                'manual-' . strtolower(str_replace([' ', '_'], '-', $reason)),
                $tables,
                'manual',
                'development'
            );

            // Log with enhanced metadata
            $logMessage = "Manual backup created: {$reason}";
            if (!empty($metadata)) {
                $logMessage .= ' | Metadata: ' . json_encode($metadata);
            }

            ($this->logger)(1, 'BackupManager', $logMessage);

            return $backupPath;

        } catch (Exception $e) {
            ($this->logger)(1, 'BackupError', "Manual backup failed for '{$reason}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get backup statistics with enhanced details
     */
    public function getEnhancedBackupStatistics(): array {
        try {
            // Use existing backup statistics function if available
            if (function_exists('getBackupStatistics')) {
                $basicStats = getBackupStatistics();
            } else {
                $basicStats = $this->calculateBasicStatistics();
            }

            // Enhance with retention analysis
            $stats = $basicStats;
            $stats['retention_analysis'] = $this->analyzeRetention();
            $stats['health_score'] = $this->calculateHealthScore($stats);
            $stats['recommendations'] = $this->generateRecommendations($stats);

            return $stats;

        } catch (Exception $e) {
            ($this->logger)(1, 'BackupError', 'Failed to get enhanced backup statistics: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Analyze backup retention status
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
     * Calculate overall backup system health score
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
     * Generate backup recommendations
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
     */
    public function performEnhancedCleanup(): array {
        if (!function_exists('cleanupOldBackups')) {
            throw new Exception('Backup cleanup function not available');
        }

        try {
            // Get before statistics
            $beforeStats = $this->getEnhancedBackupStatistics();

            // Perform cleanup
            $cleanupResult = cleanupOldBackups();

            // Get after statistics
            $afterStats = $this->getEnhancedBackupStatistics();

            // Calculate improvements
            $result = $cleanupResult;
            $result['health_score_before'] = $beforeStats['health_score'];
            $result['health_score_after'] = $afterStats['health_score'];
            $result['health_improvement'] = $afterStats['health_score'] - $beforeStats['health_score'];

            ($this->logger)(1, 'BackupManager', 'Enhanced cleanup completed - Health score improved by ' . $result['health_improvement'] . ' points');

            return $result;

        } catch (Exception $e) {
            ($this->logger)(1, 'BackupError', 'Enhanced cleanup failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify backup integrity
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

        } catch (Exception $e) {
            return ['valid' => false, 'error' => 'Verification failed: ' . $e->getMessage()];
        }
    }
}