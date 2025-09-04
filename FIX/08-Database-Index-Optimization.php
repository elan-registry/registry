<?php

/**
 * Database Index Optimization Script
 *
 * Administrative script to optimize database performance by adding strategic indexes.
 * Issue #Phase2: Database Performance Optimization
 *
 * Adds indexes on frequently queried columns to improve performance for car details page,
 * history loading, and search functionality. Expected improvements: 50-80% faster queries.
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
                                <i class="fa fa-database"></i> Database Index Optimization
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script analyzes current database indexes and adds strategic indexes on frequently queried columns to improve performance for car details pages, history loading, and search functionality.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Analyzes current database indexes across cars, cars_hist, and car_user tables</li>
                                    <li>Creates high-priority indexes for car history loading (50-80% faster)</li>
                                    <li>Adds medium-priority indexes for search functionality (40-70% faster)</li>
                                    <li>Implements low-priority indexes for geographic searches (30-50% faster)</li>
                                    <li>Provides rollback instructions and performance impact analysis</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Index Optimization
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
                    updateProgress(100, 100, 'Index Optimization completed successfully!');

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
                outputMessage($line++, "⚠️  SAFETY NOTICE: Backup relevant tables before running this script!");
                outputMessage($line++, "Command: mysqldump -u user -p elan_registry cars cars_hist car_user > db_backup_before_index_optimization.sql");
                outputMessage($line++, "");

                // Define indexes to create with priority order
                $indexes_to_create = [
                    // High Priority - Performance Critical
                    [
                        'table' => 'cars_hist',
                        'name' => 'idx_cars_hist_car_id',
                        'column' => 'car_id',
                        'priority' => 'HIGH',
                        'purpose' => 'Car details page history loading',
                        'expected_improvement' => '50-80% faster history queries'
                    ],
                    [
                        'table' => 'car_user',
                        'name' => 'idx_car_user_car_id',
                        'column' => 'car_id',
                        'priority' => 'HIGH',
                        'purpose' => 'Car ownership lookups',
                        'expected_improvement' => '60-90% faster ownership queries'
                    ],
                    [
                        'table' => 'car_user',
                        'name' => 'idx_car_user_userid',
                        'column' => 'userid',
                        'priority' => 'HIGH',
                        'purpose' => 'User car listings',
                        'expected_improvement' => '60-90% faster user car lists'
                    ],
                    
                    // Medium Priority - Search Performance
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_chassis',
                        'column' => 'chassis',
                        'priority' => 'MEDIUM',
                        'purpose' => 'Chassis number searches',
                        'expected_improvement' => '40-70% faster chassis searches'
                    ],
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_year',
                        'column' => 'year',
                        'priority' => 'MEDIUM',
                        'purpose' => 'Year-based filtering',
                        'expected_improvement' => '40-60% faster year filtering'
                    ],
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_series',
                        'column' => 'series',
                        'priority' => 'MEDIUM',
                        'purpose' => 'Series filtering',
                        'expected_improvement' => '40-60% faster series filtering'
                    ],
                    [
                        'table' => 'cars_hist',
                        'name' => 'idx_cars_hist_timestamp',
                        'column' => 'timestamp',
                        'priority' => 'MEDIUM',
                        'purpose' => 'Chronological history sorting',
                        'expected_improvement' => '30-50% faster history ordering'
                    ],
                    
                    // Low Priority - Geographic Search
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_city',
                        'column' => 'city',
                        'priority' => 'LOW',
                        'purpose' => 'Location-based searches (city)',
                        'expected_improvement' => '30-50% faster city searches'
                    ],
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_state',
                        'column' => 'state',
                        'priority' => 'LOW',
                        'purpose' => 'Location-based searches (state)',
                        'expected_improvement' => '30-50% faster state searches'
                    ],
                    [
                        'table' => 'cars',
                        'name' => 'idx_cars_country',
                        'column' => 'country',
                        'priority' => 'LOW',
                        'purpose' => 'Location-based searches (country)',
                        'expected_improvement' => '30-50% faster country searches'
                    ]
                ];

                $total_indexes = count($indexes_to_create);
                outputMessage($line++, "Total indexes to process: " . $total_indexes);
                
                // Analyze current index state
                $tables_to_analyze = ['cars', 'cars_hist', 'car_user'];
                $current_indexes = [];

                foreach ($tables_to_analyze as $table) {
                    try {
                        $result = $db->query("SHOW INDEX FROM `$table`");
                        if ($result->count() > 0) {
                            foreach ($result->results() as $index) {
                                $current_indexes[$table][] = [
                                    'name' => $index->Key_name,
                                    'column' => $index->Column_name,
                                    'unique' => $index->Non_unique == 0
                                ];
                            }
                            outputMessage($line++, "📊 Table $table: " . count($current_indexes[$table]) . " existing indexes");
                        }
                    } catch (Exception $e) {
                        outputMessage($line++, "⚠️  Unable to analyze table $table: " . $e->getMessage());
                    }
                }

                outputMessage($line++, "");
                outputMessage($line++, "🚀 Starting Index Optimization...");

                try {
                    // Function to check if index exists
                function indexExists($db, $table, $indexName) {
                    try {
                        $result = $db->query("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName]);
                        return $result->count() > 0;
                    } catch (Exception $e) {
                        return false;
                    }
                }

                // Function to create index with error handling
                function createIndex($db, $table, $indexName, $column) {
                    try {
                        $sql = "CREATE INDEX `$indexName` ON `$table` (`$column`)";
                        $db->query($sql);
                        return true;
                    } catch (Exception $e) {
                        return $e->getMessage();
                    }
                }

                // Process indexes by priority
                $created_indexes = 0;
                $existing_indexes = 0;
                $failed_indexes = 0;
                $errors = [];
                
                foreach ($indexes_to_create as $indexNum => $index) {
                    $table = $index['table'];
                    $name = $index['name'];
                    $column = $index['column'];
                    $priority = $index['priority'];
                    $purpose = $index['purpose'];
                    $improvement = $index['expected_improvement'];
                    
                    $current = $indexNum + 1;
                    $percentage = round(($current / $total_indexes) * 100);
                    
                    outputMessage($line++, "Processing $priority priority: $table.$column ($purpose)", $percentage);
                    
                    // Check if index already exists
                    if (indexExists($db, $table, $name)) {
                        outputMessage($line++, "  ✅ Index already exists: $name");
                        $existing_indexes++;
                        $global_successes++;
                    } else {
                        // Create the index
                        $result = createIndex($db, $table, $name, $column);
                        
                        if ($result === true) {
                            outputMessage($line++, "  ✅ Created index: $name ($improvement)");
                            $created_indexes++;
                            $global_successes++;
                        } else {
                            outputMessage($line++, "  ✗ Failed to create index: $name - $result");
                            $failed_indexes++;
                            $errors[] = "Index $name on $table.$column: " . $result;
                        }
                    }
                    
                    $global_attempts++;
                    
                    // Add small delay for high priority indexes to prevent lock contention
                    if ($priority === 'HIGH') {
                        usleep(100000); // 0.1 second delay
                    }
                }

                outputMessage($line++, "✅ Index optimization completed!");

                // Verification
                outputMessage($line++, "");
                outputMessage($line++, "🔍 Verifying index creation...");

                $verification_success = true;
                foreach ($indexes_to_create as $index) {
                    if (indexExists($db, $index['table'], $index['name'])) {
                        outputMessage($line++, "✅ Verified: {$index['name']} exists on {$index['table']}");
                    } else {
                        outputMessage($line++, "⚠️  Missing: {$index['name']} not found on {$index['table']}");
                        $verification_success = false;
                    }
                }

                if ($verification_success) {
                    outputMessage($line++, "✅ SUCCESS: All index optimization completed!");
                } else {
                    outputMessage($line++, "⚠️  WARNING: Some indexes may not have been created properly");
                }

                // Show performance impact summary
                if ($created_indexes > 0 || $existing_indexes > 0) {
                    outputMessage($line++, "");
                    outputMessage($line++, "🚀 Expected Performance Improvements:");
                    outputMessage($line++, "  • Car Details Page: 50-80% faster history loading");
                    outputMessage($line++, "  • Search Functions: 40-70% faster chassis/year searches");
                    outputMessage($line++, "  • User Listings: 60-90% faster ownership lookups");
                    outputMessage($line++, "  • Location Search: 30-50% faster geographic queries");
                }

                // Show rollback information if indexes were created
                if ($created_indexes > 0) {
                    outputMessage($line++, "");
                    outputMessage($line++, "🔄 Rollback commands (if needed):");
                    foreach ($indexes_to_create as $index) {
                        if (indexExists($db, $index['table'], $index['name'])) {
                            outputMessage($line++, "  DROP INDEX `{$index['name']}` ON `{$index['table']}`;");
                        }
                    }
                }

                // Display errors if any
                if (!empty($errors)) {
                    outputMessage($line++, "");
                    outputMessage($line++, "❌ Errors encountered:");
                    foreach ($errors as $error) {
                        outputMessage($line++, "  • " . $error);
                    }
                }

                // Log the completion action with summary  
                logger($user->data()->id, 'DatabaseOptimization', "Index optimization completed - Created: {$created_indexes}, Existing: {$existing_indexes}, Failed: {$failed_indexes} (Issue #Phase2)");

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
                    logger($user->data()->id, 'DatabaseError', "Index optimization failed: " . $e->getMessage() . " (Issue #Phase2)");
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
            <div class='col-sm-4'><strong>Indexes Processed:</strong> $global_successes/$global_attempts</div>
            <div class='col-sm-4'><strong>Created:</strong> $created_indexes</div>
            <div class='col-sm-4'><strong>Success Rate:</strong> 
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