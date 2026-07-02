<?php

declare(strict_types=1);

/**
 * 08-Rename-Admin-Pages.php Script
 *
 * Administrative script to update the UserSpice `pages` table to reflect three
 * renamed admin pages.
 * Issue #1039: Rename admin pages for clarity and add Design System to nav
 *
 * The PHP files were renamed via git mv:
 *   - manage-consolidated.php → index.php       (Admin Dashboard)
 *   - manage-maintenance.php  → maintenance.php  (Registry Maintenance)
 *   - color-preview.php       → design-system.php (Design System)
 *
 * This script keeps the UserSpice pages registry in sync with those renames so
 * permission checks and securePage() continue to resolve correctly.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Run once after deployment via maintenance.php (Maintenance tab) or direct URL
 * 2. All scripts auto-log completion to fix_script_runs table
 * 3. See app/admin/scripts/fix/README.md for detailed instructions and best practices
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

            <?php if (!isset($_POST['start'])): ?>
            <!-- Initial Description -->
            <div class="row">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-pencil-square-o"></i> Rename Admin Pages
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script updates the UserSpice <code>pages</code> table to reflect three renamed admin pages.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Updates <code>manage-consolidated.php</code> → <code>index.php</code> (Admin Dashboard)</li>
                                    <li>Updates <code>manage-maintenance.php</code> → <code>maintenance.php</code> (Registry Maintenance)</li>
                                    <li>Updates <code>color-preview.php</code> → <code>design-system.php</code> (Design System)</li>
                                    <li>Keeps the page title in sync with each renamed page</li>
                                    <li>Logs completion to the <code>fix_script_runs</code> table</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>Run this once after the renamed PHP files are deployed</li>
                                    <li>Each row is matched by its old page path; a missing row is reported as a warning, not an error</li>
                                    <li>No data is deleted — only the <code>page</code> and <code>title</code> columns are updated</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <form method="post" action="">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="start" value="1">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fa fa-play"></i> Continue - Start Rename
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php elseif (!Token::check($_POST['csrf'] ?? '')): ?>
            <div class="alert alert-danger">
                <i class="fa fa-exclamation-triangle"></i> Invalid CSRF token. Please go back and try again.
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
                        <i class="fa fa-cogs"></i> Renaming Admin Pages
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
                        echo date('[H:i:s] ') . $icon . ' ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "\n";
                        flush();
                    }

                    // Initialize results tracking
                    $results = [
                        'processed' => 0,
                        'errors' => 0,
                        'warnings' => 0
                    ];

                    // Each entry: [new page path, new title, old page path]
                    $renames = [
                        ['app/admin/index.php', 'Admin Dashboard', 'app/admin/manage-consolidated.php'],
                        ['app/admin/maintenance.php', 'Registry Maintenance', 'app/admin/manage-maintenance.php'],
                        ['app/admin/design-system.php', 'Design System', 'app/admin/color-preview.php'],
                    ];

                    try {
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Update pages table for renamed admin pages', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        foreach ($renames as [$newPage, $newTitle, $oldPage]) {
                            $db->query(
                                "UPDATE pages SET page = ?, title = ? WHERE page = ?",
                                [$newPage, $newTitle, $oldPage]
                            );

                            if ($db->error()) {
                                $results['errors']++;
                                $dbError = $db->errorString();
                                logProgress("Database error updating {$oldPage}: {$dbError}", 'error');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                    "Database error updating pages row {$oldPage} → {$newPage}: {$dbError}");
                                logProgress('Aborting — resolve the database error and re-run.', 'error');
                                break;
                            }

                            if ($db->count() > 0) {
                                $results['processed']++;
                                logProgress("{$oldPage} → {$newPage} ({$newTitle})", 'success');
                            } else {
                                $results['warnings']++;
                                logProgress("No pages row found for {$oldPage} — skipped", 'warning');
                            }
                        }

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress($results['errors'] > 0 ? 'Rename aborted — resolve errors and re-run' : 'Rename complete', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Pages Updated: {$results['processed']}", 'success');
                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }

                        // Only mark as completed when there were no errors
                        if ($results['errors'] === 0) {
                            $inserted = $db->insert('fix_script_runs', [
                                'script_name' => '08-Rename-Admin-Pages.php',
                                'completed_at' => date('Y-m-d H:i:s')
                            ]);
                            if (!$inserted) {
                                logProgress('Warning: could not write completion record — ' . $db->errorString(), 'warning');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR,
                                    'fix_script_runs insert failed: ' . $db->errorString());
                            }
                        }

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                            "Script run — Processed: {$results['processed']}, Errors: {$results['errors']}, Warnings: {$results['warnings']}");

                    } catch (\Throwable $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT_ERROR, '08-Rename-Admin-Pages fatal error [' . get_class($e) . ']: ' . $e->getMessage());
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
