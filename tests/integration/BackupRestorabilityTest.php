<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestCase.php';

use ElanRegistry\Admin\BackupManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests proving a BackupManager dump is well-formed, executable SQL
 * that round-trips real data byte-for-byte through addslashes() escaping.
 *
 * Nothing in this codebase currently calls UserSpice's importSQL() (or any other
 * restore mechanism) against a BackupManager dump — there is no production
 * restore path today. What this proves is that the dump itself is faithful and
 * mechanically replayable (real DROP/CREATE/INSERT, real MySQL parsing, real
 * row-for-row parity), which is the prerequisite for any restore mechanism to
 * work, not a guarantee that the specific mechanism an admin would reach for
 * (importSQL(), the mysql CLI, etc.) already handles it correctly.
 *
 * The existing unit tests only assert that a dump file is written and looks like SQL.
 * These tests execute the dump for real and compare the restored rows against an
 * independent snapshot taken before the dump was generated.
 *
 * generateTableDump() emits an unfiltered `DROP TABLE` + `CREATE TABLE` + `INSERT`
 * sequence, so running it verbatim would destroy the shared `users`/`cars` tables
 * every other integration test depends on, and no CREATE DATABASE privilege is
 * assumed for a separate scratch schema. Instead the dump's table identifiers are
 * rewritten to uniquely suffixed scratch tables in the same schema, anchored to the
 * start of each DROP/CREATE/INSERT statement (not a bare string replace, which would
 * also mangle row data containing the same literal substring). That rewrite is
 * verified twice: a match-count assertion right after it runs, and a whitelist guard
 * in executeSqlStatements() that refuses to execute any DROP/CREATE/INSERT statement
 * not targeting a tracked scratch table — so a rewrite that silently no-ops can't
 * fall through to running DROP TABLE against the live schema. The scratch-table
 * approach itself is safe because the schema has no foreign keys (see
 * 20260719120000_drop_cars_user_id_fk) and the `cars` audit triggers are bound to
 * the literal table name `cars`, so populating `cars_bkt_*` writes no phantom
 * `cars_hist` rows.
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

        // A throwaway base directory, deliberately not the real backups/ tree — test
        // runs must not litter it. random_bytes(), not uniqid(): the dump this directory
        // will hold contains the whole users table (password hashes included), so the
        // path must not be guessable, and the directory is created 0700 (not
        // BackupManager's default 0755) so it isn't even world-traversable in between.
        $this->backupBaseDir = rtrim(sys_get_temp_dir(), '/') . '/backup_roundtrip_' . bin2hex(random_bytes(8)) . '/';
        if (!mkdir($this->backupBaseDir, 0700)) {
            $this->fail("Could not create temp backup directory: {$this->backupBaseDir}");
        }

        $this->testUserId = $this->createTestUser();

        // Values chosen to exercise generateTableDump()'s addslashes() escaping,
        // including an embedded newline + mid-value semicolon in `comments` —
        // exactly the case executeSqlStatements()'s quote-aware parser exists to
        // handle correctly (a naive "line ends in ;" split would truncate this
        // value mid-INSERT).
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
                // Identifiers cannot be bound as parameters; these names are built from a
                // hardcoded prefix plus random_bytes(), never from external input.
                $this->db->query("DROP TABLE IF EXISTS `{$scratchTable}`");
                if ($this->db->error()) {
                    // Never let cleanup mask the original failure, but a silent swallow would
                    // leave an orphaned table in the test schema with no trace of why.
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
     * A manual backup of `users` and `cars` must restore both fixture rows byte-for-byte
     * into scratch tables, and the restored row counts must match what the dump claims.
     *
     * @return void
     */
    public function testManualBackupRestoresFixtureRowsIntoScratchTablesWithParity(): void
    {
        // Independent source of truth, captured before the dump exists. The restore is
        // never compared against the dump's own content.
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

        // The dump contains the whole users table (password hashes included).
        // BackupManager writes it via file_put_contents() with no explicit chmod, so it
        // lands at the OS umask default (typically 0644) — tighten it explicitly for the
        // remainder of this test's lifetime, same reasoning as the 0700 backupBaseDir above.
        chmod($backupPath, 0600);

        $integrity = $this->backupManager->verifyBackupIntegrity($backupPath);
        $this->assertTrue($integrity['valid'], 'Backup reported invalid: ' . ($integrity['error'] ?? 'unknown'));

        $dumpContent = file_get_contents($backupPath);
        $this->assertIsString($dumpContent, "Could not read backup file: {$backupPath}");

        $usersScratch = 'users_bkt_' . $this->scratchSuffix;
        $carsScratch = 'cars_bkt_' . $this->scratchSuffix;

        // Tracked before execution so tearDown() cleans up even a partial restore, and
        // so executeSqlStatements()'s whitelist guard (see below) knows what's allowed.
        $this->scratchTables = [$usersScratch, $carsScratch];

        // Anchored to the start of DROP/CREATE/INSERT statements, not a bare str_replace
        // of every `users`/`cars` occurrence — the dump also contains row data, and an
        // unanchored replace would corrupt any fixture value that happens to contain the
        // literal backticked substring, silently restoring wrong data instead of failing.
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
        // At minimum, DROP + CREATE for each table must have matched; INSERT count varies
        // with fixture row count. This is a fail-fast, precisely-diagnosable check — the
        // separate whitelist guard in executeSqlStatements() is what actually prevents a
        // silent no-op rewrite from reaching the live tables; this assertion exists so a
        // regression is caught right here, with a clear message, rather than surfacing
        // later as a less obvious failure inside statement execution.
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

        // Full-row, type-strict equality: addslashes() escaping could corrupt a single
        // field while the rest still match, so a subset comparison would miss it, and
        // assertSame (not assertEquals) means a numeric-string mismatch like '1e3' vs
        // '1000' fails instead of comparing equal.
        $this->assertSame($userSnapshot, $restoredUser, 'Restored user row differs from the pre-backup snapshot');
        $this->assertSame($carSnapshot, $restoredCar, 'Restored car row differs from the pre-backup snapshot');

        // Self-consistency only: the live tables can receive concurrent writes from other
        // tests, so the dump's own claimed row count is the only valid comparison.
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
     * The real backups/ directory must exist. Deliberately no skip guard: a missing
     * directory silently aborted every backup between v2.20.0 and 2026-07-29, so this
     * has to fail rather than skip.
     *
     * Uses TESTING_ROOT (the project root, defined by bootstrap-integration.php) rather
     * than $abs_us_root/$us_url_root, which are bootstrap-scope globals not visible
     * here. TESTING_ROOT resolves to the same directory in this single-docroot
     * dev/test setup.
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
     * Execute a dump's statements one at a time against the real database.
     *
     * Mirrors the accumulate-until-semicolon parsing UserSpice's own importSQL()
     * helper uses (users/helpers/us_helpers.php), but reads from an in-memory
     * string, tracks single-quote state so a `;` inside an addslashes()-escaped
     * row value doesn't falsely end a statement (importSQL() has this same bug —
     * it isn't reused here specifically to avoid inheriting it), and checks
     * error() after every statement — importSQL() returns void and reports
     * nothing on failure, so this method exists to surface exactly which
     * statement failed instead of leaving a half-restored table to be
     * diagnosed from a downstream assertion.
     *
     * Structural safety net: refuses to run any DROP/CREATE/INSERT statement that doesn't
     * target one of $this->scratchTables, regardless of what the caller's rewrite produced.
     * The caller's regex rewrite is verified separately (see the match-count assertion in
     * the test method), but that check happens before execution — this one happens at
     * execution time and doesn't rely on the caller having checked anything.
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

            // Blank lines and comments are skipped only between statements (never mid-string) —
            // a multi-line CREATE TABLE body, or a row value containing an embedded newline,
            // must be accumulated exactly as written.
            if ($statement === '' && !$inString && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                continue;
            }

            $statement .= $line . "\n";
            $inString = $this->stringStateAfterLine($line, $inString);

            // A `;` inside an open string (e.g. "...;\n" embedded in a comments field)
            // must not end the statement — only a `;` outside any string literal does.
            if ($inString || !str_ends_with($trimmed, ';')) {
                continue;
            }

            $this->executeOneStatement($statement);
            $statement = '';
        }

        $this->assertSame('', trim($statement), 'Dump ended with an incomplete statement (no closing semicolon)');
    }

    /**
     * Track whether $line leaves the parser inside an open single-quoted string,
     * given the state before this line. addslashes() escapes both `'` and `\`
     * with a leading backslash, so a quote preceded by an odd run of backslashes
     * is escaped and doesn't toggle string state.
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
            // Truncated: the statement can be a full row (password hash, email, etc.
            // for `users`) and this message is written to CI logs.
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
