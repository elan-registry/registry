<?php

/**
 * Re-geocode Null/Zero Coordinates Script
 *
 * Administrative script to identify and re-geocode cars and profiles with
 * null, zero, or invalid lat/lon coordinates using location data.
 * Displays progress and uses error reporting for debugging.
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
                                <i class="fa fa-map-marker-alt"></i> Re-geocode Null/Zero Coordinates
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script will identify and re-geocode cars and profiles that have null, zero, or invalid latitude/longitude coordinates.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Scans the database for cars and profiles with missing coordinates</li>
                                    <li>Uses Google Maps Geocoding API to obtain coordinates from location data</li>
                                    <li>Updates database records with accurate latitude/longitude values</li>
                                    <li>Preserves existing coordinates if geocoding fails</li>
                                    <li>Provides detailed progress reporting and statistics</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startGeocoding()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Re-geocoding
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
                                <i class="fa fa-map-marker-alt"></i> Progress
                            </h2>
                            <small class="text-muted">
                                <i class="fa fa-clock-o"></i> Started: <span id="startTimeText"></span>
                            </small>
                        </div>
                        <div class="card-body">
                            <div class="progress car-progress mb-2">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                                    role="progressbar"
                                    id="progressBar"
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
                                <small><em>Initializing geocoding process...</em></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                let totalSteps = 0;
                let currentStep = 0;
                let processStarted = false;

                function startGeocoding() {
                    // Hide description section
                    document.getElementById('descriptionSection').style.display = 'none';

                    // Set start time
                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();

                    // Update status
                    updateProgress(0, 100, 'Starting geocoding process...');

                    processStarted = true;

                    // Start the actual PHP process by reloading the page with a parameter
                    window.location.href = window.location.href + '?start=1';
                }

                function updateProgress(step, total, message) {
                    if (!processStarted && step === 0) return; // Don't update if not started

                    currentStep = step;
                    totalSteps = total;
                    const percentage = total > 0 ? Math.round((step / total) * 100) : 0;

                    const progressBar = document.getElementById('progressBar');
                    if (progressBar) {
                        progressBar.style.width = percentage + '%';
                        progressBar.setAttribute('aria-valuenow', percentage);
                        progressBar.textContent = percentage + '%';

                        // Remove animation when complete
                        if (percentage === 100) {
                            progressBar.classList.remove('progress-bar-striped', 'progress-bar-animated');
                        }
                    }

                    const statusElement = document.getElementById('currentStatus');
                    if (statusElement) {
                        const statusIcon = percentage === 100 ?
                            '<i class="fa fa-check-circle text-success"></i>' :
                            '<i class="fa fa-cog fa-spin text-primary"></i>';

                        statusElement.innerHTML = statusIcon + ' ' + message;
                    }
                }

                function showCompletionSummary(stats) {
                    // Update progress bar to 100% and remove animation
                    updateProgress(100, 100, 'Re-geocoding completed successfully!');

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
                    if (message.includes('✅ UPDATED')) {
                        line.className += ' text-success';
                    } else if (message.includes('✗ Failed')) {
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

                // Check if we should start automatically
                if (new URLSearchParams(window.location.search).get('start') === '1') {
                    processStarted = true;
                    document.getElementById('descriptionSection').style.display = 'none';

                    const now = new Date();
                    document.getElementById('startTimeText').textContent = now.toLocaleString();
                }
            </script>

            <?php

            // Only run the geocoding process if the start parameter is set
            if (isset($_GET['start']) && $_GET['start'] == '1') {

                # Display initial statistics
                displayStatistics();

                # Initialize global counters for overall summary
                $global_car_attempts = 0;
                $global_car_successes = 0;
                $global_profile_attempts = 0;
                $global_profile_successes = 0;

                # Re-geocode car coordinates
                regeocodeCars($global_car_attempts, $global_car_successes);

                # Re-geocode profile coordinates
                regeocodeProfiles($global_profile_attempts, $global_profile_successes);

                # Display final statistics
                displayFinalStatistics($global_car_attempts, $global_car_successes, $global_profile_attempts, $global_profile_successes);

                # Show completion summary
                $totalAttempts = $global_car_attempts + $global_profile_attempts;
                $totalSuccesses = $global_car_successes + $global_profile_successes;
                $completionPercentage = $totalAttempts > 0 ? round(($totalSuccesses / $totalAttempts) * 100) : 100;

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
            <div class='col-sm-6'><strong>Cars:</strong> $global_car_successes/$global_car_attempts</div>
            <div class='col-sm-6'><strong>Profiles:</strong> $global_profile_successes/$global_profile_attempts</div>
        </div>
        <div class='row mt-2'>
            <div class='col-12'>
                <strong>Success Rate:</strong> 
                <span style='color: $rateColor; font-weight: bold;'>
                    <i class='fa fa-$rateIcon'></i> $completionPercentage%
                </span> 
                ($totalSuccesses/$totalAttempts)
            </div>
        </div>
    `);
    </script>";
            }

            ?>


        </div> <!-- well -->
    </div> <!-- container-fluid -->
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


<?php

/**
 * Display current coordinate statistics
 */
