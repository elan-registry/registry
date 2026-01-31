<?php

declare(strict_types=1);

/**
 * Add Subresource Integrity (SRI) to CDN Resources & Upgrade DataTables
 *
 * Administrative script to add SRI hashes to external CDN resources for enhanced security
 * and upgrade DataTables from v1.10.23 to v1.11.3 to fix CVE-2021-23445.
 *
 * Issue #413: Security: Add Subresource Integrity (SRI) to external resources
 * ZAP Scan: Fixes vulnerable jQuery DataTables library (CVE-2021-23445)
 *
 * This script addresses ZAP scan findings by:
 * - Adding integrity and crossorigin attributes to CDN resources
 * - Upgrading DataTables from 1.10.23 to 1.11.3+ to fix security vulnerability
 * - SRI protects against CDN compromises by ensuring resources haven't been tampered with
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. This script updates settings table with new CDN configuration values
 * 2. No database backup needed (settings are non-destructive updates)
 * 3. Script can be safely re-run multiple times
 * 4. All scripts auto-log completion to fix_script_runs table
 * 5. After running, verify resources load correctly and ZAP scan passes
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, "Error [$errno]: $errstr in $errfile:$errline");
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
                                <i class="fa fa-shield"></i> Add SRI to CDN Resources & Upgrade DataTables
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script adds Subresource Integrity (SRI) hashes to external CDN resources and upgrades DataTables from v1.10.23 to v1.11.3 to fix CVE-2021-23445.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Adds SRI integrity hash to jQuery CDN resource</li>
                                    <li><strong>Upgrades DataTables from 1.10.23 to 1.11.3</strong> (fixes CVE-2021-23445)</li>
                                    <li>Adds SRI integrity hash and crossorigin to DataTables JS CDN resource</li>
                                    <li>Adds SRI integrity hash and crossorigin to DataTables CSS CDN resource</li>
                                    <li>Adds SRI integrity hash and crossorigin to Chart.js CDN resource</li>
                                    <li>Updates settings table with new CDN configuration values</li>
                                    <li>Logs all changes to the UserSpice logging system</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>FontAwesome Kit uses dynamic JavaScript and cannot use SRI (already has crossorigin)</li>
                                    <li>After running this script, verify all resources load correctly in the browser</li>
                                    <li>Check browser console for any CORS or integrity errors</li>
                                    <li>The Chart.js CDN setting will be changed from a URL to a full HTML tag</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start SRI Update
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
                        <i class="fa fa-cogs"></i> Adding SRI to CDN Resources
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
                        // STEP 1: Update jQuery CDN with SRI
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Add SRI to jQuery CDN', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $jquery_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/jquery@3.5.1/dist/jquery.min.js&quot; integrity=&quot;sha384-ZvpUoO/+PpLXR1lu4jmpXWu80pZlYUAfxl5NsBMWOEPSjUn/6Z/hRTt8+pR6L4N2&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        $db->query("UPDATE settings SET elan_jquery_cdn = ?", [$jquery_cdn]);

                        if ($db->count() > 0) {
                            logProgress('Updated jQuery CDN with SRI hash', 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, "Updated jQuery CDN with SRI hash");
                            $results['processed']++;
                        } else {
                            logProgress('jQuery CDN already up to date or no change made', 'warning');
                            $results['warnings']++;
                        }

                        // STEP 2: Upgrade DataTables JS CDN to v1.11.3 with SRI (fixes CVE-2021-23445)
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Upgrade DataTables JS to v1.11.3 + Add SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $datatables_js_cdn = '&lt;script type=&quot;text/javascript&quot; src=&quot;https://cdn.datatables.net/v/bs4/dt-1.11.3/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.js&quot; integrity=&quot;sha384-pUkSpEjhLIksI5FKAX4UkzoIdrf/DNbHJmyvHAnPNnssIJatoJ0VYL3M4OvrZiyo&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        $db->query("UPDATE settings SET elan_datatables_js_cdn = ?", [$datatables_js_cdn]);

                        if ($db->count() > 0) {
                            logProgress('Upgraded DataTables JS to v1.11.3 with SRI hash (CVE-2021-23445 fixed)', 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, "Upgraded DataTables JS to v1.11.3 with SRI hash (CVE-2021-23445 fixed)");
                            $results['processed']++;
                        } else {
                            logProgress('DataTables JS CDN already up to date or no change made', 'warning');
                            $results['warnings']++;
                        }

                        // STEP 3: Upgrade DataTables CSS CDN to v1.11.3 with SRI
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Upgrade DataTables CSS to v1.11.3 + Add SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $datatables_css_cdn = '&lt;link rel=&quot;stylesheet&quot; type=&quot;text/css&quot; href=&quot;https://cdn.datatables.net/v/bs4/dt-1.11.3/fh-3.1.8/r-2.2.7/rg-1.1.2/sc-2.0.3/sb-1.0.1/sp-1.2.2/datatables.min.css&quot; integrity=&quot;sha384-N6xm8BtUgmiJQhSqAMkzSd4wBrDAjrT5UiCCrTlkpbxLFl7SWl8WizprlOsn7MdJ&quot; crossorigin=&quot;anonymous&quot; /&gt;';

                        $db->query("UPDATE settings SET elan_datatables_css_cdn = ?", [$datatables_css_cdn]);

                        if ($db->count() > 0) {
                            logProgress('Upgraded DataTables CSS to v1.11.3 with SRI hash', 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, "Upgraded DataTables CSS to v1.11.3 with SRI hash");
                            $results['processed']++;
                        } else {
                            logProgress('DataTables CSS CDN already up to date or no change made', 'warning');
                            $results['warnings']++;
                        }

                        // STEP 4: Update Chart.js CDN with SRI
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 4: Add SRI to Chart.js CDN', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $chartjs_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js&quot; integrity=&quot;sha384-FcQlsUOd0TJjROrBxhJdUhXTUgNJQxTMcxZe6nHbaEfFL1zjQ+bq/uRoBQxb0KMo&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        $db->query("UPDATE settings SET elan_chartjs_cdn = ?", [$chartjs_cdn]);

                        if ($db->count() > 0) {
                            logProgress('Updated Chart.js CDN with SRI hash', 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, "Updated Chart.js CDN with SRI hash");
                            $results['processed']++;
                        } else {
                            logProgress('Chart.js CDN already up to date or no change made', 'warning');
                            $results['warnings']++;
                        }

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '17-Add-SRI-To-CDN-Resources.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE,
                            "Script completed - Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('SRI UPDATE COMPLETE', 'step');
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
                        logProgress('  • Test loading pages with CDN resources in your browser', 'info');
                        logProgress('  • Check browser console for any CORS or integrity errors', 'info');
                        logProgress('  • Run ZAP security scan to verify SRI warnings are resolved', 'info');
                        logProgress('  • Chart.js in statistics.php still needs manual update if used', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_SETTINGS_UPDATE, 'Fatal error: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="index.php" class="btn btn-primary btn-lg">
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
