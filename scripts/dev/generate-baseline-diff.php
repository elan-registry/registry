<?php

declare(strict_types=1);

/**
 * generate-baseline-diff.php — draft a Phinx migration from a live schema diff.
 *
 * Compares a *stock* UserSpice database (freshly installed from an official
 * release, nothing ElanRegistry applied to it) against a *project* database
 * (the current dev schema) and emits the structural delta as a draft Phinx
 * migration.
 *
 * This exists because ElanRegistry's baseline migration has to reproduce ~14
 * new tables plus column drift across ~28 shared tables. Hand-diffing that by
 * eye is not reviewable. The tool does the mechanical part; a human reviews and
 * edits the result.
 *
 * THE OUTPUT IS A DRAFT, NOT A MIGRATION. Redirect it to a file, read every
 * statement against the raw `mysqldump --no-data` output of both databases,
 * and edit before committing. Nothing here is authoritative.
 *
 * Keep this file checked in: the next UserSpice version bump needs the same
 * exercise, and rewriting it from scratch each time invites new mistakes.
 *
 * ## Why raw SQL and up()/down() instead of change()
 *
 * `database/migrations/README.md` prefers `change()`, but names the exact
 * exception this diff hits: `changeColumn()` inside `change()` throws
 * `IrreversibleMigrationException` on rollback because Phinx records the new
 * column definition and not the original. The drift here is full of type
 * widening and nullability changes, so `up()` + `down()` is the sanctioned
 * path. Raw DDL (rather than the `$this->table()` builder) is used because the
 * DDL is lifted verbatim from `SHOW CREATE TABLE` — translating 14 CREATE TABLE
 * statements into builder calls by hand is exactly the transcription error this
 * tool is meant to eliminate. Both choices match the majority idiom in the
 * existing migrations (see `20260711000000_drop_car_user_tables.php`).
 *
 * ## Usage
 *
 *   php scripts/dev/generate-baseline-diff.php > /tmp/draft-baseline.php
 *
 *   php scripts/dev/generate-baseline-diff.php \
 *       --stock-db=userspice_614 --stock-user=root --stock-pass=secret \
 *       --project-db=elanregi_spice --project-user=app --project-pass=... \
 *       --host=127.0.0.1 --port=3306 \
 *       --class=AddElanregistryBaseline \
 *       --exclude=phinxlog
 *
 * Every connection value can also come from the environment:
 *
 *   STOCK_DB_HOST/PORT/NAME/USER/PASS
 *   PROJECT_DB_HOST/PORT/NAME/USER/PASS   (falls back to DB_HOST/... )
 *
 * Precedence is flag > specific env var > shared flag (--host/--port) >
 * built-in default. No credential is required to be hardcoded anywhere.
 *
 * `--exclude=` takes a comma-separated list of tables to leave out of the
 * generated migration. `phinxlog` is excluded by default: Phinx creates and
 * owns that table, and a migration that creates it would deadlock provisioning.
 *
 * By default each drifted table is converted to *its own* project charset and
 * collation, so the output reproduces the project schema exactly — including
 * any legacy collations it still carries. Passing `--charset=` and
 * `--collation=` forces every drifted table to one target instead, which is how
 * you would generate a deliberate normalisation pass. Those are separate
 * decisions and should be separate migrations.
 */

// ── Argument parsing ─────────────────────────────────────────────────────────

/** @return array<string, string> */
function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $opts['help'] = '1';
            continue;
        }
        if (!preg_match('/^--([a-z0-9-]+)=(.*)$/i', $arg, $m)) {
            fwrite(STDERR, "ERROR: unrecognised argument '{$arg}' (expected --name=value).\n");
            exit(1);
        }
        $opts[$m[1]] = $m[2];
    }

    return $opts;
}

/**
 * Resolve one connection setting: flag, then each env var in order, then fallback.
 *
 * @param list<string> $envVars checked left to right, so a specific name
 *                              (PROJECT_DB_NAME) can precede a generic one (DB_NAME)
 */
function setting(array $opts, string $flag, array $envVars, string $fallback): string
{
    if (isset($opts[$flag]) && $opts[$flag] !== '') {
        return $opts[$flag];
    }
    foreach ($envVars as $envVar) {
        $env = getenv($envVar);
        if (is_string($env) && $env !== '') {
            return $env;
        }
    }

    return $fallback;
}