function displayStatistics()
{
    global $db, $line;

    outputMessage($line++, "=== INITIAL COORDINATE STATISTICS ===");

    // Count cars with coordinate issues
    $totalCars = $db->query('SELECT COUNT(*) as count FROM cars')->first()->count;
    $nullLatCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lat IS NULL OR lat = 0')->first()->count;
    $nullLonCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lon IS NULL OR lon = 0')->first()->count;
    $bothNullCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE (lat IS NULL OR lat = 0) AND (lon IS NULL OR lon = 0)')->first()->count;
    $validCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lat IS NOT NULL AND lon IS NOT NULL AND lat != 0 AND lon != 0')->first()->count;

    outputMessage($line++, "Cars - Total: $totalCars");
    outputMessage($line++, "Cars - With valid coordinates: $validCars");
    outputMessage($line++, "Cars - With null/zero latitude: $nullLatCars");
    outputMessage($line++, "Cars - With null/zero longitude: $nullLonCars");
    outputMessage($line++, "Cars - With both null/zero: $bothNullCars");

    // Count profiles with coordinate issues
    $totalProfiles = $db->query('SELECT COUNT(*) as count FROM profiles')->first()->count;
    $nullLatProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lat IS NULL OR lat = 0')->first()->count;
    $nullLonProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lon IS NULL OR lon = 0')->first()->count;
    $bothNullProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE (lat IS NULL OR lat = 0) AND (lon IS NULL OR lon = 0)')->first()->count;
    $validProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lat IS NOT NULL AND lon IS NOT NULL AND lat != 0 AND lon != 0')->first()->count;

    outputMessage($line++, "Profiles - Total: $totalProfiles");
    outputMessage($line++, "Profiles - With valid coordinates: $validProfiles");
    outputMessage($line++, "Profiles - With null/zero latitude: $nullLatProfiles");
    outputMessage($line++, "Profiles - With null/zero longitude: $nullLonProfiles");
    outputMessage($line++, "Profiles - With both null/zero: $bothNullProfiles");

    outputMessage($line++, "");
}

/**
 * Re-geocode cars with null/zero coordinates
 */
function regeocodeCars(&$attempts, &$successes)
{
    global $db, $line;

    outputMessage($line++, "=== RE-GEOCODING CAR COORDINATES ===");

    // Get cars with null/zero coordinates that have location data
    $query = "SELECT c.id, c.chassis, c.year, c.model, c.lat, c.lon, p.city, p.state, p.country 
              FROM cars c 
              JOIN profiles p ON c.user_id = p.user_id 
              WHERE (c.lat IS NULL OR c.lat = 0 OR c.lon IS NULL OR c.lon = 0) 
              AND p.city IS NOT NULL 
              AND p.city != '' 
              AND p.country IS NOT NULL 
              AND p.country != ''
              ORDER BY c.id";

    $carsToUpdate = $db->query($query)->results();
    $totalCount = count($carsToUpdate);

    if ($totalCount > 0) {
        outputMessage($line++, "Found $totalCount cars with null/zero coordinates to re-geocode");

        $successCount = 0;
        $failCount = 0;
        $processedCount = 0;

        foreach ($carsToUpdate as $car) {
            $processedCount++;
            $percentage = round(($processedCount / $totalCount) * 100);
            $address = trim($car->city . ", " . $car->state . ", " . $car->country);

            outputMessage($line++, "Processing car ID {$car->id} ({$car->year} {$car->model}) - {$address}", $percentage);

            // Attempt geocoding
            $coordinates = geocodeAddress($address);

            if ($coordinates !== false) {
                // Update car coordinates
                $updateQuery = "UPDATE cars SET lat = ?, lon = ? WHERE id = ?";
                $db->query($updateQuery, [$coordinates['lat'], $coordinates['lon'], $car->id]);

                outputMessage($line++, "  ✅ UPDATED: Car ID {$car->id} geocoded to {$coordinates['lat']}, {$coordinates['lon']}");
                $successCount++;
            } else {
                outputMessage($line++, "  ✗ Failed to geocode address");
                $failCount++;
            }

            // Small delay to respect API rate limits
            usleep(100000); // 0.1 second delay
        }

        $attempts = $totalCount;
        $successes = $successCount;
        outputMessage($line++, "Car re-geocoding complete: $successCount successful, $failCount failed");
    } else {
        $attempts = 0;
        $successes = 0;
        outputMessage($line++, "No cars found that need re-geocoding");
    }

    outputMessage($line++, "");
}

