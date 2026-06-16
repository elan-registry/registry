<?php

declare(strict_types=1);

/**
 * Decode All HTML-Encoded Fields
 *
 * One-time data migration that iteratively decodes all HTML-encoded text fields
 * in the cars, users, and profiles tables, then re-syncs the denormalised owner
 * name/location columns in cars.
 * Issue #847: migration: single script to decode all HTML-encoded text fields
 *
 * PREREQUISITES: Deploy all v2.23.0 code fixes (#840, #841, #842) before running.
 * ROLLBACK: Restore from BackupManager snapshot created at script start.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * Run once, immediately after deploying v2.23.0, via Admin → Maintenance tab.
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');
define('HTML_ENTITY_REGEXP', '&(amp|lt|gt|quot|#039|apos);');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

use ElanRegistry\Exceptions\BackupException;

if (!securePage($php_self)) {
    die();
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . BACKUP_BASE_DIR, (int) $user->data()->id);

const CARS_COLUMNS     = ['comments', 'color', 'engine', 'website', 'chassis'];
const USERS_COLUMNS    = ['fname', 'lname'];
const PROFILES_COLUMNS = ['city', 'state', 'country', 'website'];

// Table → [pk column, decode columns] — drives both pre-flight counts and decode steps.
$tableDecodeMap = [
    'cars'     => ['pk' => 'id',      'cols' => CARS_COLUMNS],
    'users'    => ['pk' => 'id',      'cols' => USERS_COLUMNS],
    'profiles' => ['pk' => 'user_id', 'cols' => PROFILES_COLUMNS],
];

/**
 * Iteratively decode HTML entities until the value stabilises or max passes reached.
 */
function iterativeDecode(string $value, int $maxPasses = 5): string
{
    $prev   = null;
    $passes = 0;
    while ($prev !== $value && $passes < $maxPasses) {
        $prev  = $value;
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $passes++;
    }
    return $value;
}

/**
 * Returns true if the string still contains recognisable HTML entities.
 */
function hasHtmlEntities(string $value): bool
{
    return (bool) preg_match('/' . HTML_ENTITY_REGEXP . '/i', $value);
}

/**
 * Count rows in $table where $column contains HTML entities.
 */
function countColumnAffected(object $db, string $table, string $column): int
{
    static $allowedTables  = ['cars', 'users', 'profiles'];
    static $allowedColumns = null;
    if ($allowedColumns === null) {
        $allowedColumns = array_merge(CARS_COLUMNS, USERS_COLUMNS, PROFILES_COLUMNS);
    }
    if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
        throw new \InvalidArgumentException("Disallowed table or column: {$table}.{$column}");
    }
    $sql    = "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$column}` REGEXP ?";
    $result = $db->query($sql, [HTML_ENTITY_REGEXP]);
    if ($db->error()) {
        throw new \RuntimeException("DB error counting affected rows in {$table}.{$column}: " . $db->errorString());
    }
    return (int) ($result->first()->cnt ?? 0);
}

/**
 * Emit a timestamped, typed progress line and flush output.
 */
function logProgress(string $message, string $type = 'info'): void
{
    $icons = [
        'info'    => 'ℹ️',
        'success' => '✅',
        'error'   => '❌',
        'warning' => '⚠️',
        'step'    => '▶️',
    ];
    $icon = $icons[$type] ?? '•';
    echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
    flush();
}

/**
 * Decode HTML entities in all listed columns for every row in $table.
 *
 * Only rows with at least one changed column are updated. Updates $changeCounts
 * and $unstableValues in place. Returns the number of rows written.
 *
 * @param object   $db             DB instance
 * @param string   $table          Table name
 * @param string   $idColumn       Primary key column
 * @param string[] $columns        Columns to decode
 * @param array    $changeCounts   Accumulated per-column change counts (modified in place)
 * @param string[] $unstableValues Identifiers of values still containing entities after 5 passes (modified in place)
 */
