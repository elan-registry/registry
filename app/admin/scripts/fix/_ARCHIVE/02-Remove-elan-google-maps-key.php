<?php

declare(strict_types=1);

/**
 * Remove Google Maps API Key Script
 *
 * Administrative script to remove the `elan_google_maps_key` column from the
 * `settings` table. This column stored the Google Maps API key which was
 * deprecated in v2.22.0 with the Google Maps removal.
 * Issue #724: Remove Google Maps API Key
 *
 * Must run after the #433 fix script (which removes `elan_google_geo_key`).
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Script lives in app/admin/scripts/fix/ as a one-time migration: 02-Remove-elan-google-maps-key.php
 * 2. Scripts are accessed via the FIX menu (app/admin/scripts/fix/index.php) or direct URL
 * 3. Sequential numbering (02) communicates that this script depends on #01 having been run first; order must be followed manually
 * 4. Auto-logs completion to fix_script_runs table
 * 5. See app/admin/scripts/fix/README.md for detailed instructions and best practices
 *
 * TEMPLATE FEATURES:
 * - Simple, reliable text-based progress output (no JavaScript complexity)
 * - Two-step process: description -> start button -> real-time processing
 * - Timestamped progress with emoji indicators
 * - Automatic completion logging
 * - Clean error handling and reporting
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
                                <i class="fa fa-key"></i> Remove Google Maps API Key
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Removes the deprecated <code>elan_google_maps_key</code> column from the <code>settings</code> table.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Drops the <code>elan_google_maps_key</code> column from the <code>settings</code> table</li>
                                    <li>Uses <code>DROP COLUMN IF EXISTS</code> for safe, idempotent execution</li>
                                    <li>Removes a setting deprecated in v2.22.0 with the Google Maps removal</li>
                                    <li>Logs completion to the <code>fix_script_runs</code> table</li>
                                    <li>Resolves GitHub issue #724</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Issue #433 (Remove deprecated Google Geocoding code) must have run first &mdash; this is now satisfied as of v2.22.0</li>
                                    <li>Backup the database before running destructive schema changes</li>
                                    <li>Any stored Google Maps API key value will be permanently lost</li>
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
                        <i class="fa fa-cogs"></i> Removing elan_google_maps_key Column
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
                        // STEP 1: Drop the elan_google_maps_key column
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Drop elan_google_maps_key column from settings table', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        logProgress('Checking if column exists...', 'info');
                        $exists = $db->query(
                            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME = 'settings'
                               AND COLUMN_NAME = 'elan_google_maps_key'"
                        )->first();

                        if ((int)($exists->c ?? 0) === 0) {
                            logProgress('Column elan_google_maps_key does not exist; nothing to do.', 'info');
                        } else {
                            logProgress('Dropping elan_google_maps_key column...', 'info');
                            $db->query("ALTER TABLE settings DROP COLUMN elan_google_maps_key;");

                            if ($db->error()) {
                                $results['errors']++;
                                logProgress('Failed to drop column: ' . $db->errorString(), 'error');
                                throw new RuntimeException('ALTER TABLE failed: ' . $db->errorString());
                            }

                            logProgress('Column elan_google_maps_key removed successfully.', 'success');
                        }

                        $results['processed']++;

                        // Log script completion
                        $inserted = $db->insert('fix_script_runs', [
                            'script_name' => '02-Remove-elan-google-maps-key.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        if (!$inserted) {
                            $results['warnings']++;
                            logProgress('WARNING: Could not record completion in fix_script_runs. Script ran successfully but will not appear as completed in the maintenance dashboard.', 'warning');
                            logger(isset($user) ? (int)$user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                                'Failed to insert fix_script_runs record for 02-Remove-elan-google-maps-key.php');
                        }

                        logger(isset($user) ? (int)$user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Script completed - Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Google Maps API Key column removal complete', 'step');
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
                        logProgress('  • Verify the settings table no longer contains elan_google_maps_key', 'info');
                        logProgress('  • Confirm the statistics page and car details maps render using MapLibre GL JS', 'info');
                        logProgress('  • Remove any remaining references to the Google Maps API key from configuration', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger(isset($user) ? (int)$user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
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