function connect(string $host, string $port, string $db, string $user, string $pass, string $label): PDO
{
    // A host MySQL client treats 'localhost' as a Unix socket. Force TCP for
    // the same reason phinx.php does.
    if ($host === 'localhost') {
        $host = '127.0.0.1';
    }

    try {
        return new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (PDOException $e) {
        fwrite(STDERR, "ERROR: cannot connect to {$label} database '{$db}' at {$host}:{$port} — {$e->getMessage()}\n");
        exit(1);
    }
}

// ── Introspection ────────────────────────────────────────────────────────────

/**
 * @return array<string, array{engine: string, collation: string, charset: string, options: string}>
 */
function fetchTables(PDO $pdo, string $schema): array
{
    // CREATE_OPTIONS carries table options that were declared explicitly, such
    // as row_format=DYNAMIC. ROW_FORMAT itself is deliberately NOT compared:
    // MySQL reports the *effective* row format there, which varies by engine
    // and version with no corresponding schema difference.
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, CREATE_OPTIONS
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :schema
            AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME"
    );
    $stmt->execute(['schema' => $schema]);

    $tables = [];
    foreach ($stmt as $row) {
        $collation = (string) ($row['TABLE_COLLATION'] ?? '');
        $tables[$row['TABLE_NAME']] = [
            'engine'    => (string) ($row['ENGINE'] ?? ''),
            'collation' => $collation,
            'charset'   => $collation === '' ? '' : explode('_', $collation)[0],
            'options'   => trim((string) ($row['CREATE_OPTIONS'] ?? '')),
        ];
    }

    return $tables;
}

/**
 * @return array<string, array<string, array<string, mixed>>> table => column => metadata
 */
function fetchColumns(PDO $pdo, string $schema): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE,
                COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA, COLUMN_COMMENT,
                GENERATION_EXPRESSION
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :schema
          ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $stmt->execute(['schema' => $schema]);

    $columns = [];
    foreach ($stmt as $row) {
        $columns[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
    }

    return $columns;
}

/**
 * @return array<string, array<string, array{unique: bool, type: string, columns: list<string>}>>
 */
function fetchIndexes(PDO $pdo, string $schema): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = :schema
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    );
    $stmt->execute(['schema' => $schema]);

    $indexes = [];
    foreach ($stmt as $row) {
        $part = $row['COLUMN_NAME'] . ($row['SUB_PART'] !== null ? "({$row['SUB_PART']})" : '');
        $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']]['unique']    = ((int) $row['NON_UNIQUE']) === 0;
        $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']]['type']      = (string) $row['INDEX_TYPE'];
        $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']]['columns'][] = $part;
    }

    return $indexes;
}

/**
 * @return array<string, array<string, array{columns: list<string>, ref_table: string, ref_columns: list<string>, on_update: string, on_delete: string}>>
 */
function fetchForeignKeys(PDO $pdo, string $schema): array
{
    // TABLE_CONSTRAINTS pins the constraint to FOREIGN KEY; REFERENTIAL_CONSTRAINTS
    // carries the ON UPDATE / ON DELETE rules that KEY_COLUMN_USAGE does not have.
    $stmt = $pdo->prepare(
        "SELECT kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME,
                kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                rc.UPDATE_RULE, rc.DELETE_RULE
           FROM information_schema.TABLE_CONSTRAINTS tc
           JOIN information_schema.KEY_COLUMN_USAGE kcu
             ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
            AND kcu.CONSTRAINT_NAME   = tc.CONSTRAINT_NAME
            AND kcu.TABLE_NAME        = tc.TABLE_NAME
           JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
             ON rc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
            AND rc.CONSTRAINT_NAME   = tc.CONSTRAINT_NAME
          WHERE tc.TABLE_SCHEMA = :schema
            AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'
          ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION"
    );
    $stmt->execute(['schema' => $schema]);

    $fks = [];
    foreach ($stmt as $row) {
        $table      = (string) $row['TABLE_NAME'];
        $constraint = (string) $row['CONSTRAINT_NAME'];

        // Rows arrive one per key column, ordered by ORDINAL_POSITION, so a
        // composite key accumulates its columns across several iterations.
        $fks[$table][$constraint] ??= [
            'columns'     => [],
            'ref_columns' => [],
            'ref_table'   => (string) $row['REFERENCED_TABLE_NAME'],
            'on_update'   => (string) $row['UPDATE_RULE'],
            'on_delete'   => (string) $row['DELETE_RULE'],
        ];
        $fks[$table][$constraint]['columns'][]     = (string) $row['COLUMN_NAME'];
        $fks[$table][$constraint]['ref_columns'][] = (string) $row['REFERENCED_COLUMN_NAME'];
    }

    return $fks;
}