function decodeTable(object $db, string $table, string $idColumn, array $columns, array &$changeCounts, array &$unstableValues): int
{
    static $allowedTables  = ['cars', 'users', 'profiles'];
    static $allowedIdCols  = ['id', 'user_id'];
    static $allowedColumns = null;
    if ($allowedColumns === null) {
        $allowedColumns = array_merge(CARS_COLUMNS, USERS_COLUMNS, PROFILES_COLUMNS);
    }
    if (!in_array($table, $allowedTables, true) || !in_array($idColumn, $allowedIdCols, true)) {
        throw new \InvalidArgumentException("Disallowed table or id column: {$table}.{$idColumn}");
    }
    foreach ($columns as $col) {
        if (!in_array($col, $allowedColumns, true)) {
            throw new \InvalidArgumentException("Disallowed column: {$col}");
        }
    }

    $selectResult = $db->query('SELECT `' . $idColumn . '`, ' . implode(', ', $columns) . ' FROM `' . $table . '`');
    if ($db->error()) {
        throw new \RuntimeException("DB error reading {$table}: " . $db->errorString());
    }
    $rows    = $selectResult->results();
    $changed = 0;

    foreach ($rows as $row) {
        $updates = [];
        $params  = [];

        foreach ($columns as $col) {
            $original = (string) ($row->$col ?? '');
            if ($original === '') {
                continue;
            }
            $decoded = iterativeDecode($original);
            if ($decoded === $original) {
                continue;
            }
            $updates[] = "`{$col}` = ?";
            $params[]  = $decoded;
            $changeCounts["{$table}.{$col}"] = ($changeCounts["{$table}.{$col}"] ?? 0) + 1;
            if (hasHtmlEntities($decoded)) {
                $unstableValues[] = "{$table} {$idColumn}={$row->$idColumn} col={$col}";
            }
        }

        if (!empty($updates)) {
            $params[] = $row->$idColumn;
            $db->query(
                'UPDATE `' . $table . '` SET ' . implode(', ', $updates) . ' WHERE `' . $idColumn . '` = ?',
                $params
            );
            if ($db->error()) {
                throw new \RuntimeException(
                    "DB error updating {$table} {$idColumn}={$row->$idColumn}: " . $db->errorString()
                );
            }
            $changed++;
        }
    }

    return $changed;
}

