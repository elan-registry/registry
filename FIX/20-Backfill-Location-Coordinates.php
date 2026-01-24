<?php

declare(strict_types=1);

/**
 * Backfill Location Coordinates Script
 *
 * Administrative script to geocode existing owner profiles and standardize location data.
 * Issue #245: Modern Location Collection (OpenStreetMap Integration)
 *
 * This script performs three operations:
 * 1. Backfills missing lat/lon coordinates for profiles with city/state/country data
 * 2. Standardizes ALL location names for profiles with coordinates (fixes "WV"→"West Virginia", "OR"→"Oregon", etc.)
 * 3. Syncs updated location data from profiles to all cars owned by each user
 *
 * Uses OpenStreetMap Nominatim API with strict 1-second rate limiting to comply with
 * usage policy. Supports resume capability for interrupted executions.
 *
 * BATCH PROCESSING:
 * - Processes profiles in batches to prevent PHP timeouts
 * - Default batch size: 20 profiles (allows ~25 seconds with 1-second API delays)
 * - Auto-redirects between batches to continue processing
 * - Tracks cumulative progress across all batches
 * - Handles both forward geocoding and reverse geocoding in separate batch sequences
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. Script is accessed via FIX/index.php menu or direct URL: FIX/20-Backfill-Location-Coordinates.php
 * 2. Database backup is automatically created before first batch
 * 3. Progress is tracked in real-time with emoji indicators
 * 4. Estimated completion time: 8-15 minutes for 500-1000 profiles
 * 5. Script can be safely interrupted - next run will resume from beginning
 * 6. All operations are logged to UserSpice logs table
 */

// UI Constants for progress output
define('SECTION_SEPARATOR', '═══════════════════════════════════════════════════════');

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Set up custom error handler to log through UserSpice logger
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    global $user;
    if ($errno !== E_DEPRECATED) {
        logger(isset($user) ? $user->data()->id : 0, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Error [$errno]: $errstr in $errfile:$errline");
    }
    return true;
});

$db = DB::getInstance();

// Initialize BackupManager
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'FIX/backups', (int)$user->data()->id);

// Check if LocationService is available
if (!class_exists('LocationService')) {
    die('ERROR: LocationService class not found. Please ensure LocationService.php is in usersc/classes/');
}

?>

