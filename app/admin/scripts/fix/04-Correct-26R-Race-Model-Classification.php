<?php

declare(strict_types=1);

/**
 * Correct 26R Race Model Classification
 *
 * One-time data migration that removes the misclassified `26R|Race|26` row
 * from the `car_models` reference table and migrates any `cars` rows that
 * reference it to the correct `S2|Race|26R` classification.
 * Issue #849: fix: correct misclassified 26R Race entry in car_models and
 * update statistics map filter
 *
 * DEPLOYMENT INSTRUCTIONS:
 * Run once via Admin → Maintenance after deploying v2.24.0.
 */

define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

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
            <!-- Initial description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-database"></i> Correct 26R Race Model Classification
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">
                                Fixes the misclassified <code>26R|Race|26</code> row in the
                                <code>car_models</code> reference table (Issue #849). The S2 Race entry
                                (1964–1966) already covers 1965 correctly.
                            </p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates a backup snapshot of <code>cars</code> and <code>car_models</code> via BackupManager.</li>
                                    <li>Migrates any <code>cars</code> row with <code>model='26R|Race|26'</code> to <code>series='S2', variant='Race', type='26R', model='S2|Race|26R'</code>.</li>
                                    <li>Deletes the <code>26R|Race|26</code> row from <code>car_models</code>.</li>
                                    <li>Records the run in <code>fix_script_runs</code>.</li>
                                    <li>Idempotent: subsequent runs report zero changes and exit cleanly.</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>The <code>cars_update</code> trigger captures the old values to <code>cars_hist</code> — audit trail is preserved.</li>
                                    <li>Reference seed data (<code>database/2-reference-data.sql</code>) is already corrected; this script removes the row from existing deployed databases.</li>
                                    <li>Run once per deployed environment (test, production) after v2.24.0 deploy.</li>
                                </ul>
                            </div>

                            <form method="POST" action="">
                                <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                                <input type="hidden" name="start" value="1">
                                <div class="text-center">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue — Start Migration
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
                        <i class="fa fa-cogs"></i> Running Migration
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * Emit a timestamped, typed progress line and flush output.
                     *
                     * @param string $message Message to display
                     * @param string $type    One of: info|success|error|warning|step
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
                        echo date('[H:i:s] ') . $icon . ' ' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n";
                        flush();
                    }

                    $results = [
                        'processed' => 0,
                        'errors'    => 0,
                        'warnings'  => 0,
                    ];

                    $backupPath = null;

                    // STEP 1: Backup
                    logProgress(SECTION_SEPARATOR, 'step');
                    logProgress('STEP 1: Create backup of cars and car_models', 'step');
                    logProgress(SECTION_SEPARATOR, 'step');

                    try {
                        $backupPath = $backupManager->createManualBackup(
                            '26R Race model classification fix — issue #849',
                            ['cars', 'car_models']
                        );
                        logProgress('Backup created: ' . basename($backupPath), 'success');
                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '04 correct-26r: backup created at ' . $backupPath
                        );
                    } catch (BackupException $e) {
                        $results['errors']++;
                        logProgress('FATAL: Backup failed — aborting migration. No data was modified.', 'error');
                        logProgress('Error: ' . $e->getMessage(), 'error');
                        logger(
                            $user->data()->id,
                            LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            '04 correct-26r: backup failed, migration aborted — ' . $e->getMessage()
                        );
                    }

                    if ($backupPath !== null) {
                        try {
                            // STEP 2: Preflight counts
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 2: Preflight — count rows to migrate', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $carsToFix = (int) $db->query(
                                "SELECT COUNT(*) AS c FROM cars WHERE model = ?",
                                ['26R|Race|26']
                            )->first()->c;

                            $modelRowExists = (int) $db->query(
                                "SELECT COUNT(*) AS c FROM car_models WHERE model_value = ?",
                                ['26R|Race|26']
                            )->first()->c;

                            logProgress("cars rows with model='26R|Race|26': {$carsToFix}", 'info');
                            logProgress("car_models rows with model_value='26R|Race|26': {$modelRowExists}", 'info');

                            // STEP 3: Apply migration in a transaction
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 3: Apply migration', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $db->query('START TRANSACTION');
                            try {
                                if ($carsToFix > 0) {
                                    $db->query(
                                        "UPDATE cars
                                            SET series = 'S2', variant = 'Race', type = '26R', model = 'S2|Race|26R'
                                          WHERE model = '26R|Race|26'"
                                    );
                                    if ($db->error()) {
                                        throw new \RuntimeException('cars UPDATE failed: ' . $db->errorString());
                                    }
                                    logProgress("Updated {$carsToFix} cars row(s)", 'success');
                                } else {
                                    logProgress('No cars rows to update', 'info');
                                }

                                if ($modelRowExists > 0) {
                                    $db->query("DELETE FROM car_models WHERE model_value = '26R|Race|26'");
                                    if ($db->error()) {
                                        throw new \RuntimeException('car_models DELETE failed: ' . $db->errorString());
                                    }
                                    logProgress('Deleted bad car_models reference row', 'success');
                                } else {
                                    logProgress('No car_models row to delete', 'info');
                                }

                                $db->query('COMMIT');
                            } catch (\Throwable $txError) {
                                $db->query('ROLLBACK');
                                logProgress('ROLLBACK: migration failed — restore from backup if data inconsistent.', 'error');
                                throw $txError;
                            }

                            $results['processed'] += $carsToFix + $modelRowExists;

                            // STEP 4: Record completion
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 4: Record completion', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $inserted = $db->insert('fix_script_runs', [
                                'script_name'  => '04-Correct-26R-Race-Model-Classification.php',
                                'completed_at' => date('Y-m-d H:i:s'),
                            ]);

                            if (!$inserted) {
                                $results['warnings']++;
                                logProgress('WARNING: Could not record completion in fix_script_runs', 'warning');
                                logger(
                                    $user->data()->id,
                                    LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                    '04 correct-26r: fix_script_runs insert failed — completion not recorded'
                                );
                            }

                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script #849 completed - cars updated: {$carsToFix}, car_models deleted: {$modelRowExists}"
                            );

                            logProgress('Completion recorded in fix_script_runs', 'success');

                            // Summary
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('POST-RUN REPORT', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress("Cars rows migrated: {$carsToFix}", $carsToFix > 0 ? 'success' : 'info');
                            logProgress("car_models rows deleted: {$modelRowExists}", $modelRowExists > 0 ? 'success' : 'info');
                            logProgress('Backup file: ' . basename($backupPath), 'info');
                            logProgress('', 'info');
                            logProgress('Post-Processing Steps:', 'info');
                            logProgress('  • Verify chassis 26 R14 (or any pre-existing 26R|Race|26 car) now displays as S2 Race.', 'info');
                            logProgress('  • Reload statistics.php and confirm the 26R map filter shows Race cars.', 'info');
                            logProgress('  • Confirm cars_hist contains an audit row for the corrected car.', 'info');
                            logProgress('', 'info');
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('Migration complete.', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            if ($results['warnings'] > 0) {
                                logProgress("Warnings: {$results['warnings']}", 'warning');
                            }

                        } catch (\Throwable $e) {
                            $results['errors']++;
                            logProgress('FATAL ERROR (' . get_class($e) . '): ' . $e->getMessage(), 'error');
                            logger(
                                $user->data()->id,
                                LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                '04 correct-26r: fatal error (' . get_class($e) . ') — ' . $e->getMessage()
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
