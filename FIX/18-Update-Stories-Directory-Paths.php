<?php

declare(strict_types=1);

/**
 * Update Stories Directory Paths Script
 *
 * Administrative script to update database references from stories/ to docs/stories/
 * after moving the stories directory for better documentation organization.
 * Issue #360: Move stories/ directory to docs/ for better organization
 *
 * WHAT THIS SCRIPT DOES:
 * - Updates menu items that reference old /stories/ paths to /docs/stories/
 * - Creates or updates page permissions for new docs/stories/ paths to public access
 * - Ensures proper access control for story pages (public access)
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to FIX/ directory with proper naming: 18-Update-Stories-Directory-Paths.php
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
                                <i class="fa fa-folder-open"></i> Update Stories Directory Database References
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script updates database references after moving the stories/ directory to docs/stories/ for better documentation organization.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Removes page entry for deleted stories.php file</li>
                                    <li>Updates page permissions for story pages to public access</li>
                                    <li>Sets docs/stories/SGO_2F/index.php to public (currently private)</li>
                                    <li>Sets docs/stories/brian_walton/index.php to public (currently private)</li>
                                    <li>Sets docs/stories/type26register.php to public (currently private)</li>
                                    <li>Logs all changes to UserSpice audit system</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>This script is safe to run multiple times (idempotent)</li>
                                    <li>Changes are immediate - no rollback capability</li>
                                    <li>Only affects page permissions (no menu items need updating)</li>
                                    <li>Story pages will become publicly accessible after running this script</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Path Updates
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
                        <i class="fa fa-cogs"></i> Updating Stories Directory References
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
                        'pages_deleted' => 0,
                        'pages_created' => 0,
                        'pages_updated' => 0,
                        'errors' => 0,
                        'warnings' => 0
                    ];

                    try {
                        // STEP 1: Clean up deleted file entry
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Removing Page Entry for Deleted File', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // Remove page entry for docs/stories/stories.php which was deleted
                        $deletedPageQuery = $db->query("
                            SELECT id, page, private
                            FROM pages
                            WHERE page = 'docs/stories/stories.php'
                        ");

                        if ($deletedPageQuery && $deletedPageQuery->count() > 0) {
                            $deletedPage = $deletedPageQuery->first();
                            logProgress("Found page entry for deleted file: {$deletedPage->page} (id: {$deletedPage->id})", 'info');

                            // Delete permission associations first
                            $db->query("DELETE FROM permission_page_matches WHERE page_id = ?", [$deletedPage->id]);

                            // Delete the page entry
                            $db->delete('pages', $deletedPage->id);

                            logProgress("Removed page entry for deleted file", 'success');
                            $results['pages_deleted'] = 1;

                            logger($user->data()->id, LOG_CATEGORY_MAINTENANCE,
                                "Deleted page entry for removed file: {$deletedPage->page}");
                        } else {
                            logProgress('Page entry already removed or does not exist', 'info');
                            $results['pages_deleted'] = 0;
                        }

                        // STEP 2: Update Page Permissions for Story Pages
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Updating Page Permissions for Story Pages', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        // List of story pages that should be public
                        $storyPages = [
                            'docs/stories/SGO_2F/index.php',
                            'docs/stories/brian_walton/index.php',
                            'docs/stories/type26register.php'
                        ];

                        foreach ($storyPages as $pagePath) {
                            logProgress("  Processing page: {$pagePath}", 'info');

                            // Check if page exists
                            $pageQuery = $db->query("SELECT id, page, private FROM pages WHERE page = ?", [$pagePath]);

                            if ($pageQuery && $pageQuery->count() > 0) {
                                // Page exists - update it to be public
                                $page = $pageQuery->first();

                                if ($page->private == 1) {
                                    logProgress("    Setting page to PUBLIC (private=0)", 'info');
                                    $db->update('pages', $page->id, ['private' => 0]);
                                    $results['pages_updated']++;

                                    // Remove any permission restrictions
                                    $db->query("DELETE FROM permission_page_matches WHERE page_id = ?", [$page->id]);

                                    logger($user->data()->id, LOG_CATEGORY_MAINTENANCE,
                                        "Updated page '{$pagePath}' to public access");
                                } else {
                                    logProgress("    Already public - no changes needed", 'info');
                                }
                            } else {
                                // Page doesn't exist - create it as public
                                logProgress("    Creating new PUBLIC page entry", 'info');

                                $db->insert('pages', [
                                    'page' => $pagePath,
                                    'private' => 0
                                ]);

                                $results['pages_created']++;

                                logger($user->data()->id, LOG_CATEGORY_MAINTENANCE,
                                    "Created public page entry for '{$pagePath}'");
                            }

                            logProgress("    ✓ Page configured", 'success');
                        }

                        logProgress('Page permissions updated successfully', 'success');

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '18-Update-Stories-Directory-Paths.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE,
                            "Script completed - Pages deleted: {$results['pages_deleted']}, " .
                            "Pages created: {$results['pages_created']}, " .
                            "Pages updated: {$results['pages_updated']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STORIES DIRECTORY PATH UPDATE COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Pages deleted (orphaned): {$results['pages_deleted']}", 'success');
                        logProgress("Pages created: {$results['pages_created']}", 'success');
                        logProgress("Pages updated to public: {$results['pages_updated']}", 'success');

                        if ($results['warnings'] > 0) {
                            logProgress("Warnings: {$results['warnings']}", 'warning');
                        }
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }

                        logProgress('', 'info');
                        logProgress('Post-Processing Steps:', 'info');
                        logProgress('  • Clear browser cache to see updated menu links', 'info');
                        logProgress('  • Test all story pages to ensure they load correctly', 'info');
                        logProgress('  • Verify menu navigation works as expected', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LOG_CATEGORY_MAINTENANCE, 'Fatal error: ' . $e->getMessage());
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
