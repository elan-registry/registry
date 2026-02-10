<?php

declare(strict_types=1);

/**
 * Fix Page Permissions Script
 *
 * Administrative script to fix page permissions and private flag across the entire application.
 * Uses pattern-based rules to set pages as PUBLIC (accessible to all) or PRIVATE (restricted).
 *
 * NOTE: Page title updates have been moved to FIX/22-Update-Page-Titles.php
 * Run FIX/22 after FIX/21 to update page titles from H1/H2 tags.
 *
 * PERMISSION ARCHITECTURE:
 * - PUBLIC pages (private = 0): No permission entries required, accessible to all users
 * - PRIVATE-ADMIN pages (private = 1): Must have Administrator (ID=2) and Editor (ID=3) permissions
 * - PRIVATE-OWNER pages (private = 1): Must have User (ID=1) permission
 * - SPECIAL PRIVATE pages: PRIVATE with NO permissions (listed below)
 *
 * ADMIN-ONLY PAGES (will be set to private=1 with Admin+Editor permissions):
 * - FIX/* - All FIX maintenance scripts
 * - *admin* - Any path containing "admin" (includes app/admin/*, app/verify/*, etc.)
 * - docs/*admin* - Admin documentation pages
 *
 * OWNER/USER PRIVATE PAGES (will be set to private=1 with User permission):
 * - app/cars/actions* - All car action endpoints
 * - app/contact/* - All contact form pages
 * - *edit* - Any path containing "edit" (that doesn't also contain "admin")
 * - usersc/* - All UserSpice customization pages (EXCEPT special cases below)
 *
 * SPECIAL CASE PRIVATE PAGES (will be set to private=1 with NO permissions):
 * - usersc/join.php - Registration page (public access, no permissions needed)
 * - usersc/login.php - Login page (public access, no permissions needed)
 * - usersc/user_settings.php - User settings page (no permissions set)
 *
 * PUBLIC PAGES (will be set to private=0 with no permissions):
 * - Everything else in app/* that doesn't match PRIVATE patterns
 * - Error pages in root: 404.php, 403.php, etc.
 * - docs/* (except docs/*admin*) - Documentation pages
 * - Examples: app/cars/index.php, app/cars/details.php, app/reports/statistics.php, docs/faq/index.php
 *
 * EXCLUDED (not modified):
 * - users/* - Core UserSpice framework files
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
 * Check if a page should be PRIVATE with NO permissions (special case)
 */
function shouldBePrivateNoPermissions($pagePath) {
    $specialPages = [
        'usersc/join.php',
        'usersc/login.php',
        'usersc/user_settings.php'
    ];
    return in_array($pagePath, $specialPages);
}

/**
 * Check if a page is ADMIN-ONLY (should have Admin+Editor permissions)
 */
function shouldHaveAdminPermissions($pagePath) {
    // FIX scripts are admin-only
    if (strpos($pagePath, 'FIX/') === 0) {
        return true;
    }

    // Any page containing "admin" is admin-only (includes docs/faq/admin/*)
    if (strpos($pagePath, 'admin') !== false) {
        return true;
    }

    return false;
}

/**
 * Determine if a page should be PRIVATE based on pattern matching
 */
function shouldBePrivate($pagePath) {
    // Error pages (404.php, 403.php, etc.) in root should be PUBLIC
    if (preg_match('#^40\d\.php$#', $pagePath)) {
        return false;
    }

    // docs/faq/* pages should generally be PUBLIC
    // EXCEPT docs/faq/admin/* which should be PRIVATE-ADMIN
    if (strpos($pagePath, 'docs/') === 0) {
        // docs/*admin* should be PRIVATE
        if (strpos($pagePath, 'admin') !== false) {
            return true;
        }
        // Other docs pages should be PUBLIC
        return false;
    }

    $patterns = [
        '#^FIX/#',                           // FIX/* scripts
        '#^app/admin/#',                     // app/admin/* pages
        '#admin#',                           // Any path containing "admin"
        '#^app/cars/actions#',               // app/cars/actions* endpoints
        '#^app/contact/#',                   // app/contact/* pages
        '#edit#',                            // Any path containing "edit"
        '#^usersc/#'                         // usersc/* pages
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $pagePath)) {
            return true;
        }
    }

    return false;
}

