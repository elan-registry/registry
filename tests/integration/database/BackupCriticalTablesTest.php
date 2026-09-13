<?php

declare(strict_types=1);

require_once __DIR__ . '/../IntegrationTestCase.php';

use ElanRegistry\Admin\BackupManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for BackupManager's table discovery (issue #1714)
 *
 * BackupManager carried a hardcoded `getCriticalTables()` list (plus a separate
 * default inside `createSchemaBackup()`), and
 * `app/admin/includes/system/backup-operations.php` carried a third,
 * independently hand-copied one. #1696 corrected a stale `car_history` name in the
 * first and made `generateTableDump()` fail loudly on a missing table, but could not
 * see the duplicate — which still named `car_history` and so broke every manual
 * backup outright.
 *
 * The fix (#1714) deleted both lists and replaced them with
 * `BackupManager::getAllTables()`, which discovers every base table in the connected
 * schema at runtime via `information_schema.TABLES`. A derived list cannot drift the
 * way two independently maintained lists did. These tests verify that guarantee
 * against a real database rather than the fake `DatabaseInterface` double the unit
 * tests use, and — most importantly — assert the deleted hardcoded array in
 * backup-operations.php does not silently reappear.
 */
#[Group('integration')]
final class BackupCriticalTablesTest extends IntegrationTestCase
{
    private string $testBackupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        $this->testBackupDir = sys_get_temp_dir() . '/backup_critical_tables_test_' . uniqid() . '/';
        mkdir($this->testBackupDir);
        mkdir($this->testBackupDir . 'automated/');
        mkdir($this->testBackupDir . 'manual/');
        mkdir($this->testBackupDir . 'rollback/');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testBackupDir)) {
            $this->recursiveRemoveDirectory($this->testBackupDir);
        }

        parent::tearDown();
    }

    /**
     * A backup created with the default (no explicit $tables) must dump every base
     * table in the connected schema — verified against a live information_schema
     * query, not a hardcoded list, so this test cannot itself go stale the way
     * getCriticalTables() did.
     */
    #[Group('integration')]
    public function test_defaultBackupDumpsEveryBaseTableInSchema(): void
    {
        $expectedTables = array_map(
            static fn(object $row): string => $row->TABLE_NAME,
            $this->db->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
                 ORDER BY TABLE_NAME"
            )->results()
        );

        $this->assertNotEmpty(
            $expectedTables,
            'The connected test schema reports no base tables — cannot verify backup coverage.'
        );

        $backupManager = new BackupManager(dbi(), $this->testBackupDir, 1);
        $backupPath = $backupManager->createManualBackup('Backup Critical Tables Test');

        $this->assertFileExists($backupPath);
        $content = file_get_contents($backupPath);
        $this->assertNotFalse($content, "Could not read generated backup file: {$backupPath}");

        foreach ($expectedTables as $table) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE `' . preg_quote($table, '/') . '`/',
                $content,
                "Default backup is missing a CREATE TABLE statement for `{$table}`. " .
                "getAllTables() must discover every base table in the schema — a table " .
                "missing here means a backup silently drops data, exactly the failure " .
                "mode #1714 fixed."
            );
        }
    }

    /**
     * ANTI-REGRESSION GUARD — the most important assertion in this file.
     *
     * #1696 put a hardcoded critical-tables array in BackupManager itself; #1714
     * found and removed a SECOND, independently hand-copied hardcoded array in
     * app/admin/includes/system/backup-operations.php (`$criticalTables = [...]`,
     * which listed the nonexistent `car_history` table) that nobody noticed drifting
     * out of sync with the first. Both lists are gone now: the admin endpoint calls
     * `createManualBackup($reason, [], ...)` with an empty table array so
     * BackupManager::getAllTables() discovers every table itself.
     *
     * This test reads the file as plain text and fails if a table-name array literal
     * creeps back in. It cannot detect every conceivable reintroduction (someone
     * could hide a list behind a differently-shaped construct), but it catches the
     * specific pattern that caused #1714: a PHP array literal assigned to a variable
     * whose name suggests a table list. That is precisely the shape both prior lists
     * had, and the shape most likely to be pasted back in by a future "quick fix".
     */
    #[Group('integration')]
    public function test_backupOperationsHasNoHardcodedTableList(): void
    {
        $path = dirname(__DIR__, 3) . '/app/admin/includes/system/backup-operations.php';
        $this->assertFileExists($path, "Could not locate backup-operations.php at {$path}");

        $source = file_get_contents($path);
        $this->assertNotFalse($source, "Could not read {$path}");

        // Matches a PHP array literal assigned to a variable whose name contains
        // "table" (case-insensitive), e.g. `$criticalTables = [...]` or
        // `$tables = ['settings', 'users', ...]`. This is the exact shape of both
        // hardcoded lists #1714 removed.
        $matched = preg_match(
            '/\$\w*[Tt]ables?\w*\s*=\s*\[/',
            $source,
            $matches
        );

        $this->assertSame(
            0,
            $matched,
            "backup-operations.php appears to contain a hardcoded table-name array " .
            "literal again (matched: '" . ($matches[0] ?? '') . "'). This is exactly " .
            "the pattern that caused #1714: a second, independently maintained table " .
            "list that silently drifted out of sync with BackupManager::getAllTables() " .
            "and broke every manual backup. Do not reintroduce a hardcoded table list " .
            "in this file — pass an empty array to createManualBackup()/createSchemaBackup() " .
            "so BackupManager discovers tables itself, or pass an explicit array only " .
            "when intentionally backing up a subset."
        );
    }

    /**
     * Helper: Recursively remove a directory and its contents
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
