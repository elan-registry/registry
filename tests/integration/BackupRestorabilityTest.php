<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Admin\BackupManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves a BackupManager dump is executable SQL that restores real data
 * byte-for-byte. There is no production restore path yet; this proves the
 * dump is faithful, which any restore needs.
 *
 * Safety: the dump drops and recreates tables. Table names are rewritten to
 * random scratch tables, anchored to statement starts so row data is not
 * changed. executeSqlStatements() refuses any DROP/CREATE/INSERT that does not
 * target a scratch table, so a rewrite that does nothing cannot drop live
 * tables. Scratch tables are safe: no FKs, and the cars triggers are bound to
 * the literal name `cars`.
 */
#[Group('integration')]
#[Group('admin')]
final class BackupRestorabilityTest extends IntegrationTestCase
{
    private string $backupBaseDir = '';
    private string $scratchSuffix = '';

    /** @var string[] Scratch tables the restore creates; dropped in tearDown() */
    private array $scratchTables = [];

    private BackupManager $backupManager;
    private int $testUserId = 0;
    private int $testCarId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireDatabase();

        // Not the real backups/ tree. The dump holds password hashes, so the path
        // uses random_bytes() and the directory is 0700.
        $this->backupBaseDir = rtrim(sys_get_temp_dir(), '/') . '/backup_roundtrip_' . bin2hex(random_bytes(8)) . '/';
        if (!mkdir($this->backupBaseDir, 0700)) {
            $this->fail("Could not create temp backup directory: {$this->backupBaseDir}");
        }

        $this->testUserId = $this->createTestUser();

        // Exercises addslashes() escaping, incl. a newline + semicolon inside a value
        // that a naive "line ends in ;" split would truncate.
        $this->testCarId = $this->createTestCar($this->testUserId, [
            'color'    => "O'Brien Green",
            'comments' => "Round-trip fixture: apostrophe ' backslash \\ quote \" percent %\nline two; still one value\nline three",
        ]);

