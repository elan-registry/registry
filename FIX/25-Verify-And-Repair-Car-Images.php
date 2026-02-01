<?php
declare(strict_types=1);

// Image Verification and Repair FIX Script
// Verifies image references, creates missing thumbnails, fixes extensionless files
// Includes critical safety features: process locking, transactions, filesystem rollback

// Initialize UserSpice and security
require_once '../users/init.php';
securePage($php_self);

// Essential imports
require_once $abs_us_root . $us_url_root . 'usersc/classes/LogCategories.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/Resize.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/Car/CarImageProcessor.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/Exceptions/ImageProcessingException.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/Exceptions/AdminOperationException.php';

use ElanRegistry\Exceptions\ImageProcessingException;
use ElanRegistry\Exceptions\AdminOperationException;

// Configuration and globals
$imageDir = $abs_us_root . $us_url_root . $settings->elan_image_dir;
$orphanDir = $imageDir . 'orphan/';
$thumbnailSizes = array_map('intval', array_filter(explode(',', $settings->elan_image_thumbnail_sizes ?? '100,300,768,1024,2048')));
$maxFileSize = 10485760; // 10MB
$maxBatchTime = 25; // seconds per batch (5s buffer before 30s timeout)

// Error handler for detailed reporting
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    logProgress("PHP Error ({$errno}): {$errstr} in {$errfile}:{$errline}", 'error');
    return true;
});

// Cleanup on shutdown - CRITICAL: Release process lock
register_shutdown_function(function() {
    global $lockFile;
    if (isset($lockFile) && file_exists($lockFile)) {
        @unlink($lockFile);
    }
});

// ============================================================================
// CRITICAL SECURITY FUNCTIONS
// ============================================================================

/**
 * Acquire process lock to prevent concurrent execution
 * CRITICAL: Prevents multiple admins from running script simultaneously
 */
function acquireProcessLock(string $lockFile, int $userId, int $lockTimeout = 3600): bool
{
    // Check for existing lock
    if (file_exists($lockFile)) {
        $lockData = json_decode(file_get_contents($lockFile), true);
        $lockAge = time() - ($lockData['timestamp'] ?? 0);

        if ($lockAge < $lockTimeout) {
            throw new AdminOperationException(
                "Script already running (started by user {$lockData['user']} at " .
                date('Y-m-d H:i:s', $lockData['timestamp']) . ")"
            );
        }

        // Stale lock detected - remove it
        @unlink($lockFile);
        logProgress("Removed stale process lock (age: {$lockAge}s)", 'warning');
    }

    // Create new lock
    $lockData = [
        'user' => $userId,
        'timestamp' => time(),
        'pid' => getmypid()
    ];

    $lockDir = dirname($lockFile);
    if (!is_dir($lockDir)) {
        mkdir($lockDir, 0755, true);
    }

    if (file_put_contents($lockFile, json_encode($lockData)) === false) {
        throw new AdminOperationException("Failed to create process lock file");
    }

    return true;
}

/**
 * Detect image file extension using EXIF only (secure)
 * SECURITY: Uses exif_imagetype() only, avoids GD fallback vulnerability
 */
function detectImageExtension(string $filePath): ?string
{
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return null;
    }

    $imageType = @exif_imagetype($filePath);
    if ($imageType === false) {
        return null; // Could not detect
    }

    $ext = image_type_to_extension($imageType, false);
    if ($ext === false) {
        return null;
    }

    // Normalize 'jpeg' to 'jpg'
    return ($ext === 'jpeg') ? 'jpg' : $ext;
}

/**
 * Validate image file before and after operations
 * SECURITY: Ensures file is valid, readable, and reasonable size
 */
function validateImageFile(string $filePath, int $maxSize = 10485760): bool
{
    // Check existence and readability
    if (!file_exists($filePath) || !is_readable($filePath)) {
        return false;
    }

    // Check file size (not 0, not oversized)
    $size = @filesize($filePath);
    if ($size === false || $size === 0 || $size > $maxSize) {
        return false;
    }

    // Verify it's a valid image type
    $imageType = @exif_imagetype($filePath);
    return $imageType !== false;
}

/**
 * Validate orphan file before copying (SECURITY CRITICAL)
 * Prevents copying potentially malicious files from orphan directory
 */
function validateOrphanFile(string $filePath, int $maxSize = 10485760): bool
{
    // Use same validation as regular images
    if (!validateImageFile($filePath, $maxSize)) {
        return false;
    }

    // Verify file has valid extension for image type
    $ext = detectImageExtension($filePath);
    if ($ext === null) {
        return false;
    }

    // Additional check: verify by opening with GD (confirms it's a real image)
    $imageInfo = @getimagesize($filePath);
    return $imageInfo !== false && isset($imageInfo[0]) && isset($imageInfo[1]);
}