/**
 * @return array<string, list<array<string, mixed>>> table => triggers
 */
function fetchTriggers(PDO $pdo, string $schema): array
{
    $stmt = $pdo->prepare(
        "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE,
                ACTION_TIMING, ACTION_STATEMENT
           FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = :schema
          ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, TRIGGER_NAME"
    );
    $stmt->execute(['schema' => $schema]);

    $triggers = [];
    foreach ($stmt as $row) {
        $triggers[$row['EVENT_OBJECT_TABLE']][] = $row;
    }

    return $triggers;
}

/**
 * Exact CREATE TABLE DDL, straight from the server.
 *
 * information_schema is good for *detecting* drift but a poor source for
 * *reproducing* it — generated columns, expression defaults and index prefixes
 * all round-trip badly. SHOW CREATE TABLE is what the server itself would emit.
 */
function showCreateTable(PDO $pdo, string $table): string
{
    $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch();

    // AUTO_INCREMENT counters are per-environment state, never schema.
    return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $row['Create Table']);
}

/**
 * Pull one column's definition line out of SHOW CREATE TABLE.
 *
 * Returned without the leading backticked name and without the trailing comma,
 * e.g. "int NOT NULL DEFAULT '0'". Returns null if the column is absent.
 */
function columnDefinition(string $createTable, string $column): ?string
{
    $quoted = preg_quote($column, '/');
    foreach (explode("\n", $createTable) as $line) {
        if (preg_match('/^\s*`' . $quoted . '`\s+(.*?),?$/', $line, $m)) {
            return trim($m[1]);
        }
    }

    return null;
}

// ── Comparison ───────────────────────────────────────────────────────────────

/**
 * Column signature with charset/collation deliberately excluded.
 *
 * The project database was converted wholesale to utf8mb4_unicode_ci at some
 * point, so nearly every text column differs from stock on collation alone.
 * Folding that out here is what lets the generator collapse the noise into one
 * CONVERT TO CHARACTER SET per table instead of hundreds of MODIFY COLUMNs.
 */
function structuralSignature(array $col): string
{
    return implode('|', [
        $col['COLUMN_TYPE'],
        $col['IS_NULLABLE'],
        $col['COLUMN_DEFAULT'] ?? '~NULL~',
        $col['EXTRA'],
        $col['COLUMN_COMMENT'],
        // Two generated columns can share a type and differ entirely in what
        // they compute. Note that SHOW CREATE TABLE re-renders the expression's
        // charset introducers, so it is not a faithful source for this one
        // field — compare information_schema, and hand-check any generated
        // column the tool reports.
        $col['GENERATION_EXPRESSION'] ?? '',
    ]);
}

/**
 * True when the two columns differ only in charset/collation.
 */
function collationOnlyDrift(array $stockCol, array $projectCol): bool
{
    if (structuralSignature($stockCol) !== structuralSignature($projectCol)) {
        return false;
    }

    return $stockCol['COLLATION_NAME'] !== $projectCol['COLLATION_NAME']
        || $stockCol['CHARACTER_SET_NAME'] !== $projectCol['CHARACTER_SET_NAME'];
}

function indexSignature(array $index): string
{
    return ($index['unique'] ? 'UNIQUE' : 'INDEX') . ':' . $index['type'] . ':' . implode(',', $index['columns']);
}

function fkSignature(array $fk): string
{
    return implode(',', $fk['columns'])
        . '->' . $fk['ref_table'] . '(' . implode(',', $fk['ref_columns']) . ')'
        . ' ON UPDATE ' . $fk['on_update'] . ' ON DELETE ' . $fk['on_delete'];
}

// ── Emission helpers ─────────────────────────────────────────────────────────

