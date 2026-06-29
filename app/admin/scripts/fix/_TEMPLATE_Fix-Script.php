<?php

declare(strict_types=1);

/**
 * [SCRIPT_NAME] Script
 *
 * Administrative script to [SCRIPT_DESCRIPTION].
 * Issue #[ISSUE_NUMBER]: [ISSUE_TITLE]
 *
 * [ADDITIONAL_NOTES]
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to app/admin/scripts/fix/ (one-time migrations) or app/admin/scripts/maintenance/ (repeatable tasks) with proper naming: ##-Descriptive-Name.php
 * 2. Replace all [PLACEHOLDERS] with appropriate content
 * 3. Scripts are accessed via manage-maintenance.php (Maintenance tab) or direct URL
 * 4. Use sequential numbering (01, 02, 03...) for proper execution order
 * 5. All scripts auto-log completion to fix_script_runs table
 * 6. See app/admin/scripts/fix/README.md for detailed instructions and best practices
 *
 * TEMPLATE FEATURES:
 * - Simple, reliable text-based progress output (no JavaScript complexity)
 * - Two-step process: description → start button → real-time processing
 * - Timestamped progress with emoji indicators
 * - Automatic completion logging
 * - Clean error handling and reporting
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Optional: Include BackupManager if you need database backups
// BackupManager auto-loaded via custom autoloader (now in usersc/classes/admin/)

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

// Optional: Initialize BackupManager if needed
// $backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'backups', $user->data()->id);

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
                                <i class="fa fa-[ICON_NAME]"></i> [SCRIPT_TITLE]
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">[BRIEF_DESCRIPTION]</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>[BULLET_POINT_1]</li>
                                    <li>[BULLET_POINT_2]</li>
                                    <li>[BULLET_POINT_3]</li>
                                    <li>[BULLET_POINT_4]</li>
                                    <li>[BULLET_POINT_5]</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>[WARNING_POINT_1]</li>
                                    <li>[WARNING_POINT_2]</li>
                                    <li>[WARNING_POINT_3]</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start [ACTION_NAME]
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
                        <i class="fa fa-cogs"></i> [PROCESSING_TITLE]
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

                    try {
                        // STEP 1: [STEP_1_NAME]
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: [STEP_1_DESCRIPTION]', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Example: Create backup
                        // $backupPath = $backupManager->createSchemaBackup('[OPERATION_NAME]', ['table1', 'table2']);
                        // logProgress('Backup created: ' . basename($backupPath), 'success');
                        // logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Backup created: {$backupPath}");

                        // [YOUR STEP 1 CODE HERE]
                        logProgress('Step 1 processing...', 'info');
                        // Example processing
                        $results['processed']++;
                        logProgress('Step 1 completed', 'success');

                        // STEP 2: [STEP_2_NAME]
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: [STEP_2_DESCRIPTION]', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // [YOUR STEP 2 CODE HERE]
                        logProgress('Step 2 processing...', 'info');
                        // Example: Database query
                        // $db->query("UPDATE table SET field = ? WHERE condition = ?", ['value', 'condition']);
                        // $rowsAffected = $db->count();
                        // logProgress("Updated {$rowsAffected} rows", 'success');

                        $results['processed']++;
                        logProgress('Step 2 completed', 'success');

                        // STEP 3: [STEP_3_NAME] (add more steps as needed)
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: [STEP_3_DESCRIPTION]', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // [YOUR STEP 3 CODE HERE]
                        logProgress('Step 3 processing...', 'info');
                        $results['processed']++;
                        logProgress('Step 3 completed', 'success');

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '[##-Script-Name.php]',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Script completed - Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('[COMPLETION_MESSAGE]', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Items Processed: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • [POST_STEP_1]', 'info');
                        logProgress('  • [POST_STEP_2]', 'info');
                        logProgress('  • [POST_STEP_3]', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
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