/**
 * Re-geocode profiles with null/zero coordinates
 */
function regeocodeProfiles(&$attempts, &$successes)
{
    global $db, $line;

    outputMessage($line++, "=== RE-GEOCODING PROFILE COORDINATES ===");

    // Get profiles with null/zero coordinates that have location data
    $query = "SELECT p.id, p.user_id, p.lat, p.lon, p.city, p.state, p.country, u.username
              FROM profiles p 
              JOIN users u ON p.user_id = u.id
              WHERE (p.lat IS NULL OR p.lat = 0 OR p.lon IS NULL OR p.lon = 0) 
              AND p.city IS NOT NULL 
              AND p.city != '' 
              AND p.country IS NOT NULL 
              AND p.country != ''
              ORDER BY p.id";

    $profilesToUpdate = $db->query($query)->results();
    $totalCount = count($profilesToUpdate);

    if ($totalCount > 0) {
        outputMessage($line++, "Found $totalCount profiles with null/zero coordinates to re-geocode");

        $successCount = 0;
        $failCount = 0;
        $processedCount = 0;

        foreach ($profilesToUpdate as $profile) {
            $processedCount++;
            $percentage = round(($processedCount / $totalCount) * 100);
            $address = trim($profile->city . ", " . $profile->state . ", " . $profile->country);

            outputMessage($line++, "Processing profile ID {$profile->id} (user: {$profile->username}) - {$address}", $percentage);

            // Attempt geocoding
            $coordinates = geocodeAddress($address);

            if ($coordinates !== false) {
                // Update profile coordinates
                $updateQuery = "UPDATE profiles SET lat = ?, lon = ? WHERE id = ?";
                $db->query($updateQuery, [$coordinates['lat'], $coordinates['lon'], $profile->id]);

                outputMessage($line++, "  ✅ UPDATED: Profile ID {$profile->id} (user: {$profile->username}) geocoded to {$coordinates['lat']}, {$coordinates['lon']}");
                $successCount++;
            } else {
                outputMessage($line++, "  ✗ Failed to geocode address");
                $failCount++;
            }

            // Small delay to respect API rate limits
            usleep(100000); // 0.1 second delay
        }

        $attempts = $totalCount;
        $successes = $successCount;
        outputMessage($line++, "Profile re-geocoding complete: $successCount successful, $failCount failed");
    } else {
        $attempts = 0;
        $successes = 0;
        outputMessage($line++, "No profiles found that need re-geocoding");
    }

    outputMessage($line++, "");
}

/**
 * Geocode an address using Google Maps API
 */
function geocodeAddress($address)
{
    global $settings;

    // URL encode the address
    $address = urlencode($address);

    // Google Maps Geocoding API URL
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$settings->elan_google_geo_key}";

    // Use cURL for better error handling
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ElanRegistry/2.3');
        curl_setopt($ch, CURLOPT_REFERER, 'https://elanregistry.org/');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }
    } else {
        // Fallback to file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'ElanRegistry/2.3',
                'header' => "Referer: https://elanregistry.org/\r\n"
            ]
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            return false;
        }
    }

    // Decode JSON response
    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    // Check if geocoding was successful
    if ($data['status'] === 'OK' && !empty($data['results'])) {
        $location = $data['results'][0]['geometry']['location'];

        if (!empty($location['lat']) && !empty($location['lng'])) {
            return [
                'lat' => round($location['lat'], 4),
                'lon' => round($location['lng'], 4)
            ];
        }
    }

    return false;
}

/**
 * Display final statistics after re-geocoding
 */