/**
 * Emit `$this->execute('...')` with the SQL indented to sit inside a method.
 *
 * Single-quoted, not double-quoted: DDL text sourced from `information_schema`
 * (column COMMENTs, string DEFAULTs, generated-column expressions, trigger
 * bodies) could contain `$` or `{$...}` sequences that PHP would interpolate
 * as variables if emitted into a double-quoted string. Single-quoted PHP
 * strings never interpolate, so only `\` and `'` need escaping — and `\` must
 * be escaped first, or escaping `'` would double-escape the backslash it just
 * introduced.
 */
function emitExecute(string $sql, int $indentLevel = 2): string
{
    $indent = str_repeat('    ', $indentLevel);
    $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], rtrim($sql));
    $lines  = explode("\n", $escaped);

    if (count($lines) === 1) {
        return $indent . "\$this->execute('" . $lines[0] . "');" . "\n";
    }

    $inner = $indent . '    ';
    $out   = $indent . '$this->execute(' . "\n";
    foreach ($lines as $i => $line) {
        $out .= $inner
            . ($i === 0 ? "'" : ' ')
            . $line
            . ($i === count($lines) - 1 ? "'" : '')
            . "\n";
    }

    return $out . $indent . ');' . "\n";
}

function emitComment(string $text, int $indentLevel = 2): string
{
    $indent = str_repeat('    ', $indentLevel);
    $out    = '';
    foreach (explode("\n", $text) as $line) {
        $out .= $indent . '// ' . $line . "\n";
    }

    return $out;
}

