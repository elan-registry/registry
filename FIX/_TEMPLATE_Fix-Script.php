<?php

declare(strict_types=1);

/**
 * [SCRIPT_NAME] Script
 *
 * Administrative script to [SCRIPT_DESCRIPTION].
 * Issue #[ISSUE_NUMBER]: [ISSUE_TITLE]
 *
 * [ADDITIONAL_NOTES]
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Copy this file to FIX/ directory with proper naming: ##-Descriptive-Name.php
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
                                <i class="fa fa-[ICON_NAME]"></i> [SCRIPT_TITLE]
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">[SCRIPT_DESCRIPTION_PARAGRAPH]</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>[BULLET_POINT_1]</li>
                                    <li>[BULLET_POINT_2]</li>
                                    <li>[BULLET_POINT_3]</li>
                                    <li>[BULLET_POINT_4]</li>
                                    <li>[BULLET_POINT_5]</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start [ACTION_NAME]
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
                    updateProgress(100, 100, '[ACTION_NAME] completed successfully!');

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

                // SAFETY: Create automatic backup
                outputMessage($line++, "⚠️  SAFETY NOTICE: Creating automatic backup...");
                try {
                    // Clean up old backups first
                    $cleanupSummary = cleanupOldBackups();
                    outputMessage($line++, "🧹 Cleaned up old backups: {$cleanupSummary['automated']['deleted']} automated, {$cleanupSummary['manual']['deleted']} manual, {$cleanupSummary['rollback']['deleted']} rollback");

                    // Create backup for this script
                    $backupPath = createStandardizedBackup(
                        '[SCRIPT_KEBAB_NAME]',           // e.g., 'cleanup-orphaned-profiles'
                        ['[TABLE1]', '[TABLE2]'],       // Tables to backup
                        'automated',                     // Backup type
                        '[ENVIRONMENT]'                  // 'development', 'test', or 'production'
                    );
                    outputMessage($line++, "✅ Backup created: " . basename($backupPath));
                } catch (Exception $e) {
                    outputMessage($line++, "❌ Backup creation failed: " . $e->getMessage());
                    outputMessage($line++, "⚠️  Proceeding without backup - use caution!");
                }
                outputMessage($line++, "");

                // Analysis before processing
                outputMessage($line++, "🔍 Analyzing [WHAT_TO_ANALYZE]...");

                // [ADD YOUR ANALYSIS QUERIES HERE]
                $total_records = $db->query("SELECT COUNT(*) as count FROM [TABLE_NAME]")->first()->count;
                outputMessage($line++, "Total records to process: " . $total_records);

                outputMessage($line++, "");
                outputMessage($line++, "🚀 Starting [ACTION_NAME]...");

                try {
                    // [ADD YOUR MAIN PROCESSING LOGIC HERE]
                    
                    // Example processing loop:
                    /*
                    $records = $db->query("SELECT * FROM [TABLE_NAME] WHERE [CONDITIONS]")->results();
                    $total = count($records);
                    
                    foreach ($records as $index => $record) {
                        $current = $index + 1;
                        $percentage = round(($current / $total) * 100);
                        
                        // Process the record
                        $success = processRecord($record);
                        
                        if ($success) {
                            $global_successes++;
                            outputMessage($line++, "✅ Processed record ID {$record->id}", $percentage);
                        } else {
                            outputMessage($line++, "✗ Failed to process record ID {$record->id}", $percentage);
                        }
                        
                        $global_attempts++;
                        
                        // Optional: Add small delay to prevent overwhelming
                        usleep(10000); // 10ms delay
                    }
                    */

                    outputMessage($line++, "✅ [ACTION_NAME] completed successfully!");

                    // Verification
                    outputMessage($line++, "");
                    outputMessage($line++, "🔍 Verifying results...");

                    // [ADD VERIFICATION QUERIES HERE]

                    outputMessage($line++, "✅ SUCCESS: All processing completed!");

                    // Log the completion action with summary
                    // NOTE: If using logger() inside functions, add: global $user;
                    logger($user->data()->id, 'DatabaseMaintenance', "[SCRIPT_NAME] completed - Processed: {$global_successes}/{$global_attempts} records (Issue #[ISSUE_NUMBER])");

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage($line++, "✅ Script completion recorded");
                        logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
                    } catch (Exception $record_e) {
                        outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                    }

                } catch (Exception $e) {
                    outputMessage($line++, "❌ ERROR during processing: " . $e->getMessage());
                    outputMessage($line++, "Processing aborted - no changes made");
                    
                    // Log the error
                    logger($user->data()->id, 'DatabaseError', "[SCRIPT_NAME] failed: " . $e->getMessage() . " (Issue #[ISSUE_NUMBER])");
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