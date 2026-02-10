<?php

declare(strict_types=1);

/**
 * Update Page Titles Script
 *
 * Administrative script to extract and update page titles from HTML content.
 * Issue #[ISSUE_NUMBER]: Standardized page title management
 *
 * This script:
 * - Scans all app/* and docs/* pages for H1 or H2 heading tags
 * - Extracts the heading text and updates the page title in the database
 * - Provides detailed progress reporting for each updated page
 * - Handles missing or malformed HTML gracefully
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Script is accessed via FIX/index.php menu or direct URL
 * 2. Run after FIX/21 (Fix Page Permissions) has completed
 * 3. No database backup required - only updates page title field
 * 4. Can be run multiple times safely - updates all pages each time
 *
 * TEMPLATE FEATURES:
 * - Simple, reliable text-based progress output
 * - Two-step process: description → start button → real-time processing
 * - Timestamped progress with emoji indicators
 * - Automatic completion logging
 * - Clean error handling and reporting
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
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

/**
 * Extract page heading from H1 or H2 tags in HTML file
 */
function extractPageHeading(string $pagePath): string {
    global $abs_us_root, $us_url_root;

    // Build file path using same pattern as FIX/21
    $cleanPath = ltrim($pagePath, '/');
    $filePath = $abs_us_root . $us_url_root . $cleanPath;

    // Verify file exists
    if (!file_exists($filePath)) {
        return '';
    }

    try {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return '';
        }

        // Try to find H1 tag first (supports nested HTML tags)
        if (preg_match('#<h1[^>]*>(.+?)</h1>#is', $content, $matches)) {
            $text = trim(strip_tags($matches[1]));
            if (!empty($text)) {
                return $text;
            }
        }

        // Try to find H2 tag (supports nested HTML tags)
        if (preg_match('#<h2[^>]*>(.+?)</h2>#is', $content, $matches)) {
            $text = trim(strip_tags($matches[1]));
            if (!empty($text)) {
                return $text;
            }
        }

        // Also try h3 tag for pages without h1/h2
        if (preg_match('#<h3[^>]*>(.+?)</h3>#is', $content, $matches)) {
            $text = trim(strip_tags($matches[1]));
            if (!empty($text)) {
                return $text;
            }
        }
    } catch (Exception) {
        // Silently fail
    }

    return '';
}

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
                                <i class="fa fa-file-text"></i> Update Page Titles
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Extract page titles from HTML headings and update the database.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Scans all app/* pages for H1 or H2 heading tags</li>
                                    <li>Scans all docs/* pages for H1 or H2 heading tags</li>
                                    <li>Extracts heading text and updates database title column</li>
                                    <li>Handles missing headings gracefully (sets title to empty string)</li>
                                    <li>Provides detailed progress reporting for each page</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-info-circle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li>No database backup required - only updates page title field</li>
                                    <li>Can be run multiple times safely - updates all pages each time</li>
                                    <li>Scans page files directly from disk - requires file system access</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <a href="?start=1" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Continue - Start Title Updates
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
                        <i class="fa fa-cogs"></i> Updating Page Titles
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
                        'updated' => 0,
                        'errors' => 0,
                        'skipped' => 0
                    ];

                    try {
                        // STEP 1: Get all app/* and docs/* pages
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 1: Retrieving page list', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        $pagesQuery = $db->query(
                            "SELECT id, page FROM pages WHERE page LIKE 'app/%' OR page LIKE 'docs/%' ORDER BY page"
                        );

                        if ($pagesQuery->count() === 0) {
                            logProgress('No pages found to update', 'warning');
                        } else {
                            logProgress("Found {$pagesQuery->count()} pages to process", 'success');
                        }

                        $pagesToUpdate = $pagesQuery->results();

                        // STEP 2: Extract and update titles
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('STEP 2: Extracting titles and updating database', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');

                        foreach ($pagesToUpdate as $page) {
                            try {
                                $results['processed']++;

                                // Extract heading from file
                                $heading = extractPageHeading($page->page);

                                // Update database
                                $db->update('pages', $page->id, ['title' => $heading]);

                                if (!empty($heading)) {
                                    $results['updated']++;
                                    logProgress("✓ {$page->page} → \"{$heading}\"", 'success');
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Updated title for {$page->page}: {$heading}");
                                } else {
                                    $results['skipped']++;
                                    logProgress("→ {$page->page} (title empty)", 'info');
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Processed {$page->page} - no heading extracted");
                                }

                            } catch (Exception $e) {
                                $results['errors']++;
                                logProgress("✗ {$page->page}: " . $e->getMessage(), 'error');
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Failed to update {$page->page}: " . $e->getMessage());
                            }
                        }

                        // Log script completion
                        $db->insert('fix_script_runs', [
                            'script_name' => '22-Update-Page-Titles.php',
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                            "Page titles updated - Processed: {$results['processed']}, Updated: {$results['updated']}, Skipped: {$results['skipped']}, Errors: {$results['errors']}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('COMPLETION SUMMARY', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Total Processed: {$results['processed']}", 'success');
                        logProgress("Titles Updated: {$results['updated']}", 'success');
                        logProgress("Headings Not Found: {$results['skipped']}", 'warning');
                        if ($results['errors'] > 0) {
                            logProgress("Errors: {$results['errors']}", 'error');
                        }
                        logProgress('', 'info');
                        logProgress('Script completed successfully!', 'success');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Fatal error: ' . $e->getMessage());
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
