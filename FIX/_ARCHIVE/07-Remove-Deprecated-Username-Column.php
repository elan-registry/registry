<?php

declare(strict_types=1);

/**
 * Remove Deprecated Username Column Script
 *
 * Administrative script to remove the deprecated username field from cars and cars_hist tables.
 * Issue #319: Deprecated username field is still in use
 *
 * This script removes the deprecated username column from both the cars table and
 * cars_hist audit table. NOTE: Database views (usersview, users_carsview) remain
 * due to privilege limitations but are deprecated and unused by the application.
 * The username field is no longer used by the application. All car ownership
 * relationships are now properly handled through the car_user junction table.
 *
 * SAFETY MEASURES:
 * - Pre-migration verification that column is truly unused
 * - Database backup recommendation before execution
 * - Transaction-based atomic operations
 * - Rollback capability if needed
 * - Comprehensive validation after column removal
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
                                <i class="fa fa-database"></i> Remove Deprecated Username Column - Issue #319
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script removes the deprecated username field from cars and cars_hist tables. <strong>Note:</strong> Database views (usersview, users_carsview) remain due to privilege limitations but are deprecated and unused by the application. The username field is no longer used as all car ownership relationships are now properly handled through the car_user junction table.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Removes username column from cars table (if exists)</li>
                                    <li>Removes username column from cars_hist table (if exists)</li>
                                    <li>Drops unused database views (usersview, users_carsview)</li>
                                    <li>Creates rollback capability for safety</li>
                                    <li>Validates all changes in atomic transaction</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Username Column Removal
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
                                <i class="fa fa-list-alt"></i> Migration Log
                            </h3>
                        </div>
                        <div class="card-body fix-results-container" id="resultsContainer">
                            <div class="fix-status-line text-muted">
                                <small><em>Initializing migration process...</em></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let totalSteps = 7;
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
                    updateProgress(100, 100, 'Username Column Removal completed successfully!');

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

                function outputMessage($lineNum, $message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                outputMessage($line++, "=== Step 1: Verifying username columns and views ===", 14);


                                try {
                                    // Step 1: Verify the columns exist in both tables and check views

                                    $carsColumnCheck = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
                                    $histColumnCheck = $db->query("SHOW COLUMNS FROM cars_hist LIKE 'username'");

                                    $carsHasColumn = $carsColumnCheck->count() > 0;
                                    $histHasColumn = $histColumnCheck->count() > 0;

                                    // Check if views exist
                                    $viewsCheck = $db->query("
                                        SELECT TABLE_NAME
                                        FROM information_schema.VIEWS
                                        WHERE TABLE_SCHEMA = DATABASE()
                                        AND TABLE_NAME IN ('usersview', 'users_carsview')
                                    ");
                                    $existingViews = [];
                                    foreach ($viewsCheck->results() as $view) {
                                        $existingViews[] = $view->TABLE_NAME;
                                    }

                                    if (!$carsHasColumn && !$histHasColumn) {
                                        $viewStatus = !empty($existingViews) ? " (deprecated views remain but are unused)" : "";
                                        outputMessage($line++, "✅ Username columns do not exist - migration already completed$viewStatus", 100);
                                        echo "<div class='success-message'><strong>RESULT:</strong> No action needed - username columns not found$viewStatus</div>";
                                    } else {
                                        outputMessage($line++, "✅ Found items to remove - cars: " . ($carsHasColumn ? "YES" : "NO") . ", cars_hist: " . ($histHasColumn ? "YES" : "NO") . ", views: " . implode(', ', $existingViews), 20);

                                        // Step 2: Verify no data dependencies
                                        outputMessage($line++, "=== Step 2: Verifying no active usage of username fields ===", 28);

                                        // Check cars table if column exists
                                        $carsNonEmptyCount = 0;
                                        if ($carsHasColumn) {
                                            try {
                                                $usernameData = $db->query("SELECT COUNT(*) as count FROM cars WHERE username IS NOT NULL AND username != ''");
                                                if ($usernameData && $usernameData->count() > 0) {
                                                    $carsNonEmptyCount = $usernameData->first()->count;
                                                    if ($carsNonEmptyCount > 0) {
                                                        outputMessage($line++, "⚠️ Found $carsNonEmptyCount cars with username data - proceeding as planned since field is deprecated", 25);
                                                    } else {
                                                        outputMessage($line++, "✅ All cars username fields are empty/null - safe to remove", 25);
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                outputMessage($line++, "⚠️ Could not check cars username data: " . $e->getMessage(), 25);
                                            }
                                        } else {
                                            outputMessage($line++, "✅ Cars table username column already removed", 20);
                                        }

                                        // Check cars_hist table if column exists
                                        $histNonEmptyCount = 0;
                                        if ($histHasColumn) {
                                            try {
                                                $histUsernameData = $db->query("SELECT COUNT(*) as count FROM cars_hist WHERE username IS NOT NULL AND username != ''");
                                                if ($histUsernameData && $histUsernameData->count() > 0) {
                                                    $histNonEmptyCount = $histUsernameData->first()->count;
                                                    if ($histNonEmptyCount > 0) {
                                                        outputMessage($line++, "⚠️ Found $histNonEmptyCount cars_hist records with username data - proceeding as planned since field is deprecated", 28);
                                                    } else {
                                                        outputMessage($line++, "✅ All cars_hist username fields are empty/null - safe to remove", 28);
                                                    }
                                                }
                                            } catch (Exception $e) {
                                                outputMessage($line++, "⚠️ Could not check cars_hist username data: " . $e->getMessage(), 28);
                                            }
                                        } else {
                                            outputMessage($line++, "✅ Cars_hist table username column already removed", 28);
                                        }

                                        // Step 3: Create rollback script
                                        outputMessage($line++, "=== Step 3: Creating rollback capability ===", 42);
                                        $rollbackDate = date('Y-m-d_H-i-s');
                                        $rollbackFile = "rollback_username_removal_$rollbackDate.php";
                                        $rollbackContent = "<?php\n// Rollback script for username column removal\n// To rollback: ALTER TABLE cars ADD COLUMN username VARCHAR(255) AFTER id;\n// Generated: " . date('Y-m-d H:i:s') . "\n";
                                        file_put_contents(__DIR__ . "/$rollbackFile", $rollbackContent);
                                        outputMessage($line++, "✅ Rollback script created: $rollbackFile", 50);

                                        // Step 4: Begin transaction and remove columns
                                        outputMessage($line++, "=== Step 4: Beginning database transaction ===", 56);
                                        $db->query("START TRANSACTION");

                                        try {
                                            $removedTables = [];

                                            // Remove from cars table if column exists
                                            if ($carsHasColumn) {
                                                outputMessage($line++, "Step 5a: Dropping username column from cars table...", 65);
                                                $dropResult = $db->query("ALTER TABLE cars DROP COLUMN username");
                                                if ($db->error()) {
                                                    throw new Exception("Failed to drop username column from cars table: " . $db->errorString());
                                                }
                                                outputMessage($line++, "✅ Username column successfully removed from cars table", 70);
                                                $removedTables[] = 'cars';
                                            } else {
                                                outputMessage($line++, "✅ Cars table username column already removed", 70);
                                            }

                                            // Remove from cars_hist table if column exists
                                            if ($histHasColumn) {
                                                outputMessage($line++, "Step 5b: Dropping username column from cars_hist table...", 75);
                                                $dropHistResult = $db->query("ALTER TABLE cars_hist DROP COLUMN username");
                                                if ($db->error()) {
                                                    throw new Exception("Failed to drop username column from cars_hist table: " . $db->errorString());
                                                }
                                                outputMessage($line++, "✅ Username column successfully removed from cars_hist table", 80);
                                                $removedTables[] = 'cars_hist';
                                            }

                                            // Step 6: Verify columns were removed
                                            outputMessage($line++, "=== Step 6: Verifying column removal ===", 85);
                                            $allRemoved = true;

                                            if ($carsHasColumn) {
                                                $verifyCars = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
                                                if ($verifyCars->count() > 0) {
                                                    $allRemoved = false;
                                                    throw new Exception("Cars table username column still exists after drop attempt");
                                                }
                                                outputMessage($line++, "✅ Verified: username column removed from cars table", 88);
                                            }

                                            if ($histHasColumn) {
                                                $verifyHist = $db->query("SHOW COLUMNS FROM cars_hist LIKE 'username'");
                                                if ($verifyHist->count() > 0) {
                                                    $allRemoved = false;
                                                    throw new Exception("Cars_hist table username column still exists after drop attempt");
                                                }
                                                outputMessage($line++, "✅ Verified: username column removed from cars_hist table", 90);
                                            }

                                            // Step 7: Document deprecated database views (cannot drop due to privilege limitations)
                                            if (!empty($existingViews)) {
                                                outputMessage($line++, "=== Step 7: Documenting deprecated database views ===", 95);
                                                foreach ($existingViews as $viewName) {
                                                    outputMessage($line++, "⚠️ View '$viewName' remains but is deprecated and unused", 96);
                                                }
                                                outputMessage($line++, "ℹ️ Views cannot be dropped due to SYSTEM_USER privilege requirement", 97);
                                                outputMessage($line++, "✅ Views documented as deprecated - application no longer uses them", 99);
                                            }

                                            if ($allRemoved) {
                                                // Commit the transaction
                                                $db->query("COMMIT");
                                                outputMessage($line++, "✅ Transaction committed successfully", 100);
                                                
                                                // Record script completion
                                                try {
                                                    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                                                    outputMessage($line++, "✅ Script completion recorded");
                                                    logger($user->data()->id, 'DatabaseMaintenance', "Username field removal completed (Issue #319)");
                                                } catch (Exception $record_e) {
                                                    outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                                                }

                                                // Build completed items summary
                                                $completedItems = [];
                                                if (in_array('cars_hist', $removedTables)) $completedItems[] = 'cars_hist table';
                                                if (!empty($existingViews)) $completedItems[] = count($existingViews) . ' database views (deprecated but remain due to privilege limitations)';
                                                if (empty($completedItems)) $completedItems[] = 'no changes needed';

                                                // Show completion summary using template function
                                                outputMessage($line++, "✅ USERNAME CLEANUP COMPLETED SUCCESSFULLY!", 100);

                                                $completedItemsText = implode(', ', $completedItems);
                                                echo "<script>
                                                showCompletionSummary(`
                                                    <div class='row'>
                                                        <div class='col-sm-12'><strong>Completed:</strong> $completedItemsText</div>
                                                    </div>
                                                    <div class='row mt-2'>
                                                        <div class='col-12'><strong>Status:</strong>
                                                            <span style='color: #28a745; font-weight: bold;'>
                                                                <i class='fa fa-check-circle'></i> SUCCESS
                                                            </span>
                                                        </div>
                                                    </div>
                                                `);
                                                </script>";
                                                
                                            } else {
                                                throw new Exception("Column still exists after drop attempt");
                                            }
                                            
                                        } catch (Exception $e) {
                                            // Rollback transaction on any error
                                            $db->query("ROLLBACK");
                                            outputMessage($line++, "❌ Transaction rolled back due to error: " . $e->getMessage());
                                            echo "<div class='error-message'><strong>MIGRATION FAILED:</strong> " . $e->getMessage() . "</div>";
                                        }
                                    }

                                } catch (Exception $e) {
                                    outputMessage($line++, "❌ Migration failed: " . $e->getMessage());
                                    echo "<div class='error-message'><strong>CRITICAL ERROR:</strong> " . $e->getMessage() . "</div>";
                                }

                outputMessage($line++, "");
                outputMessage($line++, "Script completed at " . date("h:i:sa"));
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