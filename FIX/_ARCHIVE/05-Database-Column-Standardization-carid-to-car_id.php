<?php

/**
 * Database Column Standardization Script
 *
 * Administrative script to standardize column naming from carid to car_id.
 * Issue #158: Database Column Standardization - Phase 2
 *
 * This script renames database columns for consistency:
 * - car_user.carid → car_user.car_id
 * - car_user_hist.carid → car_user_hist.car_id
 *
 * SAFETY MEASURES:
 * - Full database backup before execution
 * - Transaction-based atomic operations
 * - Comprehensive pre/post validation
 * - Automatic rollback on failure
 * - Rollback script generation
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
                                <i class="fa fa-database"></i> Database Column Standardization
                            </h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">This script standardizes database column naming by renaming <code>carid</code> columns to <code>car_id</code> for consistency across the schema.</p>

                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> What this script does:</h5>
                                <ul class="mb-0">
                                    <li><strong>Safety Check:</strong> Verifies migration hasn't already been completed</li>
                                    <li>Creates full database backup before any changes</li>
                                    <li>Validates pre-migration database state and structure</li>
                                    <li>Renames <code>car_user.carid</code> to <code>car_user.car_id</code></li>
                                    <li>Renames <code>car_user_hist.carid</code> to <code>car_user_hist.car_id</code></li>
                                    <li>Validates post-migration functionality with test queries</li>
                                </ul>
                            </div>

                            <div class="alert alert-warning">
                                <h5><i class="fa fa-exclamation-triangle"></i> Prerequisites:</h5>
                                <ul class="mb-0">
                                    <li>All pre-migration tests must pass</li>
                                    <li>Maintenance window should be active</li>
                                    <li>24 PHP files need updates after database migration</li>
                                    <li>Full database backup will be created automatically</li>
                                </ul>
                            </div>

                            <div class="text-center">
                                <button onclick="startProcessing()" class="btn btn-success">
                                    <i class="fa fa-play"></i> Continue - Start Column Standardization
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
                    updateProgress(100, 100, 'Column Standardization completed successfully!');

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
                $migration_start_time = microtime(true);
                
                function outputMessage($lineNum, $message, $percentage = null) {
                    echo '<script>addLogMessage("' . addslashes($message) . '");</script>';
                    if ($percentage !== null) {
                        echo '<script>updateProgress(' . $percentage . ', 100, "' . addslashes($message) . '");</script>';
                    }
                    ob_flush();
                    flush();
                }

                // Step 1: Database Backup (20%)
                outputMessage($line++, "=== Phase 1: Database Backup ===", 5);
                outputMessage($line++, "🔒 Creating full database backup for safety...");

                $backupSuccess = false;
                $backupFile = '';
                
                try {
                    // Get database configuration from UserSpice globals
                    $host = $GLOBALS['config']['mysql']['host'];
                    $username = $GLOBALS['config']['mysql']['username'];
                    $password = $GLOBALS['config']['mysql']['password'];
                    $dbname = $GLOBALS['config']['mysql']['db'];
                    $port = $GLOBALS['config']['mysql']['port'] ?? 8889;  // Default MAMP port
                    
                    $backupDir = dirname(__FILE__) . '/backups';
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0755, true);
                    }
                    
                    $timestamp = date('Y-m-d_H-i-s');
                    $backupFile = "{$backupDir}/elanregi_spice_migration_backup_{$timestamp}.sql";
                    
                    // Build mysqldump command for MAMP environment
                    if (strpos($host, 'localhost') !== false && file_exists('/Applications/MAMP/Library/bin/mysqldump')) {
                        $mysqldumpPath = '/Applications/MAMP/Library/bin/mysqldump';
                        $socketParam = '--socket=/Applications/MAMP/tmp/mysql/mysql.sock';
                    } else {
                        $mysqldumpPath = 'mysqldump';
                        $socketParam = '';
                    }
                    
                    $command = sprintf(
                        '%s --host=%s --port=%d %s --user=%s --password=%s --single-transaction --routines --triggers --events %s > %s 2>&1',
                        escapeshellcmd($mysqldumpPath),
                        escapeshellarg($host),
                        $port,
                        $socketParam,
                        escapeshellarg($username),
                        escapeshellarg($password),
                        escapeshellarg($dbname),
                        escapeshellarg($backupFile)
                    );
                    
                    exec($command, $output, $returnCode);
                    
                    if ($returnCode === 0 && file_exists($backupFile) && filesize($backupFile) > 1000) {
                        $backupSize = round(filesize($backupFile) / 1024, 2);
                        outputMessage($line++, "✅ Database backup created: {$backupSize} KB", 20);
                        outputMessage($line++, "📁 Backup location: " . basename($backupFile));
                        $backupSuccess = true;
                    } else {
                        throw new Exception("Backup failed - return code: {$returnCode}");
                    }
                    
                } catch (Exception $e) {
                    outputMessage($line++, "❌ BACKUP FAILED: " . $e->getMessage());
                    outputMessage($line++, "🛑 Migration aborted - no changes made");
                    outputMessage($line++, "");
                    
                    // Log the backup failure
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Column standardization backup failed: " . $e->getMessage() . " (Issue #Phase2)");
                    goto script_end;
                }

                // Step 2: Pre-migration Validation (40%)
                outputMessage($line++, "");
                outputMessage($line++, "=== Phase 2: Pre-migration Validation ===", 25);
                
                try {
                    // Validate tables exist
                    $tables = ['cars', 'car_user', 'car_user_hist', 'cars_hist'];
                    foreach ($tables as $table) {
                        $result = $db->query("SHOW TABLES LIKE '{$table}'");
                        if ($result->count() == 0) {
                            throw new Exception("Required table '{$table}' not found");
                        }
                    }
                    outputMessage($line++, "✅ All required tables exist", 30);
                    
                    // SAFETY CHECK: Verify migration hasn't already been run
                    outputMessage($line++, "🔍 Checking if migration has already been completed...", 32);
                    
                    $carUserCols = $db->query("DESCRIBE car_user")->results();
                    $hasCarid = false;
                    $hasCarId = false;
                    
                    foreach ($carUserCols as $col) {
                        if ($col->Field === 'carid') $hasCarid = true;
                        if ($col->Field === 'car_id') $hasCarId = true;
                    }
                    
                    if ($hasCarId && !$hasCarid) {
                        outputMessage($line++, "⚠️  MIGRATION ALREADY COMPLETED!");
                        outputMessage($line++, "✅ car_user table already has 'car_id' column");
                        outputMessage($line++, "✅ car_user table no longer has 'carid' column");
                        outputMessage($line++, "🛑 No migration needed - database is already standardized");
                        
                        // Log that migration was already completed
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Column standardization skipped - migration already completed (Issue #Phase2)");
                        goto script_end;
                    }
                    
                    if (!$hasCarid) {
                        throw new Exception("car_user table missing 'carid' column - unexpected database state");
                    }
                    
                    if ($hasCarId) {
                        throw new Exception("car_user table already has 'car_id' column - migration may have partially run");
                    }
                    
                    // Check car_user_hist table for migration status
                    $carUserHistCols = $db->query("DESCRIBE car_user_hist")->results();
                    $hasCaridHist = false;
                    $hasCarIdHist = false;
                    foreach ($carUserHistCols as $col) {
                        if ($col->Field === 'carid') $hasCaridHist = true;
                        if ($col->Field === 'car_id') $hasCarIdHist = true;
                    }
                    
                    if ($hasCarIdHist && !$hasCaridHist) {
                        outputMessage($line++, "⚠️  MIGRATION ALREADY COMPLETED!");
                        outputMessage($line++, "✅ car_user_hist table already has 'car_id' column");
                        outputMessage($line++, "🛑 No migration needed - database is already standardized");
                        
                        // Log that migration was already completed
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Column standardization skipped - migration already completed (Issue #Phase2)");
                        goto script_end;
                    }
                    
                    if (!$hasCaridHist) {
                        throw new Exception("car_user_hist table missing 'carid' column - unexpected database state");
                    }
                    
                    if ($hasCarIdHist) {
                        throw new Exception("car_user_hist table already has 'car_id' column - migration may have partially run");
                    }
                    
                    outputMessage($line++, "✅ Column structure validation passed", 35);
                    
                    // Data count validation
                    $carCount = $db->query("SELECT COUNT(*) as count FROM cars")->first()->count;
                    $carUserCount = $db->query("SELECT COUNT(*) as count FROM car_user")->first()->count;
                    $carUserHistCount = $db->query("SELECT COUNT(*) as count FROM car_user_hist")->first()->count;
                    
                    outputMessage($line++, "📊 Data counts - Cars: {$carCount}, car_user: {$carUserCount}, car_user_hist: {$carUserHistCount}", 40);
                    
                } catch (Exception $e) {
                    outputMessage($line++, "❌ PRE-VALIDATION FAILED: " . $e->getMessage());
                    outputMessage($line++, "🛑 Migration aborted - no changes made");
                    outputMessage($line++, "");
                    
                    // Log the validation failure
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Column standardization pre-validation failed: " . $e->getMessage() . " (Issue #Phase2)");
                    goto script_end;
                }

                // Step 3: Database Migration (70%)
                outputMessage($line++, "");
                outputMessage($line++, "=== Phase 3: Database Column Rename ===", 45);
                outputMessage($line++, "🚀 Starting transaction-based migration...");
                
                try {
                    // Start transaction
                    $db->query("START TRANSACTION");
                    outputMessage($line++, "📝 Transaction started", 50);
                    
                    // Rename car_user.carid to car_user.car_id
                    $db->query("ALTER TABLE car_user CHANGE carid car_id INT(11) NOT NULL");
                    outputMessage($line++, "✅ car_user.carid → car_user.car_id", 60);
                    $global_successes++;
                    
                    // Rename car_user_hist.carid to car_user_hist.car_id
                    $db->query("ALTER TABLE car_user_hist CHANGE carid car_id INT(11) UNSIGNED NOT NULL");
                    outputMessage($line++, "✅ car_user_hist.carid → car_user_hist.car_id", 70);
                    $global_successes++;
                    
                    $global_attempts = 2; // Two column renames attempted
                    
                    // Commit transaction
                    $db->query("COMMIT");
                    outputMessage($line++, "💾 Transaction committed successfully");
                    
                } catch (Exception $e) {
                    try {
                        $db->query("ROLLBACK");
                        outputMessage($line++, "❌ MIGRATION FAILED: " . $e->getMessage());
                        outputMessage($line++, "🔄 Transaction rolled back - no changes made");
                        
                        // Log the migration failure
                        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Column standardization migration failed: " . $e->getMessage() . " (Issue #Phase2)");
                    } catch (Exception $rollbackError) {
                        outputMessage($line++, "🚨 CRITICAL: Rollback failed - manual intervention required");
                    }
                    outputMessage($line++, "");
                    goto script_end;
                }

                // Step 4: Post-migration Validation (90%)
                outputMessage($line++, "");
                outputMessage($line++, "=== Phase 4: Post-migration Validation ===", 75);
                
                try {
                    // Verify new column structure
                    $carUserCols = $db->query("DESCRIBE car_user")->results();
                    $hasCarid = false;
                    $hasCarId = false;
                    
                    foreach ($carUserCols as $col) {
                        if ($col->Field === 'carid') $hasCarid = true;
                        if ($col->Field === 'car_id') $hasCarId = true;
                    }
                    
                    if ($hasCarid || !$hasCarId) {
                        throw new Exception("car_user column rename verification failed");
                    }
                    
                    // Test queries with new column names
                    $testQuery1 = $db->query("SELECT COUNT(*) as count FROM car_user WHERE car_id > 0");
                    $count1 = $testQuery1->first()->count;
                    outputMessage($line++, "✅ car_user query test passed (count: {$count1})", 85);
                    
                    $testQuery2 = $db->query("SELECT COUNT(*) as count FROM car_user_hist WHERE car_id > 0"); 
                    $count2 = $testQuery2->first()->count;
                    outputMessage($line++, "✅ car_user_hist query test passed (count: {$count2})", 90);
                    
                    // Test JOIN operation
                    $joinQuery = $db->query("SELECT COUNT(*) as count FROM cars c LEFT JOIN car_user cu ON c.id = cu.car_id WHERE cu.car_id IS NOT NULL");
                    $joinCount = $joinQuery->first()->count;
                    outputMessage($line++, "✅ JOIN operation test passed (count: {$joinCount})", 95);
                    
                } catch (Exception $e) {
                    outputMessage($line++, "❌ POST-VALIDATION FAILED: " . $e->getMessage());
                    outputMessage($line++, "⚠️  Migration completed but validation failed - check manually");
                }

                // Step 5: Generate Rollback Script (100%)
                outputMessage($line++, "");
                outputMessage($line++, "=== Phase 5: Generating Rollback Script ===", 98);
                
                try {
                    $rollbackContent = '<?php
/**
 * EMERGENCY ROLLBACK: Revert carid to car_id Migration
 * Generated: ' . date('Y-m-d H:i:s') . '
 * 
 * WARNING: Use only if needed. Update PHP code back to carid before running.
 */

require_once "../users/init.php";
$db = DB::getInstance();

try {
    echo "Starting rollback...\n";
    $db->query("START TRANSACTION");
    $db->query("ALTER TABLE car_user CHANGE car_id carid INT(11) NOT NULL");
    $db->query("ALTER TABLE car_user_hist CHANGE car_id carid INT(11) UNSIGNED NOT NULL");
    $db->query("COMMIT");
    echo "✅ Rollback completed successfully\n";
} catch (Exception $e) {
    $db->query("ROLLBACK");
    echo "❌ Rollback failed: " . $e->getMessage() . "\n";
}
';
                    
                    $rollbackFile = dirname(__FILE__) . '/rollback_carid_migration_' . date('Y-m-d_H-i-s') . '.php';
                    file_put_contents($rollbackFile, $rollbackContent);
                    outputMessage($line++, "🔄 Rollback script created: " . basename($rollbackFile), 100);
                    
                } catch (Exception $e) {
                    outputMessage($line++, "⚠️  Could not create rollback script: " . $e->getMessage());
                }

                // Completion
                script_end:
                $executionTime = round(microtime(true) - $migration_start_time, 3);
                outputMessage($line++, "");
                outputMessage($line++, "✅ Migration process completed in {$executionTime} seconds");

                // Log the completion action with summary
                logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, "Column standardization completed - Tables migrated: {$global_successes}/{$global_attempts} in {$executionTime}s (Issue #Phase2)");

                // Record script completion
                try {
                    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                    outputMessage($line++, "✅ Script completion recorded");
                    logger($user->data()->id, LogCategories::LOG_CATEGORY_FIX_SCRIPT, "FIX script completed: " . basename(__FILE__));
                } catch (Exception $record_e) {
                    outputMessage($line++, "⚠️  Could not record script completion: " . $record_e->getMessage());
                }

                outputMessage($line++, "");
                outputMessage($line++, "📋 Next steps:");
                outputMessage($line++, "1. Update 24 PHP files to use car_id instead of carid");
                outputMessage($line++, "2. Run post-migration validation tests");
                outputMessage($line++, "3. Monitor application functionality");
                outputMessage($line++, "4. Clean up backup files after validation");

                // Calculate final stats and show completion summary
                $successRate = $global_attempts > 0 ? round(($global_successes / $global_attempts) * 100) : 100;
                $statusIcon = $global_successes === $global_attempts ? 'check-circle' : 'exclamation-triangle';
                $statusColor = $global_successes === $global_attempts ? '#28a745' : '#ffc107';

                echo "<script>
    showCompletionSummary(`
        <div class='row'>
            <div class='col-sm-6'><strong>Columns Renamed:</strong> {$global_successes}/{$global_attempts}</div>
            <div class='col-sm-6'><strong>Success Rate:</strong> 
                <span style='color: {$statusColor}; font-weight: bold;'>
                    <i class='fa fa-{$statusIcon}'></i> {$successRate}%
                </span>
            </div>
        </div>
        <div class='row mt-2'>
            <div class='col-sm-6'><strong>Execution Time:</strong> {$executionTime}s</div>
            <div class='col-sm-6'><strong>Backup Created:</strong> " . ($backupSuccess ? 'Yes' : 'No') . "</div>
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