/**
 * Generate missing thumbnails for image
 */
function generateThumbnails(string $sourcePath, string $filename, array $sizes, ?object &$results): void
{
    if (!file_exists($sourcePath)) {
        if ($results !== null) {
            $results->errors[] = "Source file not found: {$filename}";
        }
        return;
    }

    $pathInfo = pathinfo($sourcePath);
    $baseName = $pathInfo['filename'];
    $ext = $pathInfo['extension'];
    $dir = $pathInfo['dirname'];

    foreach ($sizes as $size) {
        try {
            $thumbPath = $dir . '/' . $baseName . '-resized-' . $size . '.' . $ext;

            // Skip if thumbnail already exists
            if (file_exists($thumbPath)) {
                continue;
            }

            $resizer = new Resize($sourcePath);
            $resizer->resizeImage($size, $size, 'auto');
            $resizer->saveImage($thumbPath, 90);

            if ($results !== null) {
                $results->thumbs_generated++;
            }

        } catch (Exception $e) {
            if ($results !== null) {
                $results->errors[] = "Thumbnail generation failed for {$size}px: " . $e->getMessage();
            }
        }
    }
}

/**
 * Update car's image field when filename changes
 */
function updateCarImageFilename(int $carId, string $oldFilename, string $newFilename, object $db): bool
{
    $car = $db->query("SELECT image FROM cars WHERE id = ?", [$carId])->first();
    if (!$car) {
        return false;
    }

    // Decode image array (handle both JSON and legacy CSV)
    $images = [];
    if (!empty($car->image)) {
        $decoded = json_decode($car->image);
        if (is_array($decoded)) {
            $images = $decoded;
        } else {
            $images = array_filter(explode(',', $car->image ?? ''));
        }
    }

    // Replace old with new
    $key = array_search($oldFilename, $images, true);
    if ($key === false) {
        return false;
    }

    $images[$key] = $newFilename;
    $newJson = json_encode(array_values($images));

    try {
        $db->update('cars', $carId, ['image' => $newJson]);
        return true;
    } catch (ImageProcessingException|AdminOperationException $e) {
        throw new ImageProcessingException("Database update failed: " . $e->getMessage());
    }
}

/**
 * Process single car with transaction and filesystem rollback
 * CRITICAL: All operations wrapped in transaction for atomicity
 */
