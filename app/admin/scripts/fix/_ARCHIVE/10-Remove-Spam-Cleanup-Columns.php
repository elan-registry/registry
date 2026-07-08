<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;

/**
 * Remove Spam Cleanup Columns Script
 *
 * Administrative script to remove elan_spam_* columns from the settings table
 * and delete the spam_inactive_cleanup cron row.
 * Issue #623: Dead-code sweep — spam/inactive-user cleanup system removal
 *
 * This script is safe to run on both fresh installs and existing databases.
 * Each column is pre-checked in information_schema before a plain DROP COLUMN is issued,
 * so the script is a no-op on databases where the columns were never added.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to app/admin/scripts/fix/ (one-time migrations) or app/admin/scripts/maintenance/ (repeatable tasks) with proper naming: ##-Descriptive-Name.php
 * 2. Replace all [PLACEHOLDERS] with appropriate content
 * 3. Scripts are accessed via maintenance.php (Maintenance tab) or direct URL
 * 4. Use sequential numbering (01, 02, 03...) for proper execution order
 * 5. All scripts auto-log completion to fix_script_runs table
 * 6. See app/admin/scripts/fix/README.md for detailed instructions and best practices
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
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
                                <i class="fa fa-trash"></i> Remove Spam Cleanup Columns
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Removes the deprecated spam/inactive-user cleanup system database artifacts from the settings table and crons table.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Drops <code>elan_spam_cleanup_enabled</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_cleanup_dry_run</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_inactive_days</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_grace_period_days</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_max_deletions</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_max_percentage</code> column from settings (if exists)</li>
                                    <li>Drops <code>elan_spam_email_notifications</code> column from settings (if exists)</li>
                                    <li>Deletes the <code>spam_inactive_cleanup.php</code> row from the crons table (if present)</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>All DROP operations pre-check information_schema before executing — safe to run on databases that never had these columns</li>
                                    <li>This is a one-way operation; back up the database before running if the settings values may be needed</li>
                                    <li>The cron script itself must already be deleted from <code>users/cron/</code></li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Column Removal
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
                        <i class="fa fa-cogs"></i> Removing Spam Cleanup Columns
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    /**
                     * Helper function for progress output
                     *
                     * @param string $message Message to display
                     * @param string $type Type of message: 'info', 'success', 'error', 'warning', 'step'
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
                        'processed' => 0,
                        'errors' => 0,
                        'warnings' => 0
                    ];

                    /**
                     * A single ALTER TABLE with multiple DROP COLUMN IF EXISTS clauses fails in MySQL 8.0.40.
                     * Each column is pre-checked against information_schema, then dropped individually.
                     *
                     * @param string $column Column name (must be a literal from the hardcoded list below)
                     * @param string $sql    Pre-built DROP COLUMN SQL (no variable interpolation in the query string)
                     */
                    $dropColumn = function (string $column, string $sql) use ($db, &$results): void {
                        $exists = $db->query(
                            "SELECT 1 FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME = ?",
                            [$column]
                        )->count() > 0;

                        if (!$exists) {
                            logProgress("  {$column}: already absent — skipped", 'warning');
                            return;
                        }

                        $db->query($sql);
                        if ($db->error()) {
                            logProgress("  {$column}: DROP FAILED — " . $db->errorString(), 'error');
                            $results['errors']++;
                        } else {
                            logProgress("  {$column}: dropped", 'success');
                            $results['processed']++;
                        }
                    };

                    try {
                        // STEP 1: Drop elan_spam_* columns from settings table
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Drop elan_spam_* columns from settings table', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $dropColumn('elan_spam_cleanup_enabled',    'ALTER TABLE settings DROP COLUMN elan_spam_cleanup_enabled');
                        $dropColumn('elan_spam_cleanup_dry_run',     'ALTER TABLE settings DROP COLUMN elan_spam_cleanup_dry_run');
                        $dropColumn('elan_spam_inactive_days',       'ALTER TABLE settings DROP COLUMN elan_spam_inactive_days');
                        $dropColumn('elan_spam_grace_period_days',   'ALTER TABLE settings DROP COLUMN elan_spam_grace_period_days');
                        $dropColumn('elan_spam_max_deletions',       'ALTER TABLE settings DROP COLUMN elan_spam_max_deletions');
                        $dropColumn('elan_spam_max_percentage',      'ALTER TABLE settings DROP COLUMN elan_spam_max_percentage');
                        $dropColumn('elan_spam_email_notifications', 'ALTER TABLE settings DROP COLUMN elan_spam_email_notifications');

                        // Verify: confirm zero spam columns remain
                        $remaining = $db->query(
                            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME LIKE 'elan_spam%'"
                        )->results();

                        if (empty($remaining)) {
                            logProgress('Verification: no elan_spam_* columns remain in settings', 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Dropped elan_spam_* columns from settings table');
                        } else {
                            $still = implode(', ', array_map(fn($r) => $r->COLUMN_NAME, $remaining));
                            logProgress("Verification FAILED: columns still present: {$still}", 'error');
                            logProgress('Run the following SQL manually:', 'error');
                            foreach ($remaining as $r) {
                                logProgress("  ALTER TABLE settings DROP COLUMN {$r->COLUMN_NAME};", 'error');
                            }
                            $results['errors']++;
                        }

                        // STEP 2: Delete spam_inactive_cleanup.php cron row
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Delete spam_inactive_cleanup.php from crons table', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $db->query("DELETE FROM crons WHERE file = ?", ['spam_inactive_cleanup.php']);
                        if ($db->error()) {
                            logProgress('FAILED to delete cron row: ' . $db->errorString(), 'error');
                            $results['errors']++;
                        } else {
                            $deleted = $db->count();
                            if ($deleted > 0) {
                                logProgress("Deleted {$deleted} cron row(s) for spam_inactive_cleanup.php", 'success');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Deleted {$deleted} cron row(s) for spam_inactive_cleanup.php");
                                $results['processed']++;
                            } else {
                                logProgress('No cron row found for spam_inactive_cleanup.php (already absent)', 'warning');
                                $results['warnings']++;
                            }
                        }

                        // Log script completion only when all operations succeeded
                        if ($results['errors'] === 0) {
                            $db->insert('fix_script_runs', [
                                'script_name' => '10-Remove-Spam-Cleanup-Columns.php',
                                'completed_at' => date('Y-m-d H:i:s')
                            ]);
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script completed — Processed: {$results['processed']}, Warnings: {$results['warnings']}");
                        } else {
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                "Script finished with errors — NOT recording completion. Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");
                        }

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('SPAM CLEANUP COLUMN REMOVAL COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Steps Processed: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']} — see above for manual SQL", 'error');
                            logProgress('Script NOT marked complete. Rerun after manual remediation.', 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Run composer test:quick to confirm no regressions', 'info');

                    } catch (Exception $e) {
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
