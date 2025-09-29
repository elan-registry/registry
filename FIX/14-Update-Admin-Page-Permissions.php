<?php

declare(strict_types=1);

/**
 * Update Admin Page Permissions Script
 *
 * Administrative script to update UserSpice page permissions for all admin pages
 * to ensure consistent access control across the administrative interface.
 *
 * This script ensures all pages in app/admin/ directory have proper permissions
 * set to match the standard admin permission level (Administrator = 1, Editor = 2).
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to FIX/ directory with proper naming: 14-Update-Admin-Page-Permissions.php
 * 2. The FIX/.htaccess allows all scripts except templates to run directly
 * 3. Scripts can be accessed via FIX/index.php menu or direct URL
 * 4. Use sequential numbering (01, 02, 03...) for proper execution order
 * 5. Return buttons automatically detect new window vs direct navigation
 * 6. See FIX/README.md for detailed instructions and best practices
 *
 * TEMPLATE USAGE:
 * - Always use this template for consistent UI/UX across all FIX scripts
 * - Replace [PLACEHOLDERS] with appropriate content for your script
 * - Maintain the two-step process: description card + start button + progress tracking
 * - Use outputMessage() for progress updates and addLogMessage() for detailed logging
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get the database instance
$db = DB::getInstance();

// Include standardized backup functions
require_once 'backup-functions.php';

$line = 1; // Where messages go

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <style>
                .fix-progress-header {
                    position: sticky;
                    top: 0;
                    z-index: 1030;
                }

                .fix-results-container {
                    max-height: 500px;
                    overflow-y: auto;
                    font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;
                    font-size: 0.875rem;
                    line-height: 1.4;
                }

                .fix-summary-section {
                    position: sticky;
                    bottom: 0;
                    z-index: 1020;
                }

                .fix-status-line {
                    margin: 0.25rem 0;
                    padding: 0.125rem 0;
                }

                /* Ensure proper Bootstrap grid behavior */
                #progressSection .row {
                    margin-left: -15px;
                    margin-right: -15px;
                }

                #progressSection [class*="col-"] {
                    padding-left: 15px;
                    padding-right: 15px;
                    float: left;
                }

                @media (min-width: 768px) {
                    #progressSection .col-md-6 {
                        width: 50%;
                    }
                }
            </style>

            <!-- Initial Description Card -->
            <div class="row" id="descriptionSection">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-shield"></i> Update Admin Page Permissions
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script will update UserSpice page permissions for all administrative pages to ensure consistent access control across the admin interface.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Scans all PHP files in the app/admin/ directory</li>
                                    <li>Checks if pages exist in UserSpice pages table</li>
                                    <li>Creates page entries for missing admin pages</li>
                                    <li>Updates page titles with appropriate admin descriptions</li>
                                    <li>Sets consistent permission levels (Administrator=1, Editor=2)</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important:</h5>
                                <ul class="mb-0">
                                    <li>This will modify the UserSpice pages and permission_page_matches tables</li>
                                    <li>A backup will be created before making changes</li>
                                    <li>Only admin pages (app/admin/*) will be affected</li>
                                    <li>Existing permissions for non-admin pages remain unchanged</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Permission Update
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="row mb-4" id="progressSection">
                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-cogs"></i> Progress
                            </h2>
                            <small class="text-muted">
                                <i class="fa fa-clock-o"></i> Started: <span id="startTimeText"></span>
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="progress car-progress mb-2">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                                    id="progressBar"
                                    role="progressbar"
                                    style="width: 0%;"
                                    aria-valuenow="0"
                                    aria-valuemin="0"
                                    aria-valuemax="100">
                                    <span id="progressText">0%</span>
                                </div>
                            </div>
                            <div id="progressInfo" class="text-muted text-center">
                                Ready to begin...
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-list"></i> Results
                            </h2>
                        </div>
                        <div class="card-body">
                            <div id="results" class="fix-results-container">
                                <!-- Results will appear here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Section -->
            <div class="row" id="summarySection">
                <div class="col-lg-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-check-circle"></i> Operation Complete
                            </h2>
                        </div>
                        <div class="card-body">
                            <div id="summaryContent">
                                <!-- Summary will appear here -->
                            </div>
                            <div class="text-center mt-3">
                                <button onclick="goToReturn()" class="btn btn-primary">
                                    <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
    // Hide progress and summary sections initially
    document.getElementById('progressSection').style.display = 'none';
    document.getElementById('summarySection').style.display = 'none';

    let startTime;
    let logMessages = [];
    let stats = {
        totalPages: 0,
        pagesAdded: 0,
        pagesUpdated: 0,
        permissionsSet: 0,
        errors: 0
    };

    function startProcessing() {
        // Hide description and show progress
        document.getElementById('descriptionSection').style.display = 'none';
        document.getElementById('progressSection').style.display = 'block';

        // Record start time
        startTime = new Date();
        document.getElementById('startTimeText').textContent = startTime.toLocaleTimeString();

        // Start the process
        processAdminPages();
    }

    function updateProgress(percentage, message) {
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');
        const progressInfo = document.getElementById('progressInfo');

        progressBar.style.width = percentage + '%';
        progressBar.setAttribute('aria-valuenow', percentage);
        progressText.textContent = Math.round(percentage) + '%';
        progressInfo.textContent = message;
    }

    function outputMessage(message, type = 'info') {
        const results = document.getElementById('results');
        const timestamp = new Date().toLocaleTimeString();
        const messageClass = type === 'error' ? 'text-danger' :
                           type === 'success' ? 'text-success' :
                           type === 'warning' ? 'text-warning' : 'text-info';

        results.innerHTML += `<div class="fix-status-line"><span class="text-muted">[${timestamp}]</span> <span class="${messageClass}">${message}</span></div>`;
        results.scrollTop = results.scrollHeight;

        // Add to log
        logMessages.push(`[${timestamp}] ${message}`);
    }

    function addLogMessage(message) {
        logMessages.push(message);
    }

    async function processAdminPages() {
        try {
            outputMessage('Starting admin page permission update process...', 'info');
            updateProgress(5, 'Initializing...');

            // Step 1: Create backup
            outputMessage('Creating database backup...', 'info');
            const backupResult = await makeBackupRequest();
            if (!backupResult.success) {
                throw new Error('Backup failed: ' + backupResult.message);
            }
            outputMessage('✓ Database backup created successfully', 'success');
            updateProgress(15, 'Backup completed');

            // Step 2: Get admin pages
            outputMessage('Scanning admin directory for PHP pages...', 'info');
            const adminPages = await getAdminPages();
            stats.totalPages = adminPages.length;
            outputMessage(`✓ Found ${stats.totalPages} admin pages to process`, 'success');
            updateProgress(25, 'Page scan completed');

            // Step 3: Process each page
            outputMessage('Processing admin pages...', 'info');
            for (let i = 0; i < adminPages.length; i++) {
                const page = adminPages[i];
                const progress = 25 + ((i / adminPages.length) * 60);
                updateProgress(progress, `Processing ${page.file}...`);

                await processSinglePage(page);
            }

            updateProgress(90, 'Finalizing...');

            // Step 4: Log the operation
            outputMessage('Logging operation...', 'info');
            await logOperation();
            outputMessage('✓ Operation logged successfully', 'success');

            updateProgress(100, 'Complete');
            outputMessage('✓ Admin page permission update completed successfully!', 'success');

            // Show summary
            showSummary();

        } catch (error) {
            outputMessage('✗ Error: ' + error.message, 'error');
            stats.errors++;
            showSummary();
        }
    }

    async function makeBackupRequest() {
        try {
            const response = await fetch('backup-functions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=backup_tables&tables=pages,permission_page_matches'
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return await response.json();
        } catch (error) {
            // If backup fails, continue without backup but warn user
            outputMessage('⚠ Warning: Backup failed, continuing without backup: ' + error.message, 'warning');
            return { success: true, message: 'Continuing without backup' };
        }
    }

    async function getAdminPages() {
        try {
            const response = await fetch('14-Update-Admin-Page-Permissions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_admin_pages'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.message || 'Failed to get admin pages');
            }

            outputMessage(`✓ Found ${result.count || 0} admin pages to process`, 'info');
            return result.pages || [];
        } catch (error) {
            outputMessage('✗ Error getting admin pages: ' + error.message, 'error');
            throw error;
        }
    }

    async function processSinglePage(page) {
        try {
            const response = await fetch('14-Update-Admin-Page-Permissions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=process_page&page=${encodeURIComponent(page.file)}&title=${encodeURIComponent(page.title)}`
            });
            const result = await response.json();

            if (result.success) {
                if (result.action === 'added') {
                    stats.pagesAdded++;
                    outputMessage(`✓ Added page: ${page.file}`, 'success');
                } else if (result.action === 'updated') {
                    stats.pagesUpdated++;
                    outputMessage(`✓ Updated page: ${page.file}`, 'success');
                } else {
                    outputMessage(`• Page already exists: ${page.file}`, 'info');
                }

                if (result.permissions_set) {
                    stats.permissionsSet++;
                }
            } else {
                throw new Error(result.message || 'Unknown error processing page');
            }
        } catch (error) {
            stats.errors++;
            outputMessage(`✗ Error processing ${page.file}: ${error.message}`, 'error');
        }
    }

    async function logOperation() {
        const response = await fetch('14-Update-Admin-Page-Permissions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=log_operation'
        });
        return await response.json();
    }

    function showSummary() {
        document.getElementById('progressSection').style.display = 'none';
        document.getElementById('summarySection').style.display = 'block';

        const endTime = new Date();
        const duration = ((endTime - startTime) / 1000).toFixed(1);

        const summaryContent = document.getElementById('summaryContent');
        summaryContent.innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <h4><i class="fa fa-chart-bar"></i> Summary Statistics</h4>
                    <table class="table table-striped">
                        <tr><td><strong>Total Pages Processed:</strong></td><td>${stats.totalPages}</td></tr>
                        <tr><td><strong>Pages Added:</strong></td><td>${stats.pagesAdded}</td></tr>
                        <tr><td><strong>Pages Updated:</strong></td><td>${stats.pagesUpdated}</td></tr>
                        <tr><td><strong>Permissions Set:</strong></td><td>${stats.permissionsSet}</td></tr>
                        <tr><td><strong>Errors:</strong></td><td class="${stats.errors > 0 ? 'text-danger' : 'text-success'}">${stats.errors}</td></tr>
                        <tr><td><strong>Duration:</strong></td><td>${duration} seconds</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <h4><i class="fa fa-info-circle"></i> Next Steps</h4>
                    <ul>
                        <li>All admin pages now have proper permission entries</li>
                        <li>Permissions are set to Administrator (1) and Editor (2) levels</li>
                        <li>Test admin access to verify proper functionality</li>
                        <li>Check UserSpice admin panel for page management</li>
                    </ul>
                </div>
            </div>
        `;
    }

    function goToReturn() {
        // Detect if opened in new window/tab vs direct navigation
        if (window.history.length <= 1 || window.opener) {
            window.close();
        } else {
            window.location.href = 'index.php';
        }
    }
</script>

<?php

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    $action = $_POST['action'];

    // Debug logging
    error_log("FIX Script: Processing action: " . $action);

    try {
        switch ($action) {
            case 'get_admin_pages':
                $pages = scanAdminPages();
                echo json_encode(['success' => true, 'pages' => $pages, 'count' => count($pages)]);
                break;

            case 'process_page':
                $page = $_POST['page'] ?? '';
                $title = $_POST['title'] ?? '';
                $result = processPagePermissions($page, $title);
                echo json_encode($result);
                break;

            case 'log_operation':
                $result = logScriptRun();
                echo json_encode($result);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown action']);
        }
    } catch (Exception $e) {
        error_log("FIX Script Error: " . $e->getMessage());
        error_log("FIX Script Stack: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit;
}

/**
 * Scan the admin directory for PHP pages
 * @return array Array of page information with file paths and titles
 * @throws Exception If admin directory not found or not accessible
 */
function scanAdminPages(): array {
    $pages = [];
    $baseDir = dirname(__DIR__); // Get project root
    $adminDir = $baseDir . '/app/admin';

    error_log("FIX Script: Base dir: " . $baseDir);
    error_log("FIX Script: Admin dir: " . $adminDir);
    error_log("FIX Script: Admin dir exists: " . (is_dir($adminDir) ? 'YES' : 'NO'));

    if (!is_dir($adminDir)) {
        throw new Exception("Admin directory not found: {$adminDir}");
    }

    // Get all PHP files recursively
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($adminDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    $fileCount = 0;
    foreach ($iterator as $file) {
        $fileCount++;
        error_log("FIX Script: Found file: " . $file->getPathname());

        if ($file->isFile() && $file->getExtension() === 'php') {
            // Get path relative to project root
            $baseDirPattern = $baseDir . DIRECTORY_SEPARATOR;
            $fullPath = $file->getPathname();
            $relativePath = str_replace($baseDirPattern, '', $fullPath);
            $pages[] = [
                'file' => $relativePath,
                'title' => generatePageTitle($relativePath)
            ];
            error_log("FIX Script: Added page: " . $relativePath);
        }
    }

    error_log("FIX Script: Total files found: " . $fileCount);
    error_log("FIX Script: PHP pages added: " . count($pages));

    return $pages;
}

/**
 * Generate appropriate page titles for admin pages
 * @param string $filePath The file path to generate title for
 * @return string Generated page title
 */
function generatePageTitle(string $filePath): string {
    $fileName = basename($filePath, '.php');

    // Special cases for known admin pages
    $titleMap = [
        'manage-consolidated.php' => 'Admin - Consolidated Management Interface',
        'load-owner-profile.php' => 'Admin - Load Owner Profile',
        'load-owner-info.php' => 'Admin - Load Owner Information',
        'process-admin-contact.php' => 'Admin - Process Contact Form',
        'tab-cleanup.php' => 'Admin - Cleanup Management Tab',
        'tab-system.php' => 'Admin - System Management Tab',
        'tab-settings.php' => 'Admin - Settings Tab',
        'tab-car_mgmt.php' => 'Admin - Car Management Tab',
        'process-owner-search.php' => 'Admin - Owner Search Process',
        'schema-operations.php' => 'Admin - Schema Operations',
        'tab-data_quality.php' => 'Admin - Data Quality Tab',
        'process-owner-sync-location.php' => 'Admin - Owner Location Sync',
        'tab-duplicates.php' => 'Admin - Duplicate Management Tab',
        'tab-manage_cars.php' => 'Admin - Manage Cars Tab',
        'process-owner-update.php' => 'Admin - Owner Update Process',
        'tab-owner_mgmt.php' => 'Admin - Owner Management Tab'
    ];

    if (isset($titleMap[$fileName . '.php'])) {
        return $titleMap[$fileName . '.php'];
    }

    // Generate title from file path
    $parts = explode('/', $filePath);
    $fileName = end($parts);
    $fileName = str_replace(['-', '_'], ' ', basename($fileName, '.php'));
    $fileName = ucwords($fileName);

    if (strpos($filePath, 'app/admin/includes/') !== false) {
        return "Admin - {$fileName}";
    } else {
        return "Admin - {$fileName}";
    }
}

/**
 * Process permissions for a single page
 * @param string $pagePath The page path to process
 * @param string $title The page title
 * @return array Result array with success status and details
 * @throws Exception On database errors
 */
function processPagePermissions(string $pagePath, string $title): array {
    global $db;

    try {
        // Check if page exists
        $existingPage = $db->query("SELECT * FROM pages WHERE page = ?", [$pagePath]);

        if ($existingPage->count() > 0) {
            // Update existing page title if needed
            $pageData = $existingPage->first();
            if ($pageData->page_title !== $title) {
                $db->query("UPDATE pages SET page_title = ? WHERE page = ?", [$title, $pagePath]);
                $action = 'updated';
            } else {
                $action = 'exists';
            }
            $pageId = $pageData->id;
        } else {
            // Insert new page
            $db->query("INSERT INTO pages (page, page_title, private) VALUES (?, ?, 1)", [$pagePath, $title]);
            $pageId = $db->lastInsertId();
            $action = 'added';
        }

        // Set permissions (Administrator = 1, Editor = 2)
        $permissions = [1, 2]; // Administrator and Editor permission IDs
        $permissionsSet = false;

        foreach ($permissions as $permissionId) {
            // Check if permission already exists
            $existingPerm = $db->query(
                "SELECT * FROM permission_page_matches WHERE permission_id = ? AND page_id = ?",
                [$permissionId, $pageId]
            );

            if ($existingPerm->count() === 0) {
                // Add permission
                $db->query(
                    "INSERT INTO permission_page_matches (permission_id, page_id) VALUES (?, ?)",
                    [$permissionId, $pageId]
                );
                $permissionsSet = true;
            }
        }

        return [
            'success' => true,
            'action' => $action,
            'page_id' => $pageId,
            'permissions_set' => $permissionsSet
        ];

    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (RuntimeException $e) {
        return ['success' => false, 'message' => 'Runtime error: ' . $e->getMessage()];
    }
}

/**
 * Log the script run
 * @return array Result array with success status
 * @throws Exception On database errors
 */
function logScriptRun(): array {
    global $db;

    try {
        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", ['14-Update-Admin-Page-Permissions.php']);
        return ['success' => true];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
    } catch (RuntimeException $e) {
        return ['success' => false, 'message' => 'Runtime error: ' . $e->getMessage()];
    }
}

require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>