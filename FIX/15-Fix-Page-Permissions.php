<?php

declare(strict_types=1);

/**
 * Fix Page Permissions Script
 *
 * Administrative script to fix UserSpice page permissions across the entire application.
 * Ensures proper access control for User, Administrator, and Editor roles.
 *
 * ACCESS RULES:
 * - Administrator: Full access (includes editor capabilities)
 * - Editor: Access to admin pages and FIX scripts
 * - User: NO access to FIX/*, app/admin/* (which includes verify)
 * - User: SHOULD have access to app/cars/* (except manage), app/contact/*, usersc/*
 *
 * WHAT THIS SCRIPT FIXES:
 * - Adds User permission to user-facing pages (app/cars/*, app/contact/*, usersc/*)
 * - Removes User permission from admin pages (app/admin/*, includes verify)
 * - Adds Administrator + Editor permissions to admin pages and FIX scripts
 * - Sets proper permissions for management pages
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Access via FIX/index.php menu or direct URL
 * 2. Script uses two-step confirmation for safety
 * 3. Automatic backup created before any changes
 * 4. All changes logged to UserSpice audit system
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../users/init.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get the database instance
$db = DB::getInstance();

// Debug: Check if database is connected
if (!$db) {
    die("Database connection failed");
}

// Permission IDs
define('PERM_USER', 1);
define('PERM_ADMIN', 2);
define('PERM_EDITOR', 3);

/**
 * Analyze current permissions and determine what needs to change
 */
function analyzePermissions($db) {
    $issues = [
        'add_user' => [],
        'remove_user' => [],
        'add_editor' => [],
        'add_admin_editor' => [],
        'set_private' => [],  // Pages that should be private but aren't
    ];

    // First, check for pages that SHOULD be private but are set to public
    $publicCheckQuery = "
        SELECT
            p.id,
            p.page,
            p.private
        FROM pages p
        WHERE p.private = 0
          AND (p.page LIKE 'app/admin/%' OR p.page LIKE 'app/verify/%' OR p.page LIKE 'FIX/%')
        ORDER BY p.page
    ";

    $publicPages = $db->query($publicCheckQuery)->results();
    foreach ($publicPages as $page) {
        $issues['set_private'][] = [
            'id' => $page->id,
            'page' => $page->page,
            'current' => 'PUBLIC',
            'required' => 'PRIVATE with Administrator, Editor',
            'action' => 'SET TO PRIVATE, ADD Administrator, Editor'
        ];
    }

    // Get all private pages in app/, usersc/, and FIX/
    $query = "
        SELECT
            p.id,
            p.page,
            GROUP_CONCAT(DISTINCT pm.permission_id ORDER BY pm.permission_id) as perm_ids,
            GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.id SEPARATOR ', ') as perm_names
        FROM pages p
        LEFT JOIN permission_page_matches pm ON p.id = pm.page_id
        LEFT JOIN permissions pr ON pm.permission_id = pr.id
        WHERE p.private = 1
          AND (p.page LIKE 'usersc/%' OR p.page LIKE 'app/%' OR p.page LIKE 'FIX/%')
        GROUP BY p.id, p.page
        ORDER BY p.page
    ";

    $pages = $db->query($query)->results();

    foreach ($pages as $page) {
        $permIds = $page->perm_ids ? explode(',', $page->perm_ids) : [];
        $hasUser = in_array(PERM_USER, $permIds);
        $hasAdmin = in_array(PERM_ADMIN, $permIds);
        $hasEditor = in_array(PERM_EDITOR, $permIds);
        $hasNoPerms = empty($permIds);

        // app/cars/* pages (except manage) - should have User permission
        if (preg_match('#^app/cars/#', $page->page) && $page->page != 'app/cars/manage.php') {
            if ($hasNoPerms) {
                $issues['add_user'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => 'NO PERMISSIONS',
                    'required' => 'User',
                    'action' => 'ADD User'
                ];
            }
        }

        // app/contact/* pages - should have User permission
        if (preg_match('#^app/contact/#', $page->page)) {
            if ($hasNoPerms) {
                $issues['add_user'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => 'NO PERMISSIONS',
                    'required' => 'User',
                    'action' => 'ADD User'
                ];
            }
        }

        // usersc/* pages - should have User permission
        if (preg_match('#^usersc/#', $page->page)) {
            if ($hasNoPerms) {
                $issues['add_user'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => 'NO PERMISSIONS',
                    'required' => 'User',
                    'action' => 'ADD User'
                ];
            }
        }

        // app/admin/* pages - should be Admin + Editor only (no User)
        if (preg_match('#^app/admin/#', $page->page)) {
            if ($hasUser) {
                $issues['remove_user'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => $page->perm_names ?: 'None',
                    'required' => 'Administrator, Editor',
                    'action' => 'REMOVE User, ADD Editor'
                ];
            }
            if (!$hasEditor) {
                $issues['add_editor'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => $page->perm_names ?: 'None',
                    'required' => 'Administrator, Editor',
                    'action' => 'ADD Editor'
                ];
            }
        }

        // app/cars/manage.php - should be Admin + Editor
        if ($page->page == 'app/cars/manage.php') {
            if ($hasNoPerms || !$hasAdmin || !$hasEditor) {
                $issues['add_admin_editor'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => $page->perm_names ?: 'NO PERMISSIONS',
                    'required' => 'Administrator, Editor',
                    'action' => 'ADD Administrator, Editor'
                ];
            }
        }

        // app/reports/data-quality.php - should be Admin + Editor
        if ($page->page == 'app/reports/data-quality.php') {
            if (!$hasEditor) {
                $issues['add_editor'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => $page->perm_names ?: 'None',
                    'required' => 'Administrator, Editor',
                    'action' => 'ADD Editor'
                ];
            }
        }

        // FIX/* pages - should be Admin + Editor
        if (preg_match('#^FIX/#', $page->page)) {
            if ($hasNoPerms || !$hasAdmin || !$hasEditor) {
                $issues['add_admin_editor'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'current' => $page->perm_names ?: 'NO PERMISSIONS',
                    'required' => 'Administrator, Editor',
                    'action' => 'ADD Administrator, Editor'
                ];
            }
        }
    }

    return $issues;
}

