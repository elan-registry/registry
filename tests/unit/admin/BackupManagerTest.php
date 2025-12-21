<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Load BackupManager and dependencies
require_once dirname(__DIR__, 3) . '/app/admin/includes/classes/BackupException.php';
require_once dirname(__DIR__, 3) . '/app/admin/includes/classes/BackupManager.php';

/**
 * Unit tests for BackupManager class
 *
 * Tests backup creation, validation, statistics, and cleanup operations
 * for the database backup management system.
 *
 * @group fast
 * @group unit
 * @group admin
 */
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
     * @group fast
     * @return void
     */
    public function testInstantiation(): void
    {
        $this->assertInstanceOf(BackupManager::class, $this->backupManager);
    }

    /**
     * Test createSchemaBackup creates a backup file
     *
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
    public function testVerifyBackupIntegrityNonExistentFile(): void
    {
        $result = $this->backupManager->verifyBackupIntegrity('/nonexistent/backup.sql');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not found', strtolower($result['error']));
    }

    /**
     * Test verifyBackupIntegrity detects empty file
     *
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
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
     * @group fast
     * @return void
     */
    public function testBackupContainsSqlStatements(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('SQL Test', ['settings']);
        $content = file_get_contents($backupPath);

        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
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
