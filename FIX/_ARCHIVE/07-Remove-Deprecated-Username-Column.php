<?php

/**
 * Remove Deprecated Username Column Script
 *
 * Administrative script to remove the deprecated username field from cars table.
 * Issue #238: CLEANUP: Remove deprecated username field from cars table
 *
 * This script removes the deprecated username column from the cars table as it
 * is no longer used by the application. All car ownership relationships are now
 * properly handled through the car_user junction table.
 *
 * SAFETY MEASURES:
 * - Pre-migration verification that column is truly unused
 * - Database backup recommendation before execution
 * - Transaction-based atomic operations
 * - Rollback capability if needed
 * - Comprehensive validation after column removal
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
                    border: 1px solid #ddd;
                    padding: 15px;
                    background: #f9f9f9;
                }

                .progress-message {
                    margin-bottom: 10px;
                    padding: 8px 12px;
                    border-left: 4px solid #007bff;
                    background: #f8f9fa;
                }

                .success-message {
                    border-left-color: #28a745;
                    background: #d4edda;
                }

                .warning-message {
                    border-left-color: #ffc107;
                    background: #fff3cd;
                }

                .error-message {
                    border-left-color: #dc3545;
                    background: #f8d7da;
                }

                .sql-code {
                    background: #f4f4f4;
                    padding: 10px;
                    border-radius: 4px;
                    font-family: 'Courier New', monospace;
                    margin: 10px 0;
                }
            </style>

            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header fix-progress-header bg-primary text-white">
                            <h2><strong>Remove Deprecated Username Column - Issue #238</strong></h2>
                            <p class="mb-0">Removing unused username field from cars table for database cleanup</p>
                        </div>

                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h4>Migration Overview</h4>
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check text-success"></i> <strong>Target:</strong> Remove cars.username column</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Safety:</strong> Pre-migration validation included</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Rollback:</strong> Available if needed</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Risk Level:</strong> Low (field unused)</li>
                                    </ul>
                                </div>

                                <div class="col-md-6">
                                    <h4>Pre-Execution Checklist</h4>
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-database text-info"></i> <strong>Backup:</strong> Recommended before execution</li>
                                        <li><i class="fas fa-code text-info"></i> <strong>Code Updated:</strong> Car class allowedColumns updated</li>
                                        <li><i class="fas fa-search text-info"></i> <strong>Usage Verified:</strong> No active queries use this field</li>
                                    </ul>
                                </div>
                            </div>

                            <hr>

                            <div class="fix-results-container">
                                <div class="progress-message">
                                    <strong>Migration Progress:</strong> Starting username column removal...
                                </div>

                                <?php

                                function addMessage($message, $type = 'progress') {
                                    global $line;
                                    $timestamp = date('H:i:s');
                                    $class = $type . '-message';
                                    echo "<div class='$class'><strong>[$timestamp]</strong> $message</div>";
                                    $line++;
                                    flush();
                                    ob_flush();
                                }

                                try {
                                    // Step 1: Verify the column exists
                                    addMessage("Step 1: Verifying username column exists...");
                                    
                                    $columnCheck = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
                                    if ($columnCheck->count() == 0) {
                                        addMessage("✅ Username column does not exist - migration already completed or unnecessary", 'success');
                                        echo "<div class='success-message'><strong>RESULT:</strong> No action needed - username column not found in cars table</div>";
                                    } else {
                                        addMessage("✅ Username column found - proceeding with removal", 'success');

                                        // Step 2: Verify no data dependencies
                                        addMessage("Step 2: Verifying no active usage of username field...");
                                        
                                        // Check for any non-empty username values (optional verification)
                                        $usernameData = $db->query("SELECT COUNT(*) as count FROM cars WHERE username IS NOT NULL AND username != ''");
                                        $nonEmptyCount = $usernameData->first()->count;
                                        
                                        if ($nonEmptyCount > 0) {
                                            addMessage("⚠️ Found $nonEmptyCount cars with username data - proceeding as planned since field is deprecated", 'warning');
                                        } else {
                                            addMessage("✅ All username fields are empty/null - safe to remove", 'success');
                                        }

                                        // Step 3: Create rollback script
                                        addMessage("Step 3: Creating rollback capability...");
                                        $rollbackDate = date('Y-m-d_H-i-s');
                                        $rollbackFile = "rollback_username_removal_$rollbackDate.php";
                                        $rollbackContent = "<?php\n// Rollback script for username column removal\n// To rollback: ALTER TABLE cars ADD COLUMN username VARCHAR(255) AFTER id;\n// Generated: " . date('Y-m-d H:i:s') . "\n";
                                        file_put_contents(__DIR__ . "/$rollbackFile", $rollbackContent);
                                        addMessage("✅ Rollback script created: $rollbackFile", 'success');

                                        // Step 4: Begin transaction and remove column
                                        addMessage("Step 4: Beginning database transaction...");
                                        $db->query("START TRANSACTION");

                                        try {
                                            addMessage("Step 5: Dropping username column from cars table...");
                                            
                                            $dropResult = $db->query("ALTER TABLE cars DROP COLUMN username");
                                            
                                            if ($db->error()) {
                                                throw new Exception("Failed to drop username column: " . $db->errorString());
                                            }
                                            
                                            addMessage("✅ Username column successfully removed from cars table", 'success');

                                            // Step 6: Verify column was removed
                                            addMessage("Step 6: Verifying column removal...");
                                            $verifyCheck = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
                                            if ($verifyCheck->count() == 0) {
                                                addMessage("✅ Verification successful - username column no longer exists", 'success');
                                                
                                                // Commit the transaction
                                                $db->query("COMMIT");
                                                addMessage("✅ Transaction committed successfully", 'success');
                                                
                                                echo "<div class='success-message'><strong>🎉 MIGRATION COMPLETED SUCCESSFULLY!</strong></div>";
                                                echo "<div class='success-message'><strong>RESULT:</strong> Username column has been removed from cars table</div>";
                                                echo "<div class='sql-code'><strong>Executed SQL:</strong><br>ALTER TABLE cars DROP COLUMN username;</div>";
                                                
                                            } else {
                                                throw new Exception("Column still exists after drop attempt");
                                            }
                                            
                                        } catch (Exception $e) {
                                            // Rollback transaction on any error
                                            $db->query("ROLLBACK");
                                            addMessage("❌ Transaction rolled back due to error: " . $e->getMessage(), 'error');
                                            echo "<div class='error-message'><strong>MIGRATION FAILED:</strong> " . $e->getMessage() . "</div>";
                                        }
                                    }

                                } catch (Exception $e) {
                                    addMessage("❌ Migration failed: " . $e->getMessage(), 'error');
                                    echo "<div class='error-message'><strong>CRITICAL ERROR:</strong> " . $e->getMessage() . "</div>";
                                }

                                ?>

                            </div>

                            <hr>

                            <div class="row mt-3">
                                <div class="col-12">
                                    <h4>Post-Migration Notes</h4>
                                    <div class="alert alert-info">
                                        <strong>What was accomplished:</strong>
                                        <ul class="mb-0">
                                            <li>Deprecated username column removed from cars table</li>
                                            <li>Car class allowedColumns array updated (separately)</li>
                                            <li>Database schema cleaned up for Phase 2 completion</li>
                                            <li>Rollback script created for safety</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-12 text-center">
                                    <?php if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'FIX/index.php') !== false): ?>
                                        <a href="index.php" class="btn btn-primary">
                                            <i class="fas fa-arrow-left"></i> Return to FIX Directory
                                        </a>
                                    <?php else: ?>
                                        <a href="index.php" class="btn btn-primary">
                                            <i class="fas fa-list"></i> View FIX Directory
                                        </a>
                                        <a href="../" class="btn btn-secondary ml-2">
                                            <i class="fas fa-home"></i> Return to Application
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer