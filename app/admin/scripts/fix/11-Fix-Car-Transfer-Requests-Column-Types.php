<?php

declare(strict_types=1);

/**
 * Fix Car Transfer Requests Column Types
 *
 * Administrative script to align requested_by_user_id and created_by columns
 * in the car_transfer_requests table with the type used by the referenced users.id column.
 * If users.id is INT UNSIGNED the columns are changed to INT UNSIGNED (dropping and
 * re-adding the FK constraints so MySQL accepts the type change). If users.id is signed
 * INT the columns are already compatible and the script completes as a no-op.
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
                                    <li>Checks <code>users.id</code> type to determine the required target type for FK columns</li>
                                    <li>Checks <code>requested_by_user_id</code> in <code>car_transfer_requests</code> — drops FK, alters to match <code>users.id</code> type, and re-adds FK if a change is needed</li>
                                    <li>Checks <code>created_by</code> in <code>car_transfer_requests</code> — same drop/alter/re-add approach</li>
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
                     * Foreign key definitions for the two columns being altered.
                     * Used to drop constraints before ALTER and re-add them after.
                     */
                    $foreignKeys = [
                        'requested_by_user_id' => 'fk_transfer_requested_by',
                        'created_by'           => 'fk_transfer_created_by',
                    ];

                    /**
                     * Drop a named FK constraint if it exists, alter a column in car_transfer_requests,
                     * then re-add the FK constraint. MySQL requires the FK to be absent during the ALTER
                     * when the column type changes signedness.
                     *
                     * Column names and FK names are hardcoded literals from the call-sites below — not user input.
                     *
                     * @param string $column        Column name
                     * @param string $fkName        Foreign key constraint name
                     * @param string $targetType    MySQL column type to set (e.g. 'INT UNSIGNED NOT NULL')
                     * @param string $targetTypeLow Lowercase form used for the idempotency check
                     */
                    $alterColumn = function (
                        string $column,
                        string $fkName,
                        string $targetType,
                        string $targetTypeLow
                    ) use ($db, &$results): void {
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

                        if ($row && strtolower((string) $row->COLUMN_TYPE) === $targetTypeLow) {
                            logProgress("  {$column}: already {$targetType} — skipped", 'warning');
                            $results['skipped']++;
                            return;
                        }

                        // Check whether the FK constraint currently exists before trying to drop it
                        $fkExists = $db->query(
                            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'car_transfer_requests'
                               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                            [$fkName]
                        )->first();

                        if ($db->error()) {
                            logProgress("  {$column}: FK existence check failed — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }

                        if ($fkExists) {
                            // DDL identifiers cannot be parameterized — $fkName is a hardcoded literal
                            $dropFkSql = "ALTER TABLE car_transfer_requests DROP FOREIGN KEY `{$fkName}`";
                            $db->query($dropFkSql);
                            if ($db->error()) {
                                logProgress("  {$column}: DROP FOREIGN KEY `{$fkName}` failed — " . $db->errorString(), 'error');
                                $results['errors']++;
                                return;
                            }
                            logProgress("  {$column}: dropped FK `{$fkName}`", 'info');
                        }

                        // DDL identifiers cannot be parameterized — $column and $targetType are hardcoded literals
                        $modifyColSql = "ALTER TABLE car_transfer_requests MODIFY COLUMN `{$column}` {$targetType}";
                        $db->query($modifyColSql);
                        if ($db->error()) {
                            logProgress("  {$column}: ALTER FAILED — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }

                        // Re-add the FK constraint (uses ON DELETE CASCADE to match the original DDL)
                        // DDL identifiers cannot be parameterized — $fkName and $column are hardcoded literals
                        $addFkSql = "ALTER TABLE car_transfer_requests"
                            . " ADD CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`)"
                            . " REFERENCES `users` (`id`) ON DELETE CASCADE";
                        $db->query($addFkSql);
                        if ($db->error()) {
                            logProgress("  {$column}: ALTER succeeded but re-adding FK `{$fkName}` failed — " . $db->errorString(), 'error');
                            $results['errors']++;
                            return;
                        }
                        logProgress("  {$column}: re-added FK `{$fkName}`", 'info');

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

                        if ($verify && strtolower((string) $verify->COLUMN_TYPE) === $targetTypeLow) {
                            logProgress("  {$column}: altered to {$targetType}", 'success');
                            $results['altered']++;
                        } else {
                            if (!$verify) {
                                logProgress("  {$column}: verification failed — column not found in information_schema after ALTER", 'error');
                            } else {
                                logProgress("  {$column}: ALTER ran but type is still '{$verify->COLUMN_TYPE}' — expected '{$targetTypeLow}'", 'error');
                            }
                            $results['errors']++;
                        }
                    };

                    try {
                        // Determine the type of users.id so we can set the correct target type.
                        // MySQL requires FK column types to match the referenced column exactly (signedness included).
                        $usersIdRow = $db->query(
                            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'id'"
                        )->first();

                        if ($db->error() || !$usersIdRow) {
                            logProgress('PREREQUISITE FAILED: Could not determine users.id type — ' . $db->errorString(), 'error');
                            $results['errors']++;
                            throw new \RuntimeException('Cannot proceed without users.id type');
                        }

                        $usersIdType = strtolower((string) $usersIdRow->COLUMN_TYPE);
                        logProgress("users.id type: {$usersIdType}", 'info');

                        // The FK columns must match users.id in signedness.
                        // If users.id is signed int, the columns are already the correct type and will be
                        // skipped by the idempotency check below. If users.id is int unsigned, we alter them.
                        $targetType    = ($usersIdType === 'int unsigned') ? 'INT UNSIGNED NOT NULL' : 'INT NOT NULL';
                        $targetTypeLow = ($usersIdType === 'int unsigned') ? 'int unsigned' : 'int';

                        if ($usersIdType !== 'int unsigned') {
                            logProgress(
                                "users.id is '{$usersIdType}' — FK columns must stay signed INT to remain compatible. "
                                . 'Columns already match; they will be reported as skipped.',
                                'warning'
                            );
                        }

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Fix requested_by_user_id column type', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $alterColumn(
                            'requested_by_user_id',
                            $foreignKeys['requested_by_user_id'],
                            $targetType,
                            $targetTypeLow
                        );

                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Fix created_by column type', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $alterColumn(
                            'created_by',
                            $foreignKeys['created_by'],
                            $targetType,
                            $targetTypeLow
                        );

                        // Log script completion when all operations succeeded (altered or correctly skipped)
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