function processCarImages(
    int $carId,
    array $issues,
    string $imageDir,
    string $orphanDir,
    array $thumbnailSizes,
    object $db,
    int $maxFileSize
): array
{
    $result = [
        'car_id' => $carId,
        'files_renamed' => 0,
        'files_recovered' => 0,
        'thumbs_generated' => 0,
        'errors' => [],
        'filesystem_changes' => []
    ];

    // CRITICAL: Wrap in transaction for atomicity
    try {
        $db->query("BEGIN");

        foreach ($issues as $issue) {
            switch ($issue['type']) {

                // Case 1: Rename extensionless files
                case 'no_extension':
                    $oldPath = $imageDir . $carId . '/' . $issue['file'];
                    $ext = detectImageExtension($oldPath);

                    if ($ext === null) {
                        throw new ImageProcessingException("Cannot detect extension for {$issue['file']}");
                    }

                    $newFilename = $issue['file'] . '.' . $ext;
                    $newPath = $imageDir . $carId . '/' . $newFilename;

                    // Prevent overwrite
                    if (file_exists($newPath)) {
                        throw new ImageProcessingException("Target file already exists: {$newFilename}");
                    }

                    // Perform rename
                    if (!rename($oldPath, $newPath)) {
                        throw new ImageProcessingException("Failed to rename: {$issue['file']}");
                    }

                    // CRITICAL: Validate renamed file
                    if (!validateImageFile($newPath, $maxFileSize)) {
                        @rename($newPath, $oldPath); // Immediate rollback
                        throw new ImageProcessingException("Renamed file failed validation: {$newFilename}");
                    }

                    // Track for rollback capability
                    $result['filesystem_changes'][] = [
                        'action' => 'rename',
                        'old' => $oldPath,
                        'new' => $newPath
                    ];

                    // Update database
                    if (!updateCarImageFilename($carId, $issue['file'], $newFilename, $db)) {
                        throw new ImageProcessingException("Database update failed for {$issue['file']}");
                    }

                    $result['files_renamed']++;
                    break;

                // Case 2: Recover from orphan directory
                case 'recoverable':
                    $orphanPath = $orphanDir . $issue['file'];

                    // SECURITY CRITICAL: Validate orphan file before copying
                    if (!validateOrphanFile($orphanPath, $maxFileSize)) {
                        throw new ImageProcessingException("Orphan file failed validation: {$issue['file']} (may be corrupted or malicious)");
                    }

                    $destPath = $imageDir . $carId . '/' . $issue['file'];

                    // Prevent overwrite
                    if (file_exists($destPath)) {
                        throw new ImageProcessingException("Destination file already exists: {$issue['file']}");
                    }

                    // Copy from orphan (not move - preserve backup)
                    if (!copy($orphanPath, $destPath)) {
                        throw new ImageProcessingException("Failed to copy from orphan: {$issue['file']}");
                    }

                    // Verify copy integrity
                    if (!validateImageFile($destPath, $maxFileSize)) {
                        @unlink($destPath); // Immediate cleanup
                        throw new ImageProcessingException("Copied file failed validation: {$issue['file']}");
                    }

                    $result['filesystem_changes'][] = [
                        'action' => 'copy',
                        'path' => $destPath
                    ];

                    $result['files_recovered']++;
                    break;

                // Case 3: Generate missing thumbnails
                case 'missing_thumbnails':
                    $sourcePath = $imageDir . $carId . '/' . $issue['file'];

                    if (!file_exists($sourcePath)) {
                        throw new ImageProcessingException("Source file not found for thumbnails: {$issue['file']}");
                    }

                    generateThumbnails($sourcePath, $issue['file'], $issue['sizes'], $result);
                    break;
            }
        }

        // All operations successful - commit transaction
        $db->query("COMMIT");
        return $result;

    } catch (ImageProcessingException|AdminOperationException $e) {
        // CRITICAL: Rollback transaction
        try {
            $db->query("ROLLBACK");
        } catch (ImageProcessingException|AdminOperationException $rollbackError) {
            // Log but don't fail
            logProgress("Rollback error: " . $rollbackError->getMessage(), 'error');
        }

        // CRITICAL: Reverse filesystem changes in reverse order
        foreach (array_reverse($result['filesystem_changes']) as $change) {
            try {
                if ($change['action'] === 'rename') {
                    if (!file_exists($change['old']) && file_exists($change['new'])) {
                        @rename($change['new'], $change['old']);
                    }
                } elseif ($change['action'] === 'copy') {
                    if (file_exists($change['path'])) {
                        @unlink($change['path']);
                    }
                }
            } catch (ImageProcessingException|AdminOperationException $rollbackError) {
                logProgress("Filesystem rollback error: " . $rollbackError->getMessage(), 'error');
            }
        }

        // Return error result
        $result['errors'][] = "Transaction failed: " . $e->getMessage();
        return $result;
    }
}

/**
 * Verify car images - report phase
 */
function verifyCarImages(
    int $carId,
    ?string $imageJson,
    string $imageDir,
    string $orphanDir,
    array $thumbnailSizes
): array
{
    $issues = [];
    $fullImageDir = $imageDir . $carId . '/';

    // Skip if no image data
    if (empty($imageJson)) {
        return $issues;
    }

    // Decode image list (handle JSON and legacy CSV)
    $images = [];
    $decoded = json_decode($imageJson);
    if (is_array($decoded)) {
        $images = $decoded;
    } else {
        $images = array_filter(explode(',', $imageJson ?? ''));
    }

    if (empty($images)) {
        return $issues;
    }

    // Directory must exist
    if (!is_dir($fullImageDir)) {
        $issues[] = [
            'type' => 'missing_directory',
            'file' => 'N/A',
            'message' => "Directory not found: {$fullImageDir}"
        ];
        return $issues;
    }

    // Check each image
    foreach ($images as $filename) {
        $filePath = $fullImageDir . $filename;

        // Check if file exists
        if (!file_exists($filePath)) {
            // Check orphan directory for recovery
            $orphanPath = $orphanDir . $filename;
            if (file_exists($orphanPath)) {
                $issues[] = [
                    'type' => 'recoverable',
                    'file' => $filename,
                    'message' => 'Found in orphan directory'
                ];
            } else {
                $issues[] = [
                    'type' => 'lost',
                    'file' => $filename,
                    'message' => 'Not found anywhere'
                ];
            }
            continue;
        }

        // Check for extensionless file
        if (strpos($filename, '.') === false) {
            $ext = detectImageExtension($filePath);
            if ($ext === null) {
                $issues[] = [
                    'type' => 'undetectable',
                    'file' => $filename,
                    'message' => 'Cannot detect extension'
                ];
            } else {
                $issues[] = [
                    'type' => 'no_extension',
                    'file' => $filename,
                    'message' => "Can be renamed to .{$ext}"
                ];
            }
            continue;
        }

        // Check for missing thumbnails
        $pathInfo = pathinfo($filePath);
        $baseName = $pathInfo['filename'];
        $ext = $pathInfo['extension'];
        $missingThumbs = [];

        foreach ($thumbnailSizes as $size) {
            $thumbPath = $fullImageDir . $baseName . '-resized-' . $size . '.' . $ext;
            if (!file_exists($thumbPath)) {
                $missingThumbs[] = $size;
            }
        }

        if (!empty($missingThumbs)) {
            $issues[] = [
                'type' => 'missing_thumbnails',
                'file' => $filename,
                'sizes' => $missingThumbs,
                'message' => 'Missing: ' . implode(', ', $missingThumbs) . 'px'
            ];
        }
    }

    return $issues;
}

