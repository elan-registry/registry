<?php

/**
 * Generate Test Data for SPAM Cleanup Script
 *
 * Administrative script to create test users for validating the automated SPAM cleanup system.
 * Issue #232: Automated SPAM and Inactive User Cleanup System
 *
 * Creates 6 SPAM users and 6 inactive users that match the exact criteria used by the cleanup system.
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
                                <i class="fa fa-users-cog"></i> Generate Test Data for SPAM Cleanup
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script creates test users to validate the automated SPAM and inactive user cleanup system (Issue #232).</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li>Creates 3 legacy SPAM users (1969 dates, never active, unverified)</li>
                                    <li>Creates 3 suspicious pattern SPAM users (recent dates, never active, unverified)</li>
                                    <li>Creates 6 inactive users (30+ days old, verified but no cars)</li>
                                    <li>Auto-creates profile entries for all test users</li>
                                    <li>Validates that users match cleanup system criteria</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Safety Notice:</h5>
                                <p class="mb-0">Test users will have obvious prefixes (<code>test_spam_</code>, <code>test_inactive_</code>) and will be created with test email addresses. This script will remove any existing test users before creating new ones.</p>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Test Data Generation
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
                    updateProgress(100, 100, 'Test Data Generation completed successfully!');

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
            <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='../FIX/';}" class="btn btn-outline-primary">
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
                outputMessage($line++, "⚠️  SAFETY NOTICE: This script creates test users in the database!");
                outputMessage($line++, "Backup command: mysqldump -u username -p database_name users profiles > test_users_backup.sql");
                outputMessage($line++, "");

                // Analysis before processing
                outputMessage($line++, "🔍 Analyzing existing test users...");

                // Check for existing test users
                $existing_spam = $db->query("SELECT COUNT(*) as count FROM users WHERE username LIKE 'test_spam_%'")->first()->count;
                $existing_inactive = $db->query("SELECT COUNT(*) as count FROM users WHERE username LIKE 'test_inactive_%'")->first()->count;
                outputMessage($line++, "Existing test SPAM users: " . $existing_spam);
                outputMessage($line++, "Existing test inactive users: " . $existing_inactive);

                outputMessage($line++, "");
                outputMessage($line++, "🚀 Starting Test Data Generation...");

                try {
                    // Clean up existing test users first
                    if ($existing_spam > 0 || $existing_inactive > 0) {
                        outputMessage($line++, "🧹 Cleaning up existing test users...");
                        
                        $cleanup_users = $db->query("SELECT id FROM users WHERE username LIKE 'test_spam_%' OR username LIKE 'test_inactive_%'")->results();
                        foreach ($cleanup_users as $user) {
                            // Delete profile first
                            $db->query("DELETE FROM profiles WHERE user_id = ?", [$user->id]);
                            // Delete user permissions
                            $db->query("DELETE FROM user_permission_matches WHERE user_id = ?", [$user->id]);
                            // Delete user
                            $db->query("DELETE FROM users WHERE id = ?", [$user->id]);
                        }
                        
                        $cleanup_count = count($cleanup_users);
                        outputMessage($line++, "✅ Removed {$cleanup_count} existing test users");
                        $global_attempts += $cleanup_count;
                        $global_successes += $cleanup_count;
                    }

                    // Create SPAM users
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Creating SPAM Test Users ===");
                    
                    $spam_users_created = 0;
                    $total_users_to_create = 12; // 6 SPAM + 6 inactive
                    
                    // SPAM Type 1: Legacy data anomalies (3 users)
                    for ($i = 1; $i <= 3; $i++) {
                        $username = "test_spam_legacy_{$i}";
                        $email = "test_spam_legacy_{$i}@example.com";
                        
                        $user_data = [
                            'username' => $username,
                            'email' => $email,
                            'password' => password_hash('testpassword', PASSWORD_DEFAULT),
                            'fname' => 'Test',
                            'lname' => "SpamLegacy{$i}",
                            'join_date' => '1969-12-31 23:59:59', // Pre-1980 date
                            'last_login' => '0000-00-00 00:00:00', // Never logged in
                            'email_verified' => 0, // Unverified
                            'permissions' => 1,
                            'active' => 1,
                            'logins' => 0,
                            'created' => date('Y-m-d H:i:s'),
                            'modified' => date('Y-m-d H:i:s')
                        ];
                        
                        if ($db->insert('users', $user_data)) {
                            $user_id = $db->lastId();
                            
                            // Create profile entry
                            $profile_data = [
                                'user_id' => $user_id,
                                'bio' => 'Test SPAM user - legacy data anomaly'
                            ];
                            $db->insert('profiles', $profile_data);
                            
                            // Add user permissions
                            $db->insert('user_permission_matches', ['user_id' => $user_id, 'permission_id' => 1]);
                            
                            $spam_users_created++;
                            $global_successes++;
                            
                            $percentage = round(($global_successes / $total_users_to_create) * 100);
                            outputMessage($line++, "✅ Created legacy SPAM user: {$username}", $percentage);
                        } else {
                            outputMessage($line++, "✗ Failed to create legacy SPAM user: {$username}");
                        }
                        
                        $global_attempts++;
                        usleep(50000); // 50ms delay
                    }
                    
                    // SPAM Type 2: Suspicious registration patterns (3 users)  
                    for ($i = 1; $i <= 3; $i++) {
                        $username = "test_spam_suspicious_{$i}";
                        $email = "test_spam_suspicious_{$i}@example.com";
                        
                        // Calculate date 10 days ago (older than 7-day threshold)
                        $join_date = date('Y-m-d H:i:s', strtotime('-10 days'));
                        
                        $user_data = [
                            'username' => $username,
                            'email' => $email,
                            'password' => password_hash('testpassword', PASSWORD_DEFAULT),
                            'fname' => 'Test',
                            'lname' => "SpamSuspicious{$i}",
                            'join_date' => $join_date, // Recent but > 7 days
                            'last_login' => '0000-00-00 00:00:00', // Never logged in
                            'email_verified' => 0, // Unverified
                            'permissions' => 1,
                            'active' => 1,
                            'logins' => 0,
                            'created' => date('Y-m-d H:i:s'),
                            'modified' => date('Y-m-d H:i:s')
                        ];
                        
                        if ($db->insert('users', $user_data)) {
                            $user_id = $db->lastId();
                            
                            // DON'T create profile (suspicious pattern = no profile completion)
                            
                            // Add user permissions
                            $db->insert('user_permission_matches', ['user_id' => $user_id, 'permission_id' => 1]);
                            
                            $spam_users_created++;
                            $global_successes++;
                            
                            $percentage = round(($global_successes / $total_users_to_create) * 100);
                            outputMessage($line++, "✅ Created suspicious SPAM user: {$username}", $percentage);
                        } else {
                            outputMessage($line++, "✗ Failed to create suspicious SPAM user: {$username}");
                        }
                        
                        $global_attempts++;
                        usleep(50000); // 50ms delay
                    }

                    // Create Inactive users
                    outputMessage($line++, "");
                    outputMessage($line++, "=== Creating Inactive Test Users ===");
                    
                    $inactive_users_created = 0;
                    
                    // Inactive users (6 users) - 35+ days old, verified, no cars
                    for ($i = 1; $i <= 6; $i++) {
                        $username = "test_inactive_user_{$i}";
                        $email = "test_inactive_user_{$i}@example.com";
                        
                        // Calculate date 35+ days ago (past 30-day threshold)
                        $days_ago = 35 + $i; // Varying ages
                        $join_date = date('Y-m-d H:i:s', strtotime("-{$days_ago} days"));
                        
                        // Some never logged in, some logged in long ago
                        $last_login = ($i % 2 == 0) ? '0000-00-00 00:00:00' : date('Y-m-d H:i:s', strtotime('-95 days'));
                        
                        $user_data = [
                            'username' => $username,
                            'email' => $email,
                            'password' => password_hash('testpassword', PASSWORD_DEFAULT),
                            'fname' => 'Test',
                            'lname' => "Inactive{$i}",
                            'join_date' => $join_date, // 35+ days ago
                            'last_login' => $last_login, // Never or long ago
                            'email_verified' => 1, // Verified (distinguishes from SPAM)
                            'permissions' => 1,
                            'active' => 1,
                            'logins' => ($last_login == '0000-00-00 00:00:00') ? 0 : 1,
                            'created' => date('Y-m-d H:i:s'),
                            'modified' => date('Y-m-d H:i:s')
                        ];
                        
                        if ($db->insert('users', $user_data)) {
                            $user_id = $db->lastId();
                            
                            // Create profile entry (inactive users have profiles)
                            $profile_data = [
                                'user_id' => $user_id,
                                'bio' => 'Test inactive user - no cars registered',
                                'city' => 'Test City',
                                'state' => 'Test State',
                                'country' => 'Test Country'
                            ];
                            $db->insert('profiles', $profile_data);
                            
                            // Add user permissions
                            $db->insert('user_permission_matches', ['user_id' => $user_id, 'permission_id' => 1]);
                            
                            $inactive_users_created++;
                            $global_successes++;
                            
                            $percentage = round(($global_successes / $total_users_to_create) * 100);
                            outputMessage($line++, "✅ Created inactive user: {$username} (joined {$days_ago} days ago)", $percentage);
                        } else {
                            outputMessage($line++, "✗ Failed to create inactive user: {$username}");
                        }
                        
                        $global_attempts++;
                        usleep(50000); // 50ms delay
                    }

                    outputMessage($line++, "✅ Test Data Generation completed successfully!");
                    outputMessage($line++, "Created {$spam_users_created} SPAM test users");
                    outputMessage($line++, "Created {$inactive_users_created} inactive test users");

                    // Verification
                    outputMessage($line++, "");
                    outputMessage($line++, "🔍 Verifying test data against cleanup criteria...");

                    // Verify SPAM users match criteria
                    $spam_criteria_1 = $db->query("
                        SELECT COUNT(*) as count FROM users u
                        WHERE u.join_date < '1980-01-01' 
                        AND u.last_login = '0000-00-00 00:00:00'
                        AND u.email_verified = 0
                        AND u.username LIKE 'test_spam_legacy_%'
                        AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
                    ")->first()->count;
                    
                    $spam_criteria_2 = $db->query("
                        SELECT COUNT(*) as count FROM users u
                        WHERE u.join_date > '2020-01-01'
                        AND u.last_login = '0000-00-00 00:00:00'
                        AND u.email_verified = 0
                        AND u.username LIKE 'test_spam_suspicious_%'
                        AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
                        AND (SELECT COUNT(*) FROM profiles p WHERE p.user_id = u.id) = 0
                        AND DATEDIFF(NOW(), u.join_date) > 7
                    ")->first()->count;
                    
                    // Verify inactive users match criteria
                    $inactive_criteria = $db->query("
                        SELECT COUNT(*) as count FROM users u
                        WHERE DATEDIFF(NOW(), u.join_date) > 30
                        AND u.username LIKE 'test_inactive_%'
                        AND u.protected = 0
                        AND (SELECT COUNT(*) FROM cars c WHERE c.user_id = u.id) = 0
                        AND (
                            u.last_login = '0000-00-00 00:00:00'
                            OR DATEDIFF(NOW(), u.last_login) > 90
                        )
                    ")->first()->count;

                    outputMessage($line++, "✅ SPAM users matching legacy criteria: {$spam_criteria_1}/3");
                    outputMessage($line++, "✅ SPAM users matching suspicious criteria: {$spam_criteria_2}/3");
                    outputMessage($line++, "✅ Inactive users matching criteria: {$inactive_criteria}/6");

                    if ($spam_criteria_1 == 3 && $spam_criteria_2 == 3 && $inactive_criteria == 6) {
                        outputMessage($line++, "✅ SUCCESS: All test users match cleanup criteria!");
                    } else {
                        outputMessage($line++, "⚠️  WARNING: Some test users may not match expected criteria");
                    }

                    // Record script completion
                    try {
                        $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                        outputMessage($line++, "✅ Script completion recorded");
                    } catch (Exception $record_e) {
                        outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                    }

                } catch (Exception $e) {
                    outputMessage($line++, "❌ ERROR during processing: " . $e->getMessage());
                    outputMessage($line++, "Processing aborted - partial changes may have been made");
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
            <div class='col-sm-6'><strong>Test Users Created:</strong> $global_successes/$global_attempts</div>
            <div class='col-sm-6'><strong>Success Rate:</strong> 
                <span style='color: $rateColor; font-weight: bold;'>
                    <i class='fa fa-$rateIcon'></i> $completionPercentage%
                </span>
            </div>
        </div>
        <div class='mt-2'>
            <small class='text-muted'>
                <i class='fa fa-info-circle'></i> Test users can now be used to validate the SPAM cleanup system.
                Run the cleanup script in dry-run mode first to verify detection.
            </small>
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
    <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='../FIX/';}" class="btn btn-outline-primary">
        <i class="fa fa-arrow-left" aria-hidden="true"></i> Return to FIX Menu
    </button>
</div>

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>