function displayFinalStatistics($carAttempts, $carSuccesses, $profileAttempts, $profileSuccesses)
{
    global $db, $line, $user;

    outputMessage($line++, "=== FINAL COORDINATE STATISTICS ===");

    // Count cars with coordinate issues after processing
    $totalCars = $db->query('SELECT COUNT(*) as count FROM cars')->first()->count;
    $nullLatCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lat IS NULL OR lat = 0')->first()->count;
    $nullLonCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lon IS NULL OR lon = 0')->first()->count;
    $bothNullCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE (lat IS NULL OR lat = 0) AND (lon IS NULL OR lon = 0)')->first()->count;
    $validCars = $db->query('SELECT COUNT(*) as count FROM cars WHERE lat IS NOT NULL AND lon IS NOT NULL AND lat != 0 AND lon != 0')->first()->count;

    outputMessage($line++, "Cars - Total: $totalCars");
    outputMessage($line++, "Cars - With valid coordinates: $validCars");
    outputMessage($line++, "Cars - With null/zero latitude: $nullLatCars");
    outputMessage($line++, "Cars - With null/zero longitude: $nullLonCars");
    outputMessage($line++, "Cars - With both null/zero: $bothNullCars");

    // Count profiles with coordinate issues after processing
    $totalProfiles = $db->query('SELECT COUNT(*) as count FROM profiles')->first()->count;
    $nullLatProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lat IS NULL OR lat = 0')->first()->count;
    $nullLonProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lon IS NULL OR lon = 0')->first()->count;
    $bothNullProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE (lat IS NULL OR lat = 0) AND (lon IS NULL OR lon = 0)')->first()->count;
    $validProfiles = $db->query('SELECT COUNT(*) as count FROM profiles WHERE lat IS NOT NULL AND lon IS NOT NULL AND lat != 0 AND lon != 0')->first()->count;

    outputMessage($line++, "Profiles - Total: $totalProfiles");
    outputMessage($line++, "Profiles - With valid coordinates: $validProfiles");
    outputMessage($line++, "Profiles - With null/zero latitude: $nullLatProfiles");
    outputMessage($line++, "Profiles - With null/zero longitude: $nullLonProfiles");
    outputMessage($line++, "Profiles - With both null/zero: $bothNullProfiles");

    // Calculate overall completion percentage and summary
    $totalAttempts = $carAttempts + $profileAttempts;
    $totalSuccesses = $carSuccesses + $profileSuccesses;
    $totalFailures = $totalAttempts - $totalSuccesses;
    $completionPercentage = $totalAttempts > 0 ? round(($totalSuccesses / $totalAttempts) * 100) : 100;

    outputMessage($line++, "");
    outputMessage($line++, "=== OVERALL RE-GEOCODING SUMMARY ===");
    outputMessage($line++, "Completion: $completionPercentage% successful", $completionPercentage);
    outputMessage($line++, "Cars processed: $carAttempts attempts, $carSuccesses successful");
    outputMessage($line++, "Profiles processed: $profileAttempts attempts, $profileSuccesses successful");
    outputMessage($line++, "Total attempts: $totalAttempts");
    outputMessage($line++, "Total successes: $totalSuccesses");
    outputMessage($line++, "Total failures: $totalFailures");

    if ($totalSuccesses > 0) {
        outputMessage($line++, "✅ Successfully updated $totalSuccesses coordinate records");
        // Log the completion action with summary
        logger($user->data()->id, 'DatabaseCleanup', "Re-geocoding completed - Updated: {$totalSuccesses}/{$totalAttempts} coordinates");
    } else if ($totalAttempts > 0) {
        outputMessage($line++, "⚠️  No coordinates were successfully updated");
        logger($user->data()->id, 'DatabaseCleanup', "Re-geocoding completed - No coordinates updated from {$totalAttempts} attempts");
    } else {
        outputMessage($line++, "ℹ️  No records required re-geocoding");
        logger($user->data()->id, 'DatabaseCleanup', "Re-geocoding completed - No records required geocoding");
    }

    // Record script completion
    try {
        global $db, $user;
        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
        outputMessage($line++, "✓ Script completion recorded");
        logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
    } catch (Exception) {
        // Create table if it doesn't exist
        try {
            $db->query("CREATE TABLE IF NOT EXISTS fix_script_runs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                script_name VARCHAR(255) NOT NULL,
                completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_script_name (script_name)
            )");
            $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
            outputMessage($line++, "✓ Script completion recorded");
        } catch (Exception) {
            outputMessage($line++, "⚠ Could not record script completion");
        }
    }

    outputMessage($line++, "");
}

/**
 * Output progress message with timestamp and optional percentage
 */
function outputMessage($current, $message, $percentage = null)
{
    $timestamp = date('h:i:sa');
    $formattedMessage = $timestamp . ' - ' . $message;

    echo "<script>addLogMessage('" . addslashes($formattedMessage) . "');</script>";

    if ($percentage !== null) {
        echo "<script>updateProgress($current, 100, '" . addslashes($message) . "');</script>";
    }

    myFlush();
}

/**
 * Flush output buffer for real-time display
 */
function myFlush()
{
    echo str_repeat(' ', 256);
    if (@ob_get_contents()) {
        @ob_end_flush();
    }
    flush();
}