        $this->backupManager = new BackupManager($this->db, $this->backupBaseDir, $this->testUserId);
        $this->scratchSuffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->isDatabaseConnected()) {
            foreach ($this->scratchTables as $scratchTable) {
                // Identifiers cannot be bound; names are a fixed prefix plus random_bytes().
                $this->db->query("DROP TABLE IF EXISTS `{$scratchTable}`");
                if ($this->db->error()) {
                    // Do not mask the original failure, but leave a trace of the orphan table.
                    fwrite(STDERR, "NOTE: tearDown() failed to drop scratch table {$scratchTable}: {$this->db->errorString()}\n");
                }
            }
        }

        if (is_dir($this->backupBaseDir)) {
            $this->recursiveRemoveDirectory($this->backupBaseDir);
        }

        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testManualBackupRestoresFixtureRowsIntoScratchTablesWithParity(): void
    {
        // Captured before the dump; the restore is never compared with the dump itself.
        $userSnapshot = $this->fetchRow('users', 'id', $this->testUserId);
        $carSnapshot = $this->fetchRow('cars', 'id', $this->testCarId);
        $this->assertNotEmpty($userSnapshot, 'Fixture user row must exist before the backup');
        $this->assertNotEmpty($carSnapshot, 'Fixture car row must exist before the backup');

        $backupPath = $this->backupManager->createManualBackup(
            'Integration Test Round Trip',
            ['users', 'cars'],
            ['test' => self::class]
        );

        $this->assertFileExists($backupPath);
        $this->assertGreaterThan(0, filesize($backupPath));

        // The dump holds password hashes and lands at the umask default; tighten it.
        chmod($backupPath, 0600);

        $integrity = $this->backupManager->verifyBackupIntegrity($backupPath);
        $this->assertTrue($integrity['valid'], 'Backup reported invalid: ' . ($integrity['error'] ?? 'unknown'));

        $dumpContent = file_get_contents($backupPath);
        $this->assertIsString($dumpContent, "Could not read backup file: {$backupPath}");

        $usersScratch = 'users_bkt_' . $this->scratchSuffix;
        $carsScratch = 'cars_bkt_' . $this->scratchSuffix;

        // Track before execution so tearDown() cleans a partial restore and the whitelist guard knows the names.
        $this->scratchTables = [$usersScratch, $carsScratch];

        // Anchored to statement starts: an unanchored replace would change row data.
        $rewritten = preg_replace(
            [
                '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `users`/m',
                '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `cars`/m',
            ],
            ["$1 `{$usersScratch}`", "$1 `{$carsScratch}`"],
            $dumpContent,
            -1,
            $rewriteCount
        );
        $this->assertIsString($rewritten, 'Table-name rewrite regex failed');
        // Fail fast with a clear message; the whitelist guard is the real protection.
        $this->assertGreaterThanOrEqual(
            4,
            $rewriteCount,
            'Table-name rewrite matched too few statements — the dump format may have changed'
        );

        $this->executeSqlStatements($rewritten);

        $restoredUser = $this->fetchRow($usersScratch, 'id', $this->testUserId);
        $restoredCar = $this->fetchRow($carsScratch, 'id', $this->testCarId);

        $this->assertNotEmpty($restoredUser, "Fixture user {$this->testUserId} was not restored into {$usersScratch}");
        $this->assertNotEmpty($restoredCar, "Fixture car {$this->testCarId} was not restored into {$carsScratch}");

        // Full-row assertSame: one corrupted field, or '1e3' vs '1000', must fail.
        $this->assertSame($userSnapshot, $restoredUser, 'Restored user row differs from the pre-backup snapshot');
        $this->assertSame($carSnapshot, $restoredCar, 'Restored car row differs from the pre-backup snapshot');

        // Live tables get concurrent writes, so compare only with the dump's own count.
        $this->assertSame(
            substr_count($dumpContent, 'INSERT INTO `users` VALUES ('),
            $this->countRows($usersScratch),
            'Restored user row count does not match the number of INSERTs in the dump'
        );
        $this->assertSame(
            substr_count($dumpContent, 'INSERT INTO `cars` VALUES ('),
            $this->countRows($carsScratch),
            'Restored car row count does not match the number of INSERTs in the dump'
        );
    }

    /**
     * No skip guard: a missing directory silently stopped every backup from
     * v2.20.0 to 2026-07-29, so this must fail.
     *
     * @return void
     */
    public function testRealBackupsDirectoryExistsAsEnvironmentSanityCheck(): void
    {
        $realBackupDir = TESTING_ROOT . '/' . BACKUP_BASE_DIR;

        $this->assertDirectoryExists(
            $realBackupDir,
            "Production backups directory missing at {$realBackupDir} — this must exist for "
            . "BackupManager's real (non-test) callers to succeed."
        );
    }

    /**
     * Execute a dump's statements one at a time.
     *
     * Does not reuse UserSpice importSQL(): it splits on a `;` inside a quoted
     * value and reports no error. Refuses any DROP/CREATE/INSERT that does not
     * target $this->scratchTables, independent of the caller's checks.
     *
     * @param string $sql Dump contents with table identifiers already rewritten
     * @return void
     */
    private function executeSqlStatements(string $sql): void
    {
        $statement = '';
        $inString = false;

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);

            // Skip blank lines and comments only between statements, never inside a string.
            if ($statement === '' && !$inString && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }

            $statement .= $line . "\n";
            $inString = $this->stringStateAfterLine($line, $inString);

            // Only a `;` outside a string literal ends the statement.
            if ($inString || !str_ends_with($trimmed, ';')) {
                continue;
            }

            $this->executeOneStatement($statement);
            $statement = '';
        }

        $this->assertSame('', trim($statement), 'Dump ended with an incomplete statement (no closing semicolon)');
    }

    /**
     * Track whether $line leaves the parser inside an open single-quoted string.
     * A quote after an odd run of backslashes is escaped (addslashes()).
     *
     * @param string $line Raw dump line (no trailing newline)
     * @param bool $inString Whether a string literal was already open before $line
     * @return bool Whether a string literal is open after $line
     */
    private function stringStateAfterLine(string $line, bool $inString): bool
    {
        $backslashRun = 0;

        foreach (str_split($line) as $char) {
            if ($char === '\\') {
                $backslashRun++;
                continue;
            }
            if ($char === "'" && $backslashRun % 2 === 0) {
                $inString = !$inString;
            }
            $backslashRun = 0;
        }

        return $inString;
    }

    /**
     * Whitelist-check and execute one complete SQL statement.
     *
     * @param string $statement A single statement, including its trailing `;`
     * @return void
     */
    private function executeOneStatement(string $statement): void
    {
        if (
            preg_match('/^(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE|INSERT INTO) `([^`]+)`/', $statement, $matches)
            && !in_array($matches[1], $this->scratchTables, true)
        ) {
            $this->fail(
                "Refusing to execute a restore statement targeting non-scratch table `{$matches[1]}` "
                . '— the table-name rewrite must have missed this statement.'
            );
        }

        $this->db->query($statement);
        if ($this->db->error()) {
            // Truncated: the statement can hold a password hash and this goes to CI logs.
            $preview = substr(preg_replace('/\s+/', ' ', $statement) ?? $statement, 0, 120);
            $this->fail(
                "Restore statement failed: {$this->db->errorString()}\nStatement (truncated): {$preview}…"
            );
        }
    }

    /**
     * Fetch a single row as an associative array, failing loudly if the query errors.
     *
     * @param string $table Table name (hardcoded or scratch-suffixed, never external input)
     * @param string $column Column to filter on
     * @param int $value Value to match
     * @return array<string, mixed> The matching row, or an empty array if none matched
     */
    private function fetchRow(string $table, string $column, int $value): array
    {
        $result = $this->db->query("SELECT * FROM `{$table}` WHERE `{$column}` = ?", [$value]);
        if ($result->error()) {
            $this->fail("Row lookup failed for {$table}.{$column}={$value}: {$result->errorString()}");
        }

        return $result->first(true);
    }

    /**
     * Count the rows in a table, failing loudly if the query errors.
     *
     * @param string $table Table name (scratch-suffixed, never external input)
     * @return int Row count
     */
    private function countRows(string $table): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM `{$table}`");
        if ($result->error()) {
            $this->fail("Row count failed for {$table}: {$result->errorString()}");
        }

        return (int) $result->first()->cnt;
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory path to remove
     * @return void
     */
    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_link($path) || !is_dir($path)) {
                if (!unlink($path)) {
                    fwrite(STDERR, "NOTE: tearDown() failed to unlink {$path}\n");
                }
            } else {
                $this->recursiveRemoveDirectory($path);
            }
        }
        if (!rmdir($dir)) {
            fwrite(STDERR, "NOTE: tearDown() failed to remove directory {$dir}\n");
        }
    }
}
