<?php

declare(strict_types=1);

/**
 * Fix Car Transfer Requests Column Types
 *
 * Administrative script to change requested_by_user_id and created_by columns
 * in the car_transfer_requests table from signed INT to INT UNSIGNED to match the
 * unsigned type used by the referenced users.id column.
 * Issue #1164: Fix column type mismatch in car_transfer_requests
 *
 * This script is idempotent — each column is pre-checked in information_schema
 * before any ALTER TABLE is issued. Re-running on an already-migrated database
 * skips both columns without issuing any DDL.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 2. Scripts are accessed via maintenance.php (Maintenance tab) or direct URL
 * 3. Use sequential numbering (01, 02, 03...) for proper execution order
 * 4. All scripts auto-log completion to fix_script_runs table
 * 5. See app/admin/scripts/fix/README.md for detailed instructions and best practices
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!isset($_GET['start'])): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-wrench"></i> Fix Car Transfer Requests Column Types
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Corrects the column types for <code>requested_by_user_id</code> and <code>created_by</code> in the <code>car_transfer_requests</code> table, changing them from <code>int(11)</code> to <code>INT UNSIGNED</code> to match the unsigned type used by the referenced <code>users.id</code> column.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Checks <code>requested_by_user_id</code> in <code>car_transfer_requests</code> — alters to <code>INT UNSIGNED NOT NULL</code> if not already correct</li>
                                    <li>Checks <code>created_by</code> in <code>car_transfer_requests</code> — alters to <code>INT UNSIGNED NOT NULL</code> if not already correct</li>
                                    <li>Verifies each alteration by re-querying <code>information_schema</code> after the <code>ALTER TABLE</code></li>
                                    <li>Records script completion in <code>fix_script_runs</code> only when all operations succeed</li>
                                </ul>
                            </div>

                            <div class="alert alert-success">
                                <h5><i class="fa fa-check-circle"></i> Idempotent — safe to re-run:</h5>
                                <ul class="mb-0">
                                    <li>Each column is pre-checked in <code>information_schema</code> before any <code>ALTER TABLE</code> is issued</li>
                                    <li>Columns already typed <code>int unsigned</code> are skipped with a warning — no destructive action is taken</li>
                                    <li>Safe to run on databases that were already migrated or on fresh installs that had the correct type from the start</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Column Type Fix
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php else:
                // Processing mode - simple text output
                ob_end_clean(); // Clear template buffering
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024); // Pad to force initial flush
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Fixing Car Transfer Requests Column Types
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * Writes a timestamped, icon-prefixed line to the output buffer and flushes for real-time streaming.
                     *
                     * @param string $message Message to display
                     * @param string $type    One of: 'info', 'success', 'error', 'warning', 'step'
                     * @return void
                     */
                    function logProgress(string $message, string $type = 'info'): void {
                        $icons = [
                            'info' => 'ℹ️',
                            'success' => '✅',
                            'error' => '❌',
                            'warning' => '⚠️',
                            'step' => '▶️'
                        ];
                        $icon = $icons[$type] ?? '•';
                        echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
                        flush();
                    }

                    // Initialize results tracking
                    $results = [
                        'altered' => 0,
                        'skipped' => 0,
                        'errors' => 0,
                    ];

                    /**
                     * Check and alter a single column in car_transfer_requests to INT UNSIGNED NOT NULL.
                     *
                     * Column names are hardcoded literal strings from the call-sites below — not user input.
                     * The information_schema lookup uses a prepared statement for the column name value.
                     *
                     * @param string $column Column name (must be a literal from the hardcoded list below)
                     * @param string $sql    Pre-built ALTER TABLE SQL (no variable interpolation in the query string)
                     */
                    $alterColumn = function (string $column, string $sql) use ($db, &$results): void {
                        $row = $db->query(
                            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_transfer_requests' AND COLUMN_NAME = ?",
                            [$column]
                        )->first();

                        if ($db->error()) {
                            logProgress("  {$column}: information_schema query failed — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }

                        if ($row && strtolower((string) $row->COLUMN_TYPE) === 'int unsigned') {
                            logProgress("  {$column}: already INT UNSIGNED — skipped", 'warning');
                            $results['skipped']++;
                            return;
                        }

                        $db->query($sql);
                        if ($db->error()) {
                            logProgress("  {$column}: ALTER FAILED — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }

                        // Verify by re-querying information_schema
                        $verify = $db->query(
                            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_transfer_requests' AND COLUMN_NAME = ?",
                            [$column]
                        )->first();

                        if ($db->error()) {
                            logProgress("  {$column}: verification query failed — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }

                        if ($verify && strtolower((string) $verify->COLUMN_TYPE) === 'int unsigned') {
                            logProgress("  {$column}: altered to INT UNSIGNED", 'success');
                            $results['altered']++;
                        } else {
                            if (!$verify) {
                                logProgress("  {$column}: verification failed — column not found in information_schema after ALTER", 'error');
                            } else {
                                logProgress("  {$column}: ALTER ran but type is still '{$verify->COLUMN_TYPE}' — expected 'int unsigned'", 'error');
                            }
                            $results['errors']++;
                        }
                    };

                    try {
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Fix requested_by_user_id column type', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $alterColumn(
                            'requested_by_user_id',
                            'ALTER TABLE car_transfer_requests MODIFY COLUMN requested_by_user_id INT UNSIGNED NOT NULL'
                        );

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Fix created_by column type', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $alterColumn(
                            'created_by',
                            'ALTER TABLE car_transfer_requests MODIFY COLUMN created_by INT UNSIGNED NOT NULL'
                        );

                        // Log script completion only when all operations succeeded
                        if ($results['errors'] === 0) {
                            $inserted = $db->insert('fix_script_runs', [
                                'script_name' => '11-Fix-Car-Transfer-Requests-Column-Types.php',
                                'completed_at' => date('Y-m-d H:i:s'),
                            ]);
                            if (!$inserted) {
                                logProgress('Failed to record script completion in fix_script_runs — ' . $db->errorString(), 'error');
                                $results['errors']++;
                            }
                            $userId = isset($user) && $user->isLoggedIn() ? (int) $user->data()->id : 0;
                            logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script completed — Altered: {$results['altered']}, Skipped: {$results['skipped']}");
                        } else {
                            $userId = isset($user) && $user->isLoggedIn() ? (int) $user->data()->id : 0;
                            logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script finished with errors — NOT recording completion. Altered: {$results['altered']}, Skipped: {$results['skipped']}, Errors: {$results['errors']}");
                        }

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('CAR TRANSFER REQUESTS COLUMN TYPE FIX COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Altered: {$results['altered']}", 'success');
                        if ($results['skipped'] > 0) {
                            logProgress("Skipped (already correct): {$results['skipped']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']} — see above for details", 'error');
                            logProgress('Script NOT marked complete. Rerun after manual remediation.', 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Run composer test:quick to confirm no regressions', 'info');

                    } catch (\Throwable $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        $userId = isset($user) && $user->isLoggedIn() ? (int) $user->data()->id : 0;
                        logger($userId, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
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