<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">

            <?php if (!isset($_GET['start'])): ?>
            <!-- Initial Description -->
            <div class="row" id="descriptionSection">
                <div class="col-lg-12 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">
                                <i class="fa fa-map-marker"></i> Backfill Location Coordinates
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script geocodes owner profiles to populate missing coordinates and standardize location data using OpenStreetMap's Nominatim API.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Backs up the profiles and cars tables before any changes</li>
                                    <li><strong>Step 1:</strong> Creates database backup</li>
                                    <li><strong>Step 2:</strong> Forward geocodes profiles with city/state/country to add coordinates</li>
                                    <li><strong>Step 3:</strong> Reverse geocodes ALL profiles with coordinates to standardize location names</li>
                                    <li><strong>Step 4:</strong> Syncs location data from profiles to all owned cars</li>
                                    <li>Fixes: "WV"→"West Virginia", "OR"→"Oregon", "Wv"→"West Virginia", case inconsistencies</li>
                                    <li>Respects Nominatim usage policy (1 request per second)</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-cogs"></i> Batch Processing Configuration</h5>
                                <p class="mb-3">This script uses batch processing to prevent timeouts. Batch size determines how many profiles are processed before auto-redirecting to the next batch.</p>

                                <div class="form-group row">
                                    <label for="batchSize" class="col-sm-3 col-form-label">Batch Size:</label>
                                    <div class="col-sm-4">
                                        <select id="batchSize" class="form-control">
                                            <option value="10">10 profiles per batch (Safe)</option>
                                            <option value="20" selected>20 profiles per batch (Default)</option>
                                            <option value="25">25 profiles per batch (Faster)</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-5">
                                        <small class="text-muted">
                                            <i class="fa fa-info-circle"></i> Smaller batches are safer for servers with strict timeouts
                                        </small>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Important Notes:</h5>
                                <ul class="mb-0">
                                    <li><strong>Time Required:</strong> Estimated 8-15 minutes for 500-1000 profiles</li>
                                    <li><strong>Batch Processing:</strong> Script auto-redirects between batches (prevents timeouts)</li>
                                    <li><strong>Rate Limiting:</strong> Nominatim requires 1-second delay between requests</li>
                                    <li><strong>Do Not Close:</strong> Keep browser window open until all batches complete</li>
                                    <li><strong>Backup:</strong> Database backup created automatically before first batch</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success btn-lg">
                                    <i class="fa fa-play"></i> Start Location Data Migration
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let processStarted = false;

                function startProcessing() {
                    if (processStarted) return;
                    processStarted = true;

                    // Get selected batch size
                    const batchSize = document.getElementById('batchSize').value;

                    // Start the actual processing with batch size
                    const params = new URLSearchParams(window.location.search);
                    params.set('start', '1');
                    params.set('batch_size', batchSize);
                    params.set('step', 'forward_geocode');
                    params.set('offset', '0');

                    window.location.href = window.location.pathname + '?' + params.toString();
                }
            </script>

            <?php else:
                // Processing mode - simple text output
                ob_end_clean(); // Clear template buffering
                header('Content-Type: text/html; charset=utf-8');
                echo str_repeat(' ', 1024); // Pad to force initial flush
                flush();
            ?>

            <div class="card registry-card">
                <div class="card-header">
                    <h2 class="mb-0">
                        <i class="fa fa-cogs"></i> Location Data Migration in Progress
                    </h2>
                    <small class="text-muted">
                        <i class="fa fa-clock-o"></i> Started: <?php echo date('Y-m-d H:i:s'); ?>
                    </small>
                </div>
                <div class="card-body">
                    <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 600px; overflow-y: auto; font-family: 'Courier New', monospace; font-size: 13px;"><?php

                    // Batch processing parameters
                    $batch_size = (int)($_GET['batch_size'] ?? 20);
                    $offset = (int)($_GET['offset'] ?? 0);
                    $step = $_GET['step'] ?? 'forward_geocode'; // 'forward_geocode' or 'reverse_geocode'

                    // Cumulative counters (across all batches)
                    $cumulative_profiles_checked = (int)($_GET['total_profiles_checked'] ?? 0);
                    $cumulative_profiles_updated = (int)($_GET['total_profiles_updated'] ?? 0);
                    $cumulative_profiles_skipped = (int)($_GET['total_profiles_skipped'] ?? 0);
                    $cumulative_geocoding_errors = (int)($_GET['total_geocoding_errors'] ?? 0);
                    $cumulative_api_calls = (int)($_GET['total_api_calls'] ?? 0);
                    $cumulative_cars_updated = (int)($_GET['total_cars_updated'] ?? 0);
                    $backup_created = (int)($_GET['backup_created'] ?? 0);

                    // Track batch start time for timeout management
                    $batch_start_time = time();
                    $max_batch_time = 25; // Allow 25 seconds per batch (5s buffer)

                    /**
                     * Helper function for progress output
                     */
                    function logProgress($message, $type = 'info') {
                        $icons = [
                            'info' => 'ℹ️',
                            'success' => '✅',
                            'error' => '❌',
                            'warning' => '⚠️',
                            'step' => '▶️'
                        ];
                        $icon = $icons[$type] ?? '•';
                        echo date('[H:i:s] ') . $icon . ' ' . $message . "\n";
                        flush();
                    }

                    /**
                     * Sync profile location data to all cars owned by user
                     */
                    function syncLocationToCars($userId, $city, $state, $country, $lat, $lon) {
                        global $db;

                        $cars = $db->query("SELECT id FROM cars WHERE user_id = ?", [$userId])->results();
                        if (empty($cars)) {
                            return 0;
                        }

                        $carsUpdated = 0;
                        foreach ($cars as $car) {
                            $updateSuccess = $db->update('cars', $car->id, [
                                'city' => $city,
                                'state' => $state,
                                'country' => $country,
                                'lat' => $lat,
                                'lon' => $lon
                            ]);
                            if ($updateSuccess) {
                                $carsUpdated++;
                            }
                        }
                        return $carsUpdated;
                    }

                    try {
                        // STEP 1: Create Database Backup (only on first batch)
                        if ($offset === 0 && $step === 'forward_geocode' && $backup_created === 0) {
                            logProgress(SECTION_SEPARATOR, 'step');
                            logProgress('STEP 1: Creating Database Backup', 'step');
                            logProgress(SECTION_SEPARATOR, 'step');

                            $backupPath = $backupManager->createSchemaBackup('Location Coordinate Backfill', ['profiles', 'cars']);
                            logProgress('Backup created: ' . basename($backupPath), 'success');
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Backup created before coordinate backfill: {$backupPath}");
                            $backup_created = 1;
                            logProgress('', 'info');
                        }

                        // Initialize LocationService
                        $locationService = new LocationService();

                        // STEP 2: Forward Geocoding (city/state/country → lat/lon)
                        if ($step === 'forward_geocode') {
                            if ($offset === 0) {
                                logProgress(SECTION_SEPARATOR, 'step');
                                logProgress('STEP 2: Forward Geocoding (Adding Coordinates)', 'step');
                                logProgress(SECTION_SEPARATOR, 'step');
                            }

                            // Get total count for progress tracking
                            $totalCount = $db->query("
                                SELECT COUNT(*) as count
                                FROM profiles p
                                WHERE (p.lat IS NULL OR p.lon IS NULL OR p.lat = 0 OR p.lon = 0)
                                  AND p.city IS NOT NULL AND p.city != '' AND LENGTH(TRIM(p.city)) > 0
                                  AND p.country IS NOT NULL AND p.country != '' AND LENGTH(TRIM(p.country)) > 0
                            ")->results()[0]->count;

                            // Get batch of profiles (batch_size and offset are cast to int on lines 181-182)
                            $profilesToGeocode = $db->query("
                                SELECT p.id, p.user_id, p.city, p.state, p.country, p.lat, p.lon,
                                       u.fname, u.lname
                                FROM profiles p
                                LEFT JOIN users u ON p.user_id = u.id
                                WHERE (p.lat IS NULL OR p.lon IS NULL OR p.lat = 0 OR p.lon = 0)
                                  AND p.city IS NOT NULL AND p.city != '' AND LENGTH(TRIM(p.city)) > 0
                                  AND p.country IS NOT NULL AND p.country != '' AND LENGTH(TRIM(p.country)) > 0
                                ORDER BY p.id
                                LIMIT " . (int)$batch_size . " OFFSET " . (int)$offset . "
                            ")->results();

                            if ($offset === 0) {
                                logProgress("Found {$totalCount} profiles needing coordinates", 'info');
                                if ($totalCount === 0) {
                                    logProgress('No profiles need geocoding!', 'success');
                                } else {
                                    logProgress("Processing batch: " . ($offset + 1) . " to " . min($offset + $batch_size, $totalCount), 'info');
                                    logProgress('', 'info');
                                }
                            }

                            $batchProcessed = 0;
                            foreach ($profilesToGeocode as $profile) {
                                $cumulative_profiles_checked++;
                                $batchProcessed++;

                                $locationParts = array_filter([
                                    trim($profile->city),
                                    trim($profile->state ?? ''),
                                    trim($profile->country)
                                ]);
                                $locationQuery = implode(', ', $locationParts);

                                logProgress("Processing: {$profile->fname} {$profile->lname} ({$locationQuery})", 'info');

                                try {
                                    $geocodeResult = $locationService->searchLocation($locationQuery, $profile->user_id, 1);

                                    if (!empty($geocodeResult) && isset($geocodeResult[0])) {
                                        $location = $geocodeResult[0];
                                        $cumulative_api_calls++;

                                        $updateSuccess = $db->update('profiles', ['user_id' => $profile->user_id], [
                                            'lat' => (float)$location['lat'],
                                            'lon' => (float)$location['lon']
                                        ]);

                                        if ($updateSuccess) {
                                            $cumulative_profiles_updated++;
                                            logProgress("  ✓ Updated coordinates: {$location['lat']}, {$location['lon']}", 'success');

                                            $carsUpdated = syncLocationToCars(
                                                $profile->user_id,
                                                trim($profile->city),
                                                trim($profile->state ?? ''),
                                                trim($profile->country),
                                                (float)$location['lat'],
                                                (float)$location['lon']
                                            );

                                            if ($carsUpdated > 0) {
                                                $cumulative_cars_updated += $carsUpdated;
                                                logProgress("    ↳ Synced to {$carsUpdated} car(s)", 'info');
                                            }
                                        } else {
                                            $cumulative_geocoding_errors++;
                                            logProgress("  ✗ Database update failed", 'error');
                                        }
                                    } else {
                                        $cumulative_profiles_skipped++;
                                        logProgress("  ⊘ No results found", 'warning');
                                    }

                                } catch (Exception $e) {
                                    $cumulative_geocoding_errors++;
                                    logProgress("  ✗ Error: " . $e->getMessage(), 'error');
                                }

                                // Rate limiting
                                sleep(1);

                                // Check for timeout
                                if ((time() - $batch_start_time) > $max_batch_time) {
                                    logProgress("⚠️ Batch time limit reached, continuing to next batch...", 'warning');
                                    break;
                                }
                            }

                            // Check if there are more profiles to forward geocode
                            $next_offset = $offset + $batchProcessed;
                            if ($next_offset < $totalCount) {
                                logProgress("📦 Batch complete! Continuing to next forward geocoding batch...", 'info');
                                logProgress("📈 Progress: {$next_offset}/{$totalCount} profiles", 'info');

                                $next_url = $_SERVER['PHP_SELF'] . '?' . http_build_query([
                                    'start' => '1',
                                    'batch_size' => $batch_size,
                                    'step' => 'forward_geocode',
                                    'offset' => $next_offset,
                                    'total_profiles_checked' => $cumulative_profiles_checked,
                                    'total_profiles_updated' => $cumulative_profiles_updated,
                                    'total_profiles_skipped' => $cumulative_profiles_skipped,
                                    'total_geocoding_errors' => $cumulative_geocoding_errors,
                                    'total_api_calls' => $cumulative_api_calls,
                                    'total_cars_updated' => $cumulative_cars_updated,
                                    'backup_created' => $backup_created
                                ]);

                                echo "<script>
                                    setTimeout(function() {
                                        window.location.href = '{$next_url}';
                                    }, 2000);
                                </script>";
                                exit;
                            }

                            // Forward geocoding complete, move to reverse geocoding
                            logProgress("✅ Forward geocoding complete!", 'success');
                            logProgress("", 'info');

                            $next_url = $_SERVER['PHP_SELF'] . '?' . http_build_query([
                                'start' => '1',
                                'batch_size' => $batch_size,
                                'step' => 'reverse_geocode',
                                'offset' => 0,
                                'total_profiles_checked' => $cumulative_profiles_checked,
                                'total_profiles_updated' => $cumulative_profiles_updated,
                                'total_profiles_skipped' => $cumulative_profiles_skipped,
                                'total_geocoding_errors' => $cumulative_geocoding_errors,
                                'total_api_calls' => $cumulative_api_calls,
                                'total_cars_updated' => $cumulative_cars_updated,
                                'backup_created' => $backup_created
                            ]);

                            echo "<script>
                                setTimeout(function() {
                                    window.location.href = '{$next_url}';
                                }, 2000);
                            </script>";
                            exit;
                        }

                        // STEP 3: Reverse Geocoding (lat/lon → standardized location names)
                        if ($step === 'reverse_geocode') {
                            if ($offset === 0) {
                                logProgress(SECTION_SEPARATOR, 'step');
                                logProgress('STEP 3: Reverse Geocoding (Standardizing Location Names)', 'step');
                                logProgress(SECTION_SEPARATOR, 'step');
                                logProgress('Fixes: "WV"→"West Virginia", "OR"→"Oregon", case inconsistencies', 'info');
                                logProgress('', 'info');
                            }

                            // Get total count
                            $totalCount = $db->query("
                                SELECT COUNT(*) as count
                                FROM profiles p
                                WHERE p.lat IS NOT NULL AND p.lon IS NOT NULL
                                  AND p.lat != 0 AND p.lon != 0
                            ")->results()[0]->count;

                            // Get batch of profiles (batch_size and offset are cast to int on lines 181-182)
                            $profilesToStandardize = $db->query("
                                SELECT p.id, p.user_id, p.city, p.state, p.country, p.lat, p.lon,
                                       u.fname, u.lname
                                FROM profiles p
                                LEFT JOIN users u ON p.user_id = u.id
                                WHERE p.lat IS NOT NULL AND p.lon IS NOT NULL
                                  AND p.lat != 0 AND p.lon != 0
                                ORDER BY p.id
                                LIMIT " . (int)$batch_size . " OFFSET " . (int)$offset . "
                            ")->results();

                            if ($offset === 0) {
                                logProgress("Found {$totalCount} profiles with coordinates", 'info');
                                logProgress("Processing batch: " . ($offset + 1) . " to " . min($offset + $batch_size, $totalCount), 'info');
                                logProgress('', 'info');
                            }

                            $batchProcessed = 0;
                            foreach ($profilesToStandardize as $profile) {
                                $cumulative_profiles_checked++;
                                $batchProcessed++;

                                $currentLocation = trim(implode(', ', array_filter([
                                    $profile->city,
                                    $profile->state,
                                    $profile->country
                                ]))) ?: 'No location text';

                                logProgress("Processing: {$profile->fname} {$profile->lname}", 'info');
                                logProgress("  Current: {$currentLocation}", 'info');

                                try {
                                    $reverseResult = $locationService->reverseGeocode((float)$profile->lat, (float)$profile->lon, (int)$profile->user_id);

                                    if (!empty($reverseResult)) {
                                        $cumulative_api_calls++;

                                        $newCity = $reverseResult['city'] ?? '';
                                        $newState = $reverseResult['state'] ?? '';
                                        $newCountry = $reverseResult['country'] ?? '';

                                        $cityChanged = trim($profile->city ?? '') !== $newCity;
                                        $stateChanged = trim($profile->state ?? '') !== $newState;
                                        $countryChanged = trim($profile->country ?? '') !== $newCountry;

                                        if ($cityChanged || $stateChanged || $countryChanged) {
                                            $updateSuccess = $db->update('profiles', ['user_id' => $profile->user_id], [
                                                'city' => $newCity,
                                                'state' => $newState,
                                                'country' => $newCountry
                                            ]);

                                            if ($updateSuccess) {
                                                $cumulative_profiles_updated++;
                                                logProgress("  ✓ Standardized: {$newCity}, {$newState}, {$newCountry}", 'success');

                                                $carsUpdated = syncLocationToCars(
                                                    $profile->user_id,
                                                    $newCity,
                                                    $newState,
                                                    $newCountry,
                                                    (float)$profile->lat,
                                                    (float)$profile->lon
                                                );

                                                if ($carsUpdated > 0) {
                                                    $cumulative_cars_updated += $carsUpdated;
                                                    logProgress("    ↳ Synced to {$carsUpdated} car(s)", 'info');
                                                }
                                            } else {
                                                $cumulative_geocoding_errors++;
                                                logProgress("  ✗ Database update failed", 'error');
                                            }
                                        } else {
                                            $cumulative_profiles_skipped++;
                                            logProgress("  ✓ Already standardized", 'success');
                                        }
                                    } else {
                                        $cumulative_profiles_skipped++;
                                        logProgress("  ⊘ No results found", 'warning');
                                    }

                                } catch (Exception $e) {
                                    $cumulative_geocoding_errors++;
                                    logProgress("  ✗ Error: " . $e->getMessage(), 'error');
                                }

                                // Rate limiting
                                sleep(1);

                                // Check for timeout
                                if ((time() - $batch_start_time) > $max_batch_time) {
                                    logProgress("⚠️ Batch time limit reached, continuing to next batch...", 'warning');
                                    break;
                                }
                            }

                            // Check if there are more profiles to reverse geocode
                            $next_offset = $offset + $batchProcessed;
                            if ($next_offset < $totalCount) {
                                logProgress("📦 Batch complete! Continuing to next reverse geocoding batch...", 'info');
                                logProgress("📈 Progress: {$next_offset}/{$totalCount} profiles", 'info');

                                $next_url = $_SERVER['PHP_SELF'] . '?' . http_build_query([
                                    'start' => '1',
                                    'batch_size' => $batch_size,
                                    'step' => 'reverse_geocode',
                                    'offset' => $next_offset,
                                    'total_profiles_checked' => $cumulative_profiles_checked,
                                    'total_profiles_updated' => $cumulative_profiles_updated,
                                    'total_profiles_skipped' => $cumulative_profiles_skipped,
                                    'total_geocoding_errors' => $cumulative_geocoding_errors,
                                    'total_api_calls' => $cumulative_api_calls,
                                    'total_cars_updated' => $cumulative_cars_updated,
                                    'backup_created' => $backup_created
                                ]);

                                echo "<script>
                                    setTimeout(function() {
                                        window.location.href = '{$next_url}';
                                    }, 2000);
                                </script>";
                                exit;
                            }

                            // All processing complete!
                            logProgress("✅ Reverse geocoding complete!", 'success');
                        }

                        // Log script completion
                        try {
                            $db->insert('fix_script_runs', [
                                'script_name' => '20-Backfill-Location-Coordinates.php',
                                'completed_at' => date('Y-m-d H:i:s')
                            ]);
                            logProgress("✅ Script completion logged to fix_script_runs", 'success');
                        } catch (Exception $e) {
                            logProgress("⚠️ Could not log to fix_script_runs: " . $e->getMessage(), 'warning');
                        }

                        logger((int)$user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE,
                            "Location migration completed - Profiles: {$cumulative_profiles_checked} checked, {$cumulative_profiles_updated} updated, {$cumulative_profiles_skipped} skipped | Cars: {$cumulative_cars_updated} synced | Errors: {$cumulative_geocoding_errors} | API Calls: {$cumulative_api_calls}");

                        // Display summary
                        logProgress('', 'info');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress('LOCATION DATA MIGRATION COMPLETE', 'step');
                        logProgress(SECTION_SEPARATOR, 'step');
                        logProgress("Total Profiles Processed: {$cumulative_profiles_checked}", 'info');
                        logProgress("Profiles Updated: {$cumulative_profiles_updated}", 'success');
                        logProgress("Cars Updated: {$cumulative_cars_updated}", 'success');
                        if ($cumulative_profiles_skipped > 0) {
                            logProgress("Profiles Skipped: {$cumulative_profiles_skipped}", 'warning');
                        }
                        if ($cumulative_geocoding_errors > 0) {
                            logProgress("Errors: {$cumulative_geocoding_errors}", 'error');
                        }
                        logProgress("Total API Calls: {$cumulative_api_calls}", 'info');
                        logProgress('', 'info');
                        logProgress('Operations Completed:', 'info');
                        logProgress('  • Forward geocoded profiles with city/state/country', 'info');
                        logProgress('  • Reverse geocoded profiles to standardize location names', 'info');
                        logProgress('  • Synced location data from profiles to cars', 'info');

                    } catch (Exception $e) {
                        logProgress('FATAL ERROR: ' . $e->getMessage(), 'error');
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, 'Fatal error during coordinate backfill: ' . $e->getMessage());
                    }

                    ?></pre>

                    <div class="text-center mt-3">
                        <a href="index.php" class="btn btn-primary btn-lg">
                            <i class="fa fa-arrow-left"></i> Return to FIX Menu
                        </a>
                    </div>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