/**
 * Analyze current permissions and determine what needs to change
 */
function analyzePermissions($db) {
    $issues = [
        'set_public' => [],                    // Pages that should be public but are private
        'set_private_admin' => [],             // Pages that should be private-admin but are public
        'set_private_user' => [],              // Pages that should be private-user but are public
        'set_private_no_perms' => [],          // Pages that should be private with NO permissions
        'remove_perms' => [],                  // Public pages that have permissions
        'add_perms_admin' => [],               // Private-admin pages missing Admin+Editor permissions
        'add_perms_user' => [],                // Private-user pages missing User permission
    ];

    // Get all pages (excluding users/* only)
    $query = "
        SELECT
            p.id,
            p.page,
            p.private,
            GROUP_CONCAT(DISTINCT pm.permission_id ORDER BY pm.permission_id) as perm_ids,
            GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.id SEPARATOR ', ') as perm_names
        FROM pages p
        LEFT JOIN permission_page_matches pm ON p.id = pm.page_id
        LEFT JOIN permissions pr ON pm.permission_id = pr.id
        WHERE p.page LIKE 'app/%'
           OR p.page LIKE 'FIX/%'
           OR p.page LIKE 'usersc/%'
           OR p.page LIKE 'docs/%'
           OR p.page LIKE '40%.php'
        GROUP BY p.id, p.page, p.private
        ORDER BY p.page
    ";

    $pages = $db->query($query)->results();

    foreach ($pages as $page) {
        $permIds = $page->perm_ids ? explode(',', $page->perm_ids) : [];
        $hasPerms = !empty($permIds);
        $hasAdmin = in_array(PERM_ADMIN, $permIds);
        $hasEditor = in_array(PERM_EDITOR, $permIds);
        $hasUser = in_array(PERM_USER, $permIds);
        $shouldBePrivateNoPermsFlag = shouldBePrivateNoPermissions($page->page);
        $shouldBePrivateFlag = shouldBePrivate($page->page);
        $isAdminPage = shouldHaveAdminPermissions($page->page);

        $pageTitle = ''; // Title updates moved to FIX/22-Update-Page-Titles.php

        // Handle special case: pages that should be PRIVATE with NO permissions
        if ($shouldBePrivateNoPermsFlag) {
            if ($page->private == 0 || $hasPerms) {
                // Either not private, or has permissions (both need fixing)
                $issues['set_private_no_perms'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'title' => $pageTitle,
                    'current' => ($page->private == 0 ? 'PUBLIC' : 'PRIVATE') . ($hasPerms ? ' with permissions' : ''),
                    'required' => 'PRIVATE with no permissions',
                    'action' => 'SET TO PRIVATE, REMOVE all permissions'
                ];
            }
        } elseif ($shouldBePrivateFlag) {
            if ($isAdminPage) {
                // Page SHOULD be PRIVATE with Admin+Editor permissions
                if ($page->private == 0) {
                    // Currently public, but should be private
                    $issues['set_private_admin'][] = [
                        'id' => $page->id,
                        'page' => $page->page,
                        'title' => $pageTitle,
                        'current' => 'PUBLIC',
                        'required' => 'PRIVATE with Administrator, Editor',
                        'action' => 'SET TO PRIVATE, ADD Administrator, Editor'
                    ];
                } elseif (!$hasAdmin || !$hasEditor) {
                    // Already private, but missing permissions
                    $issues['add_perms_admin'][] = [
                        'id' => $page->id,
                        'page' => $page->page,
                        'title' => $pageTitle,
                        'current' => 'PRIVATE (' . ($page->perm_names ?: 'no permissions') . ')',
                        'missing' => (!$hasAdmin && !$hasEditor) ? 'Administrator, Editor' :
                                    (!$hasAdmin ? 'Administrator' : 'Editor'),
                        'action' => 'ADD ' . ((!$hasAdmin && !$hasEditor) ? 'Administrator, Editor' :
                                             (!$hasAdmin ? 'Administrator' : 'Editor'))
                    ];
                }
            } else {
                // Page SHOULD be PRIVATE with User permission
                if ($page->private == 0) {
                    // Currently public, but should be private
                    $issues['set_private_user'][] = [
                        'id' => $page->id,
                        'page' => $page->page,
                        'title' => $pageTitle,
                        'current' => 'PUBLIC',
                        'required' => 'PRIVATE with User',
                        'action' => 'SET TO PRIVATE, ADD User'
                    ];
                } elseif (!$hasUser) {
                    // Already private, but missing User permission
                    $issues['add_perms_user'][] = [
                        'id' => $page->id,
                        'page' => $page->page,
                        'title' => $pageTitle,
                        'current' => 'PRIVATE (' . ($page->perm_names ?: 'no permissions') . ')',
                        'required' => 'User',
                        'action' => 'ADD User'
                    ];
                }
            }
        } else {
            // Page SHOULD be PUBLIC
            if ($page->private == 1) {
                // Currently private, but should be public
                $issues['set_public'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'title' => $pageTitle,
                    'current' => 'PRIVATE',
                    'required' => 'PUBLIC with no permissions',
                    'action' => 'SET TO PUBLIC, REMOVE all permissions'
                ];
            } elseif ($hasPerms) {
                // Currently public, but has permissions (should have none)
                $issues['remove_perms'][] = [
                    'id' => $page->id,
                    'page' => $page->page,
                    'title' => $pageTitle,
                    'current' => 'PUBLIC with permissions',
                    'perms' => $page->perm_names ?: 'unknown',
                    'action' => 'REMOVE all permissions'
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
        logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, 'Starting permission analysis');

        try {
            $issues = analyzePermissions($db);
            $totalIssues = count($issues['set_public']) + count($issues['set_private_admin']) +
                          count($issues['set_private_user']) + count($issues['set_private_no_perms']) +
                          count($issues['remove_perms']) + count($issues['add_perms_admin']) +
                          count($issues['add_perms_user']);

            logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Analysis completed, found {$totalIssues} issues");

            echo json_encode([
                'success' => true,
                'totalIssues' => $totalIssues,
                'counts' => [
                    'set_public' => count($issues['set_public']),
                    'set_private_admin' => count($issues['set_private_admin']),
                    'set_private_user' => count($issues['set_private_user']),
                    'set_private_no_perms' => count($issues['set_private_no_perms']),
                    'remove_perms' => count($issues['remove_perms']),
                    'add_perms_admin' => count($issues['add_perms_admin']),
                    'add_perms_user' => count($issues['add_perms_user'])
                ],
                'issues' => $issues
            ]);
        } catch (Exception $e) {
            logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX_ERROR, "Analysis failed: " . $e->getMessage());
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

// Include standardized backup functions
require_once 'backup-functions.php';

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
                                <i class="fa fa-shield"></i> Fix Page Permissions & Titles
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script analyzes and fixes page permissions, privacy settings, and page titles across the entire application to ensure proper access control and accurate page information.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Sets pages as PUBLIC (private=0) by default with no permissions</li>
                                    <li>Marks pages as PRIVATE (private=1) based on pattern matching</li>
                                    <li>Ensures PRIVATE pages have Administrator and Editor permissions</li>
                                    <li>Removes all permissions from PUBLIC pages</li>
                                    <li>Creates automatic backup before making any changes</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> PRIVATE Page Patterns (will be private=1 with Admin+Editor):</h5>
                                <ul class="mb-0">
                                    <li><strong>FIX/*</strong> - All FIX maintenance scripts</li>
                                    <li><strong>app/admin/*</strong> - All admin pages</li>
                                    <li><strong>*admin*</strong> - Any path containing "admin"</li>
                                    <li><strong>app/cars/actions*</strong> - All car action endpoints</li>
                                    <li><strong>app/contact/*</strong> - All contact form pages</li>
                                    <li><strong>*edit*</strong> - Any path containing "edit"</li>
                                    <li><strong>usersc/*</strong> - All UserSpice customization pages</li>
                                </ul>
                            </div>

                            <div class="alert alert-secondary">
                                <h5><i class="fa fa-info-circle"></i> PUBLIC Pages (will be private=0 with no permissions):</h5>
                                <p class="mb-2">All other pages in app/*, including:</p>
                                <ul class="mb-0">
                                    <li><strong>app/cars/index.php</strong> - Car listing</li>
                                    <li><strong>app/cars/details.php</strong> - Car details</li>
                                    <li><strong>app/reports/statistics.php</strong> - Public statistics</li>
                                    <li><strong>app/privacy.php</strong> - Privacy policy</li>
                                    <li>Other public-facing pages</li>
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
                                    <h4><i class="fa fa-check-circle"></i> No Permission Issues Found!</h4>
                                    <p>All page permissions are correctly configured.</p>
                                </div>
                                <div class="text-center">
                                    <button onclick="window.location.href='index.php';" class="btn btn-outline-primary">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            `;
                        } else {
                            analysisData = data.issues; // Store for later use
                            let listHTML = `
                                <div class="alert alert-warning">
                                    <h4><i class="fa fa-exclamation-triangle"></i> Issues Found</h4>
                                    <p>Found <strong>${data.totalIssues}</strong> permission issues that need to be fixed:</p>
                                    <ul>
                                        ${data.counts.set_public > 0 ? `<li>${data.counts.set_public} pages are PRIVATE but should be PUBLIC</li>` : ''}
                                        ${data.counts.set_private_admin > 0 ? `<li>${data.counts.set_private_admin} pages are PUBLIC but should be PRIVATE (admin)</li>` : ''}
                                        ${data.counts.set_private_user > 0 ? `<li>${data.counts.set_private_user} pages are PUBLIC but should be PRIVATE (owner)</li>` : ''}
                                        ${data.counts.set_private_no_perms > 0 ? `<li>${data.counts.set_private_no_perms} pages should be PRIVATE with no permissions</li>` : ''}
                                        ${data.counts.remove_perms > 0 ? `<li>${data.counts.remove_perms} PUBLIC pages have permissions to remove</li>` : ''}
                                        ${data.counts.add_perms_admin > 0 ? `<li>${data.counts.add_perms_admin} PRIVATE (admin) pages need Administrator + Editor permissions</li>` : ''}
                                        ${data.counts.add_perms_user > 0 ? `<li>${data.counts.add_perms_user} PRIVATE (owner) pages need User permission</li>` : ''}
                                    </ul>
                                </div>
                            `;

                            // Helper function to format page display
                            const formatPageItem = (issue) => {
                                if (issue.title) {
                                    return `<div><strong>${issue.title}</strong><br/><small style="color: #666;">${issue.page}</small></div>`;
                                } else {
                                    return `<div>${issue.page}</div>`;
                                }
                            };

                            // Set pages to public
                            if (data.issues.set_public && data.issues.set_public.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-success">✅ Set to Public (${data.issues.set_public.length}): PRIVATE but should be PUBLIC</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.set_public.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Set pages to private (admin)
                            if (data.issues.set_private_admin && data.issues.set_private_admin.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-danger">⚠️ Set to Private - Admin (${data.issues.set_private_admin.length}): PUBLIC but should be PRIVATE</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.set_private_admin.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Set pages to private (owner)
                            if (data.issues.set_private_user && data.issues.set_private_user.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-danger">⚠️ Set to Private - Owner (${data.issues.set_private_user.length}): PUBLIC but should be PRIVATE</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.set_private_user.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Set pages to private with no permissions
                            if (data.issues.set_private_no_perms && data.issues.set_private_no_perms.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-info">ℹ️ Set to Private, No Permissions (${data.issues.set_private_no_perms.length}): Special case pages</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.set_private_no_perms.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Remove permissions
                            if (data.issues.remove_perms && data.issues.remove_perms.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-warning">⚠️ Remove Permissions (${data.issues.remove_perms.length}): PUBLIC pages with permissions</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.remove_perms.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Add permissions (admin)
                            if (data.issues.add_perms_admin && data.issues.add_perms_admin.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-primary">ℹ️ Add Admin Permissions (${data.issues.add_perms_admin.length}): need Administrator + Editor</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.add_perms_admin.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            // Add permissions (owner)
                            if (data.issues.add_perms_user && data.issues.add_perms_user.length > 0) {
                                listHTML += `<div class="mt-4"><h6 class="text-primary">ℹ️ Add Owner Permissions (${data.issues.add_perms_user.length}): need User permission</h6>`;
                                listHTML += '<div style="background-color: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 0.85rem;">';
                                data.issues.add_perms_user.forEach(issue => {
                                    listHTML += formatPageItem(issue);
                                });
                                listHTML += '</div></div>';
                            }

                            listHTML += `
                                <div class="text-center mt-4">
                                    <button onclick="showDetailedChanges()" class="btn btn-success">
                                        <i class="fa fa-arrow-right"></i> Step 2: Review Detailed Changes
                                    </button>
                                    <button onclick="abortProcess()" class="btn btn-danger ml-2">
                                        <i class="fa fa-times"></i> Abort
                                    </button>
                                </div>
                            `;
                            resultsElement.innerHTML = listHTML;
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

                        // Set pages to public
                        if (data.issues.set_public && data.issues.set_public.length > 0) {
                            detailsHTML += `<h6 class="text-success">✅ Set to Public (${data.issues.set_public.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.set_public.forEach(issue => {
                                detailsHTML += `<tr><td>${issue.page}</td><td><span class="badge badge-primary">PRIVATE</span></td><td><span class="badge badge-success">PUBLIC - No Permissions</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Helper function to format page name with title
                        const formatPageName = (issue) => {
                            if (issue.title) {
                                return `<strong>${issue.title}</strong><br/><small style="color: #666;">${issue.page}</small>`;
                            } else {
                                return issue.page;
                            }
                        };

                        // Set pages to private (admin)
                        if (data.issues.set_private_admin && data.issues.set_private_admin.length > 0) {
                            detailsHTML += `<h6 class="text-danger">⚠️ Set to Private - Admin (${data.issues.set_private_admin.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.set_private_admin.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-danger">PUBLIC</span></td><td><span class="badge badge-primary">PRIVATE - Administrator, Editor</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Set pages to private (owner)
                        if (data.issues.set_private_user && data.issues.set_private_user.length > 0) {
                            detailsHTML += `<h6 class="text-danger">⚠️ Set to Private - Owner (${data.issues.set_private_user.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.set_private_user.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-danger">PUBLIC</span></td><td><span class="badge badge-primary">PRIVATE - User</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Set pages to private with no permissions
                        if (data.issues.set_private_no_perms && data.issues.set_private_no_perms.length > 0) {
                            detailsHTML += `<h6 class="text-info">ℹ️ Set to Private, No Permissions (${data.issues.set_private_no_perms.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.set_private_no_perms.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-info">PRIVATE - No Permissions</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Remove permissions from public pages
                        if (data.issues.remove_perms && data.issues.remove_perms.length > 0) {
                            detailsHTML += `<h6 class="text-warning">⚠️ Remove Permissions from Public Pages (${data.issues.remove_perms.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Be</th></tr></thead><tbody>';
                            data.issues.remove_perms.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-secondary">${issue.perms}</span></td><td><span class="badge badge-success">PUBLIC - No Permissions</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Add permissions (admin)
                        if (data.issues.add_perms_admin && data.issues.add_perms_admin.length > 0) {
                            detailsHTML += `<h6 class="text-primary">ℹ️ Add Admin Permissions (${data.issues.add_perms_admin.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Add</th></tr></thead><tbody>';
                            data.issues.add_perms_admin.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-primary">${issue.missing}</span></td></tr>`;
                            });
                            detailsHTML += '</tbody></table>';
                        }

                        // Add permissions (owner)
                        if (data.issues.add_perms_user && data.issues.add_perms_user.length > 0) {
                            detailsHTML += `<h6 class="text-primary">ℹ️ Add Owner Permissions (${data.issues.add_perms_user.length} pages):</h6>`;
                            detailsHTML += '<table class="table table-sm table-bordered permission-table mb-4"><thead><tr><th>Page</th><th>Current</th><th>Will Add</th></tr></thead><tbody>';
                            data.issues.add_perms_user.forEach(issue => {
                                detailsHTML += `<tr><td>${formatPageName(issue)}</td><td><span class="badge badge-secondary">${issue.current}</span></td><td><span class="badge badge-success">${issue.required}</span></td></tr>`;
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

                function outputMessage($message, $percentage = null) {
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
                outputMessage("⚠️  SAFETY NOTICE: Creating automatic backup...");
                try {
                    $cleanupSummary = cleanupOldBackups();
                    outputMessage("🧹 Cleaned up old backups: {$cleanupSummary['automated']['deleted']} automated, {$cleanupSummary['manual']['deleted']} manual, {$cleanupSummary['rollback']['deleted']} rollback");

                    $backupPath = createStandardizedBackup(
                        'fix-page-permissions',
                        ['pages', 'permission_page_matches'],
                        'automated',
                        'development'
                    );
                    outputMessage("✅ Backup created: " . basename($backupPath));
                } catch (Exception $e) {
                    outputMessage("❌ Backup creation failed: " . $e->getMessage());
                    outputMessage("⚠️  Aborting - backup is required for this operation!");
                    exit;
                }
                outputMessage("");

                // Get issues to fix
                outputMessage("🔍 Analyzing permissions...");
                $issues = analyzePermissions($db);

                $totalChanges = count($issues['set_public']) + count($issues['set_private_admin']) +
                               count($issues['set_private_user']) + count($issues['set_private_no_perms']) +
                               count($issues['remove_perms']) + count($issues['add_perms_admin']) +
                               count($issues['add_perms_user']);

                outputMessage("Found {$totalChanges} changes to make");
                outputMessage("");
                outputMessage("🚀 Starting permission fixes...");

                try {
                    $currentChange = 0;

                    // Set pages to public and remove all permissions
                    if (!empty($issues['set_public'])) {
                        outputMessage("");
                        outputMessage("=== Setting Pages to Public ===");
                        foreach ($issues['set_public'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Set page to public
                                $db->update('pages', $issue['id'], ['private' => 0]);

                                // Remove ALL permissions
                                $db->query("DELETE FROM permission_page_matches WHERE page_id = ?",
                                    [$issue['id']]);

                                $global_successes++;
                                outputMessage("✅ Set to PUBLIC with no permissions: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Set page to PUBLIC with no permissions: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Set pages to private (admin) and add proper permissions
                    if (!empty($issues['set_private_admin'])) {
                        outputMessage("");
                        outputMessage("=== Setting Pages to Private - Admin ===");
                        foreach ($issues['set_private_admin'] as $issue) {
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
                                outputMessage("✅ Set to PRIVATE with Administrator + Editor: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Set page to PRIVATE with Admin+Editor: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Set pages to private (owner) and add User permission
                    if (!empty($issues['set_private_user'])) {
                        outputMessage("");
                        outputMessage("=== Setting Pages to Private - Owner ===");
                        foreach ($issues['set_private_user'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Set page to private
                                $db->update('pages', $issue['id'], ['private' => 1]);

                                // Add User permission
                                $existingUser = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_USER])->count();
                                if ($existingUser == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_USER,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                $global_successes++;
                                outputMessage("✅ Set to PRIVATE with User permission: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Set page to PRIVATE with User: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Set pages to private with no permissions (special case)
                    if (!empty($issues['set_private_no_perms'])) {
                        outputMessage("");
                        outputMessage("=== Setting Pages to Private with No Permissions ===");
                        foreach ($issues['set_private_no_perms'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Set page to private
                                $db->update('pages', $issue['id'], ['private' => 1]);

                                // Remove ALL permissions
                                $db->query("DELETE FROM permission_page_matches WHERE page_id = ?",
                                    [$issue['id']]);

                                $global_successes++;
                                outputMessage("✅ Set to PRIVATE with no permissions: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Set page to PRIVATE with no permissions: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Remove permissions from public pages
                    if (!empty($issues['remove_perms'])) {
                        outputMessage("");
                        outputMessage("=== Removing Permissions from Public Pages ===");
                        foreach ($issues['remove_perms'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Remove ALL permissions
                                $db->query("DELETE FROM permission_page_matches WHERE page_id = ?",
                                    [$issue['id']]);

                                $global_successes++;
                                outputMessage("✅ Removed permissions from: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Removed all permissions from PUBLIC page: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Add permissions (admin) to private pages
                    if (!empty($issues['add_perms_admin'])) {
                        outputMessage("");
                        outputMessage("=== Adding Admin Permissions to Private Pages ===");
                        foreach ($issues['add_perms_admin'] as $issue) {
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
                                outputMessage("✅ Added admin permissions to: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Added admin permissions to page: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                    // Add permissions (owner) to private pages
                    if (!empty($issues['add_perms_user'])) {
                        outputMessage("");
                        outputMessage("=== Adding Owner Permissions to Private Pages ===");
                        foreach ($issues['add_perms_user'] as $issue) {
                            $global_attempts++;
                            $currentChange++;
                            $percentage = round(($currentChange / $totalChanges) * 100);

                            try {
                                // Add User permission if not exists
                                $existingUser = $db->query("SELECT id FROM permission_page_matches WHERE page_id = ? AND permission_id = ?",
                                    [$issue['id'], PERM_USER])->count();
                                if ($existingUser == 0) {
                                    $db->insert('permission_page_matches', [
                                        'permission_id' => PERM_USER,
                                        'page_id' => $issue['id']
                                    ]);
                                }

                                $global_successes++;
                                outputMessage("✅ Added owner permissions to: {$issue['page']}", $percentage);
                                logger($user->data()->id, LogCategories::LOG_CATEGORY_PERMISSION_FIX, "Added owner permissions to page: {$issue['page']} (ID: {$issue['id']})");
                            } catch (Exception $e) {
                                outputMessage("✗ Failed to update {$issue['page']}: " . $e->getMessage(), $percentage);
                            }
                        }
                    }

                            outputMessage("");
                            outputMessage("✅ Permission fixes completed successfully!");

                            // Verification
                            outputMessage("");
                            outputMessage("🔍 Verifying results...");
                            $issuesAfter = analyzePermissions($db);
                            $remainingIssues = count($issuesAfter['set_public']) + count($issuesAfter['set_private_admin']) +
                                              count($issuesAfter['set_private_user']) + count($issuesAfter['set_private_no_perms']) +
                                              count($issuesAfter['remove_perms']) + count($issuesAfter['add_perms_admin']) +
                                              count($issuesAfter['add_perms_user']);

                            if ($remainingIssues == 0) {
                                outputMessage("✅ SUCCESS: All permissions fixed correctly!");
                            } else {
                                outputMessage("⚠️  WARNING: {$remainingIssues} issues still remain - may need manual review");
                            }

                            // Log completion
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Permission fix completed - Fixed: {$global_successes}/{$global_attempts} pages");

                        } catch (Exception $e) {
                            outputMessage("❌ ERROR during processing: " . $e->getMessage());
                            outputMessage("You can restore from backup if needed");
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Permission fix failed: " . $e->getMessage());
                        }

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage("✅ Script completion recorded");
                    } catch (Exception $record_e) {
                        outputMessage("⚠️  Could not record script completion: " . $record_e->getMessage());
                    }

                outputMessage("");
                outputMessage("Script completed at " . date("h:i:sa"));

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
