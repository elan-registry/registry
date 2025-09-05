<?php

/**
 * Cleanup Orphaned Profiles Script
 *
 * Administrative script to clean up orphaned records in the database.
 * Issue #35: Clean up orphaned profiles and car relationships
 *
 * Removes profiles without corresponding users and reassigns orphaned cars.
 * Maintains referential integrity by cleaning up broken relationships.
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
                                <i class="fa fa-broom"></i> Database Cleanup: Orphaned Records
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script cleans up orphaned database records to maintain referential integrity and optimize database performance.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Analyzes database for orphaned profile records (profiles without users)</li>
                                    <li>Cleans up orphaned car_user relationship records</li>
                                    <li>Reassigns orphaned cars to the "noowner" system user</li>
                                    <li>Provides detailed statistics before and after cleanup</li>
                                    <li>Maintains audit trail of all cleanup actions</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Safety Notice:</h5>
                                <p class="mb-0">This script will permanently delete orphaned records. Consider creating a database backup before running in production.</p>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Database Cleanup
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
                    updateProgress(100, 100, 'Database cleanup completed successfully!');

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

                // Step 1: Display initial statistics (20%)
                outputMessage($line++, "=== Initial Database Analysis ===", 10);
                
                // Count users and profiles
                $userCount = $db->query('SELECT COUNT(*) as count FROM users')->first()->count;
                $profileCount = $db->query('SELECT COUNT(*) as count FROM profiles')->first()->count;
                $orphanedProfiles = $db->query('SELECT COUNT(*) as count FROM profiles p LEFT JOIN users u ON p.user_id = u.id WHERE u.id IS NULL')->first()->count;
                
                outputMessage($line++, "📊 Users: $userCount");
                outputMessage($line++, "📊 Profiles: $profileCount");
                outputMessage($line++, "🔍 Orphaned Profiles: $orphanedProfiles");
                
                // Count car relationships
                $carCount = $db->query('SELECT COUNT(*) as count FROM cars WHERE user_id > 0')->first()->count;
                $carUserCount = $db->query('SELECT COUNT(*) as count FROM car_user')->first()->count;
                $orphanedCarUser = $db->query('SELECT COUNT(*) as count FROM car_user cu LEFT JOIN users u ON cu.userid = u.id WHERE u.id IS NULL')->first()->count;
                $orphanedCars = $db->query('SELECT COUNT(*) as count FROM cars c LEFT JOIN users u ON c.user_id = u.id WHERE u.id IS NULL AND c.user_id IS NOT NULL AND c.user_id > 0')->first()->count;
                
                outputMessage($line++, "🚗 Cars with owners: $carCount");
                outputMessage($line++, "🔗 Car-User relationships: $carUserCount");
                outputMessage($line++, "🔍 Orphaned car_user records: $orphanedCarUser");
                outputMessage($line++, "🔍 Orphaned cars: $orphanedCars", 20);
                
                outputMessage($line++, "");

                // Step 2: Clean up orphaned profiles (40%)
                outputMessage($line++, "=== Cleaning Up Orphaned Profiles ===", 25);
                
                if ($orphanedProfiles > 0) {
                    outputMessage($line++, "Found $orphanedProfiles orphaned profile records");
                    
                    // Get orphaned profiles before deletion for logging
                    $orphanedQuery = $db->query('SELECT p.id, p.user_id FROM profiles p LEFT JOIN users u ON p.user_id = u.id WHERE u.id IS NULL');
                    $orphaned = $orphanedQuery->results();
                    
                    // Delete orphaned profiles
                    $deleted = $db->query('DELETE p FROM profiles p LEFT JOIN users u ON p.user_id = u.id WHERE u.id IS NULL');
                    
                    outputMessage($line++, "✅ Deleted $orphanedProfiles orphaned profile records");
                    $global_successes += $orphanedProfiles;
                    
                    // Log each deletion (show first 5)
                    $logCount = min(5, count($orphaned));
                    for ($i = 0; $i < $logCount; $i++) {
                        $profile = $orphaned[$i];
                        outputMessage($line++, "  → Deleted profile ID {$profile->id} (user_id: {$profile->user_id})");
                    }
                    if (count($orphaned) > 5) {
                        outputMessage($line++, "  → ... and " . (count($orphaned) - 5) . " more profile records");
                    }
                } else {
                    outputMessage($line++, "✅ No orphaned profiles found - profiles are clean");
                }
                $global_attempts += $orphanedProfiles;
                
                outputMessage($line++, "", 40);

                // Step 3: Clean up orphaned car_user records (60%)
                outputMessage($line++, "=== Cleaning Up Orphaned Car-User Records ===", 45);
                
                if ($orphanedCarUser > 0) {
                    outputMessage($line++, "Found $orphanedCarUser orphaned car_user records");
                    
                    // Get orphaned car_user records before deletion for logging
                    $orphanedQuery = $db->query('SELECT cu.id, cu.userid, cu.car_id FROM car_user cu LEFT JOIN users u ON cu.userid = u.id WHERE u.id IS NULL');
                    $orphaned = $orphanedQuery->results();
                    
                    // Delete orphaned car_user records
                    $deleted = $db->query('DELETE cu FROM car_user cu LEFT JOIN users u ON cu.userid = u.id WHERE u.id IS NULL');
                    
                    outputMessage($line++, "✅ Deleted $orphanedCarUser orphaned car_user records");
                    $global_successes += $orphanedCarUser;
                    
                    // Log each deletion (show first 5)
                    $logCount = min(5, count($orphaned));
                    for ($i = 0; $i < $logCount; $i++) {
                        $record = $orphaned[$i];
                        outputMessage($line++, "  → Deleted car_user ID {$record->id} (user_id: {$record->userid}, car_id: {$record->car_id})");
                    }
                    if (count($orphaned) > 5) {
                        outputMessage($line++, "  → ... and " . (count($orphaned) - 5) . " more car_user records");
                    }
                } else {
                    outputMessage($line++, "✅ No orphaned car_user records found - relationships are clean");
                }
                $global_attempts += $orphanedCarUser;
                
                outputMessage($line++, "", 60);

                // Step 4: Reassign orphaned cars (80%)
                outputMessage($line++, "=== Reassigning Orphaned Cars ===", 65);
                
                if ($orphanedCars > 0) {
                    // Find or create 'noowner' user
                    $noOwnerQuery = $db->query('SELECT id FROM users WHERE username = ? OR fname = ? OR lname = ?', ['noowner', 'noowner', 'noowner']);
                    
                    if ($noOwnerQuery->count() > 0) {
                        $noOwnerUserId = $noOwnerQuery->first()->id;
                        outputMessage($line++, "Found 'noowner' user (ID: $noOwnerUserId)");
                        
                        // Get list of orphaned cars
                        $orphanedCarsQuery = $db->query('SELECT id, chassis FROM cars c LEFT JOIN users u ON c.user_id = u.id WHERE u.id IS NULL AND c.user_id IS NOT NULL AND c.user_id > 0');
                        $orphanedCarsList = $orphanedCarsQuery->results();
                        
                        // Reassign cars to noowner
                        $reassigned = $db->query('UPDATE cars SET user_id = ? WHERE user_id NOT IN (SELECT id FROM users) AND user_id IS NOT NULL AND user_id > 0', [$noOwnerUserId]);
                        
                        outputMessage($line++, "✅ Reassigned $orphanedCars orphaned cars to 'noowner'");
                        $global_successes += $orphanedCars;
                        
                        // Create new car_user entries for reassigned cars
                        foreach ($orphanedCarsList as $car) {
                            $db->query('INSERT INTO car_user (userid, car_id) VALUES (?, ?)', [$noOwnerUserId, $car->id]);
                            outputMessage($line++, "  → Reassigned car {$car->chassis} (ID: {$car->id}) to noowner");
                        }
                        
                        outputMessage($line++, "✅ Created new car_user relationships for reassigned cars");
                        
                    } else {
                        outputMessage($line++, "❌ 'noowner' user not found - cannot reassign orphaned cars");
                        outputMessage($line++, "   Please create a 'noowner' user account first");
                    }
                } else {
                    outputMessage($line++, "✅ No orphaned cars found - car ownership is clean");
                }
                $global_attempts += $orphanedCars;
                
                outputMessage($line++, "", 80);

                // Step 5: Final verification and statistics (100%)
                outputMessage($line++, "=== Final Database Statistics ===", 85);
                
                // Recount after cleanup
                $finalUserCount = $db->query('SELECT COUNT(*) as count FROM users')->first()->count;
                $finalProfileCount = $db->query('SELECT COUNT(*) as count FROM profiles')->first()->count;
                $finalOrphanedProfiles = $db->query('SELECT COUNT(*) as count FROM profiles p LEFT JOIN users u ON p.user_id = u.id WHERE u.id IS NULL')->first()->count;
                $finalCarUserCount = $db->query('SELECT COUNT(*) as count FROM car_user')->first()->count;
                $finalOrphanedCarUser = $db->query('SELECT COUNT(*) as count FROM car_user cu LEFT JOIN users u ON cu.userid = u.id WHERE u.id IS NULL')->first()->count;
                $finalOrphanedCars = $db->query('SELECT COUNT(*) as count FROM cars c LEFT JOIN users u ON c.user_id = u.id WHERE u.id IS NULL AND c.user_id IS NOT NULL AND c.user_id > 0')->first()->count;
                
                outputMessage($line++, "📊 Final Users: $finalUserCount");
                outputMessage($line++, "📊 Final Profiles: $finalProfileCount");
                outputMessage($line++, "✅ Final Orphaned Profiles: $finalOrphanedProfiles");
                outputMessage($line++, "📊 Final Car-User relationships: $finalCarUserCount");
                outputMessage($line++, "✅ Final Orphaned car_user records: $finalOrphanedCarUser");
                outputMessage($line++, "✅ Final Orphaned cars: $finalOrphanedCars");
                
                outputMessage($line++, "", 95);
                outputMessage($line++, "✅ Database cleanup completed successfully!", 100);

                // Log the completion action with summary
                logger($user->data()->id, 'DatabaseCleanup', "Orphaned profiles cleanup completed - Processed: {$global_successes}/{$global_attempts} records (Issue #18)");

                // Record script completion
                try {
                    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                    outputMessage($line++, "✅ Script completion recorded");
                    logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
                } catch (Exception $record_e) {
                    outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                }

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
            <div class='col-sm-6'><strong>Profiles Cleaned:</strong> $orphanedProfiles</div>
            <div class='col-sm-6'><strong>Car Records Cleaned:</strong> " . ($orphanedCarUser + $orphanedCars) . "</div>
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