<?php

/**
 * Thumbnail Optimization Script
 *
 * Administrative script to regenerate thumbnails for optimal mobile/responsive performance.
 * Issue #176: Max image size for upload and display should be configurable
 *
 * This script optimizes the thumbnail generation system by:
 * - Generating new 768px thumbnails (tablet/mobile landscape size)
 * - Removing old 600px thumbnails (underutilized size)
 * - Preserving existing 100px, 300px, 1024px, and 2048px thumbnails
 * - Using existing high-quality source images (1024px or 2048px) for regeneration
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
                                <i class="fa fa-images"></i> Thumbnail Optimization Script
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script optimizes thumbnail sizes for better mobile and responsive performance by generating new 768px thumbnails and removing underutilized 600px thumbnails.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Generates new 768px thumbnails for tablet/mobile landscape viewing</li>
                                    <li>Removes old 600px thumbnails to save disk space</li>
                                    <li>Preserves existing 100px, 300px, 1024px, and 2048px thumbnails</li>
                                    <li>Uses existing high-quality source images (1024px or 2048px) for regeneration</li>
                                    <li>Processes all car images in the registry database</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Thumbnail Optimization
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
                    updateProgress(100, 100, 'Thumbnail Optimization completed successfully!');

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
                $global_processed = 0;
                $global_generated = 0;
                $global_removed = 0;
                $global_errors = 0;
                
                function outputMessage($message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                // Include Resize class for thumbnail generation
                require_once $abs_us_root . $us_url_root . 'usersc/classes/Resize.php';

                outputMessage("=== THUMBNAIL OPTIMIZATION STARTED ===");
                outputMessage("Timestamp: " . date('Y-m-d H:i:s'));
                outputMessage("");

                // Get image directory path
                $imageBasePath = $abs_us_root . $us_url_root . $settings->elan_image_dir;
                outputMessage("🔍 Image base path: " . $imageBasePath);

                // Get all cars with images
                $cars_with_images = $db->query("SELECT id, image FROM cars WHERE image IS NOT NULL AND image != ''")->results();
                $total_cars = count($cars_with_images);
                
                outputMessage("📊 Found {$total_cars} cars with image data");
                outputMessage("");

                if ($total_cars == 0) {
                    outputMessage("ℹ️  No cars with images found. Process complete.");
                    echo '<script>showCompletionSummary("<p>No cars with images to process.</p>");</script>';
                    exit;
                }

                outputMessage("🚀 Starting thumbnail optimization...");
                outputMessage("");

                try {
                    foreach ($cars_with_images as $index => $car) {
                        $car_id = $car->id;
                        $car_images = [];
                        
                        // Parse image data
                        if (!empty($car->image)) {
                            $decoded = json_decode($car->image);
                            if ($decoded !== null && is_array($decoded)) {
                                $car_images = $decoded;
                            } else {
                                // Handle non-JSON format (comma-separated)
                                $car_images = array_filter(explode(',', $car->image));
                            }
                        }

                        $percentage = round((($index + 1) / $total_cars) * 100);
                        outputMessage("Processing Car ID {$car_id} (" . ($index + 1) . "/{$total_cars})...", $percentage);
                        
                        if (empty($car_images)) {
                            outputMessage("  ⚠️  No images found for Car ID {$car_id}");
                            continue;
                        }

                        $car_image_dir = $imageBasePath . $car_id . '/';
                        
                        if (!is_dir($car_image_dir)) {
                            outputMessage("  ❌ Image directory not found: {$car_image_dir}");
                            $global_errors++;
                            continue;
                        }

                        $car_generated = 0;
                        $car_removed = 0;

                        foreach ($car_images as $image_name) {
                            if (empty($image_name)) {
                                continue;
                            }
                            
                            $base_name = pathinfo($image_name, PATHINFO_FILENAME);
                            $extension = pathinfo($image_name, PATHINFO_EXTENSION);
                            
                            // Skip files that are already thumbnails
                            if (strpos($base_name, '-resized-') !== false) {
                                continue;
                            }

                            // Define file paths
                            $source_1024 = $car_image_dir . $base_name . '-resized-1024.' . $extension;
                            $source_2048 = $car_image_dir . $base_name . '-resized-2048.' . $extension;
                            $target_768 = $car_image_dir . $base_name . '-resized-768.' . $extension;
                            $old_600 = $car_image_dir . $base_name . '-resized-600.' . $extension;

                            // Generate 768px thumbnail if it doesn't exist
                            if (!file_exists($target_768)) {
                                $source_file = null;
                                
                                // Prefer 1024px source, fallback to 2048px
                                if (file_exists($source_1024)) {
                                    $source_file = $source_1024;
                                } elseif (file_exists($source_2048)) {
                                    $source_file = $source_2048;
                                }
                                
                                if ($source_file) {
                                    try {
                                        $resizer = new Resize($source_file);
                                        $resizer->resizeImage(768, 768, 'landscape');
                                        $resizer->saveImage($target_768, 90);
                                        
                                        if (file_exists($target_768)) {
                                            outputMessage("    ✅ Generated 768px: {$base_name}.{$extension}");
                                            $car_generated++;
                                        } else {
                                            outputMessage("    ❌ Failed to generate 768px: {$base_name}.{$extension}");
                                            $global_errors++;
                                        }
                                    } catch (Exception $e) {
                                        outputMessage("    ❌ Error generating 768px {$base_name}.{$extension}: " . $e->getMessage());
                                        $global_errors++;
                                    }
                                } else {
                                    outputMessage("    ⚠️  No suitable source image for 768px generation: {$base_name}.{$extension}");
                                }
                            }

                            // Remove 600px thumbnail if it exists
                            if (file_exists($old_600)) {
                                if (unlink($old_600)) {
                                    outputMessage("    🗑️  Removed 600px: {$base_name}.{$extension}");
                                    $car_removed++;
                                } else {
                                    outputMessage("    ❌ Failed to remove 600px: {$base_name}.{$extension}");
                                    $global_errors++;
                                }
                            }
                        }

                        if ($car_generated > 0 || $car_removed > 0) {
                            outputMessage("  📊 Car ID {$car_id}: Generated={$car_generated}, Removed={$car_removed}");
                        } else {
                            outputMessage("  ℹ️  Car ID {$car_id}: No changes needed");
                        }

                        $global_processed++;
                        $global_generated += $car_generated;
                        $global_removed += $car_removed;
                        
                        outputMessage("");
                        
                        // Small delay to prevent server overload
                        usleep(100000); // 0.1 second
                    }

                    outputMessage("✅ Thumbnail Optimization completed successfully!");

                    // Log the completion action with summary
                    logger($user->data()->id, 'ThumbnailOptimization', "Thumbnail optimization completed - Processed: {$global_processed}, Generated: {$global_generated}, Removed: {$global_removed}, Errors: {$global_errors} (Issue #176)");

                } catch (Exception $e) {
                    outputMessage("❌ ERROR during processing: " . $e->getMessage());
                    outputMessage("Processing aborted - partial changes may have been made");
                    
                    // Log the error
                    logger($user->data()->id, 'ThumbnailOptimization', "Thumbnail optimization failed: " . $e->getMessage() . " (Issue #176)");
                    $global_errors++;
                }

                outputMessage("");
                outputMessage("Script completed at " . date("h:i:sa"));

                // Calculate final stats and show completion summary
                $completionPercentage = $global_processed > 0 ? 100 : 0;

                // Determine color based on success rate
                $rateColor = '#dc3545'; // red (default for errors)
                $rateIcon = 'exclamation-circle';
                if ($global_errors == 0) {
                    $rateColor = '#28a745'; // green
                    $rateIcon = 'check-circle';
                } elseif ($global_errors < ($global_generated + $global_removed) / 2) {
                    $rateColor = '#ffc107'; // yellow
                    $rateIcon = 'exclamation-triangle';
                }

                $statsHtml = "
                    <div class='row'>
                        <div class='col-sm-3'><strong>Cars Processed:</strong> $global_processed</div>
                        <div class='col-sm-3'><strong>768px Generated:</strong> $global_generated</div>
                        <div class='col-sm-3'><strong>600px Removed:</strong> $global_removed</div>
                        <div class='col-sm-3'><strong>Errors:</strong> 
                            <span style='color: $rateColor; font-weight: bold;'>
                                <i class='fa fa-$rateIcon'></i> $global_errors
                            </span>
                        </div>
                    </div>";

                echo "<script>showCompletionSummary(`$statsHtml`);</script>";
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
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer ?>