<?php

declare(strict_types=1);

/**
 * Username Field Removal Verification Script
 *
 * Administrative script to verify the deprecated username field has been properly
 * removed from the database and that all triggers are clean of username references.
 * Issue #319: Deprecated username field is still in use
 *
 * This verification script performs comprehensive checks to ensure:
 * - Username column is absent from cars table
 * - Username column is absent from cars_hist table
 * - Database triggers don't reference username
 * - Views are updated to exclude username
 * - No orphaned username data remains
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to FIX/ directory with proper naming: ##-Descriptive-Name.php
 * 2. The FIX/.htaccess allows all scripts except templates to run directly
 * 3. Scripts can be accessed via FIX/index.php menu or direct URL
 * 4. Use sequential numbering (01, 02, 03...) for proper execution order
 * 5. Return buttons automatically detect new window vs direct navigation
 * 6. See FIX/README.md for detailed instructions and best practices
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
                                <i class="fa fa-search"></i> Username Field Removal Verification
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This verification script performs comprehensive read-only checks to ensure the deprecated username field has been completely removed from the database schema and is no longer referenced in any database objects.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Verifies username column is absent from cars table</li>
                                    <li>Verifies username column is absent from cars_hist table</li>
                                    <li>Checks all car-related triggers for username references</li>
                                    <li>Inspects database views for deprecated username usage</li>
                                    <li>Tests basic database functionality after username removal</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Database Verification
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
            <div class="row mb-4" id="logSection">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fa fa-list-alt"></i> Verification Log
                            </h3>
                        </div>
                        <div class="card-body fix-results-container" id="resultsContainer">
                            <div class="fix-status-line text-muted">
                                <small><em>Initializing verification process...</em></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let totalSteps = 6;
                let currentStep = 0;
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
                    // Update progress bar to 100% and remove animation
                    updateProgress(100, 100, 'Database Verification completed successfully!');

                    // Populate summary content
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

                    // Add appropriate Bootstrap text colors for different message types
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

                function startProcessing() {
                    if (processStarted) return;
                    processStarted = true;

                    // Hide description section
                    document.getElementById('descriptionSection').style.display = 'none';

                    // Set start time
                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();

                    // Start the actual processing
                    window.location.href = window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'start=1';
                }

                // Check if we should start automatically
                if (new URLSearchParams(window.location.search).get('start') === '1') {
                    processStarted = true;
                    document.getElementById('descriptionSection').style.display = 'none';

                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();
                }
            </script>

            <?php
            // Only run the actual processing if start parameter is set
            if (isset($_GET['start']) && $_GET['start'] == '1') {

                // Initialize verification counters
                $verificationsPassed = 0;
                $verificationsTotal = 6;
                $issues = [];

                function outputMessage($lineNum, $message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                outputMessage($line++, "🔍 Starting comprehensive username field removal verification...");
                outputMessage($line++, "");

                try {
                    // Step 1: Verify username column is absent from cars table
                    outputMessage($line++, "=== Step 1: Checking cars table structure ===", 17);

                    $columnCheck = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
                    if ($columnCheck->count() > 0) {
                        $issues[] = "Username column still exists in cars table";
                        outputMessage($line++, "❌ FAIL: Username column still exists in cars table");
                    } else {
                        $verificationsPassed++;
                        outputMessage($line++, "✅ PASS: Username column successfully removed from cars table");
                    }

                    // Step 2: Verify username column is absent from cars_hist table
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Step 2: Checking cars_hist table structure ===", 33);

                    $histColumnCheck = $db->query("SHOW COLUMNS FROM cars_hist LIKE 'username'");
                    if ($histColumnCheck->count() > 0) {
                        $issues[] = "Username column still exists in cars_hist table";
                        outputMessage($line++, "❌ FAIL: Username column still exists in cars_hist table");
                    } else {
                        $verificationsPassed++;
                        outputMessage($line++, "✅ PASS: Username column successfully removed from cars_hist table");
                    }

                    // Step 3: Check all car-related triggers for username references
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Step 3: Inspecting car history triggers ===", 50);

                    $triggers = $db->query("
                        SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_STATEMENT
                        FROM information_schema.TRIGGERS
                        WHERE TRIGGER_SCHEMA = DATABASE()
                        AND (EVENT_OBJECT_TABLE = 'cars' OR TRIGGER_NAME LIKE 'cars_%')
                        ORDER BY TRIGGER_NAME
                    ");

                    if ($triggers->count() == 0) {
                        $issues[] = "No car history triggers found - data audit trail may be compromised";
                        outputMessage($line++, "❌ CONCERN: No car history triggers found");
                    } else {
                        outputMessage($line++, "Found " . $triggers->count() . " car-related triggers to inspect");

                        $triggerIssues = 0;
                        foreach ($triggers->results() as $trigger) {
                            $triggerName = $trigger->TRIGGER_NAME;
                            $actionStatement = $trigger->ACTION_STATEMENT;

                            // Check for username references in trigger definition
                            if (stripos($actionStatement, 'username') !== false) {
                                $triggerIssues++;
                                $issues[] = "Trigger '$triggerName' contains username references";
                                outputMessage($line++, "❌ FAIL: Trigger '$triggerName' still references username column");
                            } else {
                                outputMessage($line++, "✅ PASS: Trigger '$triggerName' ({$trigger->EVENT_MANIPULATION}) clean");
                            }
                        }

                        if ($triggerIssues == 0) {
                            $verificationsPassed++;
                            outputMessage($line++, "✅ All " . $triggers->count() . " car triggers verified clean of username references");
                        }
                    }

                    // Step 4: Check database views for username references
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Step 4: Checking database views ===", 67);

                    $views = $db->query("
                        SELECT TABLE_NAME, VIEW_DEFINITION
                        FROM information_schema.VIEWS
                        WHERE TABLE_SCHEMA = DATABASE()
                        AND (TABLE_NAME LIKE '%user%' OR TABLE_NAME LIKE '%car%')
                    ");

                    if ($views->count() > 0) {
                        outputMessage($line++, "Inspecting " . $views->count() . " relevant database views");

                        $viewIssues = 0;
                        foreach ($views->results() as $view) {
                            $viewName = $view->TABLE_NAME;
                            $viewDef = $view->VIEW_DEFINITION;

                            // Check for username column references
                            if (stripos($viewDef, '`username`') !== false || stripos($viewDef, 'username') !== false) {
                                // Distinguish between cars.username (bad) and users.username (acceptable)
                                if (stripos($viewDef, 'cars.username') !== false ||
                                    (stripos($viewDef, 'username') !== false && stripos($viewDef, 'users.username') === false && stripos($viewDef, 'u.username') === false)) {
                                    $viewIssues++;
                                    $issues[] = "View '$viewName' may reference deprecated cars.username";
                                    outputMessage($line++, "❌ CONCERN: View '$viewName' may reference cars username field");
                                } else {
                                    outputMessage($line++, "✅ INFO: View '$viewName' references users.username (acceptable)");
                                }
                            } else {
                                outputMessage($line++, "✅ PASS: View '$viewName' clean of username references");
                            }
                        }

                        if ($viewIssues == 0) {
                            $verificationsPassed++;
                            outputMessage($line++, "✅ All database views verified clean or contain only acceptable user.username references");
                        }
                    } else {
                        $verificationsPassed++;
                        outputMessage($line++, "✅ No relevant database views found to check");
                    }

                    // Step 5: Check for orphaned username constraints or indexes
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Step 5: Checking for orphaned constraints ===", 83);

                    // Check for any indexes that might reference username on cars table
                    $indexes = $db->query("SHOW INDEX FROM cars WHERE Column_name = 'username'");
                    if ($indexes->count() > 0) {
                        $issues[] = "Orphaned indexes on username column found in cars table";
                        outputMessage($line++, "❌ FAIL: Found orphaned indexes on username column in cars table");
                    } else {
                        outputMessage($line++, "✅ PASS: No orphaned username indexes found in cars table");
                    }

                    // Check for any indexes that might reference username on cars_hist table
                    $histIndexes = $db->query("SHOW INDEX FROM cars_hist WHERE Column_name = 'username'");
                    if ($histIndexes->count() > 0) {
                        $issues[] = "Orphaned indexes on username column found in cars_hist table";
                        outputMessage($line++, "❌ FAIL: Found orphaned indexes on username column in cars_hist table");
                    } else {
                        $verificationsPassed++;
                        outputMessage($line++, "✅ PASS: No orphaned username indexes found in cars_hist table");
                    }

                    // Step 6: Test basic car operations to ensure compatibility
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Step 6: Testing database compatibility ===", 100);

                    try {
                        $testQuery = $db->query("SELECT id, model, series, year FROM cars LIMIT 1");
                        $verificationsPassed++;
                        outputMessage($line++, "✅ PASS: Basic car queries working normally");
                    } catch (Exception $e) {
                        $issues[] = "Database compatibility issue detected: " . $e->getMessage();
                        outputMessage($line++, "❌ FAIL: Database compatibility issue: " . $e->getMessage());
                    }

                    // Final verification summary
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Verification Summary ===");

                    if (count($issues) == 0) {
                        outputMessage($line++, "✅ SUCCESS: All verification checks passed!");
                        outputMessage($line++, "✅ Username field has been successfully removed from database");
                        outputMessage($line++, "✅ All database objects are clean of username references");

                        // Log successful verification
                        logger($user->data()->id, 'DatabaseMaintenance', "Username field removal verification passed (Issue #319)");
                    } else {
                        outputMessage($line++, "❌ VERIFICATION FAILED - Issues detected:");
                        foreach ($issues as $issue) {
                            outputMessage($line++, "   • $issue");
                        }

                        // Log verification failure
                        logger($user->data()->id, 'DatabaseMaintenance', "Username field removal verification failed - " . count($issues) . " issues detected (Issue #319)");
                    }

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage($line++, "✅ Verification results recorded");
                        logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
                    } catch (Exception $record_e) {
                        outputMessage($line++, "⚠️  Could not record verification results: " . $record_e->getMessage());
                    }

                } catch (Exception $e) {
                    outputMessage($line++, "❌ ERROR during verification: " . $e->getMessage());
                    outputMessage($line++, "Verification aborted");

                    // Log the error
                    logger($user->data()->id, 'DatabaseError', "Username field verification failed: " . $e->getMessage() . " (Issue #319)");
                }

                outputMessage($line++, "");
                outputMessage($line++, "Verification completed at " . date("h:i:sa"));

                // Calculate final stats and show completion summary
                $successPercentage = $verificationsTotal > 0 ? round(($verificationsPassed / $verificationsTotal) * 100) : 100;
                $issuesCount = count($issues);

                // Determine color based on success rate
                $rateColor = '#dc3545'; // red (default for failures)
                $rateIcon = 'exclamation-circle';
                if ($issuesCount == 0) {
                    $rateColor = '#28a745'; // green
                    $rateIcon = 'check-circle';
                } elseif ($successPercentage >= 50) {
                    $rateColor = '#ffc107'; // yellow
                    $rateIcon = 'exclamation-triangle';
                }

                $statusText = $issuesCount == 0 ? "PASSED" : "FAILED";

                echo "<script>
    showCompletionSummary(`
        <div class='row'>
            <div class='col-sm-6'><strong>Verifications:</strong> $verificationsPassed/$verificationsTotal passed</div>
            <div class='col-sm-6'><strong>Status:</strong>
                <span style='color: $rateColor; font-weight: bold;'>
                    <i class='fa fa-$rateIcon'></i> $statusText
                </span>
            </div>
        </div>
        <div class='row mt-2'>
            <div class='col-12'><strong>Issues Found:</strong> $issuesCount</div>
        </div>
    `);
    </script>";
            }

            ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return to FIX Menu button -->
<div style="margin-top: 20px; text-align: center;">
    <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='index.php';}" class="btn btn-outline-primary">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> Return to FIX Menu
    </button>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>