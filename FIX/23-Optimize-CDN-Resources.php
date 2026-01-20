<?php

declare(strict_types=1);

/**
 * Optimize CDN Resources - Minified Versions with SRI
 *
 * Administrative script to update CDN resources to their minified versions with
 * Subresource Integrity (SRI) hashes for improved performance and security.
 *
 * Issue #442 Phase 2: Performance optimization
 * Milestone: v2.12.0
 *
 * This script addresses performance optimization by:
 * - Updating Bootstrap 4.x CDN to minified version with SRI
 * - Updating jQuery to minified version with SRI
 * - Updating Popper.js to minified version with SRI
 * - Ensures all CDN resources are optimized for fastest loading
 * - SRI protects against CDN compromises by ensuring resources haven't been tampered with
 *
 * Expected Performance Impact:
 * - Minified CSS: ~25% smaller (ElanRegistry.css)
 * - Minified JS: ~30% smaller (jQuery, Bootstrap)
 * - After gzip: ~20-25% total bandwidth savings
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. This script updates settings table with optimized CDN configuration values
 * 2. No database backup needed (settings are non-destructive updates)
 * 3. Script can be safely re-run multiple times
 * 4. All scripts auto-log completion to fix_script_runs table
 * 5. After running, verify resources load correctly in browser
 * 6. Use external performance test tool to measure before/after improvements
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');
define('LOG_CATEGORY_PLACEHOLDER', 'PerformanceOptimization');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LOG_CATEGORY_PLACEHOLDER, "Error [$errno]: $errstr in $errfile:$errline");
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
                                <i class="fa fa-tachometer"></i> Optimize CDN Resources for Performance
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script updates CDN resources to their minified versions with Subresource Integrity (SRI) hashes, improving performance and security.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Updates Bootstrap 4.6.2 CSS CDN to minified version with SRI hash</li>
                                    <li>Updates jQuery 3.6.0 CDN to minified version with SRI hash</li>
                                    <li>Updates Bootstrap 4.6.2 JS CDN to minified version with SRI hash</li>
                                    <li>Updates Popper.js 1.16.1 CDN to minified version with SRI hash</li>
                                    <li>Preserves existing DataTables, Bootswatch, and FontAwesome configurations</li>
                                    <li>Logs all changes to the UserSpice logging system</li>
                                </ul>
                            </div>

                            <div class="alert alert-success">
                                <h5><i class="fa fa-line-chart"></i> Expected Performance Improvements:</h5>
                                <ul class="mb-0">
                                    <li>CSS file size reduction: ~25% smaller with minification</li>
                                    <li>JavaScript file size reduction: ~30% smaller with minification</li>
                                    <li>Total bandwidth savings (after gzip): ~20-25%</li>
                                    <li>Estimated savings per page load: ~20-30 KB</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This script only updates CDN URLs - local CSS consolidation is a separate step</li>
                                    <li>After running this script, verify all resources load correctly in your browser</li>
                                    <li>Check browser console for any CORS or integrity errors</li>
                                    <li>Use external performance testing tool to measure before/after improvements</li>
                                    <li>This script is safe to re-run multiple times if needed</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start CDN Optimization
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
                        <i class="fa fa-cogs"></i> Optimizing CDN Resources
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
                        // STEP 1: Update Bootstrap CSS CDN with minified version + SRI
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Update Bootstrap 4.6.2 CSS CDN with minified version + SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $bootstrap_css_cdn = '&lt;link rel=&quot;stylesheet&quot; href=&quot;https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css&quot; integrity=&quot;sha384-xOolHFLEh07PJGoPkLv1IbcEPTNtaed2xpHsD9ESMhqIYd0nLMwNLD69Npy4HI+N&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/link&gt;';

                        try {
                            $db->query("UPDATE settings SET elan_bootstrap_css_cdn = ?", [$bootstrap_css_cdn]);
                            if ($db->count() > 0) {
                                logProgress('Updated Bootstrap CSS CDN to v4.6.2 minified with SRI', 'success');
                                logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Updated Bootstrap CSS CDN to v4.6.2 minified version with SRI hash");
                                $results['processed']++;
                            } else {
                                logProgress('Bootstrap CSS CDN already optimized or no change made', 'warning');
                                $results['warnings']++;
                            }
                        } catch (PDOException $e) {
                            logProgress("Error updating Bootstrap CSS CDN: " . $e->getMessage(), 'error');
                            logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Database error updating Bootstrap CSS: " . $e->getMessage());
                            $results['errors']++;
                        }

                        // STEP 2: Update jQuery CDN with minified version + SRI
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Update jQuery 3.6.0 CDN with minified version + SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $jquery_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js&quot; integrity=&quot;sha384-vtXRMe3mGCbOeY7l30aIg8H9p3GdeSe4IFlP6G8JMa7o7lXvnz3GFKzPxzJdPfGK&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        try {
                            $db->query("UPDATE settings SET elan_jquery_cdn = ?", [$jquery_cdn]);
                            if ($db->count() > 0) {
                                logProgress('Updated jQuery CDN to v3.6.0 minified with SRI', 'success');
                                logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Updated jQuery CDN to v3.6.0 minified version with SRI hash");
                                $results['processed']++;
                            } else {
                                logProgress('jQuery CDN already optimized or no change made', 'warning');
                                $results['warnings']++;
                            }
                        } catch (PDOException $e) {
                            logProgress("Error updating jQuery CDN: " . $e->getMessage(), 'error');
                            logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Database error updating jQuery: " . $e->getMessage());
                            $results['errors']++;
                        }

                        // STEP 3: Update Bootstrap JS CDN with minified version + SRI
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: Update Bootstrap 4.6.2 JS CDN with minified version + SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $bootstrap_js_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js&quot; integrity=&quot;sha384-+sLIOodYLS7CIrQpBjl+C7nPvqq+FbNUBDunl/OZv93DB7Ln/533i8e/mZXLi/P+&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        try {
                            $db->query("UPDATE settings SET elan_bootstrap_js_cdn = ?", [$bootstrap_js_cdn]);
                            if ($db->count() > 0) {
                                logProgress('Updated Bootstrap JS CDN to v4.6.2 minified with SRI', 'success');
                                logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Updated Bootstrap JS CDN to v4.6.2 minified version with SRI hash");
                                $results['processed']++;
                            } else {
                                logProgress('Bootstrap JS CDN already optimized or no change made', 'warning');
                                $results['warnings']++;
                            }
                        } catch (PDOException $e) {
                            logProgress("Error updating Bootstrap JS CDN: " . $e->getMessage(), 'error');
                            logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Database error updating Bootstrap JS: " . $e->getMessage());
                            $results['errors']++;
                        }

                        // STEP 4: Update Popper.js CDN with minified version + SRI
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 4: Update Popper.js 1.16.1 CDN with minified version + SRI', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $popper_cdn = '&lt;script src=&quot;https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js&quot; integrity=&quot;sha384-9/reFTGAW83EW2RDu2S0VKaIzap3H66lZH81PoYlFhbGU+6BZp6G7niu735Sk7lN&quot; crossorigin=&quot;anonymous&quot;&gt;&lt;/script&gt;';

                        try {
                            $db->query("UPDATE settings SET elan_popper_cdn = ?", [$popper_cdn]);
                            if ($db->count() > 0) {
                                logProgress('Updated Popper.js CDN to v1.16.1 minified with SRI', 'success');
                                logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Updated Popper.js CDN to v1.16.1 minified version with SRI hash");
                                $results['processed']++;
                            } else {
                                logProgress('Popper.js CDN already optimized or no change made', 'warning');
                                $results['warnings']++;
                            }
                        } catch (PDOException $e) {
                            logProgress("Error updating Popper.js CDN: " . $e->getMessage(), 'error');
                            logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Database error updating Popper.js: " . $e->getMessage());
                            $results['errors']++;
                        }

                        // Log script completion
                        try {
                            $db->insert('fix_script_runs', [
                                'script_name' => '23-Optimize-CDN-Resources.php',
                                'completed_at' => date('Y-m-d H:i:s')
                            ]);
                        } catch (PDOException $e) {
                            logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, "Warning: Could not log script completion: " . $e->getMessage());
                        }

                        logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER,
                            "Script completed - Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('CDN OPTIMIZATION COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Items Processed: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Next Steps:', 'info');
                        logProgress('  1. Test loading pages with optimized CDN resources in your browser', 'info');
                        logProgress('  2. Check browser console for any CORS or integrity errors', 'info');
                        logProgress('  3. Clear browser cache (Cmd+Shift+R / Ctrl+Shift+R for hard refresh)', 'info');
                        logProgress('  4. Use external performance testing tool to measure improvements', 'info');
                        logProgress('  5. Next phase: Consolidate local CSS files (ElanRegistry.css + style.css)', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LOG_CATEGORY_PLACEHOLDER, 'Fatal error: ' . $e->getMessage());
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
