<?php

declare(strict_types=1);

use ElanRegistry\Admin\BackupManager;
use ElanRegistry\Exceptions\BackupException;
use ElanRegistry\LogCategories;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDatabase;

use PHPUnit\Framework\Attributes\Group;

#[Group('fast')]
#[Group('unit')]
#[Group('admin')]
final class BackupManagerTest extends TestCase
{
    private string $testBackupDir;
    private BackupManagerFakeDatabase $mockDb;
    private BackupManager $backupManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testBackupDir = sys_get_temp_dir() . '/backup_test_' . uniqid() . '/';
        mkdir($this->testBackupDir);
        mkdir($this->testBackupDir . 'automated/');
        mkdir($this->testBackupDir . 'manual/');
        mkdir($this->testBackupDir . 'rollback/');

        $this->mockDb = $this->createMockDatabase();

        $this->backupManager = new BackupManager($this->mockDb, $this->testBackupDir, 1);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testBackupDir)) {
            $this->recursiveRemoveDirectory($this->testBackupDir);
        }

        parent::tearDown();
    }

    #[Group('fast')]
    public function testCreateSchemaBackup(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Test Operation', ['settings']);

        $this->assertFileExists($backupPath);
        $this->assertStringContainsString('automated_schema-test-operation', $backupPath);
        $this->assertStringEndsWith('.sql', $backupPath);
    }

    /**
     * The default table list comes from information_schema, not a hardcoded list.
     */
    #[Group('fast')]
    public function testCreateSchemaBackupWithDefaultTables(): void
    {
        $discoveredTables = ['cars', 'profiles', 'settings', 'users'];
        $mockDb = $this->createMockDatabase(tableNames: $discoveredTables);
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $backupPath = $backupManager->createSchemaBackup('Default Tables Test');

        $this->assertFileExists($backupPath);

        $content = file_get_contents($backupPath);
        $this->assertStringContainsString(
            '-- Tables: ' . implode(', ', $discoveredTables),
            $content
        );
    }

    #[Group('fast')]
    #[Group('unit')]
    public function testGetAllTablesThrowsWhenSchemaQueryErrors(): void
    {
        $mockDb = $this->createMockDatabase(failOnSqlSubstring: 'information_schema.TABLES');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('Cannot determine which tables to back up');

        $backupManager->createManualBackup('Schema Query Failure Test');
    }

    /**
     * No tables means the connection points somewhere unexpected; a backup of
     * nothing must not report success.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testGetAllTablesThrowsWhenSchemaQueryReturnsNoTables(): void
    {
        $mockDb = $this->createMockDatabase(emptyResultOnSqlSubstring: 'information_schema.TABLES');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('the connected schema reports no tables');

        $backupManager->createManualBackup('Schema Query Empty Test');
    }

    #[Group('fast')]
    public function testCreateManualBackup(): void
    {
        $backupPath = $this->backupManager->createManualBackup('Pre-Migration', ['users', 'cars']);

        $this->assertFileExists($backupPath);
        $this->assertStringContainsString('manual_manual-pre-migration', $backupPath);
        $this->assertStringEndsWith('.sql', $backupPath);
    }

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

    #[Group('fast')]
    public function testVerifyBackupIntegrityValidBackup(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Test Validation', ['settings']);

        $result = $this->backupManager->verifyBackupIntegrity($backupPath);

        $this->assertTrue($result['valid']);
        $this->assertArrayHasKey('file_size', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('age_hours', $result);
        $this->assertGreaterThan(0, $result['file_size']);
    }

    #[Group('fast')]
    public function testVerifyBackupIntegrityNonExistentFile(): void
    {
        $result = $this->backupManager->verifyBackupIntegrity('/nonexistent/backup.sql');

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('not found', strtolower($result['error']));
    }

    #[Group('fast')]
    public function testVerifyBackupIntegrityEmptyFile(): void
    {
        $emptyFile = $this->testBackupDir . 'empty_backup.sql';
        touch($emptyFile);

        $result = $this->backupManager->verifyBackupIntegrity($emptyFile);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('empty', $result['error']);
    }

    /**
     * Structure without INSERTs is the sign of a data dump that failed silently.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testVerifyBackupIntegrityStructureOnlyNoData(): void
    {
        $structureOnlyFile = $this->testBackupDir . 'structure_only_backup.sql';
        file_put_contents(
            $structureOnlyFile,
            "DROP TABLE IF EXISTS `settings`;\nCREATE TABLE `settings` (`id` int) ENGINE=InnoDB;\n"
        );

        $result = $this->backupManager->verifyBackupIntegrity($structureOnlyFile);

        $this->assertFalse($result['valid']);
        $this->assertSame('Backup contains table structure but no data', $result['error']);
    }

    /**
     * The error handler absorbs fopen()'s E_WARNING so PHPUnit does not flag it.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testVerifyBackupIntegrityFopenFailureGuard(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test fopen() failure as root — chmod 0000 has no effect.');
        }

        $unreadableFile = $this->testBackupDir . 'unreadable_backup.sql';
        file_put_contents(
            $unreadableFile,
            "CREATE TABLE `settings` (`id` int) ENGINE=InnoDB;\nINSERT INTO `settings` VALUES (1);\n"
        );
        chmod($unreadableFile, 0000);

        $capturedWarning = null;
        set_error_handler(
            static function (int $errno, string $errstr) use (&$capturedWarning): bool {
                if ($errno === E_WARNING) {
                    $capturedWarning = $errstr;
                    return true;
                }
                return false;
            },
            E_WARNING
        );
        try {
            $result = $this->backupManager->verifyBackupIntegrity($unreadableFile);
        } finally {
            restore_error_handler();
            chmod($unreadableFile, 0644);
        }

        $this->assertFalse($result['valid']);
        $this->assertSame('Backup file could not be opened', $result['error']);
        $this->assertNotNull(
            $capturedWarning,
            'A PHP E_WARNING should be emitted when fopen() fails on an unreadable file.'
        );
    }

    #[Group('fast')]
    public function testGetEnhancedBackupStatistics(): void
    {
        $this->backupManager->createSchemaBackup('Test Stats 1', ['settings']);
        $this->backupManager->createManualBackup('Test Stats 2', ['users']);

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('automated', $stats);
        $this->assertArrayHasKey('manual', $stats);
        $this->assertArrayHasKey('rollback', $stats);
        $this->assertArrayHasKey('retention_analysis', $stats);
        $this->assertArrayHasKey('health_score', $stats);
        $this->assertArrayHasKey('recommendations', $stats);

        $this->assertArrayHasKey('count', $stats['automated']);
        $this->assertArrayHasKey('total_size', $stats['automated']);
        $this->assertEquals(1, $stats['automated']['count']);

        $this->assertEquals(1, $stats['manual']['count']);
    }

    #[Group('fast')]
    public function testGetEnhancedBackupStatisticsHealthScore(): void
    {
        $this->backupManager->createSchemaBackup('Health Test', ['settings']);

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        $this->assertIsInt($stats['health_score']);
        $this->assertGreaterThanOrEqual(0, $stats['health_score']);
        $this->assertLessThanOrEqual(100, $stats['health_score']);
    }

    #[Group('fast')]
    public function testPerformEnhancedCleanup(): void
    {
        $this->backupManager->createSchemaBackup('Cleanup Test 1', ['settings']);
        $this->backupManager->createManualBackup('Cleanup Test 2', ['users']);

        $result = $this->backupManager->performEnhancedCleanup();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('automated', $result);
        $this->assertArrayHasKey('manual', $result);
        $this->assertArrayHasKey('rollback', $result);
        $this->assertArrayHasKey('health_score_before', $result);
        $this->assertArrayHasKey('health_score_after', $result);
        $this->assertArrayHasKey('health_improvement', $result);

        $this->assertArrayHasKey('scanned', $result['automated']);
        $this->assertArrayHasKey('deleted', $result['automated']);
        $this->assertGreaterThanOrEqual($result['automated']['deleted'], $result['automated']['scanned']);
    }

    #[Group('fast')]
    public function testPerformEnhancedCleanupPreservesRecentBackups(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Recent Backup', ['settings']);

        $result = $this->backupManager->performEnhancedCleanup();

        $this->assertFileExists($backupPath);
        $this->assertEquals(0, $result['automated']['deleted']);
    }

    #[Group('fast')]
    public function testBackupFileNamingConvention(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('Test Naming', ['settings']);

        $filename = basename($backupPath);

        $this->assertMatchesRegularExpression(
            '/^automated_schema-test-naming_development_\d{8}_\d{6}\.sql$/',
            $filename
        );
    }

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

    #[Group('fast')]
    public function testBackupContainsSqlStatements(): void
    {
        $backupPath = $this->backupManager->createSchemaBackup('SQL Test', ['settings']);
        $content = file_get_contents($backupPath);

        $this->assertStringContainsString('CREATE TABLE', $content);
        $this->assertStringContainsString('INSERT INTO', $content);
    }

    /**
     * The realpath guard must not follow a symlink out of the backup base. The
     * `_development_` filename gives a real retention tier, not the default.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testCleanupBlocksSymlinkTraversal(): void
    {
        if (!function_exists('symlink') || (PHP_OS_FAMILY === 'Windows' && !extension_loaded('com_dotnet'))) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

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

            touch($symlinkPath, time() - 100 * 86400);

            $result = $this->backupManager->performEnhancedCleanup();

            $this->assertFileExists($secretPath, 'Path-traversal target was deleted');

            $this->assertSame(0, $result['automated']['deleted']);
        } finally {
            if (is_link($symlinkPath)) {
                unlink($symlinkPath);
            }
            $this->recursiveRemoveDirectory($outsideDir);
        }
    }

    /**
     * The realpath guard must not block a real aged file inside the base.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testCleanupDeletesOldFileWithinBackupBase(): void
    {
        $oldBackup = $this->testBackupDir
            . 'automated/elanregistry_automated_development_2024-01-01T000000_schema.sql';
        file_put_contents($oldBackup, "-- old backup content\n");

        touch($oldBackup, time() - 100 * 86400);

        $result = $this->backupManager->performEnhancedCleanup();

        $this->assertSame(1, $result['automated']['deleted']);
        $this->assertFileDoesNotExist($oldBackup);
    }

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

    #[Group('fast')]
    #[Group('unit')]
    public function testAnalyzeRetentionClassifiesFreshBackupAsWithinPolicy(): void
    {
        $freshBackup = $this->testBackupDir
            . 'manual/manual_fresh-backup_development_20250101_120000.sql';
        file_put_contents($freshBackup, "-- fresh\n");

        $stats = $this->backupManager->getEnhancedBackupStatistics();

        $manualAnalysis = $stats['retention_analysis']['manual'] ?? [];
        $this->assertGreaterThan(0, $manualAnalysis['within_policy'] ?? 0, 'Fresh manual backup should be within_policy');
        $this->assertSame(0, $manualAnalysis['approaching_expiry'] ?? 0, 'Fresh manual backup should not be approaching_expiry');
        $this->assertSame(0, $manualAnalysis['expired'] ?? 0, 'Fresh manual backup should not be expired');
    }

    #[Group('fast')]
    #[Group('unit')]
    public function testTableDumpDataQueryFailureAbortsBackup(): void
    {
        $mockDb = $this->createMockDatabase('SELECT * FROM `cars`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $filesBefore = glob($this->testBackupDir . 'manual/*.sql');

        try {
            $backupManager->createManualBackup('Data Query Failure', ['cars']);
            $this->fail('Expected BackupException was not thrown');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Failed to read data for table cars', $e->getMessage());
        }

        $filesAfter = glob($this->testBackupDir . 'manual/*.sql');
        $this->assertSame($filesBefore, $filesAfter, 'No partial backup file should be written when the data query fails');
    }

    /**
     * The dump streams to `.partial` (#1714). The other failure tests glob
     * `*.sql`, which a leftover `.partial` would pass, so this globs `*`.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testAbortedBackupLeavesNoPartialTempFile(): void
    {
        $mockDb = $this->createMockDatabase('SELECT * FROM `cars`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        try {
            $backupManager->createManualBackup('Partial Cleanup', ['cars']);
            $this->fail('Expected BackupException was not thrown');
        } catch (BackupException $e) {
        }

        $this->assertSame(
            [],
            glob($this->testBackupDir . 'manual/*.partial'),
            'An aborted backup must not leave a .partial temp file behind'
        );
        $this->assertSame(
            [],
            glob($this->testBackupDir . 'manual/*'),
            'An aborted backup must leave the backup directory empty, not merely free of .sql files'
        );
    }

    /**
     * BackupFailed must come from the outer catch in createStandardizedBackup()
     * only; generateTableDump() logs the per-table detail as BACKUP_ERROR.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testTableDumpStructureQueryFailureAbortsBackup(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $mockDb = $this->createMockDatabase('SHOW CREATE TABLE `cars`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $filesBefore = glob($this->testBackupDir . 'manual/*.sql');

        try {
            $backupManager->createManualBackup('Structure Query Failure', ['cars']);
            $this->fail('Expected BackupException was not thrown');
        } catch (BackupException $e) {
            $this->assertStringContainsString('Failed to read structure for table cars', $e->getMessage());
        }

        $filesAfter = glob($this->testBackupDir . 'manual/*.sql');
        $this->assertSame($filesBefore, $filesAfter, 'No partial backup file should be written when the structure query fails');

        $backupFailedEntries = array_filter(
            $mockLogEntries,
            fn($e) => $e['category'] === LogCategories::LOG_CATEGORY_BACKUP_FAILED
                && str_contains($e['message'], 'Backup aborted for')
        );
        $this->assertNotEmpty(
            $backupFailedEntries,
            'createStandardizedBackup() should log LOG_CATEGORY_BACKUP_FAILED for the propagated table-dump failure'
        );

        $tableDumpErrorEntries = array_filter(
            $mockLogEntries,
            fn($e) => $e['category'] === LogCategories::LOG_CATEGORY_BACKUP_ERROR
                && str_contains($e['message'], 'Error backing up table cars')
        );
        $this->assertNotEmpty(
            $tableDumpErrorEntries,
            'generateTableDump() should log the per-table detail under LOG_CATEGORY_BACKUP_ERROR, not LOG_CATEGORY_BACKUP_FAILED'
        );
    }

    /**
     * A missing table aborts, not warns: a wrong table name once dropped the car
     * audit trail from every manual backup while it reported success (#1696).
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testTableDumpTableNotFoundAbortsBackup(): void
    {
        $mockDb = $this->createMockDatabase(null, 0, 'SHOW CREATE TABLE `missing_table`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage("Cannot back up 'missing_table': table does not exist");

        $backupManager->createManualBackup('Missing Table Test', ['missing_table', 'cars']);
    }

    #[Group('fast')]
    #[Group('unit')]
    public function testMissingTableLeavesNoBackupFile(): void
    {
        $mockDb = $this->createMockDatabase(null, 0, 'SHOW CREATE TABLE `missing_table`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $before = glob($this->testBackupDir . '/**/*.sql') ?: [];

        try {
            $backupManager->createManualBackup('Missing Table Test', ['missing_table', 'cars']);
            $this->fail('Expected BackupException for a missing table');
        } catch (BackupException) {
            // expected
        }

        $after = glob($this->testBackupDir . '/**/*.sql') ?: [];
        $this->assertSame($before, $after, 'A failed backup must not leave a file behind');
    }

    /**
     * \DB::first() returns [] for no rows, so without the is_object() guard the
     * dump gets a bare `;` and cannot restore. A table dropped mid-backup does this.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testTableDumpMissingStructureRowAbortsBackup(): void
    {
        $mockDb = $this->createMockDatabase(null, 0, null, 'SHOW CREATE TABLE `cars`');
        $backupManager = new BackupManager($mockDb, $this->testBackupDir, 1);

        $filesBefore = glob($this->testBackupDir . 'manual/*.sql');

        try {
            $backupManager->createManualBackup('Missing Structure Row', ['cars']);
            $this->fail('Expected BackupException was not thrown');
        } catch (BackupException $e) {
            $this->assertStringContainsString('No structure returned for table cars', $e->getMessage());
        }

        $filesAfter = glob($this->testBackupDir . 'manual/*.sql');
        $this->assertSame(
            $filesBefore,
            $filesAfter,
            'No backup file should be written when the structure row is missing'
        );
    }

    /**
     * A fresh read-only base, because setUp()'s base already has manual/.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testMkdirFailureLogsBackupFailedFromCreateStandardizedBackup(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test mkdir() failure as root — permission checks have no effect.');
        }

        global $mockLogEntries;
        $mockLogEntries = [];

        $readonlyBase = sys_get_temp_dir() . '/backup_readonly_' . uniqid() . '/';
        mkdir($readonlyBase);
        chmod($readonlyBase, 0555);

        $capturedWarning = null;
        set_error_handler(
            static function (int $errno, string $errstr) use (&$capturedWarning): bool {
                if ($errno === E_WARNING) {
                    $capturedWarning = $errstr;
                    return true;
                }
                return false;
            },
            E_WARNING
        );

        try {
            $backupManager = new BackupManager($this->mockDb, $readonlyBase, 1);

            try {
                $backupManager->createManualBackup('Mkdir Failure', ['settings']);
                $this->fail('Expected BackupException was not thrown');
            } catch (BackupException $e) {
                $this->assertStringContainsString('Failed to create backup directory', $e->getMessage());
            }
        } finally {
            restore_error_handler();
            chmod($readonlyBase, 0755);
            $this->recursiveRemoveDirectory($readonlyBase);
        }

        $this->assertNotNull(
            $capturedWarning,
            'A PHP E_WARNING should be emitted when mkdir() fails on a read-only parent directory.'
        );

        $backupFailedEntries = array_filter(
            $mockLogEntries,
            fn($e) => $e['category'] === LogCategories::LOG_CATEGORY_BACKUP_FAILED
                && str_contains($e['message'], 'Backup aborted for')
        );
        $this->assertNotEmpty(
            $backupFailedEntries,
            'createStandardizedBackup() should log LOG_CATEGORY_BACKUP_FAILED when mkdir() fails, not just on propagated table-dump failures'
        );
    }

    /**
     * A raw \Throwable (e.g. PDOException) must still raise BackupFailed, or the
     * health badge stays "Healthy" for a backup that wrote nothing.
     */
    #[Group('fast')]
    #[Group('unit')]
    public function testNonBackupExceptionFailureStillLogsBackupFailed(): void
    {
        global $mockLogEntries;
        $mockLogEntries = [];

        $backupManager = new BackupManager(new BackupManagerThrowingDatabase(), $this->testBackupDir, 1);

        try {
            $backupManager->createManualBackup('Connection Drop', ['settings']);
            $this->fail('Expected \RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('MySQL server has gone away', $e->getMessage());
        }

        $backupFailedEntries = array_filter(
            $mockLogEntries,
            fn($e) => $e['category'] === LogCategories::LOG_CATEGORY_BACKUP_FAILED
                && str_contains($e['message'], 'Backup aborted for')
        );
        $this->assertNotEmpty(
            $backupFailedEntries,
            'createStandardizedBackup() should log LOG_CATEGORY_BACKUP_FAILED even when the failure is a raw \Throwable, not a BackupException'
        );
    }

    #[Group('fast')]
    #[Group('unit')]
    public function testHealthScoreReflectsRecentBackupFailures(): void
    {
        $this->backupManager->createSchemaBackup('Health Failure Baseline', ['settings']);

        $baselineStats = $this->backupManager->getEnhancedBackupStatistics();
        $this->assertFalse($baselineStats['recent_failures']);

        $failureMockDb = $this->createMockDatabase(null, 1);
        $failureBackupManager = new BackupManager($failureMockDb, $this->testBackupDir, 1);

        $failureStats = $failureBackupManager->getEnhancedBackupStatistics();

        $this->assertTrue($failureStats['recent_failures']);
        $this->assertSame(
            $baselineStats['health_score'] - 30,
            $failureStats['health_score'],
            'Recent backup failures should deduct exactly 30 points from the health score'
        );
    }

    #[Group('fast')]
    #[Group('unit')]
    public function testRecentBackupFailuresCheckFailsOpenOnDbError(): void
    {
        $this->backupManager->createSchemaBackup('Fail Open Baseline', ['settings']);
        $baselineStats = $this->backupManager->getEnhancedBackupStatistics();
        $this->assertFalse($baselineStats['recent_failures']);

        $failingLogsDb = $this->createMockDatabase('FROM logs');
        $failingLogsBackupManager = new BackupManager($failingLogsDb, $this->testBackupDir, 1);

        $failureStats = $failingLogsBackupManager->getEnhancedBackupStatistics();

        $this->assertFalse(
            $failureStats['recent_failures'],
            'A logs-query DB error must fail open (false), not be treated as a recent failure'
        );
        $this->assertSame(
            $baselineStats['health_score'],
            $failureStats['health_score'],
            'A failed (fail-open) recent-failures check must not affect the health score'
        );
    }

    /**
     * @param string|null $failOnSqlSubstring Matching query fails generically
     * @param int $recentFailureCount `cnt` returned by the `logs` COUNT(*) query
     * @param string|null $tableNotFoundOnSqlSubstring Matching query fails as 42S02/1146
     * @param string|null $emptyResultOnSqlSubstring Matching query succeeds with no rows
     * @param string[]|null $tableNames Rows for the information_schema.TABLES query
     */
    private function createMockDatabase(
        ?string $failOnSqlSubstring = null,
        int $recentFailureCount = 0,
        ?string $tableNotFoundOnSqlSubstring = null,
        ?string $emptyResultOnSqlSubstring = null,
        ?array $tableNames = null
    ): BackupManagerFakeDatabase
    {
        return new BackupManagerFakeDatabase(
            $failOnSqlSubstring,
            $recentFailureCount,
            $tableNotFoundOnSqlSubstring,
            $emptyResultOnSqlSubstring,
            $tableNames
        );
    }

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

/**
 * Mirrors the real `\DB`: query() returns $this, failures set error() instead of
 * throwing, and first() returns [] for no rows. The `logs` count is returned only
 * when the bound logtype is LOG_CATEGORY_BACKUP_FAILED. The SQL-substring options
 * are checked before the information_schema.TABLES discovery query.
 *
 * A named class, not anonymous: PHPStan reports `impureMethod.pure` when an
 * anonymous class overrides a `@phpstan-impure` method with a pure body.
 */
class BackupManagerFakeDatabase extends FakeDatabase
{
    private bool $lastQueryFailed = false;

    /** @var array<int, mixed> PDO errorInfo triple for the most recent query */
    private array $lastErrorInfo = ['', 0, ''];

    /** @var array<int, \stdClass> Rows returned by the most recent query */
    private array $lastRows = [];

    /** @var string[] Table names returned by the information_schema.TABLES discovery query */
    private readonly array $tableNames;

    /**
     * @param string[]|null $tableNames Defaults to ['settings']
     */
    public function __construct(
        private readonly ?string $failOnSqlSubstring = null,
        private readonly int $recentFailureCount = 0,
        private readonly ?string $tableNotFoundOnSqlSubstring = null,
        private readonly ?string $emptyResultOnSqlSubstring = null,
        ?array $tableNames = null
    ) {
        $this->tableNames = $tableNames ?? ['settings'];
    }

    /**
     * @param array<mixed> $params
     */
    public function query(string $sql, array $params = []): self
    {
        $this->lastQueryFailed = false;
        $this->lastErrorInfo = ['', 0, ''];
        $this->lastRows = [];

        if ($this->tableNotFoundOnSqlSubstring !== null && str_contains($sql, $this->tableNotFoundOnSqlSubstring)) {
            $this->lastQueryFailed = true;
            $this->lastErrorInfo = ['42S02', 1146, "Table doesn't exist"];
            return $this;
        }

        if ($this->failOnSqlSubstring !== null && str_contains($sql, $this->failOnSqlSubstring)) {
            $this->lastQueryFailed = true;
            $this->lastErrorInfo = ['HY000', 2006, 'MySQL server has gone away'];
            return $this;
        }

        if ($this->emptyResultOnSqlSubstring !== null && str_contains($sql, $this->emptyResultOnSqlSubstring)) {
            return $this;
        }

        if (str_contains($sql, 'information_schema.TABLES')) {
            $this->lastRows = array_map(
                static function (string $tableName): \stdClass {
                    $row = new \stdClass();
                    $row->TABLE_NAME = $tableName;
                    return $row;
                },
                $this->tableNames
            );

            return $this;
        }

        if (str_contains($sql, 'FROM logs')) {
            $matchesFailedCategory = ($params[0] ?? null) === LogCategories::LOG_CATEGORY_BACKUP_FAILED;

            $countRow = new \stdClass();
            $countRow->cnt = $matchesFailedCategory ? $this->recentFailureCount : 0;
            $this->lastRows = [$countRow];

            return $this;
        }

        $createTableObj = new \stdClass();
        $createTableObj->{'Create Table'} = 'CREATE TABLE `settings` (`id` int) ENGINE=InnoDB';

        $dataObj = new \stdClass();
        $dataObj->id = 1;
        $dataObj->meta_key = 'test';
        $dataObj->meta_value = 'value';

        $this->lastRows = [$createTableObj, $dataObj];

        return $this;
    }

    public function error(): bool
    {
        return $this->lastQueryFailed;
    }

    public function errorString(): string
    {
        return $this->lastQueryFailed ? 'Mock database error: query failed' : '';
    }

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return $this->lastErrorInfo;
    }

    public function count(): int
    {
        return count($this->lastRows);
    }

    /** @return array<string, mixed>|object */
    public function first(bool $assoc = false): array|object
    {
        return $this->lastRows[0] ?? [];
    }

    public function results(bool $assoc = false): array
    {
        return $this->lastRows;
    }
}

/**
 * A connection fault that throws instead of setting error(). Named for the same
 * PHPStan reason as BackupManagerFakeDatabase.
 */
class BackupManagerThrowingDatabase extends FakeDatabase
{
    /**
     * @param array<mixed> $params
     */
    public function query(string $sql, array $params = []): self
    {
        throw new \RuntimeException('MySQL server has gone away');
    }
}
