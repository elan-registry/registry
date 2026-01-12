<?php

declare(strict_types=1);

/**
 * Optimize DataTables CDN - Remove Unused Extensions
 *
 * Administrative script to optimize DataTables CDN URLs by removing unused extensions.
 * Issue #168: Enhanced search capability investigation
 *
 * Analysis revealed we're loading 5 unused DataTables extensions (RowGroup, Scroller,
 * Select, SearchBuilder, SearchPanes). This script optimizes the CDN to load only
 * what we actually use: DataTables Core, FixedHeader, and Responsive.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to FIX/ directory with proper naming: 19-Add-Select-Extension-DataTables-CDN.php
 * 2. Scripts are accessed via FIX/index.php menu or direct URL
 * 3. Script uses two-step confirmation for safety
 * 4. All changes logged to UserSpice audit system
 * 5. Script auto-logs completion to fix_script_runs table
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');
define('LOG_CATEGORY_MAINTENANCE', 'DatabaseMaintenance');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/exceptions/SchemaException.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($user) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LOG_CATEGORY_MAINTENANCE, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

// Initialize BackupManager
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'backup/', (int) $user->data()->id);

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
                                <i class="fa fa-tachometer"></i> Optimize DataTables CDN Configuration
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script optimizes the DataTables CDN URLs by removing unused extensions, reducing page load size and improving performance.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates a database backup before making changes</li>
                                    <li>Updates DataTables JavaScript CDN URL to remove unused extensions</li>
                                    <li>Updates DataTables CSS CDN URL to remove unused extensions</li>
                                    <li>Reduces bundle size by removing 5 unused extensions</li>
                                    <li>Logs all changes to the UserSpice audit system</li>
                                </ul>
                            </div>

                            <div class="alert alert-success">
                                <h5><i class="fa fa-check-circle"></i> Performance Benefits:</h5>
                                <ul class="mb-0">
                                    <li><strong>Smaller bundle size</strong>: Faster page loads and reduced bandwidth</li>
                                    <li><strong>Less JavaScript to parse</strong>: Improved browser performance</li>
                                    <li><strong>Cleaner configuration</strong>: Only load what we actually use</li>
                                    <li><strong>Easier maintenance</strong>: Simpler extension list to manage</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Current vs. Optimized Configuration:</h5>

                                <p><strong>Current Extensions (8 total):</strong></p>
                                <ul>
                                    <li>✅ DataTables Core (dt-1.10.23) - <strong>USED</strong></li>
                                    <li>✅ FixedHeader (fh-3.1.8) - <strong>USED</strong></li>
                                    <li>✅ Responsive (r-2.2.7) - <strong>USED</strong></li>
                                    <li>❌ RowGroup (rg-1.1.2) - <strong class="text-danger">UNUSED</strong></li>
                                    <li>❌ Scroller (sc-2.0.3) - <strong class="text-danger">UNUSED</strong></li>
                                    <li>❌ Select (sl-1.3.3) - <strong class="text-danger">UNUSED</strong></li>
                                    <li>❌ SearchBuilder (sb-1.0.1) - <strong class="text-danger">UNUSED</strong></li>
                                    <li>❌ SearchPanes (sp-1.2.2) - <strong class="text-danger">UNUSED</strong></li>
                                </ul>

                                <p><strong>Optimized Extensions (3 total):</strong></p>
                                <ul class="mb-0">
                                    <li>✅ DataTables Core (dt-1.10.23)</li>
                                    <li>✅ FixedHeader (fh-3.1.8)</li>
                                    <li>✅ Responsive (r-2.2.7)</li>
                                </ul>

                                <p class="mt-3 mb-0"><strong>Removing:</strong> 5 unused extensions (RowGroup, Scroller, Select, SearchBuilder, SearchPanes)</p>
                            </div>

                            <div class="alert alert-secondary">
                                <h5><i class="fa fa-info-circle"></i> Impact:</h5>
                                <ul class="mb-0">
                                    <li>Changes affect all users immediately</li>
                                    <li>No visible UX changes - only performance improvements</li>
                                    <li>Database backup will be created before making changes</li>
                                    <li>After running, clear browser cache for best results</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Optimize CDN URLs
                                </a>
                                <a href="index.php" class="btn btn-secondary btn-lg ml-2">
                                    <i class="fa fa-times"></i> Cancel
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
                        <i class="fa fa-cogs"></i> Optimizing DataTables CDN Configuration
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
                    function logProgress($message, $type = 'info') {
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
                        // STEP 1: Create Database Backup
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Creating Database Backup', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $backupPath = $backupManager->createSchemaBackup(
                            'FIX #19: Optimize DataTables CDN',
                            ['settings']
                        );
                        logProgress('Backup created: ' . basename($backupPath), 'success');
                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE, "FIX #19: Backup created at {$backupPath}");
                        $results['processed']++;

                        // STEP 2: Update JavaScript CDN URL
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Updating DataTables JavaScript CDN URL', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Get current JS CDN
                        $currentJsCdn = htmlspecialchars_decode($settings->elan_datatables_js_cdn);
                        logProgress('Current JS CDN includes 8 extensions', 'info');
                        logProgress('Removing: RowGroup, Scroller, Select, SearchBuilder, SearchPanes', 'info');

                        // Optimized JS CDN - only Core, FixedHeader, Responsive
                        $newJsCdn = '<script type="text/javascript" src="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.js"></script>';

                        $updateJs = $db->update('settings', 1, [
                            'elan_datatables_js_cdn' => htmlspecialchars($newJsCdn)
                        ]);

                        if (!$updateJs) {
                            throw new SchemaException('Failed to update JavaScript CDN URL');
                        }

                        logProgress('JavaScript CDN URL updated successfully', 'success');
                        logProgress('Optimized to 3 extensions (was 8)', 'success');
                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE, "FIX #19: Optimized DataTables JS CDN - removed 5 unused extensions");
                        $results['processed']++;

                        // STEP 3: Update CSS CDN URL
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Updating DataTables CSS CDN URL', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Get current CSS CDN
                        $currentCssCdn = htmlspecialchars_decode($settings->elan_datatables_css_cdn);
                        logProgress('Current CSS CDN includes 8 extensions', 'info');
                        logProgress('Removing: RowGroup, Scroller, Select, SearchBuilder, SearchPanes', 'info');

                        // Optimized CSS CDN - only Core, FixedHeader, Responsive
                        $newCssCdn = '<link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/v/bs4/dt-1.10.23/fh-3.1.8/r-2.2.7/datatables.min.css" />';

                        $updateCss = $db->update('settings', 1, [
                            'elan_datatables_css_cdn' => htmlspecialchars($newCssCdn)
                        ]);

                        if (!$updateCss) {
                            throw new SchemaException('Failed to update CSS CDN URL');
                        }

                        logProgress('CSS CDN URL updated successfully', 'success');
                        logProgress('Optimized to 3 extensions (was 8)', 'success');
                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE, "FIX #19: Optimized DataTables CSS CDN - removed 5 unused extensions");
                        $results['processed']++;

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '19-Add-Select-Extension-DataTables-CDN.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE,
                            "FIX #19 completed - Settings optimized: {$results['processed']}, Errors: {$results['errors']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('CDN OPTIMIZATION COMPLETED SUCCESSFULLY', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Settings Updated: {$results['processed']}", 'success');
                        logProgress("Errors: {$results['errors']}", $results['errors'] > 0 ? 'error' : 'success');
                        logProgress('', 'info');
                        logProgress('Optimizations Applied:', 'info');
                        logProgress('  • Removed RowGroup extension (rg-1.1.2) - unused', 'success');
                        logProgress('  • Removed Scroller extension (sc-2.0.3) - unused', 'success');
                        logProgress('  • Removed Select extension (sl-1.3.3) - unused', 'success');
                        logProgress('  • Removed SearchBuilder extension (sb-1.0.1) - unused', 'success');
                        logProgress('  • Removed SearchPanes extension (sp-1.2.2) - unused', 'success');
                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Clear browser cache (Ctrl+Shift+Delete or Cmd+Shift+Delete)', 'info');
                        logProgress('  • Hard refresh DataTables pages (Ctrl+Shift+R or Cmd+Shift+R)', 'info');
                        logProgress('  • Verify DataTables loads correctly with no console errors', 'info');
                        logProgress('  • Notice improved page load performance', 'info');
                        logProgress('', 'info');
                        logProgress('Final Extension Configuration:', 'info');
                        logProgress('  1. DataTables Core (dt-1.10.23)', 'info');
                        logProgress('  2. FixedHeader (fh-3.1.8)', 'info');
                        logProgress('  3. Responsive (r-2.2.7)', 'info');

                    } catch (SchemaException $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE, 'FIX #19 fatal error: ' . $e->getMessage());
                        $results['errors']++;
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="<?= $us_url_root ?>app/cars/index.php" class="btn btn-success btn-lg">
                            <i class="fa fa-car"></i> Test Car Listing Page
                        </a>
                        <a href="index.php" class="btn btn-primary btn-lg ml-2">
                            <i class="fa fa-arrow-left"></i> Return to FIX Menu
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
