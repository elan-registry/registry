<?php

/**
 * Cleanup Orphaned Car-User Records Script
 *
 * Administrative script to remove orphaned records from car_user table where referenced cars no longer exist.
 * Issue #35: When a car is deleted clean up car_user table
 *
 * Removes car_user records that reference deleted cars, maintaining referential integrity.
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
                    background: white;
                    border-top: 2px solid #28a745;
                    margin-top: 20px;
                    padding-top: 15px;
                }

                .fix-log-entry {
                    padding: 2px 0;
                    border-left: 3px solid transparent;
                    padding-left: 8px;
                    margin: 1px 0;
                }

                .fix-log-entry.success { border-left-color: #28a745; background-color: #f8fff8; }
                .fix-log-entry.warning { border-left-color: #ffc107; background-color: #fffef8; }
                .fix-log-entry.error { border-left-color: #dc3545; background-color: #fff8f8; }
                .fix-log-entry.info { border-left-color: #17a2b8; background-color: #f8feff; }

                .fix-section-header {
                    font-weight: bold;
                    font-size: 1.1em;
                    color: #495057;
                    border-bottom: 2px solid #e9ecef;
                    padding: 8px 0 5px 0;
                    margin: 15px 0 10px 0;
                }

                .btn-outline-danger:not(:disabled):not(.disabled):hover {
                    background-color: #dc3545;
                    border-color: #dc3545;
                }
            </style>

            <div class="row">
                <div class="col-sm-12">
                    <div class="fix-progress-header bg-white pb-3 mb-3">
                        <h1><i class="fa fa-database" aria-hidden="true"></i> Cleanup Orphaned Car-User Records</h1>
                        <p class="lead">Remove car_user table records that reference deleted cars</p>
                        
                        <?php if (!isset($_POST['run_cleanup'])): ?>
                            <div class="alert alert-info">
                                <h5><i class="fa fa-info-circle"></i> About This Cleanup</h5>
                                <p>This script will:</p>
                                <ul>
                                    <li>Identify car_user records where the referenced car no longer exists</li>
                                    <li>Remove these orphaned records to maintain database integrity</li>
                                    <li>Log all cleanup actions for audit trail</li>
                                    <li>Provide detailed progress reporting</li>
                                </ul>
                                <p><strong>Backup Recommendation:</strong><br>
                                <code>/Applications/MAMP/Library/bin/mysqldump -h localhost -P 8889 -u claude -p"claude" elanregi_spice car_user > car_user_backup_$(date +%Y%m%d).sql</code></p>
                            </div>
                            
                            <form method="post">
                                <button type="submit" name="run_cleanup" value="1" class="btn btn-outline-danger btn-lg">
                                    <i class="fa fa-trash"></i> Run Cleanup
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (isset($_POST['run_cleanup'])): ?>
                <div class="row">
                    <div class="col-lg-8">
                        <div class="fix-section-header">Cleanup Progress</div>
                        <div class="fix-results-container" id="progress-log">
                            
                            <?php
                            function logProgress($message, $type = 'info', $line_num = null) {
                                global $line;
                                $timestamp = date('H:i:s');
                                $line_display = $line_num !== null ? $line_num : $line++;
                                echo "<div class='fix-log-entry {$type}'>[{$timestamp}] [{$line_display}] {$message}</div>\n";
                                echo str_repeat(' ', 1024); // Force output buffer flush
                                flush();
                                if (ob_get_level()) ob_flush();
                            }

                            // Start cleanup process
                            logProgress("Starting orphaned car_user records cleanup process", 'info');
                            logProgress("Timestamp: " . date('Y-m-d H:i:s T'), 'info');
                            
                            try {
                                // Step 1: Identify orphaned records
                                logProgress("Step 1: Identifying orphaned car_user records", 'info');
                                
                                $orphanedQuery = $db->query("
                                    SELECT cu.id, cu.userid, cu.car_id, cu.mtime 
                                    FROM car_user cu 
                                    WHERE cu.car_id NOT IN (SELECT id FROM cars)
                                    ORDER BY cu.car_id ASC
                                ");
                                
                                $orphanedRecords = $orphanedQuery->results();
                                $orphanedCount = count($orphanedRecords);
                                
                                if ($orphanedCount == 0) {
                                    logProgress("No orphaned car_user records found - database is clean!", 'success');
                                } else {
                                    logProgress("Found {$orphanedCount} orphaned car_user records to cleanup", 'warning');
                                    
                                    // Show some examples of what will be deleted
                                    logProgress("Sample orphaned records (showing first 5):", 'info');
                                    $sampleCount = min(5, $orphanedCount);
                                    for ($i = 0; $i < $sampleCount; $i++) {
                                        $record = $orphanedRecords[$i];
                                        logProgress("  → car_user.id={$record->id}, userid={$record->userid}, carid={$record->carid} (missing car)", 'warning');
                                    }
                                    if ($orphanedCount > 5) {
                                        logProgress("  → ... and " . ($orphanedCount - 5) . " more records", 'info');
                                    }
                                    
                                    // Step 2: Create backup information
                                    logProgress("Step 2: Logging cleanup actions for audit trail", 'info');
                                    
                                    // Step 3: Delete orphaned records
                                    logProgress("Step 3: Removing orphaned car_user records", 'info');
                                    
                                    $deletedCount = 0;
                                    foreach ($orphanedRecords as $record) {
                                        try {
                                            $deleteResult = $db->query("DELETE FROM car_user WHERE id = ?", [$record->id]);
                                            if ($deleteResult->count() > 0) {
                                                $deletedCount++;
                                                if ($deletedCount <= 10 || $deletedCount % 10 == 0) {
                                                    logProgress("Deleted car_user record: id={$record->id}, carid={$record->carid} (missing car)", 'success');
                                                }
                                            }
                                        } catch (Exception $e) {
                                            logProgress("Error deleting car_user record id={$record->id}: " . $e->getMessage(), 'error');
                                        }
                                    }
                                    
                                    // Step 4: Verify cleanup
                                    logProgress("Step 4: Verifying cleanup completion", 'info');
                                    
                                    $verifyQuery = $db->query("SELECT COUNT(*) as remaining FROM car_user WHERE carid NOT IN (SELECT id FROM cars)");
                                    $remainingCount = $verifyQuery->first()->remaining;
                                    
                                    if ($remainingCount == 0) {
                                        logProgress("Cleanup verification: All orphaned records successfully removed", 'success');
                                        logProgress("Total records deleted: {$deletedCount}", 'success');
                                    } else {
                                        logProgress("Warning: {$remainingCount} orphaned records still remain", 'error');
                                    }
                                    
                                    // Log the cleanup action
                                    logger($user->data()->id, 'DatabaseCleanup', "Cleaned up {$deletedCount} orphaned car_user records (Issue #35)");
                                }
                                
                                // Record script completion
                                try {
                                    $db->query("INSERT INTO fix_script_runs (script_name) VALUES (?)", [basename(__FILE__)]);
                                    logProgress("Script completion recorded in fix_script_runs table", 'success');
                                    logger($user->data()->id, 'SystemMaintenance', "FIX script completed: " . basename(__FILE__));
                                } catch (Exception $e) {
                                    logProgress("Warning: Could not record script completion: " . $e->getMessage(), 'warning');
                                }
                                
                                logProgress("Cleanup process completed successfully", 'success');
                                
                            } catch (Exception $e) {
                                logProgress("Fatal error during cleanup: " . $e->getMessage(), 'error');
                                logger($user->data()->id, 'DatabaseError', "Error during car_user cleanup: " . $e->getMessage());
                            }
                            ?>
                            
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="fix-section-header">Cleanup Summary</div>
                        <div class="card">
                            <div class="card-body">
                                <?php
                                // Get final statistics
                                $totalCarUserRecords = $db->query("SELECT COUNT(*) as total FROM car_user")->first()->total;
                                $remainingOrphaned = $db->query("SELECT COUNT(*) as remaining FROM car_user WHERE carid NOT IN (SELECT id FROM cars)")->first()->remaining;
                                $totalCars = $db->query("SELECT COUNT(*) as total FROM cars")->first()->total;
                                $totalUsers = $db->query("SELECT COUNT(*) as total FROM users")->first()->total;
                                ?>
                                
                                <h6><i class="fa fa-chart-bar text-primary"></i> Database Statistics</h6>
                                <table class="table table-sm">
                                    <tr><td>Total car_user records:</td><td><strong><?= number_format($totalCarUserRecords) ?></strong></td></tr>
                                    <tr><td>Total cars in system:</td><td><strong><?= number_format($totalCars) ?></strong></td></tr>
                                    <tr><td>Total users in system:</td><td><strong><?= number_format($totalUsers) ?></strong></td></tr>
                                    <tr class="<?= $remainingOrphaned > 0 ? 'table-warning' : 'table-success' ?>">
                                        <td>Remaining orphaned records:</td>
                                        <td><strong><?= number_format($remainingOrphaned) ?></strong></td>
                                    </tr>
                                </table>
                                
                                <?php if (isset($orphanedCount) && isset($deletedCount)): ?>
                                <h6><i class="fa fa-trash text-success"></i> Cleanup Results</h6>
                                <table class="table table-sm">
                                    <tr><td>Records found:</td><td><strong><?= number_format($orphanedCount) ?></strong></td></tr>
                                    <tr><td>Records deleted:</td><td><strong><?= number_format($deletedCount) ?></strong></td></tr>
                                    <tr><td>Success rate:</td><td><strong><?= $orphanedCount > 0 ? round(($deletedCount / $orphanedCount) * 100, 1) : 100 ?>%</strong></td></tr>
                                </table>
                                <?php endif; ?>
                                
                                <div class="mt-3">
                                    <button onclick="if(window.opener){window.opener.location.reload(); window.close();} else {window.location.href='index.php';}" class="btn btn-primary btn-sm">
                                        <i class="fa fa-arrow-left"></i> Return to FIX Menu
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-3">
                            <div class="card-body">
                                <h6><i class="fa fa-info-circle text-info"></i> Next Steps</h6>
                                <ul class="small">
                                    <li>Check the admin car management page for ongoing monitoring</li>
                                    <li>This cleanup can be run periodically as needed</li>
                                    <li>Consider adding automatic cleanup to car deletion process</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/container_close.php'; // Close the container ?>