// Handle POST requests for AJAX - MUST be before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'analyze') {
        // STEP 1: Analysis
        logger($user->data()->id, 'PermissionFix', 'Starting permission analysis');

        try {
            $issues = analyzePermissions($db);
            $totalIssues = count($issues['add_user']) + count($issues['remove_user']) +
                          count($issues['add_editor']) + count($issues['add_admin_editor']) +
                          count($issues['set_private']);

            logger($user->data()->id, 'PermissionFix', "Analysis completed, found {$totalIssues} issues");

            echo json_encode([
                'success' => true,
                'totalIssues' => $totalIssues,
                'counts' => [
                    'add_user' => count($issues['add_user']),
                    'remove_user' => count($issues['remove_user']),
                    'add_editor' => count($issues['add_editor']),
                    'add_admin_editor' => count($issues['add_admin_editor']),
                    'set_private' => count($issues['set_private'])
                ],
                'issues' => $issues
            ]);
        } catch (Exception $e) {
            logger($user->data()->id, 'PermissionFixError', "Analysis failed: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'error' => 'Analysis failed: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    if ($_POST['action'] === 'details') {
        // STEP 2: Get detailed changes
        try {
            $issues = analyzePermissions($db);
            echo json_encode([
                'success' => true,
                'issues' => $issues
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to get details: ' . $e->getMessage()
            ]);
        }
        exit;
    }
}

// Include standardized backup functions (compatibility wrapper)
require_once $abs_us_root . $us_url_root . 'users/helpers/backup_functions.php';

$line = 1; // Where messages go