function bt(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/** @param array<string, mixed> $trigger One row from fetchTriggers(). */
function dropTriggerSql(array $trigger): string
{
    return 'DROP TRIGGER IF EXISTS ' . bt((string) $trigger['TRIGGER_NAME']);
}

/** @param array<string, mixed> $trigger One row from fetchTriggers(). */
function createTriggerSql(array $trigger, string $table): string
{
    return sprintf(
        'CREATE TRIGGER %s %s %s ON %s FOR EACH ROW %s',
        bt((string) $trigger['TRIGGER_NAME']),
        $trigger['ACTION_TIMING'],
        $trigger['EVENT_MANIPULATION'],
        bt($table),
        $trigger['ACTION_STATEMENT']
    );
}

// ── Main ─────────────────────────────────────────────────────────────────────

$opts = parseArgs($argv);

if (isset($opts['help'])) {
    // The file header is the documentation; print it rather than duplicating it.
    $source = file_get_contents(__FILE__);
    if (preg_match('#/\*\*(.*?)\*/#s', (string) $source, $m)) {
        fwrite(STDOUT, preg_replace('/^ \* ?/m', '', $m[1]) . "\n");
    }
    exit(0);
}

$sharedHost = setting($opts, 'host', ['DB_HOST'], '127.0.0.1');
$sharedPort = setting($opts, 'port', ['DB_PORT'], '3306');

// DB_HOST may carry a port ("127.0.0.1:3307"); strip it so -h gets a bare host.
if (str_contains($sharedHost, ':')) {
    [$sharedHost, $hostPort] = explode(':', $sharedHost, 2);
    if ($sharedPort === '3306' && $hostPort !== '') {
        $sharedPort = $hostPort;
    }
}

$stockHost = setting($opts, 'stock-host', ['STOCK_DB_HOST'], $sharedHost);
$stockPort = setting($opts, 'stock-port', ['STOCK_DB_PORT'], $sharedPort);
$stockDb   = setting($opts, 'stock-db', ['STOCK_DB_NAME'], 'elanregi_spice614');
// The stock scratch database is conventionally created with user == pass == name.
$stockUser = setting($opts, 'stock-user', ['STOCK_DB_USER'], $stockDb);
$stockPass = setting($opts, 'stock-pass', ['STOCK_DB_PASS'], $stockDb);

$projectHost = setting($opts, 'project-host', ['PROJECT_DB_HOST'], $sharedHost);
$projectPort = setting($opts, 'project-port', ['PROJECT_DB_PORT'], $sharedPort);
$projectDb   = setting($opts, 'project-db', ['PROJECT_DB_NAME', 'DB_NAME'], 'elanregi_spice');
$projectUser = setting($opts, 'project-user', ['PROJECT_DB_USER', 'DB_USER'], $projectDb);
$projectPass = setting($opts, 'project-pass', ['PROJECT_DB_PASS', 'DB_PASS'], '');

$className = $opts['class'] ?? 'AddElanregistryBaseline';

// Null means "match whatever the project table already uses" (the default, and
// the only setting that produces an exact reproduction of the project schema).
// Supplying both forces every drifted table to one target instead.
$targetCharset   = $opts['charset'] ?? null;
$targetCollation = $opts['collation'] ?? null;

$excluded = array_filter(array_map('trim', explode(',', $opts['exclude'] ?? 'phinxlog')));

$stock   = connect($stockHost, $stockPort, $stockDb, $stockUser, $stockPass, 'stock');
$project = connect($projectHost, $projectPort, $projectDb, $projectUser, $projectPass, 'project');

$stockTables   = fetchTables($stock, $stockDb);
$projectTables = fetchTables($project, $projectDb);

$stockColumns   = fetchColumns($stock, $stockDb);
$projectColumns = fetchColumns($project, $projectDb);

$stockIndexes   = fetchIndexes($stock, $stockDb);
$projectIndexes = fetchIndexes($project, $projectDb);

$stockFks   = fetchForeignKeys($stock, $stockDb);
$projectFks = fetchForeignKeys($project, $projectDb);

$projectTriggers = fetchTriggers($project, $projectDb);

$newTables     = array_diff(array_keys($projectTables), array_keys($stockTables), $excluded);
$missingTables = array_diff(array_keys($stockTables), array_keys($projectTables));
$sharedTables  = array_intersect(array_keys($stockTables), array_keys($projectTables));

sort($newTables);
sort($missingTables);
sort($sharedTables);

// ── Header ───────────────────────────────────────────────────────────────────

$out = "<?php\n\ndeclare(strict_types=1);\n\nuse Phinx\\Migration\\AbstractMigration;\n\n";
$out .= "// ============================================================================\n";
$out .= "// DRAFT — generated by scripts/dev/generate-baseline-diff.php on " . date('Y-m-d H:i') . "\n";
$out .= "//   stock:   {$stockDb} @ {$stockHost}:{$stockPort}\n";
$out .= "//   project: {$projectDb} @ {$projectHost}:{$projectPort}\n";
$out .= "//   excluded tables: " . ($excluded === [] ? '(none)' : implode(', ', $excluded)) . "\n";
$out .= "//\n";
$out .= "// Read every statement below against `mysqldump --no-data` output from BOTH\n";
$out .= "// databases before committing. This generator is a transcription aid, not an\n";
$out .= "// authority.\n";
$out .= "// ============================================================================\n\n";
$out .= "final class {$className} extends AbstractMigration\n{\n";
$out .= "    public function up(): void\n    {\n";

// ── New tables ───────────────────────────────────────────────────────────────

$out .= emitComment("── New tables (" . count($newTables) . ") ───────────────────────────────────────");
$out .= "\n";

foreach ($newTables as $table) {
    $out .= emitComment($table);
    $out .= emitExecute(showCreateTable($project, $table));

    foreach ($projectTriggers[$table] ?? [] as $trigger) {
        $out .= emitExecute(dropTriggerSql($trigger));
        $out .= emitExecute(createTriggerSql($trigger, $table));
    }
    $out .= "\n";
}

if ($missingTables !== []) {
    $out .= emitComment(
        "REVIEW: present in stock but NOT in the project database:\n  "
        . implode("\n  ", $missingTables)
        . "\nNot dropped automatically — decide deliberately."
    );
    $out .= "\n";
}

// ── Shared-table drift ───────────────────────────────────────────────────────

$driftedTables    = [];
$collationTables  = [];
$statementsByTable = [];

foreach ($sharedTables as $table) {
    if (in_array($table, $excluded, true)) {
        continue;
    }

    $sCols = $stockColumns[$table] ?? [];
    $pCols = $projectColumns[$table] ?? [];

    $statements = [];
    $notes      = [];

    // Engine drift. Rare, and always worth a human look — the project has been
    // migrating leftover MyISAM tables to InnoDB one at a time.
    if ($stockTables[$table]['engine'] !== $projectTables[$table]['engine']) {
        $notes[] = sprintf(
            'REVIEW: engine differs — stock %s, project %s.',
            $stockTables[$table]['engine'],
            $projectTables[$table]['engine']
        );
        $statements[] = sprintf(
            'ALTER TABLE %s ENGINE=%s',
            bt($table),
            $projectTables[$table]['engine']
        );
    }

    // Explicitly declared table options (row_format=DYNAMIC and friends). MySQL
    // reports these in CREATE_OPTIONS only when they were declared, so a
    // difference here is real even when the effective behaviour is identical.
    if ($stockTables[$table]['options'] !== $projectTables[$table]['options']) {
        $notes[] = sprintf(
            "table options differ — stock '%s', project '%s'.",
            $stockTables[$table]['options'],
            $projectTables[$table]['options']
        );
        foreach (explode(' ', $projectTables[$table]['options']) as $option) {
            if ($option !== '') {
                $statements[] = sprintf('ALTER TABLE %s %s', bt($table), strtoupper($option));
            }
        }
    }

    // Collation: one table-level conversion, never per-column.
    //
    // The target is whatever the PROJECT table actually uses — NOT a fixed
    // house style. The project schema is not uniform (it carries legacy
    // utf8mb3_unicode_ci and utf8mb4_0900_ai_ci tables alongside
    // utf8mb4_unicode_ci ones), and this tool's job is to reproduce it exactly.
    // --charset/--collation force a single target instead, which is how a
    // deliberate normalisation pass would be generated.
    $projectCharset   = $targetCharset ?? $projectTables[$table]['charset'];
    $projectCollation = $targetCollation ?? $projectTables[$table]['collation'];

    $needsConversion = $stockTables[$table]['collation'] !== $projectCollation;
    foreach ($sCols as $name => $sCol) {
        if (isset($pCols[$name]) && collationOnlyDrift($sCol, $pCols[$name])) {
            $needsConversion = true;
        }
    }
    if ($needsConversion) {
        $collationTables[] = $table;
        if ($projectCollation !== 'utf8mb4_unicode_ci') {
            $notes[] = sprintf(
                'REVIEW: project collation is %s, not the utf8mb4_unicode_ci used by most tables.',
                $projectCollation
            );
        }
        $statements[] = sprintf(
            "ALTER TABLE %s\n CONVERT TO CHARACTER SET %s COLLATE %s",
            bt($table),
            $projectCharset,
            $projectCollation
        );
    }

    // CONVERT TO rewrites every text column to the table collation, so a column
    // that deliberately differs from its own table needs an explicit MODIFY
    // afterwards. None exist today; this catches it if one ever appears.
    foreach ($pCols as $name => $pCol) {
        if ($pCol['COLLATION_NAME'] !== null
            && $needsConversion
            && $pCol['COLLATION_NAME'] !== $projectTables[$table]['collation']
        ) {
            $notes[] = "REVIEW: {$table}.{$name} uses {$pCol['COLLATION_NAME']}, "
                . "which differs from its table collation — verify the MODIFY below survives CONVERT TO.";
        }
    }

    $createTable = showCreateTable($project, $table);

    // Added columns, in project ordinal order so AFTER anchors always exist.
    $ordered = array_keys($pCols);
    foreach ($ordered as $i => $name) {
        if (isset($sCols[$name])) {
            continue;
        }
        $definition = columnDefinition($createTable, $name);
        if ($definition === null) {
            $notes[] = "REVIEW: could not extract DDL for added column {$table}.{$name}";
            continue;
        }
        $after = $i === 0 ? ' FIRST' : ' AFTER ' . bt($ordered[$i - 1]);
        $statements[] = sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s%s',
            bt($table),
            bt($name),
            $definition,
            $after
        );
    }

    // Structurally changed columns (collation-only drift already handled above).
    foreach ($pCols as $name => $pCol) {
        if (!isset($sCols[$name])) {
            continue;
        }
        if (structuralSignature($sCols[$name]) === structuralSignature($pCol)) {
            continue;
        }
        $definition = columnDefinition($createTable, $name);
        if ($definition === null) {
            $notes[] = "REVIEW: could not extract DDL for changed column {$table}.{$name}";
            continue;
        }
        $notes[] = sprintf(
            'changed %s.%s: stock %s %s%s -> project %s %s%s',
            $table,
            $name,
            $sCols[$name]['COLUMN_TYPE'],
            $sCols[$name]['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL',
            $sCols[$name]['COLUMN_DEFAULT'] === null ? '' : " DEFAULT '{$sCols[$name]['COLUMN_DEFAULT']}'",
            $pCol['COLUMN_TYPE'],
            $pCol['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL',
            $pCol['COLUMN_DEFAULT'] === null ? '' : " DEFAULT '{$pCol['COLUMN_DEFAULT']}'"
        );
        $statements[] = sprintf(
            'ALTER TABLE %s MODIFY COLUMN %s %s',
            bt($table),
            bt($name),
            $definition
        );
    }

    // Columns stock has that the project dropped. The DROP is emitted so the
    // draft is complete, but grep for the column before keeping it — a stock
    // column missing from the project usually means the project's database
    // never ran the UserSpice update that added it, not that it was removed on
    // purpose.
    foreach ($sCols as $name => $sCol) {
        if (!isset($pCols[$name])) {
            $notes[] = "REVIEW: stock column {$table}.{$name} ({$sCol['COLUMN_TYPE']}) is absent from the project schema.";
            $statements[] = sprintf('ALTER TABLE %s DROP COLUMN %s', bt($table), bt($name));
        }
    }

    // Column ORDER. Same columns in a different sequence is a real difference
    // (information_schema.ORDINAL_POSITION) that no amount of ADD/MODIFY on
    // individual columns will fix, and it is invisible in a naive by-name diff.
    // Reordering is all-or-nothing: each MODIFY ... AFTER shifts everything
    // behind it, so the whole table is re-seated in project order.
    $sharedOrder  = array_values(array_filter(array_keys($sCols), static fn(string $c): bool => isset($pCols[$c])));
    $projectOrder = array_values(array_filter(array_keys($pCols), static fn(string $c): bool => isset($sCols[$c])));
    if ($sharedOrder !== $projectOrder) {
        $notes[] = sprintf(
            'REVIEW: %d shared column(s) sit at a different ordinal position than in stock — full re-seat below.',
            count(array_filter(
                $projectOrder,
                static fn(string $c, int $i): bool => ($sharedOrder[$i] ?? null) !== $c,
                ARRAY_FILTER_USE_BOTH
            ))
        );

        $previous = null;
        foreach (array_keys($pCols) as $name) {
            $definition = columnDefinition($createTable, $name);
            if ($definition === null) {
                $notes[] = "REVIEW: could not extract DDL to re-seat {$table}.{$name}";
                continue;
            }
            $statements[] = sprintf(
                'ALTER TABLE %s MODIFY COLUMN %s %s %s',
                bt($table),
                bt($name),
                $definition,
                $previous === null ? 'FIRST' : 'AFTER ' . bt($previous)
            );
            $previous = $name;
        }
    }

    // Index drift.
    $sIdx = $stockIndexes[$table] ?? [];
    $pIdx = $projectIndexes[$table] ?? [];
    foreach ($pIdx as $name => $index) {
        $sig = indexSignature($index);
        if (isset($sIdx[$name]) && indexSignature($sIdx[$name]) === $sig) {
            continue;
        }
        if ($name === 'PRIMARY') {
            $notes[] = "REVIEW: PRIMARY KEY differs on {$table} — handle by hand.";
            continue;
        }
        if (isset($sIdx[$name])) {
            $statements[] = sprintf('ALTER TABLE %s DROP INDEX %s', bt($table), bt($name));
        }
        $statements[] = sprintf(
            'ALTER TABLE %s ADD %s %s (%s)',
            bt($table),
            $index['unique'] ? 'UNIQUE INDEX' : 'INDEX',
            bt($name),
            implode(', ', array_map(
                static fn(string $c): string => preg_match('/^(.*)\((\d+)\)$/', $c, $m)
                    ? bt($m[1]) . "({$m[2]})"
                    : bt($c),
                $index['columns']
            ))
        );
    }
    foreach ($sIdx as $name => $index) {
        if (!isset($pIdx[$name]) && $name !== 'PRIMARY') {
            $notes[] = "REVIEW: stock index {$table}.{$name} is absent from the project schema.";
            $statements[] = sprintf('ALTER TABLE %s DROP INDEX %s', bt($table), bt($name));
        }
    }

    // Foreign key drift.
    $sFk = $stockFks[$table] ?? [];
    $pFk = $projectFks[$table] ?? [];
    foreach ($pFk as $name => $fk) {
        if (isset($sFk[$name]) && fkSignature($sFk[$name]) === fkSignature($fk)) {
            continue;
        }
        if (isset($sFk[$name])) {
            $statements[] = sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', bt($table), bt($name));
        }
        $statements[] = sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            bt($table),
            bt($name),
            implode(', ', array_map('bt', $fk['columns'])),
            bt($fk['ref_table']),
            implode(', ', array_map('bt', $fk['ref_columns'])),
            $fk['on_delete'],
            $fk['on_update']
        );
    }
    foreach ($sFk as $name => $fk) {
        if (!isset($pFk[$name])) {
            $notes[] = "REVIEW: stock foreign key {$table}.{$name} is absent from the project schema.";
            $statements[] = sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', bt($table), bt($name));
        }
    }

    // Triggers the project added to a stock table.
    foreach ($projectTriggers[$table] ?? [] as $trigger) {
        $statements[] = dropTriggerSql($trigger);
        $statements[] = createTriggerSql($trigger, $table);
    }

    if ($statements !== []) {
        $statementsByTable[$table] = ['statements' => $statements, 'notes' => $notes];
        if (count($statements) > (in_array($table, $collationTables, true) ? 1 : 0)) {
            $driftedTables[] = $table;
        }
    }
}

$out .= emitComment(
    "── Shared-table drift ──────────────────────────────────────────────────\n"
    . count($collationTables) . " table(s) need the collation conversion; "
    . count($driftedTables) . " have structural drift beyond collation."
);
$out .= "\n";

foreach ($statementsByTable as $table => $work) {
    $out .= emitComment($table . ($work['notes'] === [] ? '' : "\n  " . implode("\n  ", $work['notes'])));
    foreach ($work['statements'] as $sql) {
        $out .= emitExecute($sql);
    }
    $out .= "\n";
}

$out .= "    }\n\n";

// ── down() ───────────────────────────────────────────────────────────────────

$out .= "    public function down(): void\n    {\n";
$out .= emitComment(
    "Reverts the project schema back to stock {$stockDb}.\n"
    . "GENERATED SKELETON — verify by hand. Reverting a baseline is destructive\n"
    . "of every ElanRegistry table, so this exists for completeness and local\n"
    . "rebuild loops, not for production rollback."
);
$out .= "\n";

foreach (array_reverse($newTables) as $table) {
    foreach ($projectTriggers[$table] ?? [] as $trigger) {
        $out .= emitExecute(dropTriggerSql($trigger));
    }
    $out .= emitExecute('DROP TABLE IF EXISTS ' . bt($table));
}
$out .= "\n";

foreach ($statementsByTable as $table => $work) {
    $stockCreate = showCreateTable($stock, $table);
    $sCols       = $stockColumns[$table] ?? [];
    $pCols       = $projectColumns[$table] ?? [];

    foreach ($projectTriggers[$table] ?? [] as $trigger) {
        $out .= emitExecute(dropTriggerSql($trigger));
    }

    foreach ($pCols as $name => $pCol) {
        if (!isset($sCols[$name])) {
            $out .= emitExecute(sprintf('ALTER TABLE %s DROP COLUMN %s', bt($table), bt($name)));
            continue;
        }
        if (structuralSignature($sCols[$name]) !== structuralSignature($pCol)) {
            $definition = columnDefinition($stockCreate, $name);
            if ($definition !== null) {
                $out .= emitExecute(sprintf(
                    'ALTER TABLE %s MODIFY COLUMN %s %s',
                    bt($table),
                    bt($name),
                    $definition
                ));
            }
        }
    }

    if (in_array($table, $collationTables, true)) {
        $out .= emitExecute(sprintf(
            "ALTER TABLE %s\n CONVERT TO CHARACTER SET %s COLLATE %s",
            bt($table),
            $stockTables[$table]['charset'],
            $stockTables[$table]['collation']
        ));
    }

    if ($stockTables[$table]['engine'] !== $projectTables[$table]['engine']) {
        $out .= emitExecute(sprintf('ALTER TABLE %s ENGINE=%s', bt($table), $stockTables[$table]['engine']));
    }
}

$out .= "    }\n}\n";

fwrite(STDOUT, $out);

fwrite(STDERR, sprintf(
    "\n[summary] new tables: %d | excluded: %s | shared tables: %d | collation conversions: %d | structurally drifted: %d\n",
    count($newTables),
    $excluded === [] ? '(none)' : implode(',', $excluded),
    count($sharedTables),
    count($collationTables),
    count($driftedTables)
));
fwrite(STDERR, "[summary] drifted tables: " . implode(', ', $driftedTables) . "\n");
