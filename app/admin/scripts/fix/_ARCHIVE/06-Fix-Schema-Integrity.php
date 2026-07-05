<?php

declare(strict_types=1);

/**
 * Schema Integrity Fix Script
 *
 * Promotes UNIQUE KEY to PRIMARY KEY on cars, cars_hist, and car_user_hist,
 * and adds missing indexes on audit-trail lookup columns.
 * Issue #992: fix: schema integrity — promote UNIQUE KEY to PRIMARY KEY and add missing audit table indexes
 *
 * Changes:
 * - cars, cars_hist, car_user_hist: UNIQUE KEY id → PRIMARY KEY
 * - cars_hist: add idx_cars_hist_user_id (user_id queried in ElanRegistryOwner.php:309)
 * - car_user_hist: ensure idx_car_user_hist_car_id and idx_car_user_hist_userid exist
 *
 * Safe to run multiple times — each step checks before altering.
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [{$errno}]: {$errstr} in {$errfile}:{$errline}");
    }
    return true;
});

$db = DB::getInstance();
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . BACKUP_BASE_DIR, (int)$user->data()->id);

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!isset($_GET['start'])): ?>
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-database"></i> Fix Schema Integrity — Primary Keys &amp; Indexes
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Fixes schema integrity issues on three tables and ensures audit-trail lookup indexes exist.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Promotes <code>UNIQUE KEY id</code> to <code>PRIMARY KEY</code> on <code>cars</code>, <code>cars_hist</code>, and <code>car_user_hist</code></li>
                                    <li>Adds <code>idx_cars_hist_user_id</code> index on <code>cars_hist.user_id</code> (used in audit lookups)</li>
                                    <li>Ensures <code>idx_car_user_hist_car_id</code> and <code>idx_car_user_hist_userid</code> exist on <code>car_user_hist</code></li>
                                    <li>Each step is idempotent — already-correct state is detected and skipped</li>
                                    <li>Creates a database backup before making any changes</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>InnoDB silently uses the first NOT NULL UNIQUE KEY as the clustered index, so existing queries are unaffected</li>
                                    <li>No data is modified — only index/key metadata changes</li>
                                    <li>Brief table lock during each ALTER TABLE (minimal impact on small tables)</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue — Start Schema Fix
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                ob_end_clean();
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024);
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Schema Integrity Fix — Processing
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * @param string $message
                     * @param string $type
                     */
                    function logProgress(string $message, string $type = 'info'): void
                    {
                        $icons = [
                            'info'    => 'ℹ️',
                            'success' => '✅',
                            'error'   => '❌',
                            'warning' => '⚠️',
                            'step'    => '▶️',
                            'skip'    => '⏭️',
                        ];
                        $icon = $icons[$type] ?? '•';
                        echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
                        flush();
                    }

                    /**
                     * Returns true if the table already has a PRIMARY KEY.
                     *
                     * @param DB $db
                     * @param string $table
                     * @return bool
                     */
                    function hasPrimaryKey(DB $db, string $table): bool
                    {
                        $db->query(
                            "SELECT COUNT(*) AS cnt
                             FROM information_schema.TABLE_CONSTRAINTS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = ?
                               AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
                            [$table]
                        );
                        return (int)($db->first()->cnt ?? 0) > 0;
                    }

                    /**
                     * Returns the name of the unique (non-primary) index on the `id` column,
                     * or null if none exists.
                     *
                     * @param DB $db
                     * @param string $table
                     * @return string|null
                     */
                    function getUniqueKeyOnId(DB $db, string $table): ?string
                    {
                        $db->query(
                            "SELECT INDEX_NAME
                             FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = ?
                               AND COLUMN_NAME = 'id'
                               AND NON_UNIQUE = 0
                               AND INDEX_NAME != 'PRIMARY'
                             LIMIT 1",
                            [$table]
                        );
                        $row = $db->first();
                        return $row ? $row->INDEX_NAME : null;
                    }

                    /**
                     * Returns true if the named index exists on the table.
                     *
                     * @param DB $db
                     * @param string $table
                     * @param string $indexName
                     * @return bool
                     */
                    function hasIndex(DB $db, string $indexName, string $table): bool
                    {
                        $db->query(
                            "SELECT COUNT(*) AS cnt
                             FROM information_schema.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = ?
                               AND INDEX_NAME = ?",
                            [$table, $indexName]
                        );
                        return (int)($db->first()->cnt ?? 0) > 0;
                    }

                    $results = ['altered' => 0, 'skipped' => 0, 'errors' => 0];

                    try {
                        // STEP 1: Backup
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Create backup', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $backupPath = $backupManager->createSchemaBackup(
                            '06-Fix-Schema-Integrity',
                            ['cars', 'cars_hist', 'car_user_hist']
                        );
                        logProgress('Backup created: ' . basename($backupPath), 'success');
                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Schema backup created: {$backupPath}"
                        );

                        // STEP 2: Promote UNIQUE KEY → PRIMARY KEY
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Promote UNIQUE KEY id to PRIMARY KEY', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        foreach (['cars', 'cars_hist', 'car_user_hist'] as $table) {
                            if (hasPrimaryKey($db, $table)) {
                                logProgress("{$table}: PRIMARY KEY already exists — skipping", 'skip');
                                $results['skipped']++;
                                continue;
                            }

                            $keyName = getUniqueKeyOnId($db, $table);
                            if ($keyName === null) {
                                logProgress("{$table}: no UNIQUE KEY on id column found — skipping", 'warning');
                                $results['skipped']++;
                                continue;
                            }

                            // DDL cannot use parameterized queries for identifier names.
                            // $table is from a hardcoded PHP array; $keyName is validated below.
                            if (!preg_match('/^[a-zA-Z0-9_]+$/', $keyName)) {
                                logProgress("{$table}: unexpected key name '{$keyName}' — skipping for safety", 'error');
                                $results['errors']++;
                                continue;
                            }
                            $sql = "ALTER TABLE `{$table}` DROP KEY `{$keyName}`, ADD PRIMARY KEY (`id`)";
                            if (!$db->query($sql)) {
                                logProgress("{$table}: FAILED — " . $db->errorString(), 'error');
                                $results['errors']++;
                                continue;
                            }
                            logProgress("{$table}: dropped UNIQUE KEY `{$keyName}`, added PRIMARY KEY", 'success');
                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "{$table}: promoted UNIQUE KEY '{$keyName}' to PRIMARY KEY"
                            );
                            $results['altered']++;
                        }

                        // STEP 3: Audit-trail lookup indexes
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Add missing audit-trail lookup indexes', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $indexWork = [
                            ['table' => 'cars_hist',     'index' => 'idx_cars_hist_user_id',        'column' => 'user_id'],
                            ['table' => 'car_user_hist', 'index' => 'idx_car_user_hist_car_id',     'column' => 'car_id'],
                            ['table' => 'car_user_hist', 'index' => 'idx_car_user_hist_userid',     'column' => 'userid'],
                        ];

                        foreach ($indexWork as $item) {
                            if (hasIndex($db, $item['index'], $item['table'])) {
                                logProgress("{$item['table']}: `{$item['index']}` already exists — skipping", 'skip');
                                $results['skipped']++;
                                continue;
                            }

                            // DDL cannot use parameterized queries for identifier names.
                            // All values in $indexWork are hardcoded PHP literals.
                            $sql = "ALTER TABLE `{$item['table']}` ADD KEY `{$item['index']}` (`{$item['column']}`)";
                            if (!$db->query($sql)) {
                                logProgress("{$item['table']}: FAILED — " . $db->errorString(), 'error');
                                $results['errors']++;
                                continue;
                            }
                            logProgress("{$item['table']}: added index `{$item['index']}` on `{$item['column']}`", 'success');
                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "{$item['table']}: added index '{$item['index']}' on '{$item['column']}'"
                            );
                            $results['altered']++;
                        }

                        // Log completion — only mark as done when no errors occurred
                        if ($results['errors'] === 0) {
                            $inserted = $db->insert('fix_script_runs', [
                                'script_name' => '06-Fix-Schema-Integrity.php',
                                'completed_at' => date('Y-m-d H:i:s'),
                            ]);
                            if (!$inserted) {
                                logProgress('Warning: could not record completion in fix_script_runs — ' . $db->errorString(), 'warning');
                            }
                        }

                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "06-Fix-Schema-Integrity completed — altered: {$results['altered']}, skipped: {$results['skipped']}, errors: {$results['errors']}"
                        );

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('SCHEMA INTEGRITY FIX COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Tables/indexes altered: {$results['altered']}", 'success');
                        logProgress("Already correct (skipped): {$results['skipped']}", 'info');
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Verify with SHOW CREATE TABLE cars;', 'info');
                        logProgress('  • Verify with SHOW CREATE TABLE cars_hist;', 'info');
                        logProgress('  • Verify with SHOW CREATE TABLE car_user_hist;', 'info');

                    } catch (\Throwable $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="../../maintenance.php?tab=maintenance" class="btn btn-primary btn-lg">
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
