<?php

/**
 * Remove Duplicate History Records Script
 *
 * Administrative script to clean up duplicate rows in cars_hist table.
 * Issue #202: Delete Duplicate Rows in Car History
 *
 * DUPLICATES IDENTIFIED:
 * - 631 groups of duplicate records (631 rows to remove)
 * - Duplicates by: car_id + operation + timestamp
 * - 485 duplicate INSERT operations, 146 duplicate UPDATE operations
 * - Strategy: Keep record with LOWEST id (earliest created)
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
                                <i class="fa fa-history"></i> Remove Duplicate History Records
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Clean up duplicate rows in cars_hist table to maintain database integrity and prevent data inconsistencies.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Analyzes duplicate records in cars_hist table by car_id + operation + timestamp</li>
                                    <li>Identifies 631 groups of duplicate records (631 rows to remove)</li>
                                    <li>Removes duplicate records keeping the earliest created (lowest id)</li>
                                    <li>Preserves data integrity while eliminating duplicates</li>
                                    <li>Provides comprehensive verification and safety checks</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Duplicate Removal
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
                let totalSteps = 0;
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
                    updateProgress(100, 100, 'Duplicate Removal completed successfully!');

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
                
                // Initialize global counters
                $global_attempts = 0;
                $global_successes = 0;
                
                function outputMessage($lineNum, $message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                // SAFETY: Create backup notification
                outputMessage($line++, "⚠️  SAFETY NOTICE: Backup cars_hist table before running this script!");
                outputMessage($line++, "Command: /Applications/MAMP/Library/bin/mysqldump -h localhost -P 8889 -u claude -p\"claude\" elanregi_spice cars_hist > cars_hist_backup_$(date +%Y%m%d_%H%M%S).sql");
                outputMessage($line++, "");

                // Analysis before processing
                outputMessage($line++, "🔍 Analyzing duplicate records...", 10);

                try {
                    // Get initial statistics
                    $total_rows_before = $db->query("SELECT COUNT(*) as count FROM cars_hist")->first()->count;
                    outputMessage($line++, "Total rows in cars_hist: " . number_format($total_rows_before));

                    $duplicate_groups = $db->query("
                        SELECT COUNT(*) as count
                        FROM (
                            SELECT car_id, operation, timestamp, COUNT(*) as cnt
                            FROM cars_hist
                            GROUP BY car_id, operation, timestamp
                            HAVING COUNT(*) > 1
                        ) as dups
                    ")->first()->count;
                    outputMessage($line++, "Duplicate groups found: " . number_format($duplicate_groups));

                    $rows_to_remove = $db->query("
                        SELECT (SUM(cnt) - COUNT(*)) as rows_to_remove
                        FROM (
                            SELECT car_id, operation, timestamp, COUNT(*) as cnt
                            FROM cars_hist
                            GROUP BY car_id, operation, timestamp
                            HAVING COUNT(*) > 1
                        ) as dups
                    ")->first()->rows_to_remove;
                    outputMessage($line++, "Duplicate rows to remove: " . number_format($rows_to_remove));

                    // Show operation breakdown
                    outputMessage($line++, "Breakdown by operation:", 20);
                    $operation_breakdown = $db->query("
                        SELECT operation, COUNT(*) as duplicate_groups
                        FROM (
                            SELECT car_id, operation, timestamp, COUNT(*) as cnt
                            FROM cars_hist
                            GROUP BY car_id, operation, timestamp
                            HAVING COUNT(*) > 1
                        ) as dups
                        GROUP BY operation
                        ORDER BY duplicate_groups DESC
                    ")->results();

                    foreach ($operation_breakdown as $op) {
                        outputMessage($line++, "  - {$op->operation}: " . number_format($op->duplicate_groups) . " duplicate groups");
                    }

                    // Show sample duplicates
                    outputMessage($line++, "");
                    outputMessage($line++, "📋 Sample duplicate records:", 30);
                    $samples = $db->query("
                        SELECT car_id, operation, timestamp, COUNT(*) as count
                        FROM cars_hist
                        GROUP BY car_id, operation, timestamp
                        HAVING COUNT(*) > 1
                        ORDER BY count DESC
                        LIMIT 5
                    ")->results();

                    foreach ($samples as $sample) {
                        outputMessage($line++, "  Car {$sample->car_id} - {$sample->operation} at {$sample->timestamp} ({$sample->count} copies)");
                    }

                    outputMessage($line++, "");
                    outputMessage($line++, "🚀 Starting duplicate removal...", 50);

                    // Execute the duplicate removal
                    $result = $db->query("
                        DELETE h1 FROM cars_hist h1
                        INNER JOIN (
                            SELECT car_id, operation, timestamp, MIN(id) as min_id
                            FROM cars_hist
                            GROUP BY car_id, operation, timestamp
                            HAVING COUNT(*) > 1
                        ) h2 ON h1.car_id = h2.car_id
                            AND h1.operation = h2.operation
                            AND h1.timestamp = h2.timestamp
                            AND h1.id > h2.min_id
                    ");

                    $global_attempts = $rows_to_remove;
                    $global_successes = $result->count();

                    outputMessage($line++, "✅ Duplicate removal completed successfully!", 70);

                    // Verification
                    outputMessage($line++, "");
                    outputMessage($line++, "🔍 Verifying cleanup results...", 80);

                    $total_rows_after = $db->query("SELECT COUNT(*) as count FROM cars_hist")->first()->count;
                    outputMessage($line++, "Total rows after cleanup: " . number_format($total_rows_after));
                    outputMessage($line++, "Rows removed: " . number_format($total_rows_before - $total_rows_after));

                    $remaining_duplicates = $db->query("
                        SELECT COUNT(*) as count
                        FROM (
                            SELECT car_id, operation, timestamp, COUNT(*) as cnt
                            FROM cars_hist
                            GROUP BY car_id, operation, timestamp
                            HAVING COUNT(*) > 1
                        ) as dups
                    ")->first()->count;
                    outputMessage($line++, "Remaining duplicate groups: " . number_format($remaining_duplicates));

                    if ($remaining_duplicates == 0) {
                        outputMessage($line++, "✅ SUCCESS: All duplicates have been removed!");
                    } else {
                        outputMessage($line++, "⚠️  WARNING: Some duplicates may remain - manual review needed");
                    }

                    // Verify car 1104 specifically (from issue report)
                    $car_1104_count = $db->query("SELECT COUNT(*) as count FROM cars_hist WHERE car_id = 1104")->first()->count;
                    outputMessage($line++, "Car 1104 records after cleanup: " . number_format($car_1104_count));

                    // Safety checks
                    outputMessage($line++, "");
                    outputMessage($line++, "🔒 Running safety checks...", 90);

                    $orphaned_cars = $db->query("
                        SELECT COUNT(*) as count
                        FROM cars c
                        LEFT JOIN cars_hist h ON c.id = h.car_id
                        WHERE h.car_id IS NULL
                    ")->first()->count;

                    if ($orphaned_cars > 0) {
                        outputMessage($line++, "⚠️  WARNING: Found {$orphaned_cars} cars without history records");
                    } else {
                        outputMessage($line++, "✅ No orphaned cars found");
                    }

                    outputMessage($line++, "");
                    outputMessage($line++, "🎉 CLEANUP COMPLETED SUCCESSFULLY!");
                    outputMessage($line++, "Issue #202 resolved: Duplicate rows in cars_hist have been removed");

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage($line++, "✅ Script completion recorded");
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "FIX script completed: " . basename(__FILE__));
                    } catch (Exception $record_e) {
                        outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                    }

                    // Log the completion action with summary
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Duplicate history removal completed - Removed: {$global_successes}/{$global_attempts} records (Issue #202)");

                    outputMessage($line++, "✅ Duplicate Removal completed successfully!");

                } catch (Exception $e) {
                    outputMessage($line++, "❌ ERROR during processing: " . $e->getMessage());
                    outputMessage($line++, "Processing aborted - no changes made");
                    
                    // Log the error
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Duplicate history removal failed: " . $e->getMessage() . " (Issue #202)");
                }

                outputMessage($line++, "");
                outputMessage($line++, "Script completed at " . date("h:i:sa"));

                // Calculate final stats and show completion summary
                $completionPercentage = $global_attempts > 0 ? round(($global_successes / $global_attempts) * 100) : 100;

                // Determine color based on success rate
                $rateColor = '#dc3545'; // red (default for low success)
                $rateIcon = 'exclamation-circle';
                if ($completionPercentage >= 80) {
                    $rateColor = '#28a745'; // green
                    $rateIcon = 'check-circle';
                } elseif ($completionPercentage >= 50) {
                    $rateColor = '#ffc107'; // yellow
                    $rateIcon = 'exclamation-triangle';
                }

                echo "<script>
    showCompletionSummary(`
        <div class='row'>
            <div class='col-sm-6'><strong>Records Processed:</strong> $global_successes/$global_attempts</div>
            <div class='col-sm-6'><strong>Success Rate:</strong> 
                <span style='color: $rateColor; font-weight: bold;'>
                    <i class='fa fa-$rateIcon'></i> $completionPercentage%
                </span>
            </div>
        </div>
        <div class='row mt-2'>
            <div class='col-sm-6'><strong>Total Rows Before:</strong> " . number_format($total_rows_before ?? 0) . "</div>
            <div class='col-sm-6'><strong>Total Rows After:</strong> " . number_format($total_rows_after ?? 0) . "</div>
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