// ============================================================================
// OUTPUT FUNCTIONS
// ============================================================================

function logProgress(string $message, string $type = 'info'): void
{
    $icons = [
        'info' => 'ℹ️',
        'success' => '✅',
        'error' => '❌',
        'warning' => '⚠️',
        'step' => '▶️'
    ];

    $icon = $icons[$type] ?? '•';
    $timeStr = date('[H:i:s]');
    $text = "{$icon} {$message}";
    $escapedText = htmlspecialchars($text);

    echo "<script>if(window.addLogMessage) addLogMessage('{$timeStr} {$escapedText}');</script>\n";
    flush();
    ob_flush();
}

function outputMessage(string $message, string $type = 'info'): void
{
    $cssClass = match($type) {
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        default => 'alert-info'
    };

    echo "<div class='alert {$cssClass} alert-dismissible fade show' role='alert'>\n";
    echo htmlspecialchars($message);
    echo "<button type='button' class='close' data-dismiss='alert'><span>&times;</span></button>\n";
    echo "</div>\n";
}

// ============================================================================
// MAIN SCRIPT
// ============================================================================

$lockFile = $abs_us_root . $us_url_root . 'FIX/.lock_image_repair';

try {
    // Acquire process lock FIRST THING
    if (!isset($_GET['start'])) {
        acquireProcessLock($lockFile, (int)$user->data()->id);
    }

} catch (AdminOperationException $e) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Image Verification & Repair</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="p-4">
        <div class="container mt-5">
            <h1>🖼️ Verify and Repair Car Images</h1>
            <div class="alert alert-danger mt-4">
                <strong>Cannot Start:</strong> <?php echo htmlspecialchars($e->getMessage()); ?>
            </div>
            <a href="index.php" class="btn btn-secondary">Return to FIX Scripts</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Determine mode (report phase vs fix phase)
$isStarting = isset($_GET['start']);
$isFix = isset($_GET['fix']);