// Now load the template (this outputs HTML headers)
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

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

                .permission-table {
                    font-size: 0.85rem;
                }

                .permission-table th {
                    background-color: #f8f9fa;
                    font-weight: 600;
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
                                <i class="fa fa-shield"></i> Fix Page Permissions
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script analyzes and fixes page permissions across the entire application to ensure proper access control for User, Administrator, and Editor roles.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Analyzes all private pages in app/, usersc/, and FIX/ directories</li>
                                    <li>Adds User permission to user-facing pages (app/cars/*, app/contact/*, usersc/*)</li>
                                    <li>Removes User permission from admin pages (app/admin/*)</li>
                                    <li>Adds Editor permission to admin and management pages</li>
                                    <li>Creates automatic backup before making any changes</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Access Rules Applied:</h5>
                                <ul class="mb-0">
                                    <li><strong>User:</strong> Can access app/cars/* (except manage), app/contact/*, usersc/*</li>
                                    <li><strong>Administrator + Editor:</strong> Can access app/admin/* (includes verify), FIX/*, app/cars/manage.php, app/reports/data-quality.php</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startAnalysis()" class="btn btn-success">
                                    <i class="fa fa-search"></i> Step 1: Analyze Permissions
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analysis Results Section -->
            <div class="row" id="analysisSection" style="display: none;">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-list-alt"></i> Analysis Results
                            </h2>
                        </div>
                        <div class="card-body" id="analysisResults">
                            <div class="text-center">
                                <i class="fa fa-spinner fa-spin fa-3x"></i>
                                <p class="mt-3">Analyzing permissions...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Changes Section -->
            <div class="row" id="detailsSection" style="display: none;">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-list"></i> Detailed Changes
                            </h2>
                        </div>
                        <div class="card-body" id="detailedChanges">
                            <!-- Will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="row mb-4" id="progressSection" style="display: none;">
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
                                    aria-valuemax="100">0%</div>
                            </div>
                            <div id="currentStatus" class="text-muted small">
                                <i class="fa fa-cog fa-spin"></i> Initializing...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-bar-chart"></i> Summary
                            </h2>
                        </div>
                        <div class="card-body" id="summaryContent">
                            <div class="text-muted">
                                <em>Waiting for process to complete...</em>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Progress Log Section -->
            <div class="row mb-4" id="logSection" style="display: none;">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fa fa-list-alt"></i> Progress Log
                            </h3>
                        </div>
                        <div class="card-body fix-results-container" id="resultsContainer">
                            <div class="fix-status-line text-muted">
                                <small><em>Initializing process...</em></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let analysisData = null;
                let processStarted = false;

                function updateProgress(current, total, statusMessage) {
                    if (total === 0) return;

                    const percentage = Math.round((current / total) * 100);
                    const progressBar = document.getElementById('progressBar');

                    progressBar.style.width = percentage + '%';
                    progressBar.setAttribute('aria-valuenow', percentage);
                    progressBar.textContent = percentage + '%';

                    if (statusMessage) {
                        const statusElement = document.getElementById('currentStatus');
                        const statusIcon = percentage >= 100 ?
                            '<i class="fa fa-check-circle text-success"></i>' :
                            '<i class="fa fa-cog fa-spin"></i>';

                        statusElement.innerHTML = statusIcon + ' ' + statusMessage;
                    }
                }

                function showCompletionSummary(stats) {
                    updateProgress(100, 100, 'Permission fix completed successfully!');

                    const summaryContent = document.getElementById('summaryContent');
                    summaryContent.innerHTML = `
        <div class="mb-3">
            <h5><i class="fa fa-check-circle text-success"></i> Complete!</h5>
            <small class="text-muted">Completed at: ${new Date().toLocaleString()}</small>
        </div>
        <div class="mb-3">
            ${stats}
        </div>
        <div class="text-center">
            <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='index.php';}" class="btn btn-outline-primary">
                <i class="fa fa-arrow-left"></i> Return to FIX Menu
            </button>
        </div>
    `;
                }

                function addLogMessage(message) {
                    const container = document.getElementById('resultsContainer');
                    if (!container) return;

                    const line = document.createElement('div');
                    line.className = 'fix-status-line';

                    if (message.includes('✅')) {
                        line.className += ' text-success';
                    } else if (message.includes('✗') || message.includes('❌')) {
                        line.className += ' text-danger';
                    } else if (message.includes('===')) {
                        line.className += ' text-info font-weight-bold';
                    } else if (message.includes('Processing')) {
                        line.className += ' text-primary';
                    }

                    line.innerHTML = message;
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                }

                function startAnalysis() {
                    // Hide description, show analysis section
                    document.getElementById('descriptionSection').style.display = 'none';
                    document.getElementById('analysisSection').style.display = 'block';

                    // Fetch analysis data via AJAX
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=analyze'
                    })
                    .then(response => response.json())
                    .then(data => {
                        const resultsElement = document.getElementById('analysisResults');

                        if (data.error) {
                            resultsElement.innerHTML = `
                                <div class="alert alert-danger">
                                    <h4><i class="fa fa-exclamation-circle"></i> Analysis Error</h4>
                                    <p>${data.error}</p>
                                </div>
                                <div class="text-center">
                                    <button onclick="window.location.href='index.php';" class="btn btn-primary">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            `;
                            return;
                        }

                        if (data.totalIssues === 0) {
                            resultsElement.innerHTML = `
                                <div class="alert alert-success">
                                    <h4><i class="fa fa-check-circle"></i> No Issues Found!</h4>
                                    <p>All page permissions are correctly configured.</p>
                                </div>
                                <div class="text-center">
                                    <button onclick="window.location.href='index.php';" class="btn btn-primary">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            `;
                        } else {
                            analysisData = data.issues; // Store for later use
                            resultsElement.innerHTML = `
                                <div class="alert alert-warning">
                                    <h4><i class="fa fa-exclamation-triangle"></i> Issues Found</h4>
                                    <p>Found <strong>${data.totalIssues}</strong> permission issues that need to be fixed:</p>
                                    <ul>
                                        ${data.counts.set_private > 0 ? `<li>${data.counts.set_private} pages are PUBLIC but should be PRIVATE</li>` : ''}
                                        <li>${data.counts.add_user} pages need User permission added</li>
                                        <li>${data.counts.remove_user} pages need User permission removed</li>
                                        <li>${data.counts.add_editor} pages need Editor permission added</li>
                                        <li>${data.counts.add_admin_editor} pages need Administrator + Editor permissions</li>
                                    </ul>
                                </div>
                                <div class="text-center">
                                    <button onclick="showDetailedChanges()" class="btn btn-success">
                                        <i class="fa fa-arrow-right"></i> Step 2: Review Detailed Changes
                                    </button>
                                    <button onclick="abortProcess()" class="btn btn-danger ml-2">
                                        <i class="fa fa-times"></i> Abort
                                    </button>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        document.getElementById('analysisResults').innerHTML = `
                            <div class="alert alert-danger">
                                <h4><i class="fa fa-exclamation-circle"></i> Error</h4>
                                <p>Failed to fetch analysis: ${error.message}</p>
                            </div>
                        `;
                    });
                }

                function showDetailedChanges() {
                    // Show details section below analysis
                    document.getElementById('detailsSection').style.display = 'block';
                    document.getElementById('detailedChanges').innerHTML = '<div class="text-center"><i class="fa fa-spinner fa-spin fa-3x"></i><p class="mt-3">Loading details...</p></div>';

                    // Fetch detailed changes
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=details'
                    })
                    .then(response => response.json())
                    .then(data => {
                        let detailsHTML = '<div class="mb-3"><h5>Detailed Changes to be Made:</h5></div>';

                        // Set pages to private
                        if (data.issues.set_private && data.issues.set_private.length > 0) {
                            detailsHTML += `<h6 class="text-danger">⚠️ Set to Private + Add Permissions (${data.issues.set_private.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.set_private.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-danger">PUBLIC</span></td><td><span class="badge badge-primary">PRIVATE - Administrator, Editor</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Add User permissions
                        if (data.issues.add_user && data.issues.add_user.length > 0) {
                            detailsHTML += `<h6 class="text-success">✅ Add User Permission (${data.issues.add_user.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.add_user.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-success">User</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Remove User, Add Editor
                        if (data.issues.remove_user && data.issues.remove_user.length > 0) {
                            detailsHTML += `<h6 class="text-warning">⚠️ Remove User, Add Editor (${data.issues.remove_user.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.remove_user.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-primary">Administrator, Editor</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Add Editor
                        if (data.issues.add_editor && data.issues.add_editor.length > 0) {
                            detailsHTML += `<h6 class="text-info">ℹ️ Add Editor Permission (${data.issues.add_editor.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.add_editor.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-primary">${issue.required}</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Add Admin + Editor
                        if (data.issues.add_admin_editor && data.issues.add_admin_editor.length > 0) {
                            detailsHTML += `<h6 class="text-primary">✅ Add Administrator + Editor (${data.issues.add_admin_editor.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.add_admin_editor.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-primary">Administrator, Editor</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        detailsHTML += `
                            <div class="alert alert-info mt-4">
                                <h5><i class="fa fa-info-circle"></i> Next Steps:</h5>
                                <ul class="mb-0">
                                    <li>A backup will be created automatically before making changes</li>
                                    <li>All changes will be logged to the UserSpice audit system</li>
                                    <li>You can review the progress log as changes are made</li>
                                </ul>
                            </div>
                            <div class="text-center mt-4">
                                <button onclick="startProcessing()" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Proceed with Fix
                                </button>
                                <button onclick="abortProcess()" class="btn btn-danger btn-lg ml-2">
                                    <i class="fa fa-times"></i> Abort
                                </button>
                            </div>
                        `;

                        document.getElementById('detailedChanges').innerHTML = detailsHTML;
                    })
                    .catch(error => {
                        document.getElementById('detailedChanges').innerHTML = `
                            <div class="alert alert-danger">
                                <h4><i class="fa fa-exclamation-circle"></i> Error</h4>
                                <p>Failed to load details: ${error.message}</p>
                            </div>
                        `;
                    });
                }

                function startProcessing() {
                    if (processStarted) return;
                    processStarted = true;

                    // Show progress sections below
                    document.getElementById('progressSection').style.display = 'block';
                    document.getElementById('logSection').style.display = 'block';

                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();

                    // Clear the log container
                    document.getElementById('resultsContainer').innerHTML = '';

                    // Start execution via iframe to handle streaming output
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.src = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'execute=1';
                    document.body.appendChild(iframe);
                }

                function abortProcess() {
                    if (confirm('Are you sure you want to abort? No changes will be made.')) {
                        window.location.href = 'index.php';
                    }
                }
            </script>

            <?php
            // STEP 3: Execute Changes
            if (isset($_GET['execute']) && $_GET['execute'] == '1') {

                $global_attempts = 0;
                $global_successes = 0;

                function outputMessage($lineNum, $message, $percentage = null) {
                    // If running in iframe, call parent window functions
                    echo '<script>
                        if (window.parent && window.parent.addLogMessage) {
                            window.parent.addLogMessage("' . addslashes($message) . '");
                        } else if (window.addLogMessage) {
                            addLogMessage("' . addslashes($message) . '");
                        }
                    </script>';
                    if ($percentage !== null) {
                        echo '<script>
                            if (window.parent && window.parent.updateProgress) {
                                window.parent.updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");
                            } else if (window.updateProgress) {
                                updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");
                            }
                        </script>';
                    }
                    ob_flush();
                    flush();
                }

                // SAFETY: Create automatic backup
                outputMessage($line++, "⚠️  SAFETY NOTICE: Creating automatic backup...");
                try {
                    $cleanupSummary = cleanupOldBackups();
                    outputMessage($line++, "🧹 Cleaned up old backups: {$cleanupSummary['automated']['deleted']} automated, {$cleanupSummary['manual']['deleted']} manual, {$cleanupSummary['rollback']['deleted']} rollback");

                    $backupPath = createStandardizedBackup(
                        'fix-page-permissions',
                        ['pages', 'permission_page_matches'],
                        'automated',
                        'development'
                    );
                    outputMessage($line++, "✅ Backup created: " . basename($backupPath));
                } catch (Exception $e) {
                    outputMessage($line++, "❌ Backup creation failed: " . $e->getMessage());
                    outputMessage($line++, "⚠️  Aborting - backup is required for this operation!");
                    exit;
                }
                outputMessage($line++, "");

                // Get issues to fix
                outputMessage($line++, "🔍 Analyzing permissions...");
                $issues = analyzePermissions($db);

                $totalChanges = count($issues['add_user']) + count($issues['remove_user']) +
                               count($issues['add_editor']) + count($issues['add_admin_editor']) +
                               count($issues['set_private']);

                outputMessage($line++, "Found {$totalChanges} changes to make");
                outputMessage($line++, "");
                outputMessage($line++, "🚀 Starting permission fixes...");

                try {
                    $currentChange = 0;

                    // Set pages to private and add proper permissions
                    if (!empty($issues['set_private'])) {
                        outputMessage($line++, "");
                        outputMessage($line++, "=== Setting Pages to Private ===");
                        foreach ($issues['set_private'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Set page to private
                                $db->update('pages', $issue['id'], ['private' => 1]);

                                // Add Administrator permission if not exists
                                $existingAdmin = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_ADMIN])->count();
                                if ($existingAdmin == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_ADMIN,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                // Add Editor permission if not exists
                                $existingEditor = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_EDITOR])->count();
                                if ($existingEditor == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_EDITOR,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                $global_successes++;
                                outputMessage($line++, "✅ Set to PRIVATE with Administrator + Editor: {$issue['page']}", $percentage);
                                logger($user->data()->id, 'PermissionFix', "Set page to PRIVATE with Admin+Editor: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Add User permissions
                    if (!empty($issues['add_user'])) {
                        outputMessage($line++, "");
                        outputMessage($line++, "=== Adding User Permission ===");
                        foreach ($issues['add_user'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Add User permission
                                $db->insert('permission_page_matches', [
                                    'permission_id' => PERM_USER,
                                    'page_id' => $issue['id']
                                ]);

                                $global_successes++;
                                outputMessage($line++, "✅ Added User permission to: {$issue['page']}", $percentage);
                                logger($user->data()->id, 'PermissionFix', "Added User permission to page: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Remove User, Add Editor for admin pages
                    if (!empty($issues['remove_user'])) {
                        outputMessage($line++, "");
                        outputMessage($line++, "=== Fixing Admin Page Permissions ===");
                        foreach ($issues['remove_user'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Remove User permission
                                $db->query("DELETE FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_USER]);

                                // Add Editor permission if not exists
                                $existing = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_EDITOR])->count();
                                if ($existing == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_EDITOR,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                $global_successes++;
                                outputMessage($line++, "✅ Fixed permissions for: {$issue['page']}", $percentage);
                                logger($user->data()->id, 'PermissionFix', "Removed User, ensured Admin+Editor for: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Add Editor permission
                    if (!empty($issues['add_editor'])) {
                        outputMessage($line++, "");
                        outputMessage($line++, "=== Adding Editor Permission ===");
                        foreach ($issues['add_editor'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Check if Editor permission already exists
                                $existing = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_EDITOR])->count();

                                if ($existing == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_EDITOR,
                                        'page_id' => $issue['id']
                                    ]);
                                    $global_successes++;
                                    outputMessage($line++, "✅ Added Editor permission to: {$issue['page']}", $percentage);
                                    logger($user->data()->id, 'PermissionFix', "Added Editor permission to: {$issue['page']} (ID: {$issue['id']})");
                                } else {
                                    $global_successes++;
                                    outputMessage($line++, "✓ Editor permission already exists for: {$issue['page']}", $percentage);
                                }
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Add Administrator + Editor
                    if (!empty($issues['add_admin_editor'])) {
                        outputMessage($line++, "");
                        outputMessage($line++, "=== Adding Administrator + Editor Permissions ===");
                        foreach ($issues['add_admin_editor'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Add Administrator permission if not exists
                                $existingAdmin = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_ADMIN])->count();
                                if ($existingAdmin == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_ADMIN,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                // Add Editor permission if not exists
                                $existingEditor = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_EDITOR])->count();
                                if ($existingEditor == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_EDITOR,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                $global_successes++;
                                outputMessage($line++, "✅ Added Administrator + Editor to: {$issue['page']}", $percentage);
                                logger($user->data()->id, 'PermissionFix', "Added Administrator+Editor to: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    outputMessage($line++, "");
                    outputMessage($line++, "✅ Permission fixes completed successfully!");

                    // Verification
                    outputMessage($line++, "");
                    outputMessage($line++, "🔍 Verifying results...");
                    $issuesAfter = analyzePermissions($db);
                    $remainingIssues = count($issuesAfter['add_user']) + count($issuesAfter['remove_user']) +
                                      count($issuesAfter['add_editor']) + count($issuesAfter['add_admin_editor']);

                    if ($remainingIssues == 0) {
                        outputMessage($line++, "✅ SUCCESS: All permissions fixed correctly!");
                    } else {
                        outputMessage($line++, "⚠️  WARNING: {$remainingIssues} issues still remain - may need manual review");
                    }

                    // Log completion
                    logger($user->data()->id, 'DatabaseMaintenance', "Permission fix completed - Fixed: {$global_successes}/{$global_attempts} pages");

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage($line++, "✅ Script completion recorded");
                    } catch (Exception $record_e) {
                        outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                    }

                } catch (Exception $e) {
                    outputMessage($line++, "❌ ERROR during processing: " . $e->getMessage());
                    outputMessage($line++, "You can restore from backup if needed");
                    logger($user->data()->id, 'DatabaseError', "Permission fix failed: " . $e->getMessage());
                }

                outputMessage($line++, "");
                outputMessage($line++, "Script completed at " . date("h:i:sa"));

                // Calculate final stats
                $completionPercentage = $global_attempts > 0 ? round(($global_successes / $global_attempts) * 100) : 100;

                $rateColor = '#dc3545';
                $rateIcon = 'exclamation-circle';
                if ($completionPercentage >= 80) {
                    $rateColor = '#28a745';
                    $rateIcon = 'check-circle';
                } elseif ($completionPercentage >= 50) {
                    $rateColor = '#ffc107';
                    $rateIcon = 'exclamation-triangle';
                }

                echo "<script>
    // Call completion summary in parent window if in iframe
    if (window.parent && window.parent.showCompletionSummary) {
        window.parent.showCompletionSummary(`
            <div class='row'>
                <div class='col-sm-6'><strong>Pages Fixed:</strong> $global_successes/$global_attempts</div>
                <div class='col-sm-6'><strong>Success Rate:</strong>
                    <span style='color: $rateColor; font-weight: bold;'>
                        <i class='fa fa-$rateIcon'></i> $completionPercentage%
                    </span>
                </div>
            </div>
        `);
    } else if (window.showCompletionSummary) {
        showCompletionSummary(`
            <div class='row'>
                <div class='col-sm-6'><strong>Pages Fixed:</strong> $global_successes/$global_attempts</div>
                <div class='col-sm-6'><strong>Success Rate:</strong>
                    <span style='color: $rateColor; font-weight: bold;'>
                        <i class='fa fa-$rateIcon'></i> $completionPercentage%
                    </span>
                </div>
            </div>
        `);
    }
    </script>";
            }

            ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return buttons -->
<div style="margin-top: 20px; text-align: center;">
    <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='../app/admin/manage-consolidated.php?tab=system';}" class="btn btn-outline-primary">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> Return to Admin Console
    </button>
    <button onclick="window.location.href='index.php';" class="btn btn-outline-secondary ml-2">
        <i class="fa fa-list" aria-hidden="true"></i> FIX Menu
    </button>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; ?>
