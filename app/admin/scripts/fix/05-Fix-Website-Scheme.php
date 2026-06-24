<?php

declare(strict_types=1);

/**
 * 05-Fix-Website-Scheme.php
 * Migrate scheme-less website URLs in the cars table.
 * Issue #851: website field silently hidden when URL lacks http/https scheme
 *
 * Prepends https:// to bare domain-like values; nulls out invalid ones
 * (javascript:, data:, relative paths, etc.).
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to app/admin/scripts/fix/ with proper naming: ##-Descriptive-Name.php
 * 2. Scripts are accessed via manage-maintenance.php (Maintenance tab) or direct URL
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
                                <i class="fa fa-link"></i> Fix Website URL Schemes
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Migrates existing <code>cars</code> table rows where <code>website</code> is set but does not start with <code>http://</code> or <code>https://</code>.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Counts cars with a non-http(s) website value</li>
                                    <li>Prepends <code>https://</code> to bare domain-like values (contain a dot, no colon, don't start with <code>/</code>)</li>
                                    <li>Sets <code>website = NULL</code> for values with a non-http(s) scheme or relative paths</li>
                                    <li>Logs each change with the car ID and old/new value</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This modifies live data — run on dev first</li>
                                    <li>Each change is logged to the application audit log</li>
                                    <li>Cannot be undone automatically; back up before running on production</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Website URL Migration
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
                        <i class="fa fa-cogs"></i> Processing Website URL Migration
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
                    function logProgress($message, $type = 'info'): void {
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
                        // STEP 1: COUNT AFFECTED ROWS
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: COUNT AFFECTED ROWS', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $countResult = $db->query(
                            "SELECT COUNT(*) as cnt FROM cars WHERE website IS NOT NULL AND website != '' AND website NOT REGEXP '^https?://'"
                        )->first();
                        $count = (int)($countResult->cnt ?? 0);
                        logProgress("Found {$count} car(s) with non-http(s) website URLs", $count > 0 ? 'warning' : 'success');

                        // STEP 2: PROCESS ROWS
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: PROCESS ROWS', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $rows = $db->query(
                            "SELECT id, website FROM cars WHERE website IS NOT NULL AND website != '' AND website NOT REGEXP '^https?://'"
                        )->results();

                        foreach ($rows as $row) {
                            $carId = (int)$row->id;
                            $oldUrl = (string)$row->website;
                            $scheme = strtolower((string)parse_url($oldUrl, PHP_URL_SCHEME));

                            // Bare domain: no scheme, contains a dot, no colon, does not start with /
                            $isBareDomainLike = $scheme === ''
                                && str_contains($oldUrl, '.')
                                && !str_contains($oldUrl, ':')
                                && !str_starts_with($oldUrl, '/');

                            if ($isBareDomainLike) {
                                $newUrl = 'https://' . $oldUrl;
                                $db->query('UPDATE cars SET website = ? WHERE id = ?', [$newUrl, $carId]);
                                logProgress("Car #{$carId}: prepended https:// — '" . htmlspecialchars($oldUrl, ENT_QUOTES, 'UTF-8') . "' → '" . htmlspecialchars($newUrl, ENT_QUOTES, 'UTF-8') . "'", 'success');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "05-Fix-Website-Scheme: car #{$carId} website updated from '{$oldUrl}' to '{$newUrl}'");
                                $results['processed']++;
                            } else {
                                $db->query('UPDATE cars SET website = NULL WHERE id = ?', [$carId]);
                                logProgress("Car #{$carId}: nulled invalid URL '" . htmlspecialchars($oldUrl, ENT_QUOTES, 'UTF-8') . "' (scheme: '" . htmlspecialchars($scheme, ENT_QUOTES, 'UTF-8') . "')", 'warning');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "05-Fix-Website-Scheme: car #{$carId} website nulled (was: '{$oldUrl}')");
                                $results['processed']++;
                                $results['warnings']++;
                            }
                        }

                        // STEP 3: COMPLETE
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 3: COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '05-Fix-Website-Scheme.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Script completed - Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('Website URL scheme migration complete.', 'step');
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
                        logProgress('  • Verify affected cars in the registry that their website fields now display correctly', 'info');
                        logProgress('  • No schema changes — no additional migration steps needed', 'info');

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
