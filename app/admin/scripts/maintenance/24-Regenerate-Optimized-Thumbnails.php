<?php

declare(strict_types=1);

use ElanRegistry\LogCategories;
use ElanRegistry\Resize;

/**
 * Thumbnail Optimization Script (v2.13.0)
 *
 * Administrative script to regenerate thumbnails for optimal mobile/responsive performance.
 * Issue #176: Max image size for upload and display should be configurable
 *
 * This script:
 * 1. Updates the elan_image_thumbnail_sizes setting from 600px to 768px
 * 2. Generates new 768px thumbnails (tablet/mobile landscape size)
 * 3. Removes old 600px thumbnails (underutilized size)
 * 4. Preserves existing 100px, 300px, 1024px, and 2048px thumbnails
 * 5. Uses existing high-quality source images (1024px or 2048px) for regeneration
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'app/admin/includes/fix-script-core.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Get the database instance
$db = DB::getInstance();
$settings = getSettings();

$currentSizes = $settings->elan_image_thumbnail_sizes ?? '100,300,600,1024,2048';

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

            <?php $is_initial = admin_script_exec_requested(); ?>
            <?php if ($is_initial): ?>
            <script>document.addEventListener('DOMContentLoaded', function() {
                var el = document.getElementById('startTimeText');
                if (el) el.textContent = new Date().toLocaleString();
            });</script>
            <?php endif; ?>

            <!-- Initial Description Card -->
            <div class="row" id="descriptionSection"<?= $is_initial ? ' style="display:none;"' : '' ?>>
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-images"></i> Thumbnail Optimization Script
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script updates the thumbnail size configuration and regenerates thumbnails for better mobile and responsive performance.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Updates elan_image_thumbnail_sizes setting to replace 600px with 768px</li>
                                    <li>Generates new 768px thumbnails for tablet/mobile landscape viewing</li>
                                    <li>Removes old 600px thumbnails to save disk space</li>
                                    <li>Preserves existing 100px, 300px, 1024px, and 2048px thumbnails</li>
                                    <li>Uses existing high-quality source images (1024px or 2048px) for regeneration</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-cogs"></i> Batch Processing Configuration</h5>
                                <p class="mb-3">This script uses batch processing to prevent timeouts. You can adjust the batch size based on your server's capabilities.</p>

                                <div class="form-group row">
                                    <label for="batchSize" class="col-sm-3 col-form-label">Batch Size:</label>
                                    <div class="col-sm-4">
                                        <select id="batchSize" class="form-control">
                                            <option value="5">5 cars per batch (Safe)</option>
                                            <option value="10" selected>10 cars per batch (Default)</option>
                                            <option value="15">15 cars per batch (Faster)</option>
                                            <option value="25">25 cars per batch (High Performance)</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-5">
                                        <small class="text-muted">
                                            <i class="fa fa-info-circle"></i> Smaller batches are safer for slower servers
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="text-center">
                                <?= admin_script_start_form('Start Thumbnail Optimization') ?>
                                <script>
                                document.currentScript.previousElementSibling.addEventListener('submit', function(e) {
                                    var sel = document.getElementById('batchSize');
                                    if (sel) {
                                        var input = document.createElement('input');
                                        input.type = 'hidden'; input.name = 'batch_size'; input.value = sel.value;
                                        e.target.appendChild(input);
                                    }
                                });
                                </script>
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
            <button onclick="if(window.opener){window.opener.location.reload();} window.close();" class="btn btn-primary">
                <i class="fa fa-times"></i> Close Window
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

                    // Get selected batch size
                    const batchSize = document.getElementById('batchSize').value;

                    // Hide description section
                    document.getElementById('descriptionSection').style.display = 'none';

                    // Set start time
                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();

                    // Start the actual processing with batch size
                    const params = new URLSearchParams(window.location.search);
                    params.set('start', '1');
                    params.set('batch_size', batchSize);

                    window.location.href = window.location.pathname + '?' + params.toString();
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
            $is_initial      = admin_script_exec_requested();
            $is_continuation = $method === 'GET' && (int) ($_GET['start'] ?? 0) === 1
                               && (int) ($_GET['offset'] ?? 0) > 0
                               && isset($_SESSION['thumb_batch_token'])
                               && hash_equals($_SESSION['thumb_batch_token'], $_GET['batch_token'] ?? '');

            if ($is_initial || $is_continuation) {

                // Update thumbnail sizes setting: replace 600 with 768
                $newSizes = $currentSizes;
                if (strpos($currentSizes, '600') !== false) {
                    $newSizes = str_replace('600', '768', $currentSizes);
                    try {
                        $db->query("UPDATE settings SET elan_image_thumbnail_sizes = ? WHERE id = 1", [$newSizes]);
                        $settings->elan_image_thumbnail_sizes = $newSizes;
                    } catch (Exception $e) {
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Failed to update elan_image_thumbnail_sizes setting: " . $e->getMessage() . " (Issue #176)");
                    }
                }

                // Generate or retrieve the per-session batch nonce (guards continuation GETs against CSRF)
                if ($is_initial) {
                    try {
                        $_SESSION['thumb_batch_token'] = bin2hex(random_bytes(16));
                    } catch (\Random\RandomException $e) {
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, 'Failed to generate batch token: ' . $e->getMessage());
                        outputMessage('❌ Failed to generate a secure batch token. Cannot start batch processing safely.');
                        outputMessage('Please try again. If this persists, check server entropy availability.');
                        exit;
                    }
                }
                $batch_token = $_SESSION['thumb_batch_token'] ?? '';

                // Batch processing parameters
                $allowed_batch_sizes = [5, 10, 15, 25];
                $raw_batch           = $is_continuation ? (int) ($_GET['batch_size'] ?? 10) : (int) ($_POST['batch_size'] ?? 10);
                $batch_size          = in_array($raw_batch, $allowed_batch_sizes, true) ? $raw_batch : 10;
                $offset               = $is_continuation ? (int) ($_GET['offset'] ?? 0) : 0;
                $total_processed_prev = $is_continuation ? (int) ($_GET['total_processed'] ?? 0) : 0;

                // Initialize global counters (for this batch)
                $global_processed = 0;
                $global_generated = 0;
                $global_removed = 0;
                $global_errors = 0;

                // Cumulative counters (including previous batches)
                $cumulative_processed = $total_processed_prev;
                $cumulative_generated = $is_continuation ? (int) ($_GET['total_generated'] ?? 0) : 0;
                $cumulative_removed   = $is_continuation ? (int) ($_GET['total_removed'] ?? 0) : 0;
                $cumulative_errors    = $is_continuation ? (int) ($_GET['total_errors'] ?? 0) : 0;

                // Track batch start time for timeout management
                $batch_start_time = time();
                $max_batch_time = 25; // Allow 25 seconds per batch (5s buffer)
                
                function outputMessage(string $message, ?int $percentage = null): void {
                    $jsMessage = json_encode($message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    echo '<script>addLogMessage(' . $jsMessage . ');</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, ' . $jsMessage . ');</script>';
                    }
                    ob_flush();
                    flush();
                }

                outputMessage("=== THUMBNAIL OPTIMIZATION STARTED ===");
                outputMessage("Timestamp: " . date('Y-m-d H:i:s'));
                outputMessage("");

                // Report settings update
                if (strpos($currentSizes, '600') !== false) {
                    outputMessage("⚙️ Updated elan_image_thumbnail_sizes: {$currentSizes} → {$newSizes}");
                } else {
                    outputMessage("✅ elan_image_thumbnail_sizes already correct: {$currentSizes}");
                }
                outputMessage("");

                // Get image directory path
                $imageBasePath = $abs_us_root . $us_url_root . $settings->elan_image_dir;
                outputMessage("🔍 Image base path: " . $imageBasePath);

                // Get total count of cars with images (for overall progress tracking)
                $total_cars_result = $db->query("SELECT COUNT(*) as count FROM cars WHERE image IS NOT NULL AND image != ''")->results();
                $total_cars = $total_cars_result[0]->count;

                // Get batched cars with images for processing
                $cars_with_images = $db->query("SELECT id, image FROM cars WHERE image IS NOT NULL AND image != '' LIMIT {$batch_size} OFFSET {$offset}")->results();
                $batch_car_count = count($cars_with_images);
                
                outputMessage("📊 Found {$total_cars} total cars with image data");
                outputMessage("📦 Processing batch: " . ($offset + 1) . " to " . min($offset + $batch_size, $total_cars) . " (batch size: {$batch_size})");

                if ($total_processed_prev > 0) {
                    outputMessage("📈 Previously processed: {$total_processed_prev} cars");
                }
                outputMessage("");

                if ($total_cars == 0) {
                    outputMessage("ℹ️  No cars with images found. Process complete.");
                    echo '<script>showCompletionSummary("<p>No cars with images to process.</p>");</script>';
                    exit;
                }

                if ($batch_car_count == 0) {
                    outputMessage("✅ All cars have been processed! Batch processing complete.");

                    // Final summary with cumulative stats
                    $final_stats = "
                        <div class='row'>
                            <div class='col-sm-3'><strong>Total Cars:</strong> {$cumulative_processed}</div>
                            <div class='col-sm-3'><strong>768px Generated:</strong> {$cumulative_generated}</div>
                            <div class='col-sm-3'><strong>600px Removed:</strong> {$cumulative_removed}</div>
                            <div class='col-sm-3'><strong>Errors:</strong> {$cumulative_errors}</div>
                        </div>";

                    echo "<script>showCompletionSummary(`$final_stats`);</script>";

                    // Log to fix_script_runs table
                    try {
                        $db->insert('fix_script_runs', [
                            'script_name' => basename(__FILE__),
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);
                    } catch (Exception $e) {
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Failed to record fix_script_runs completion: " . $e->getMessage() . " (Issue #176)");
                        outputMessage("⚠️ Warning: Could not record script completion in fix_script_runs table");
                    }

                    // Log final completion
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Thumbnail optimization completed (batched) - Total Processed: {$cumulative_processed}, Generated: {$cumulative_generated}, Removed: {$cumulative_removed}, Errors: {$cumulative_errors} (Issue #176)");
                    exit;
                }

                outputMessage("🚀 Starting batch thumbnail optimization...");
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

                        // Calculate progress within current batch and overall progress
                        $current_car_overall = $offset + $index + 1;
                        $percentage = (int) round(($current_car_overall / $total_cars) * 100);
                        outputMessage("Processing Car ID {$car_id} (Overall: {$current_car_overall}/{$total_cars}, Batch: " . ($index + 1) . "/{$batch_car_count})...", $percentage);
                        
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
                        $realCarImageDir = realpath($car_image_dir);

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
                                $realOld600 = realpath($old_600);
                                // Trailing '/' is intentional: prevents /path/cars/1 from matching /path/cars/10/file.jpg
                                if ($realOld600 === false || $realCarImageDir === false || !str_starts_with($realOld600, $realCarImageDir . '/')) {
                                    outputMessage("    ⚠️  Path validation failed for: {$base_name}.{$extension} — skipping");
                                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Path boundary check failed for thumbnail deletion: {$old_600}");
                                    $global_errors++;
                                } elseif (unlink($realOld600)) { // nosemgrep: php.lang.security.unlink-use.unlink-use -- path verified within car image directory
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

                        // Check for timeout - if we're running close to limit, stop batch here
                        if ((time() - $batch_start_time) > $max_batch_time) {
                            outputMessage("⚠️ Batch time limit reached, preparing for next batch...");
                            break;
                        }

                        // Small delay to prevent server overload
                        usleep(100000); // 0.1 second
                    }

                    // Update cumulative counters
                    $cumulative_processed += $global_processed;
                    $cumulative_generated += $global_generated;
                    $cumulative_removed += $global_removed;
                    $cumulative_errors += $global_errors;

                    // Check if there are more batches to process
                    $next_offset = $offset + $batch_size;
                    if ($next_offset < $total_cars) {
                        outputMessage("📦 Batch complete! Continuing to next batch...");
                        outputMessage("📈 Progress: Processed {$cumulative_processed}/{$total_cars} cars");

                        // Build URL for next batch
                        $next_url = $php_self . '?' . http_build_query([
                            'start' => '1',
                            'batch_token' => $batch_token,
                            'batch_size' => $batch_size,
                            'offset' => $next_offset,
                            'total_processed' => $cumulative_processed,
                            'total_generated' => $cumulative_generated,
                            'total_removed' => $cumulative_removed,
                            'total_errors' => $cumulative_errors
                        ]);

                        // Log this batch completion
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Batch completed - Batch: " . (floor($offset / $batch_size) + 1) . ", Cars: {$global_processed}, Generated: {$global_generated}, Removed: {$global_removed}, Errors: {$global_errors} (Issue #176)");

                        // Auto-redirect to next batch after 2 seconds
                        $next_url_js = json_encode($next_url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                        echo "<script>
                            setTimeout(function() {
                                addLogMessage('🔄 Automatically continuing to next batch...');
                                window.location.href = {$next_url_js};
                            }, 2000);
                        </script>";

                        // Don't show completion summary yet
                        exit;
                    }

                    outputMessage("✅ All batches completed! Thumbnail optimization finished!");

                    // Log to fix_script_runs table
                    try {
                        $db->insert('fix_script_runs', [
                            'script_name' => basename(__FILE__),
                            'completed_at' => date('Y-m-d H:i:s')
                        ]);
                    } catch (Exception $e) {
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Failed to record fix_script_runs completion: " . $e->getMessage() . " (Issue #176)");
                        outputMessage("⚠️ Warning: Could not record script completion in fix_script_runs table");
                    }

                    // Log the final completion
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Thumbnail optimization completed (batched) - Total Processed: {$cumulative_processed}, Generated: {$cumulative_generated}, Removed: {$cumulative_removed}, Errors: {$cumulative_errors} (Issue #176)");

                } catch (Exception $e) {
                    // Update cumulative counters even if there's an error
                    $cumulative_processed += $global_processed;
                    $cumulative_generated += $global_generated;
                    $cumulative_removed += $global_removed;
                    $cumulative_errors += $global_errors + 1; // +1 for the current exception

                    outputMessage("❌ ERROR during batch processing: " . $e->getMessage());

                    // Check if this might be a timeout error
                    if (strpos($e->getMessage(), 'Maximum execution time') !== false ||
                        strpos($e->getMessage(), 'timeout') !== false) {

                        outputMessage("⚠️ Timeout detected - this batch will be retried");
                        outputMessage("📈 Progress so far: Processed {$cumulative_processed}/{$total_cars} cars");

                        // Calculate resume point (current batch with current progress)
                        $resume_url = $php_self . '?' . http_build_query([
                            'start' => '1',
                            'batch_token' => $batch_token,
                            'batch_size' => $batch_size,
                            'offset' => $offset, // Resume from same batch
                            'total_processed' => $cumulative_processed,
                            'total_generated' => $cumulative_generated,
                            'total_removed' => $cumulative_removed,
                            'total_errors' => $cumulative_errors
                        ]);

                        $resume_url_attr = htmlspecialchars($resume_url, ENT_QUOTES, 'UTF-8');
                        outputMessage("🔄 You can resume processing by refreshing the page or clicking the button below:");
                        echo "<div style='text-align: center; margin: 20px;'>
                            <button onclick='window.location.href=\"{$resume_url_attr}\"' class='btn btn-warning'>
                                <i class='fa fa-refresh'></i> Resume Batch Processing
                            </button>
                        </div>";

                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Batch timeout - Batch: " . (floor($offset / $batch_size) + 1) . ", Partial progress saved. Resume URL available. (Issue #176)");
                    } else {
                        outputMessage("Processing aborted - partial changes may have been made");
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "Thumbnail optimization failed: " . $e->getMessage() . " (Issue #176)");
                    }
                }

                outputMessage("");
                outputMessage("Script completed at " . date("h:i:sa"));

                // Calculate final stats and show completion summary
                $completionPercentage = $cumulative_processed > 0 ? 100 : 0;

                // Determine color based on success rate
                $rateColor = '#dc3545'; // red (default for errors)
                $rateIcon = 'exclamation-circle';
                if ($cumulative_errors == 0) {
                    $rateColor = '#28a745'; // green
                    $rateIcon = 'check-circle';
                } elseif ($cumulative_errors < ($cumulative_generated + $cumulative_removed) / 2) {
                    $rateColor = '#ffc107'; // yellow
                    $rateIcon = 'exclamation-triangle';
                }

                $statsHtml = "
                    <div class='row'>
                        <div class='col-sm-3'><strong>Cars Processed:</strong> $cumulative_processed</div>
                        <div class='col-sm-3'><strong>768px Generated:</strong> $cumulative_generated</div>
                        <div class='col-sm-3'><strong>600px Removed:</strong> $cumulative_removed</div>
                        <div class='col-sm-3'><strong>Errors:</strong>
                            <span style='color: $rateColor; font-weight: bold;'>
                                <i class='fa fa-$rateIcon'></i> $cumulative_errors
                            </span>
                        </div>
                    </div>";

                echo "<script>showCompletionSummary(`$statsHtml`);</script>";
                unset($_SESSION['thumb_batch_token']);
            } elseif ($method === 'GET' && (int) ($_GET['start'] ?? 0) === 1) {
                logger($user->data()->id, LogCategories::LOG_CATEGORY_SECURITY, 'Batch continuation rejected (session expired or token mismatch)');
                echo '<div class="alert alert-warning mt-3"><strong>Session Expired</strong> Your processing session expired. <a href="'
                    . htmlspecialchars($php_self, ENT_QUOTES, 'UTF-8')
                    . '">Start over</a> to process all thumbnails.</div>';
            }
            ?>

        </div> <!-- well -->
    </div><!-- Container -->
</div> <!-- page-wrapper -->

<!-- Return to Admin Console button -->
<div style="margin-top: 20px; text-align: center;">
    <?= admin_script_close_button('', '../../maintenance.php?tab=maintenance') ?>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer ?>