// Report phase - scan and display issues
if (!$isStarting && !$isFix):
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Image Verification & Repair</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #f5f5f5; }
            .container { background: white; padding: 2rem; border-radius: 8px; margin-top: 2rem; }
            .form-group label { font-weight: 600; }
            .btn-primary { padding: 0.75rem 2rem; font-size: 1.1rem; }
        </style>
    </head>
    <body class="p-4">
        <div class="container">
            <h1>🖼️ Verify and Repair Car Images</h1>

            <!-- Initial Description Card -->
            <div class="row" id="descriptionSection">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fas fa-check-circle"></i> Image Verification & Repair
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script verifies all car images and fixes common issues:</p>
                            <ul class="mb-3">
                                <li><strong>Missing Files:</strong> Checks for 404 errors and orphaned images</li>
                                <li><strong>Extensionless Files:</strong> Detects type and adds proper extension</li>
                                <li><strong>Orphan Recovery:</strong> Recovers lost images from backup directory</li>
                                <li><strong>Thumbnails:</strong> Generates missing thumbnail sizes (100, 300, 768, 1024, 2048px)</li>
                                <li><strong>Database Updates:</strong> Updates cars.image field when filenames change</li>
                            </ul>

                            <div class="alert alert-warning">
                                <h5><i class="fas fa-exclamation-triangle"></i> Important Notes</h5>
                                <ul class="mb-0">
                                    <li>This script processes cars in batches to avoid timeouts</li>
                                    <li>A database transaction ensures atomicity - fixes are all-or-nothing per car</li>
                                    <li>Process lock prevents concurrent execution by multiple admins</li>
                                    <li>Orphan files are <strong>copied, not moved</strong> - backup is preserved</li>
                                    <li>All operations are logged for audit trail</li>
                                </ul>
                            </div>

                            <div class="form-group row">
                                <label for="batchSize" class="col-sm-3 col-form-label">Batch Size:</label>
                                <div class="col-sm-4">
                                    <select id="batchSize" class="form-control">
                                        <option value="10" selected>10 cars per batch (Default)</option>
                                        <option value="25">25 cars per batch (Faster)</option>
                                        <option value="50">50 cars per batch (High Performance)</option>
                                        <option value="100">100 cars per batch (Very Fast)</option>
                                    </select>
                                </div>
                                <div class="col-sm-5">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> Smaller batches are safer for slower servers
                                    </small>
                                </div>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fas fa-play"></i> Start Verification
                                </button>
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                            </div>
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
                                ⚙️ Progress
                            </h2>
                            <small class="text-muted">
                                🕐 Started: <span id="startTimeText"></span>
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
                                ⏳ Initializing...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-md-6">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">
                                📊 Summary
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
                                📋 Progress Log
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
                            '✅' :
                            '⏳';
                        statusElement.innerHTML = statusIcon + ' ' + statusMessage;
                    }
                }

                function addLogMessage(message) {
                    const container = document.getElementById('resultsContainer');
                    if (!container) return;
                    const line = document.createElement('div');
                    line.className = 'fix-status-line';

                    if (message.includes('✅')) {
                        line.className += ' text-success';
                    } else if (message.includes('❌') || message.includes('✗')) {
                        line.className += ' text-danger';
                    } else if (message.includes('⚠️') || message.includes('warning')) {
                        line.className += ' text-warning';
                    } else if (message.includes('ℹ️')) {
                        line.className += ' text-info';
                    }

                    line.innerHTML = message;
                    container.appendChild(line);
                    container.scrollTop = container.scrollHeight;
                }

                function startProcessing() {
                    if (processStarted) return;
                    processStarted = true;
                    const batchSize = document.getElementById('batchSize').value;
                    document.getElementById('descriptionSection').style.display = 'none';
                    document.getElementById('progressSection').style.display = '';
                    document.getElementById('logSection').style.display = '';

                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();

                    const params = new URLSearchParams(window.location.search);
                    params.set('start', '1');
                    params.set('batch_size', batchSize);
                    window.location.href = window.location.pathname + '?' + params.toString();
                }

                if (new URLSearchParams(window.location.search).get('start') === '1') {
                    processStarted = true;
                    document.getElementById('descriptionSection').style.display = 'none';
                    document.getElementById('progressSection').style.display = '';
                    document.getElementById('logSection').style.display = '';

                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();
                }
            </script>
        </div>
    </body>
    </html>

    <?php
    exit;

