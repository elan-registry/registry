<?php

declare(strict_types=1);

use ElanRegistry\Admin\BackupManager;
use PHPUnit\Framework\TestCase;

use PHPUnit\Framework\Attributes\Group;
// BackupManager and BackupException auto-loaded via custom autoloader
// (BackupManager now in usersc/classes/admin/, BackupException in usersc/classes/exceptions/)

/**
 * Unit tests for BackupManager class
 *
 * Tests backup creation, validation, statistics, and cleanup operations
 * for the database backup management system.
 */
#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class BackupManagerTest extends TestCase
{
    private string $testBackupDir;
    private $mockDb;
    private BackupManager $backupManager;

    /**
     * Set up test environment before each test
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Create temporary backup directory for tests
        $this->testBackupDir = sys_get_temp_dir() . '/backup_test_' . uniqid() . '/';
        mkdir($this->testBackupDir);
        mkdir($this->testBackupDir . 'automated/');
        mkdir($this->testBackupDir . 'manual/');
        mkdir($this->testBackupDir . 'rollback/');

        // Create mock database
        $this->mockDb = $this->createMockDatabase();

        // Initialize BackupManager
        $this->backupManager = new BackupManager($this->mockDb, $this->testBackupDir, 1);
    }

    /**
     * Clean up test environment after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->testBackupDir)) {
            $this->recursiveRemoveDirectory($this->testBackupDir);
        }

        parent::tearDown();
    }

    /**
     * Test BackupManager instantiation
     *
     * @return void
     */
    #[Group('fast')]
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(BackupManager::class, $this->backupManager);
    }

    /**
     * Test createSchemaBackup creates a backup file
     *
     * @return void
     */
    #[Group('fast')]
    public function testCreateSchemaBackup(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Test Operation', ['settings']);

        $this->assertFileExists($backupPath);
        $this->assertStringContainsString('automated_schema-test-operation', $backupPath);
        $this->assertStringEndsWith('.sql', $backupPath);
    }

    /**
     * Test createSchemaBackup uses default tables when none specified
     *
     * @return void
     */
    #[Group('fast')]
    public function testCreateSchemaBackupWithDefaultTables(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Default Tables Test');

        $this->assertFileExists($backupPath);

        $content = file_get_contents($backupPath);
        $this->assertStringContainsString('-- Tables: settings, users, cars, car_user, profiles', $content);
    }

    /**
     * Test createManualBackup creates a backup file
     *
     * @return void
     */
    #[Group('fast')]
    public function testCreateManualBackup(): void
    {
        $backupPath = $this->backupManager->createManualBackup('Pre-Migration', ['users', 'cars']);

        $this->assertFileExists($backupPath);
        $this->assertStringContainsString('manual_manual-pre-migration', $backupPath);
        $this->assertStringEndsWith('.sql', $backupPath);
    }

    /**
     * Test createManualBackup includes metadata in backup
     *
     * @return void
     */
    #[Group('fast')]
    public function testCreateManualBackupWithMetadata(): void
    {
        $metadata = [
            'migration_version' => '2.9.2',
            'performed_by' => 'test_user'
        ];

        $backupPath = $this->backupManager->createManualBackup('Test Backup', ['users'], $metadata);

        $this->assertFileExists($backupPath);
        $content = file_get_contents($backupPath);
        $this->assertStringContainsString('-- Type: manual', $content);
    }

    /**
     * Test verifyBackupIntegrity validates a good backup
     *
     * @return void
     */
    #[Group('fast')]
    public function testVerifyBackupIntegrityValidBackup(): void
    {
        // Create a test backup
        $backupPath = $this->backupManager->createSchemaBackup('Test Validation', ['settings']);

        $result = $this->backupManager->verifyBackupIntegrity($backupPath);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('file_size', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('age_hours', $result);
        $this->assertGreaterThan(0, $result['file_size']);
    }

    /**
     * Test verifyBackupIntegrity detects non-existent file
     *
     * @return void
     */
    #[Group('fast')]
    public function testVerifyBackupIntegrityNonExistentFile(): void
    {
        $result = $this->backupManager->verifyBackupIntegrity('/nonexistent/backup.sql');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not found', strtolower($result['error']));
    }

    /**
     * Test verifyBackupIntegrity detects empty file
     *
     * @return void
     */
    #[Group('fast')]
    public function testVerifyBackupIntegrityEmptyFile(): void
    {
        // Create an empty file
        $emptyFile = $this->testBackupDir . 'empty_backup.sql';
        touch($emptyFile);

        $result = $this->backupManager->verifyBackupIntegrity($emptyFile);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    /**
     * Test getEnhancedBackupStatistics returns statistics structure
     *
     * @return void
     */
    #[Group('fast')]
    public function testGetEnhancedBackupStatistics(): void
    {
        // Create some test backups
        $this->backupManager->createSchemaBackup('Test Stats 1', ['settings']);
        $this->backupManager->createManualBackup('Test Stats 2', ['users']);

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        // Verify structure
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('automated', $stats);
        $this->assertArrayHasKey('manual', $stats);
        $this->assertArrayHasKey('rollback', $stats);
        $this->assertArrayHasKey('retention_analysis', $stats);
        $this->assertArrayHasKey('health_score', $stats);
        $this->assertArrayHasKey('recommendations', $stats);

        // Verify automated stats
        $this->assertArrayHasKey('count', $stats['automated']);
        $this->assertArrayHasKey('total_size', $stats['automated']);
        $this->assertEquals(1, $stats['automated']['count']);

        // Verify manual stats
        $this->assertEquals(1, $stats['manual']['count']);
    }

    /**
     * Test getEnhancedBackupStatistics calculates health score
     *
     * @return void
     */
    #[Group('fast')]
    public function testGetEnhancedBackupStatisticsHealthScore(): void
    {
        $this->backupManager->createSchemaBackup('Health Test', ['settings']);

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        $this->assertIsInt($stats['health_score']);
        $this->assertGreaterThanOrEqual(0, $stats['health_score']);
        $this->assertLessThanOrEqual(100, $stats['health_score']);
    }

    /**
     * Test performEnhancedCleanup returns cleanup statistics
     *
     * @return void
     */
    #[Group('fast')]
    public function testPerformEnhancedCleanup(): void
    {
        // Create some test backups
        $this->backupManager->createSchemaBackup('Cleanup Test 1', ['settings']);
        $this->backupManager->createManualBackup('Cleanup Test 2', ['users']);

        $result = $this->backupManager->performEnhancedCleanup();

        // Verify structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('automated', $result);
        $this->assertArrayHasKey('manual', $result);
        $this->assertArrayHasKey('rollback', $result);
        $this->assertArrayHasKey('health_score_before', $result);
        $this->assertArrayHasKey('health_score_after', $result);
        $this->assertArrayHasKey('health_improvement', $result);

        // Verify scanned and deleted counts
        $this->assertArrayHasKey('scanned', $result['automated']);
        $this->assertArrayHasKey('deleted', $result['automated']);
        $this->assertGreaterThanOrEqual($result['automated']['deleted'], $result['automated']['scanned']);
    }

    /**
     * Test performEnhancedCleanup does not delete recent backups
     *
     * @return void
     */
    #[Group('fast')]
    public function testPerformEnhancedCleanupPreservesRecentBackups(): void
    {
        // Create a recent backup
        $backupPath = $this->backupManager->createSchemaBackup('Recent Backup', ['settings']);

        $result = $this->backupManager->performEnhancedCleanup();

        // Recent backup should not be deleted
        $this->assertFileExists($backupPath);
        $this->assertEquals(0, $result['automated']['deleted']);
    }

    /**
     * Test backup file naming convention
     *
     * @return void
     */
    #[Group('fast')]
    public function testBackupFileNamingConvention(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Test Naming', ['settings']);

        $filename = basename($backupPath);

        // Should match pattern: automated_schema-test-naming_development_YYYYmmdd_HHiiss.sql
        $this->assertMatchesRegularExpression(
            '/^automated_schema-test-naming_development_\d{8}_\d{6}\.sql$/',
            $filename
        );
    }

    /**
     * Test backup contains metadata header
     *
     * @return void
     */
    #[Group('fast')]
    public function testBackupContainsMetadata(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Metadata Test', ['settings']);
        $content = file_get_contents($backupPath);

        $this->assertStringContainsString('-- BACKUP METADATA', $content);
        $this->assertStringContainsString('-- Type: automated', $content);
        $this->assertStringContainsString('-- Script: schema-metadata-test', $content);
        $this->assertStringContainsString('-- Environment: development', $content);
        $this->assertStringContainsString('-- Generator: BackupManager', $content);
    }

    /**
     * Test backup contains SQL statements
     *
     * @return void
     */
    #[Group('fast')]
    public function testBackupContainsSqlStatements(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('SQL Test', ['settings']);
        $content = file_get_contents($backupPath);

        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
    }

    /**
     * Test that cleanupOldBackups blocks symlink path traversal.
     *
     * Creates a symlink inside the backup directory pointing to a file
     * OUTSIDE the backup base. After ageing the symlink past the retention
     * cutoff, performEnhancedCleanup() must NOT delete the target file —
     * the realpath guard detects the traversal and skips the entry.
     *
     * The filename embeds `_development_` so that extractEnvironmentFromFilename()
     * resolves a real retention tier rather than falling through to its default,
     * keeping the test sensitive to changes in the environment-extraction regex.
     *
     * @return void
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testCleanupBlocksSymlinkTraversal(): void
    {
        if (!function_exists('symlink') || (PHP_OS_FAMILY === 'Windows' && !extension_loaded('com_dotnet'))) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        // Create a directory and target file OUTSIDE the backup base
        $outsideDir = sys_get_temp_dir() . '/outside_' . uniqid() . '/';
        mkdir($outsideDir);
        $secretPath = $outsideDir . 'secret.txt';
        file_put_contents($secretPath, 'sensitive data that must not be deleted');

        try {
            $symlinkPath = $this->testBackupDir
                . 'automated/elanregistry_automated_development_2024-01-01T000000_schema.sql';

            if (!@symlink($secretPath, $symlinkPath)) {
                $this->markTestSkipped('symlink() call failed (insufficient privileges or unsupported filesystem)');
            }

            // Age the symlink past any retention cutoff (100 days old)
            touch($symlinkPath, time() - 100 * 86400);

            $result = $this->backupManager->performEnhancedCleanup();

            // Target file outside backup base must still exist
            $this->assertFileExists($secretPath, 'Path-traversal target was deleted');

            // The realpath guard blocked deletion; deleted count for automated must be 0
            $this->assertSame(0, $result['automated']['deleted']);
        } finally {
            if (is_link($symlinkPath ?? '')) {
                unlink($symlinkPath);
            }
            $this->recursiveRemoveDirectory($outsideDir);
        }
    }

    /**
     * Test that cleanupOldBackups deletes a real old file inside the backup base.
     *
     * Companion to testCleanupBlocksSymlinkTraversal: verifies that the
     * realpath guard does NOT block legitimate deletions of aged backup files
     * that genuinely live within the backup directory.
     *
     * The filename embeds `_development_` so that extractEnvironmentFromFilename()
     * resolves a real retention tier rather than falling through to its default,
     * keeping the test sensitive to changes in the environment-extraction regex.
     *
     * @return void
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testCleanupDeletesOldFileWithinBackupBase(): void
    {
        // Create a real backup file (not a symlink) inside the backup base.
        // Filename matches extractEnvironmentFromFilename regex
        // /_(development|test|production)_/ so retention is resolved.
        $oldBackup = $this->testBackupDir
            . 'automated/elanregistry_automated_development_2024-01-01T000000_schema.sql';
        file_put_contents($oldBackup, "-- old backup content\n");

        // Age past development/automated retention (default 7 days)
        touch($oldBackup, time() - 100 * 86400);

        $result = $this->backupManager->performEnhancedCleanup();

        $this->assertSame(1, $result['automated']['deleted']);
        $this->assertFileDoesNotExist($oldBackup);
    }

    /**
     * Verify that cleanup respects per-type retention from config.php constants:
     * a file aged past the automated window (7 days) but within the manual window (30 days)
     * must be deleted from automated and kept in manual.
     *
     * @return void
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testCleanupRespectsPerTypeRetentionConstants(): void
    {
        // File aged 10 days — past automated (7d) but within manual (30d)
        $automatedBackup = $this->testBackupDir
            . 'automated/automated_manual-backup_development_20250101_120000.sql';
        $manualBackup = $this->testBackupDir
            . 'manual/manual_manual-backup_development_20250101_120000.sql';

        file_put_contents($automatedBackup, "-- automated\n");
        file_put_contents($manualBackup, "-- manual\n");

        $ageSeconds = 10 * 86400;
        touch($automatedBackup, time() - $ageSeconds);
        touch($manualBackup, time() - $ageSeconds);

        $result = $this->backupManager->performEnhancedCleanup();

        $this->assertSame(1, $result['automated']['deleted'], 'Automated backup past 7-day retention should be deleted');
        $this->assertSame(0, $result['manual']['deleted'], 'Manual backup within 30-day retention should be kept');
        $this->assertFileDoesNotExist($automatedBackup);
        $this->assertFileExists($manualBackup);
    }

    /**
     * Verify analyzeRetention() bucket classification: a fresh backup (seconds old)
     * always lands in within_policy, never approaching_expiry or expired.
     *
     * @return void
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testAnalyzeRetentionClassifiesFreshBackupAsWithinPolicy(): void
    {
        $freshBackup = $this->testBackupDir
            . 'manual/manual_fresh-backup_development_20250101_120000.sql';
        file_put_contents($freshBackup, "-- fresh\n");
        // Leave mtime at now (default)

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        $manualAnalysis = $stats['retention_analysis']['manual'] ?? [];
        $this->assertGreaterThan(0, $manualAnalysis['within_policy'] ?? 0, 'Fresh manual backup should be within_policy');
        $this->assertSame(0, $manualAnalysis['approaching_expiry'] ?? 0, 'Fresh manual backup should not be approaching_expiry');
        $this->assertSame(0, $manualAnalysis['expired'] ?? 0, 'Fresh manual backup should not be expired');
    }

    /**
     * Helper: Create a mock database object
     *
     * @return object Mock database object with query method
     */
    private function createMockDatabase(): object
    {
        return new class {
            public function query(string $sql, array $params = []): object {
                // Mock result object
                return new class {
                    public function results(): array {
                        $createTableObj = new \stdClass();
                        $createTableObj->{'Create Table'} = 'CREATE TABLE `settings` (`id` int) ENGINE=InnoDB';

                        $dataObj = new \stdClass();
                        $dataObj->id = 1;
                        $dataObj->meta_key = 'test';
                        $dataObj->meta_value = 'value';

                        return [$createTableObj, $dataObj];
                    }

                    public function count(): int {
                        return 2;
                    }

                    public function first(): ?object {
                        $createTableObj = new \stdClass();
                        $createTableObj->{'Create Table'} = 'CREATE TABLE `settings` (`id` int) ENGINE=InnoDB';
                        return $createTableObj;
                    }
                };
            }
        };
    }

    /**
     * Helper: Recursively remove a directory and its contents
     *
     * @param string $dir Directory path to remove
     * @return void
     */
    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