$isProcessing = ($method === 'POST' && isset($_POST['start']));

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if ($isProcessing && !Token::check($_POST['csrf'] ?? '')): ?>
            <!-- CSRF token mismatch -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="alert alert-danger">
                        <h5><i class="fa fa-exclamation-circle"></i> Security Token Error</h5>
                        <p>The request token was invalid or expired. Please return to the script and try again.</p>
                        <a href="<?php echo htmlspecialchars($php_self); ?>" class="btn btn-primary">
                            <i class="fa fa-arrow-left"></i> Return to Script
                        </a>
                    </div>
                </div>
            </div>

            <?php elseif (!$isProcessing): ?>
            <!-- Pre-flight: description and affected row counts -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-code"></i> Decode HTML-Encoded Fields
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                One-time data migration (v2.23.0) that decodes all HTML-encoded text fields
                                in <code>cars</code>, <code>users</code>, and <code>profiles</code>, then
                                re-syncs the denormalised owner name/location columns in <code>cars</code>.
                            </p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Backs up <code>cars</code>, <code>users</code>, and <code>profiles</code> before any writes.</li>
                                    <li>Iteratively decodes <code>cars</code>: <code>comments</code>, <code>color</code>, <code>engine</code>, <code>website</code>, <code>chassis</code>.</li>
                                    <li>Iteratively decodes <code>users</code>: <code>fname</code>, <code>lname</code>.</li>
                                    <li>Iteratively decodes <code>profiles</code>: <code>city</code>, <code>state</code>, <code>country</code>, <code>website</code>.</li>
                                    <li>Re-syncs denormalised <code>cars.fname/lname/city/state/country</code> from the decoded source rows.</li>
                                    <li>Reports any values that did not stabilise within 5 decode passes.</li>
                                </ul>
                            </div>

                            <?php
                            $preflightCounts = [];
                            $totalAffected   = 0;

                            foreach ($tableDecodeMap as $table => $spec) {
                                foreach ($spec['cols'] as $col) {
                                    $n = countColumnAffected($db, $table, $col);
                                    $preflightCounts[$table][$col] = $n;
                                    $totalAffected += $n;
                                }
                            }
                            ?>

                            <div class="alert alert-<?php echo $totalAffected > 0 ? 'warning' : 'success'; ?>">
                                <h5>
                                    <i class="fa fa-<?php echo $totalAffected > 0 ? 'exclamation-triangle' : 'check-circle'; ?>"></i>
                                    Pre-flight: Affected Rows
                                </h5>
                                <?php if ($totalAffected === 0): ?>
                                    <p class="mb-0">
                                        All fields are already clean — no HTML entities found.
                                        Running the script again will report zero rows changed.
                                    </p>
                                <?php else: ?>
                                    <table class="table table-sm table-bordered mb-0 mt-2" style="max-width: 400px;">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Table / Column</th>
                                                <th class="text-right">Rows with Entities</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($preflightCounts as $table => $cols): ?>
                                                <?php foreach ($cols as $col => $cnt): ?>
                                                    <?php if ($cnt > 0): ?>
                                                    <tr>
                                                        <td><code><?php echo htmlspecialchars("{$table}.{$col}"); ?></code></td>
                                                        <td class="text-right"><?php echo $cnt; ?></td>
                                                    </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            <?php endforeach; ?>
                                            <tr class="font-weight-bold">
                                                <td>Total column-rows affected</td>
                                                <td class="text-right"><?php echo $totalAffected; ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Run <strong>once</strong>, immediately after deploying the v2.23.0 code changes (#840, #841, #842).</li>
                                    <li>A backup of all three tables is created automatically before any writes.</li>
                                    <li>The script is idempotent — a second run will report zero rows changed.</li>
                                    <li><code>cars_hist</code> audit history is intentionally left unchanged.</li>
                                </ul>
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                <input type="hidden" name="start" value="1">
                                <div class="text-center">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue — Start Decode Migration
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                // CSRF validated — begin migration output
                ob_end_clean();
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024);
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Decoding HTML-Encoded Fields
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    $results = [
                        'processed' => 0,
                        'errors'    => 0,
                        'warnings'  => 0,
                    ];

                    $changeCounts   = [];
                    $unstableValues = [];
                    $backupPath     = null;

                    logProgress(SECTION_SEPARATOR, 'step');
                    logProgress('STEP 1: Create backup of cars, users, and profiles', 'step');
                    logProgress(SECTION_SEPARATOR, 'step');

                    try {
                        $backupPath = $backupManager->createManualBackup(
                            'HTML entity decode migration — issue #847',
                            ['cars', 'users', 'profiles']
                        );
                        logProgress('Backup created: ' . basename($backupPath), 'success');
                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '03 decode: backup created at ' . $backupPath
                        );
                    } catch (BackupException $e) {
                        $results['errors']++;
                        logProgress('FATAL: Backup failed — aborting migration. No data was modified.', 'error');
                        logProgress('Error: ' . $e->getMessage(), 'error');
                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '03 decode: backup failed, migration aborted — ' . $e->getMessage()
                        );
                        $backupPath = null;
                    }

                    if ($backupPath !== null) {
                        try {
                            $stepNum = 2;
                            foreach ($tableDecodeMap as $table => $spec) {
                                logProgress('', 'info');
                                logProgress(SECTION_SEPARATOR, 'step');
                                logProgress("STEP {$stepNum}: Decode {$table} table (" . implode(', ', $spec['cols']) . ')', 'step');
                                logProgress(SECTION_SEPARATOR, 'step');

                                $db->query('START TRANSACTION');
                                try {
                                    $changed = decodeTable($db, $table, $spec['pk'], $spec['cols'], $changeCounts, $unstableValues);
                                    $db->query('COMMIT');
                                } catch (\Throwable $tableError) {
                                    $db->query('ROLLBACK');
                                    logProgress("ROLLBACK: {$table} decode failed — restore from backup if data inconsistent.", 'error');
                                    throw $tableError;
                                }
                                logProgress("{$table}: {$changed} row(s) updated", $changed > 0 ? 'success' : 'info');
                                $results['processed'] += $changed;
                                $stepNum++;
                            }

                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress("STEP {$stepNum}: Re-sync denormalised cars.fname/lname/city/state/country", 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            // COLLATE clause resolves utf8mb4_unicode_ci vs utf8mb4_general_ci
                            // mismatch when cars/users/profiles have different default collations.
                            $resyncWhere = 'c.user_id IS NOT NULL
                                AND NOT (
                                    c.fname   COLLATE utf8mb4_unicode_ci <=> u.fname   COLLATE utf8mb4_unicode_ci
                                    AND c.lname   COLLATE utf8mb4_unicode_ci <=> u.lname   COLLATE utf8mb4_unicode_ci
                                    AND c.city    COLLATE utf8mb4_unicode_ci <=> p.city    COLLATE utf8mb4_unicode_ci
                                    AND c.state   COLLATE utf8mb4_unicode_ci <=> p.state   COLLATE utf8mb4_unicode_ci
                                    AND c.country COLLATE utf8mb4_unicode_ci <=> p.country COLLATE utf8mb4_unicode_ci
                                )';

                            $resyncSelectSql = 'SELECT COUNT(*) AS cnt
                                FROM cars c
                                INNER JOIN users u    ON c.user_id = u.id
                                INNER JOIN profiles p ON p.user_id = u.id
                                WHERE ' . $resyncWhere;
                            $resyncResult = $db->query($resyncSelectSql);
                            if ($db->error()) {
                                throw new \RuntimeException('DB error counting rows to re-sync: ' . $db->errorString());
                            }
                            $resyncCount = (int) $resyncResult->first()->cnt;

                            $resyncUpdateSql = 'UPDATE cars c
                                INNER JOIN users u    ON c.user_id = u.id
                                INNER JOIN profiles p ON p.user_id = u.id
                                SET c.fname   = u.fname,
                                    c.lname   = u.lname,
                                    c.city    = p.city,
                                    c.state   = p.state,
                                    c.country = p.country
                                WHERE ' . $resyncWhere;
                            $db->query('START TRANSACTION');
                            try {
                                $db->query($resyncUpdateSql);
                                if ($db->error()) {
                                    throw new \RuntimeException('DB error re-syncing cars denormalised columns: ' . $db->errorString());
                                }
                                $db->query('COMMIT');
                            } catch (\Throwable $resyncError) {
                                $db->query('ROLLBACK');
                                logProgress('ROLLBACK: cars re-sync failed — restore from backup if data inconsistent.', 'error');
                                throw $resyncError;
                            }

                            logProgress("Re-synced {$resyncCount} cars row(s)", $resyncCount > 0 ? 'success' : 'info');
                            $results['processed'] += $resyncCount;

                            $summaryData = array_merge($changeCounts, ['cars_resynced' => $resyncCount]);

                            $inserted = $db->insert('fix_script_runs', [
                                'script_name'  => '03-Decode-All-HTML-Encoded-Fields.php',
                                'completed_at' => date('Y-m-d H:i:s'),
                            ]);

                            if (!$inserted) {
                                $results['warnings']++;
                                logProgress('WARNING: Could not record completion in fix_script_runs', 'warning');
                                logger(
                                    $user->data()->id,
                                    LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                    '03 decode: fix_script_runs insert failed — completion not recorded'
                                );
                            }

                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                '03 decode: completed — ' . json_encode($summaryData)
                            );

                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('POST-RUN REPORT', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            if (!empty($changeCounts)) {
                                logProgress('Rows changed per column:', 'info');
                                foreach ($changeCounts as $key => $n) {
                                    logProgress("  {$key}: {$n}", 'success');
                                }
                            } else {
                                logProgress('No column data changed — fields were already clean.', 'success');
                            }

                            logProgress("Cars denormalised rows re-synced: {$resyncCount}", $resyncCount > 0 ? 'success' : 'info');
                            logProgress('Backup file: ' . basename($backupPath), 'info');

                            if (!empty($unstableValues)) {
                                logProgress('', 'info');
                                logProgress('VALUES NOT FULLY STABLE AFTER 5 PASSES — MANUAL REVIEW NEEDED:', 'warning');
                                foreach ($unstableValues as $item) {
                                    logProgress("  {$item}", 'warning');
                                }
                                $results['warnings'] += count($unstableValues);
                            }

                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('Migration complete.', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress("Total rows updated: {$results['processed']}", 'success');

                            if ($results['warnings'] > 0) {
                                logProgress("Warnings: {$results['warnings']}", 'warning');
                            }

                        } catch (\Throwable $e) {
                            $results['errors']++;
                            logProgress('FATAL ERROR (' . get_class($e) . '): ' . $e->getMessage(), 'error');
                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                '03 decode: fatal error (' . get_class($e) . ') — ' . $e->getMessage()
                            );
                        }
                    }

                    if ($results['errors'] > 0) {
                        logProgress("Errors: {$results['errors']}", 'error');
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../manage-maintenance.php?tab=maintenance" class="btn btn-primary btn-lg">
                            <i class="fa fa-arrow-left"></i> Return to Maintenance
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
