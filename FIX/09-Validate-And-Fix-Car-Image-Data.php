<?php

declare(strict_types=1);

/**
 * Car Image Data Validation and Correction Script
 *
 * Administrative script to validate and correct image data issues in the car database.
 * Issue #246: Homepage 'One of the Cars' should only select cars with valid images
 *
 * This script addresses multiple image data problems discovered during Issue #246:
 * - Plain string filenames (not JSON format)
 * - CSV format image data  
 * - Trailing whitespace and newlines in filenames
 * - Duplicate filenames within car image arrays
 * - Invalid JSON formatting
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
$settings = getSettings();

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
                                <i class="fa fa-image"></i> Car Image Data Validation & Correction
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script validates and corrects image data issues found in the car database, ensuring proper JSON formatting and file existence validation.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Convert plain string filenames to JSON format: <code>"image.jpg"</code> → <code>["image.jpg"]</code></li>
                                    <li>Convert CSV format to JSON: <code>"img1.jpg,img2.jpg"</code> → <code>["img1.jpg","img2.jpg"]</code></li>
                                    <li>Trim whitespace and newlines from filenames</li>
                                    <li>Remove duplicate filenames within each car's image array</li>
                                    <li>Generate report of images that don't exist on the server (no data removal)</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Safety Features:</h5>
                                <ul class="mb-0">
                                    <li><strong>Backup Required:</strong> Create database backup before proceeding</li>
                                    <li><strong>Preview Mode:</strong> Shows analysis with counts before making changes</li>
                                    <li><strong>Continue/Abort:</strong> Review changes and choose to proceed or cancel</li>
                                    <li><strong>No Data Loss:</strong> Files that don't exist are reported but not removed from database</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Image Data Analysis
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
                    updateProgress(100, 100, 'Image data validation completed successfully!');

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
            <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='../app/admin/manage-consolidated.php?tab=system';}" class="btn btn-outline-primary">
                <i class="fa fa-arrow-left"></i> Return to Admin Console
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
                $analysis_stats = [];
                $missing_files = [];
                
                function outputMessage($lineNum, $message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                // SAFETY: Create backup notification
                outputMessage($line++, "⚠️  SAFETY NOTICE: Backup the cars table before running this script!");
                outputMessage($line++, "Command: mysqldump -u username -p database_name cars > cars_backup.sql");
                outputMessage($line++, "");

                // Analysis before processing
                outputMessage($line++, "🔍 Analyzing car image data...");

                // Get all cars with image data
                $all_cars = $db->query("SELECT id, image FROM cars WHERE image IS NOT NULL AND image != ''")->results();
                $total_cars = count($all_cars);
                outputMessage($line++, "Total cars with image data: " . $total_cars);
                
                // Initialize analysis counters
                $analysis_stats = [
                    'total_cars' => $total_cars,
                    'valid_json' => 0,
                    'plain_string' => 0,
                    'csv_format' => 0,
                    'empty_json_arrays' => 0,
                    'whitespace_issues' => 0,
                    'duplicates_found' => 0,
                    'files_not_exist' => 0,
                    'needs_fixing' => 0
                ];

                outputMessage($line++, "");
                outputMessage($line++, "🔍 Phase 1: Analysis and validation...");

                foreach ($all_cars as $index => $car) {
                    $percentage = round((($index + 1) / $total_cars) * 50); // First 50% for analysis
                    
                    $image_field = $car->image;
                    $car_needs_fixing = false;
                    
                    // Check if it's valid JSON
                    $decoded = json_decode($image_field);
                    if ($decoded !== null && is_array($decoded)) {
                        $analysis_stats['valid_json']++;
                        
                        // Check for empty arrays
                        if (empty($decoded)) {
                            $analysis_stats['empty_json_arrays']++;
                        }
                        
                        // Check for whitespace issues or duplicates
                        $cleaned_images = [];
                        $has_whitespace = false;
                        $has_duplicates = false;
                        
                        foreach ($decoded as $img) {
                            $trimmed = trim($img);
                            if ($trimmed !== $img) {
                                $has_whitespace = true;
                                $car_needs_fixing = true;
                            }
                            if (in_array($trimmed, $cleaned_images)) {
                                $has_duplicates = true;
                                $car_needs_fixing = true;
                            } else {
                                $cleaned_images[] = $trimmed;
                            }
                        }
                        
                        if ($has_whitespace) $analysis_stats['whitespace_issues']++;
                        if ($has_duplicates) $analysis_stats['duplicates_found']++;
                        
                        // Check if files exist
                        $image_dir = $abs_us_root . $us_url_root . $settings->elan_image_dir . $car->id . '/';
                        foreach ($cleaned_images as $img_file) {
                            if (!empty($img_file) && !is_file($image_dir . $img_file)) {
                                $missing_files[] = [
                                    'car_id' => $car->id,
                                    'filename' => $img_file,
                                    'expected_path' => $image_dir . $img_file
                                ];
                                $analysis_stats['files_not_exist']++;
                            }
                        }
                        
                    } else {
                        // Not valid JSON - check if it's CSV or plain string
                        if (strpos($image_field, ',') !== false) {
                            $analysis_stats['csv_format']++;
                            $car_needs_fixing = true;
                        } else {
                            $analysis_stats['plain_string']++;  
                            $car_needs_fixing = true;
                        }
                        
                        // Check for whitespace in non-JSON data
                        if (trim($image_field) !== $image_field) {
                            $analysis_stats['whitespace_issues']++;
                            $car_needs_fixing = true;
                        }
                    }
                    
                    if ($car_needs_fixing) {
                        $analysis_stats['needs_fixing']++;
                    }
                    
                    if ($index % 100 == 0) {
                        outputMessage($line++, "Analyzed " . ($index + 1) . " of " . $total_cars . " cars...", $percentage);
                    }
                }

                outputMessage($line++, "");
                outputMessage($line++, "📊 Analysis Results:");
                outputMessage($line++, "  • Valid JSON format: " . $analysis_stats['valid_json']);
                outputMessage($line++, "  • Plain string format: " . $analysis_stats['plain_string']);
                outputMessage($line++, "  • CSV format: " . $analysis_stats['csv_format']);  
                outputMessage($line++, "  • Empty JSON arrays: " . $analysis_stats['empty_json_arrays']);
                outputMessage($line++, "  • Whitespace issues: " . $analysis_stats['whitespace_issues']);
                outputMessage($line++, "  • Duplicate filenames: " . $analysis_stats['duplicates_found']);
                outputMessage($line++, "  • Missing files on server: " . $analysis_stats['files_not_exist']);
                outputMessage($line++, "  • Cars needing fixes: " . $analysis_stats['needs_fixing']);
                outputMessage($line++, "");

                if (count($missing_files) > 0) {
                    outputMessage($line++, "⚠️  Missing Files Report:");
                    foreach (array_slice($missing_files, 0, 10) as $missing) { // Show first 10
                        outputMessage($line++, "    Car {$missing['car_id']}: {$missing['filename']} (not found at {$missing['expected_path']})");
                    }
                    if (count($missing_files) > 10) {
                        outputMessage($line++, "    ... and " . (count($missing_files) - 10) . " more missing files");
                    }
                    outputMessage($line++, "");
                }

                // User decision point
                if ($analysis_stats['needs_fixing'] > 0) {
                    outputMessage($line++, "🤔 DECISION POINT: " . $analysis_stats['needs_fixing'] . " cars need fixing.");
                    outputMessage($line++, "");
                    outputMessage($line++, "Click 'Continue' to proceed with fixes, or refresh page to abort:");
                    outputMessage($line++, '<button onclick="window.location.href=window.location.href + \'&proceed=1\'" class="btn btn-warning"><i class="fa fa-forward"></i> Continue - Apply Fixes</button> <button onclick="window.location.href=\'index.php\'" class="btn btn-secondary"><i class="fa fa-times"></i> Abort</button>');
                    
                    if (!isset($_GET['proceed']) || $_GET['proceed'] != '1') {
                        outputMessage($line++, "");
                        outputMessage($line++, "⏸️  Process paused. Choose to Continue or Abort above.");
                        
                        // Show preview summary without proceeding
                        echo "<script>
                        showCompletionSummary(`
                            <div class='alert alert-warning'>
                                <h5><i class='fa fa-pause'></i> Analysis Complete - Awaiting User Decision</h5>
                                <div class='row'>
                                    <div class='col-sm-6'><strong>Cars Analyzed:</strong> {$total_cars}</div>
                                    <div class='col-sm-6'><strong>Need Fixes:</strong> {$analysis_stats['needs_fixing']}</div>
                                </div>
                                <div class='row mt-2'>
                                    <div class='col-sm-6'><strong>Missing Files:</strong> " . count($missing_files) . "</div>
                                    <div class='col-sm-6'><strong>Status:</strong> <span class='text-warning'>Paused</span></div>
                                </div>
                            </div>
                        `);
                        </script>";
                        
                        goto end_processing;
                    }
                }

                // Phase 2: Apply fixes if user chose to proceed
                if (isset($_GET['proceed']) && $_GET['proceed'] == '1') {
                    outputMessage($line++, "");
                    outputMessage($line++, "🚀 Phase 2: Applying fixes...");
                    
                    $fix_counts = [
                        'json_conversions' => 0,
                        'whitespace_fixes' => 0,
                        'duplicates_removed' => 0,
                        'total_fixes' => 0
                    ];
                    
                    // Get cars that need fixing
                    $cars_to_fix = [];
                    foreach ($all_cars as $car) {
                        $image_field = $car->image;
                        $decoded = json_decode($image_field);
                        $needs_fix = false;
                        
                        if ($decoded === null || !is_array($decoded)) {
                            $needs_fix = true; // JSON conversion needed
                        } else {
                            // Check for whitespace or duplicates
                            foreach ($decoded as $img) {
                                if (trim($img) !== $img) {
                                    $needs_fix = true;
                                    break;
                                }
                            }
                            if (count($decoded) !== count(array_unique($decoded))) {
                                $needs_fix = true; // Duplicates
                            }
                        }
                        
                        if ($needs_fix) {
                            $cars_to_fix[] = $car;
                        }
                    }
                    
                    $total_fixes = count($cars_to_fix);
                    
                    foreach ($cars_to_fix as $index => $car) {
                        $percentage = 50 + round((($index + 1) / $total_fixes) * 50); // Second 50% for fixes
                        $image_field = $car->image;
                        $original_field = $image_field;
                        
                        // Try to parse as JSON first
                        $decoded = json_decode($image_field);
                        if ($decoded === null || !is_array($decoded)) {
                            // Convert to JSON format
                            if (strpos($image_field, ',') !== false) {
                                // CSV format
                                $images = explode(',', $image_field);
                                $fix_counts['json_conversions']++;
                            } else {
                                // Plain string
                                $images = [$image_field];
                                $fix_counts['json_conversions']++;
                            }
                        } else {
                            $images = $decoded;
                        }
                        
                        // Clean up images array
                        $cleaned_images = [];
                        $had_whitespace = false;
                        $had_duplicates = false;
                        
                        foreach ($images as $img) {
                            $trimmed = trim($img);
                            if ($trimmed !== $img) {
                                $had_whitespace = true;
                            }
                            if (!empty($trimmed) && !in_array($trimmed, $cleaned_images)) {
                                $cleaned_images[] = $trimmed;
                            } elseif (!empty($trimmed)) {
                                $had_duplicates = true;
                            }
                        }
                        
                        if ($had_whitespace) $fix_counts['whitespace_fixes']++;
                        if ($had_duplicates) $fix_counts['duplicates_removed']++;
                        
                        // Convert to JSON
                        $new_json = empty($cleaned_images) ? '' : json_encode($cleaned_images);
                        
                        if ($new_json !== $original_field) {
                            // Update database
                            try {
                                $db->update('cars', $car->id, ['image' => $new_json]);
                                $fix_counts['total_fixes']++;
                                $global_successes++;
                                
                                if ($global_successes % 50 == 0) {
                                    outputMessage($line++, "✅ Fixed " . $global_successes . " of " . $total_fixes . " cars...", $percentage);
                                }
                            } catch (Exception $e) {
                                outputMessage($line++, "✗ Failed to update car {$car->id}: " . $e->getMessage());
                            }
                        }
                        
                        $global_attempts++;
                    }

                    outputMessage($line++, "");
                    outputMessage($line++, "✅ Phase 2 completed!");
                    outputMessage($line++, "  • JSON conversions: " . $fix_counts['json_conversions']);
                    outputMessage($line++, "  • Whitespace fixes: " . $fix_counts['whitespace_fixes']);
                    outputMessage($line++, "  • Duplicates removed: " . $fix_counts['duplicates_removed']);
                    outputMessage($line++, "  • Total cars fixed: " . $fix_counts['total_fixes']);
                }

                outputMessage($line++, "");
                outputMessage($line++, "🔍 Final verification...");

                // Verification
                $final_valid = $db->query("SELECT COUNT(*) as count FROM cars WHERE image <> '' AND image <> '[]' AND JSON_VALID(image) = 1")->first()->count;
                $final_invalid = $db->query("SELECT COUNT(*) as count FROM cars WHERE image <> '' AND image <> '[]' AND (JSON_VALID(image) = 0 OR JSON_VALID(image) IS NULL)")->first()->count;

                outputMessage($line++, "✅ Cars with valid JSON images: " . $final_valid);
                outputMessage($line++, "✅ Cars with invalid image data: " . $final_invalid);
                outputMessage($line++, "✅ SUCCESS: Image data validation completed!");

                // Log the completion action with summary
                logger($user->data()->id, 'DatabaseMaintenance', "Car Image Data Validation completed - Fixed: {$global_successes}/{$global_attempts} cars (Issue #246)");

                // Record script completion
                try {
                    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                    outputMessage($line++, "✅ Script completion recorded");
                    logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
                } catch (Exception $record_e) {
                    outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
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
            <div class='col-sm-6'><strong>Cars Processed:</strong> $global_successes/$global_attempts</div>
            <div class='col-sm-6'><strong>Success Rate:</strong> 
                <span style='color: $rateColor; font-weight: bold;'>
                    <i class='fa fa-$rateIcon'></i> $completionPercentage%
                </span>
            </div>
        </div>
        <div class='row mt-2'>
            <div class='col-sm-6'><strong>Missing Files:</strong> " . count($missing_files) . "</div>
            <div class='col-sm-6'><strong>Valid JSON Now:</strong> $final_valid</div>
        </div>
    `);
    </script>";

                end_processing:
            }

            ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return to Admin Console button -->
<div style="margin-top: 20px; text-align: center;">
    <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='../app/admin/manage-consolidated.php?tab=system';}" class="btn btn-outline-primary">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> Return to Admin Console
    </button>
    <button onclick="window.location.href='index.php?legacy=1';" class="btn btn-outline-secondary ml-2">
        <i class="fa fa-list" aria-hidden="true"></i> Legacy FIX Menu
    </button>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>