// Processing phases
else:
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Image Verification & Repair - Processing</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #f5f5f5; }
            .well { background: white; padding: 2rem; border-radius: 8px; margin: 2rem auto; }
            .registry-card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); border: 1px solid #e3e6f0; }
            .registry-card .card-header { background: #f8f9fa; border-bottom: 1px solid #e3e6f0; }
            .fix-results-container { max-height: 500px; overflow-y: auto; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 0.875rem; line-height: 1.4; background: #f8f9fa; padding: 1rem; border-radius: 4px; }
            .fix-status-line { margin: 0.25rem 0; padding: 0.125rem 0; }
            .car-progress { height: 25px; }
            .progress-bar { min-width: 50px; font-weight: bold; }
        </style>
        <script>
            let processStarted = false;

            function updateProgress(current, total, statusMessage) {
                if (total === 0) return;
                const percentage = Math.round((current / total) * 100);
                const progressBar = document.getElementById('progressBar');
                if (progressBar) {
                    progressBar.style.width = percentage + '%';
                    progressBar.setAttribute('aria-valuenow', percentage);
                    progressBar.textContent = percentage + '%';
                }

                if (statusMessage) {
                    const statusElement = document.getElementById('currentStatus');
                    if (statusElement) {
                        const statusIcon = percentage >= 100 ?
                            '✅' :
                            '⏳';
                        statusElement.innerHTML = statusIcon + ' ' + statusMessage;
                    }
                }
            }

            function addLogMessage(message) {
                const container = document.getElementById('resultsContainer');
                if (!container) return;
                const line = document.createElement('div');
                line.className = 'fix-status-line';

                if (message.includes('✅')) {
                    line.className += ' text-success';
                } else if (message.includes('❌') || message.includes('✗')) {
                    line.className += ' text-danger';
                } else if (message.includes('⚠️') || message.includes('warning')) {
                    line.className += ' text-warning';
                } else if (message.includes('ℹ️')) {
                    line.className += ' text-info';
                }

                line.innerHTML = message;
                container.appendChild(line);
                container.scrollTop = container.scrollHeight;
            }

            function startProcessing() {
                if (processStarted) return;
                processStarted = true;
                const batchSize = document.getElementById('batchSize').value;
                document.getElementById('descriptionSection').style.display = 'none';
                document.getElementById('progressSection').style.display = '';
                document.getElementById('logSection').style.display = '';

                const now = new Date();
                document.getElementById('startTimeText').textContent = now.toLocaleString();

                const params = new URLSearchParams(window.location.search);
                params.set('start', '1');
                params.set('batch_size', batchSize);
                window.location.href = window.location.pathname + '?' + params.toString();
            }

            window.addEventListener('load', function() {
                if (new URLSearchParams(window.location.search).get('start') === '1') {
                    processStarted = true;
                    const descSection = document.getElementById('descriptionSection');
                    if (descSection) descSection.style.display = 'none';
                    const progSection = document.getElementById('progressSection');
                    if (progSection) progSection.style.display = '';
                    const logSection = document.getElementById('logSection');
                    if (logSection) logSection.style.display = '';

                    const now = new Date();
                    const timeText = document.getElementById('startTimeText');
                    if (timeText) timeText.textContent = now.toLocaleString();
                }
            });
        </script>
    </head>
    <body class="p-4">
        <div class="well">
            <h1>🖼️ Verify and Repair Car Images</h1>

            <?php
            // Get parameters with bounds validation
            $batchSize = (int) ($_GET['batch_size'] ?? 10);
            $offset = (int) ($_GET['offset'] ?? 0);

            // SECURITY: Bounds validation
            if ($batchSize < 1 || $batchSize > 100) {
                $batchSize = 10;
            }
            if ($offset < 0) {
                $offset = 0;
            }

            // Cumulative tracking across batches
            $totalProcessed = (int) ($_GET['total_processed'] ?? 0);
            $totalIssues = (int) ($_GET['total_issues'] ?? 0);
            $totalFixed = (int) ($_GET['total_fixed'] ?? 0);
            $totalErrors = (int) ($_GET['total_errors'] ?? 0);

            // Track all issues for fix phase
            $allIssues = isset($_GET['all_issues']) ? json_decode($_GET['all_issues'], true) : [];

            if ($isStarting && !$isFix):
                // REPORT PHASE - Show progress sections and start processing
                ?>
                <!-- Progress Section -->
                <div class="row mb-4" id="progressSection">
                    <div class="col-lg-6 col-md-6">
                        <div class="card registry-card mb-4">
                            <div class="card-header">
                                <h2 class="mb-0">
                                    ⚙️ Progress
                                </h2>
                                <small class="text-muted">
                                    🕐 Started: <span id="startTimeText"></span>
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
                                    ⏳ Scanning...
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6 col-md-6">
                        <div class="card registry-card mb-4">
                            <div class="card-header">
                                <h2 class="mb-0">
                                    📊 Summary
                                </h2>
                            </div>
                            <div class="card-body" id="summaryContent">
                                <div class="text-muted">
                                    <em>Processing...</em>
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
                                    📋 Progress Log
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
                    document.getElementById('startTimeText').textContent = new Date().toLocaleString();
                </script>

                <?php
                ob_flush();
                flush();

                logProgress("Starting verification scan...", 'step');
                    logProgress("Batch size: {$batchSize} cars, Offset: {$offset}", 'info');

                    // Get total count of cars for progress tracking
                    $totalCarsResult = $db->query(
                        "SELECT COUNT(*) as count FROM cars WHERE image IS NOT NULL AND image != ''"
                    )->first();
                    $totalCarsInDb = $totalCarsResult->count;
                    logProgress("Total cars to process: {$totalCarsInDb}", 'info');

                    // Get all cars with images
                    $allCars = $db->query(
                        "SELECT id, image FROM cars WHERE image IS NOT NULL AND image != '' LIMIT {$batchSize} OFFSET {$offset}"
                    )->results();

                    if (empty($allCars)) {
                        if ($offset === 0) {
                            logProgress("No cars with images found!", 'warning');
                            ?>
                            <div class="alert alert-info mt-4">
                                <p>No cars have image references in the database.</p>
                                <a href="index.php" class="btn btn-secondary">Return to FIX Scripts</a>
                            </div>
                            <?php
                            exit;
                        } else {
                            logProgress("Verification complete!", 'success');
                        }
                    } else {
                        $batchStartTime = time();
                        $batchIssueCount = 0;

                        foreach ($allCars as $index => $car) {
                            $currentPosition = $offset + $index + 1;
                            $overallPosition = $totalProcessed + $index + 1;
                            $percentage = round(($overallPosition / $totalCarsInDb) * 100);

                            logProgress("Scanning car {$currentPosition} (ID: {$car->id})... (Overall: {$overallPosition}/{$totalCarsInDb})", 'step');
                            ?>
                            <script>updateProgress(<?php echo $percentage; ?>, 100, 'Processing: <?php echo $overallPosition; ?>/<?php echo $totalCarsInDb; ?> cars');</script>
                            <?php

                            // Verify images for this car
                            $issues = verifyCarImages(
                                (int)$car->id,
                                $car->image,
                                $imageDir,
                                $orphanDir,
                                $thumbnailSizes
                            );

                            if (!empty($issues)) {
                                foreach ($issues as $issue) {
                                    $allIssues[] = array_merge(['car_id' => (int)$car->id], $issue);
                                    $batchIssueCount++;
                                }
                                logProgress("  Found " . count($issues) . " issue(s)", 'warning');
                            } else {
                                logProgress("  No issues", 'success');
                            }

                            // Timeout check
                            if ((time() - $batchStartTime) > $maxBatchTime) {
                                logProgress("⏱️ Batch time limit reached, preparing next batch...", 'warning');
                                break;
                            }

                            // Small delay
                            usleep(50000);
                            ob_flush();
                            flush();
                        }

                        $newTotalProcessed = $totalProcessed + count($allCars);
                        $newTotalIssues = $totalIssues + $batchIssueCount;
                        $percentageComplete = round(($newTotalProcessed / $totalCarsInDb) * 100);

                        logProgress("Batch processed: {$batchIssueCount} issues found", 'info');
                        ?>
                        <script>updateProgress(<?php echo $percentageComplete; ?>, 100, 'Batch Complete: <?php echo $newTotalProcessed; ?>/<?php echo $totalCarsInDb; ?> cars processed');</script>
                        <?php

                        // Check for next batch
                        $totalCarsInDb = $db->query(
                            "SELECT COUNT(*) as count FROM cars WHERE image IS NOT NULL AND image != ''"
                        )->first()->count;

                        $nextOffset = $offset + $batchSize;

                        if ($nextOffset < $totalCarsInDb) {
                            logProgress("Moving to next batch (offset: {$nextOffset})...", 'step');
                            $nextUrl = $php_self . '?' . http_build_query([
                                'start' => '1',
                                'batch_size' => $batchSize,
                                'offset' => $nextOffset,
                                'total_processed' => $newTotalProcessed,
                                'total_issues' => $newTotalIssues,
                                'all_issues' => json_encode($allIssues)
                            ]);
                            ?>
                            <script>
                                setTimeout(function() {
                                    addLogMessage('🔄 Automatically continuing to next batch...');
                                    window.location.href = '<?php echo $nextUrl; ?>';
                                }, 2000);
                            </script>
                            <?php
                            exit;
                        } else {
                            $newTotalProcessed = $totalCarsInDb;
                            logProgress("All cars scanned!", 'success');
                        }
                    }
                    ?>
                </div>

                <?php
                // Show results summary
                if (empty($allIssues)):
                    ?>
                    <div class="alert alert-success mt-4">
                        <h5>✅ All cars verified successfully!</h5>
                        <p>No image issues found in any of the <?php echo htmlspecialchars((string)$newTotalProcessed); ?> cars processed.</p>
                    </div>

                    <div class="btn-group mt-4">
                        <a href="index.php" class="btn btn-secondary">Return to FIX Scripts</a>
                    </div>

                    <?php
                else:
                    ?>
                    <h3 class="mt-4">⚠️ Issues Found</h3>

                    <div class="table-responsive mt-4">
                        <table class="table table-sm table-striped">
                            <thead>
                                <tr>
                                    <th>Car ID</th>
                                    <th>Issue Type</th>
                                    <th>File</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($allIssues as $issue):
                                    $type = htmlspecialchars($issue['type']);
                                    $file = htmlspecialchars($issue['file']);
                                    $carId = htmlspecialchars((string)$issue['car_id']);
                                    $msg = htmlspecialchars($issue['message'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo $carId; ?></td>
                                        <td><span class="badge badge-warning"><?php echo $type; ?></span></td>
                                        <td><code><?php echo $file; ?></code></td>
                                        <td><?php echo $msg; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-info mt-4">
                        <p><strong>Summary:</strong></p>
                        <ul>
                            <li>Cars processed: <?php echo htmlspecialchars((string)$newTotalProcessed); ?></li>
                            <li>Total issues: <?php echo count($allIssues); ?></li>
                            <li>Issues fixable: <?php echo count(array_filter($allIssues, fn($i) => in_array($i['type'], ['no_extension', 'recoverable', 'missing_thumbnails']))); ?></li>
                        </ul>
                    </div>

                    <div class="btn-group mt-4">
                        <form method="GET" class="d-inline">
                            <input type="hidden" name="fix" value="1">
                            <input type="hidden" name="batch_size" value="<?php echo $batchSize; ?>">
                            <input type="hidden" name="all_issues" value="<?php echo htmlspecialchars(json_encode($allIssues)); ?>">
                            <button type="submit" class="btn btn-danger">🔧 Fix All Issues</button>
                        </form>
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                    </div>

                    <?php
                endif;

            elseif ($isFix):
                // FIX PHASE
                ?>
                <h3 class="mt-4">🔧 Applying Fixes...</h3>

                <div class="log-output" id="logOutput">
                    <?php
                    ob_flush();
                    flush();

                    logProgress("Starting fix phase...", 'step');

                    $fixResults = [
                        'files_renamed' => 0,
                        'files_recovered' => 0,
                        'thumbs_generated' => 0,
                        'errors' => 0,
                        'cars_processed' => 0
                    ];

                    // Group issues by car
                    $issuesByCar = [];
                    foreach ($allIssues as $issue) {
                        $carId = $issue['car_id'];
                        if (!isset($issuesByCar[$carId])) {
                            $issuesByCar[$carId] = [];
                        }
                        $issuesByCar[$carId][] = $issue;
                    }

                    $processStartTime = time();

                    foreach ($issuesByCar as $carId => $carIssues) {
                        logProgress("Processing car {$carId} ({$fixResults['cars_processed']}/{count($issuesByCar)})...", 'step');

                        // Filter fixable issues
                        $fixableIssues = array_filter($carIssues, function($issue) {
                            return in_array($issue['type'], ['no_extension', 'recoverable', 'missing_thumbnails']);
                        });

                        if (empty($fixableIssues)) {
                            logProgress("  No fixable issues", 'info');
                            $fixResults['cars_processed']++;
                            continue;
                        }

                        // Process with transaction
                        try {
                            $result = processCarImages(
                                (int)$carId,
                                $fixableIssues,
                                $imageDir,
                                $orphanDir,
                                $thumbnailSizes,
                                $db,
                                $maxFileSize
                            );

                            if (!empty($result['errors'])) {
                                logProgress("  ❌ Errors: " . implode(', ', $result['errors']), 'error');
                                $fixResults['errors'] += count($result['errors']);
                            }

                            $fixResults['files_renamed'] += $result['files_renamed'];
                            $fixResults['files_recovered'] += $result['files_recovered'];
                            $fixResults['thumbs_generated'] += $result['thumbs_generated'];

                            if ($result['files_renamed'] > 0) {
                                logProgress("  ✅ {$result['files_renamed']} file(s) renamed", 'success');
                            }
                            if ($result['files_recovered'] > 0) {
                                logProgress("  ✅ {$result['files_recovered']} file(s) recovered from orphan", 'success');
                            }
                            if ($result['thumbs_generated'] > 0) {
                                logProgress("  ✅ {$result['thumbs_generated']} thumbnail(s) generated", 'success');
                            }

                        } catch (ImageProcessingException|AdminOperationException $e) {
                            logProgress("  ❌ " . $e->getMessage(), 'error');
                            $fixResults['errors']++;
                        }

                        $fixResults['cars_processed']++;

                        // Timeout check
                        if ((time() - $processStartTime) > $maxBatchTime) {
                            logProgress("⏱️ Timeout approaching, finishing current car...", 'warning');
                            break;
                        }

                        usleep(50000);
                        ob_flush();
                        flush();
                    }

                    logProgress("Fix phase complete!", 'success');
                    ?>
                </div>

                <div class="alert alert-success mt-4">
                    <h5>✅ Fixes Applied</h5>
                    <ul>
                        <li>Cars processed: <?php echo $fixResults['cars_processed']; ?></li>
                        <li>Files renamed: <?php echo $fixResults['files_renamed']; ?></li>
                        <li>Files recovered: <?php echo $fixResults['files_recovered']; ?></li>
                        <li>Thumbnails generated: <?php echo $fixResults['thumbs_generated']; ?></li>
                        <li>Errors: <?php echo $fixResults['errors']; ?></li>
                    </ul>
                </div>

                <?php
                // Log script completion
                try {
                    $db->insert('fix_script_runs', [
                        'script_name' => 'Verify-And-Repair-Car-Images',
                        'completed_at' => date('Y-m-d H:i:s')
                    ]);

                    logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT,
                        "Image verification & repair completed - Renamed: {$fixResults['files_renamed']}, " .
                        "Recovered: {$fixResults['files_recovered']}, Thumbnails: {$fixResults['thumbs_generated']}, " .
                        "Errors: {$fixResults['errors']}"
                    );
                } catch (Exception $e) {
                    logProgress("Log error: " . $e->getMessage(), 'warning');
                }
                ?>

                <div class="btn-group mt-4">
                    <a href="<?php echo $php_self; ?>" class="btn btn-primary">Run Verification Again</a>
                    <a href="index.php" class="btn btn-secondary">Return to FIX Scripts</a>
                </div>

                <?php
            endif;
            ?>

        </div>
    </body>
    </html>

    <?php
endif;
