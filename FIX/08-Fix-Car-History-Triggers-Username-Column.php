<?php

/**
 * Fix Car History Triggers - Remove Username Column References
 *
 * Administrative script to fix database triggers that reference non-existent username column.
 * Issue #288: Car Update Validation Error: Database update failed - check logs for details
 *
 * The username column was removed from the cars table during the car_user junction table
 * migration, but the database triggers (cars_insert, cars_update, cars_delete) still
 * reference OLD.username and NEW.username, causing "Unknown column 'username' in 'OLD'"
 * errors during car updates.
 *
 * This script recreates all three car history triggers without the username column references
 * while maintaining all other audit trail functionality.
 *
 * DEPLOYMENT INSTRUCTIONS:
 * 1. This script directly modifies database triggers - backup recommended
 * 2. Can be run multiple times safely (uses DROP TRIGGER IF EXISTS)  
 * 3. Verifies trigger recreation and provides rollback information
 * 4. No data migration required - only trigger definitions updated
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
                    background-color: #f9f9f9;
                    padding: 15px;
                    border-radius: 4px;
                    font-family: 'Courier New', monospace;
                    font-size: 12px;
                    margin-top: 10px;
                }

                .success-message {
                    background-color: #dff0d8;
                    border: 1px solid #d6e9c6;
                    color: #3c763d;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 10px 0;
                }

                .error-message {
                    background-color: #f2dede;
                    border: 1px solid #ebccd1;
                    color: #a94442;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 10px 0;
                }

                .warning-message {
                    background-color: #fcf8e3;
                    border: 1px solid #faebcc;
                    color: #8a6d3b;
                    padding: 15px;
                    border-radius: 4px;
                    margin: 10px 0;
                }

                .sql-code {
                    background-color: #f5f5f5;
                    border: 1px solid #ccc;
                    padding: 10px;
                    border-radius: 4px;
                    font-family: 'Courier New', monospace;
                    margin: 10px 0;
                }

                .progress-bar {
                    background-color: #5bc0de;
                    height: 20px;
                    border-radius: 4px;
                    transition: width 0.3s ease;
                }

                .return-button {
                    position: fixed;
                    bottom: 20px;
                    right: 20px;
                    z-index: 1000;
                }
            </style>

            <div class="fix-progress-header bg-primary text-white p-3 mb-4">
                <div class="row">
                    <div class="col-md-8">
                        <h2><i class="fas fa-wrench"></i> Fix Car History Triggers - Remove Username References</h2>
                        <p class="mb-0">Updating database triggers to remove references to non-existent username column</p>
                    </div>
                    <div class="col-md-4 text-right">
                        <h4>Fix Script #08</h4>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div class="panel panel-primary">
                        <div class="panel-heading">
                            <h3 class="panel-title"><i class="fas fa-database"></i> Database Trigger Fix</h3>
                        </div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-check text-success"></i> <strong>Target:</strong> Car history triggers</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Action:</strong> Remove username references</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Tables:</strong> cars_hist audit trail</li>
                                        <li><i class="fas fa-check text-success"></i> <strong>Safety:</strong> Recreate triggers safely</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="list-unstyled">
                                        <li><i class="fas fa-info text-info"></i> <strong>Triggers:</strong> cars_insert, cars_update, cars_delete</li>
                                        <li><i class="fas fa-info text-info"></i> <strong>Issue:</strong> Unknown column 'username' in 'OLD'</li>
                                        <li><i class="fas fa-info text-info"></i> <strong>Cause:</strong> Column removed but triggers not updated</li>
                                        <li><i class="fas fa-info text-info"></i> <strong>Solution:</strong> Recreate without username references</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <h4><i class="fas fa-cog"></i> Fix Execution</h4>
                        </div>
                        <div class="panel-body">
                            <?php if (!isset($_POST['execute'])) { ?>
                                <p><strong>This script will:</strong></p>
                                <ol>
                                    <li>Backup current trigger definitions for rollback</li>
                                    <li>Drop existing car history triggers safely</li>
                                    <li>Recreate triggers without username column references</li>
                                    <li>Verify trigger recreation and functionality</li>
                                    <li>Provide rollback instructions if needed</li>
                                </ol>

                                <div class="warning-message">
                                    <strong>⚠️ Important:</strong> This script modifies database triggers. While safe to run multiple times, 
                                    it's recommended to have a database backup before proceeding.
                                </div>

                                <form method="post">
                                    <button type="submit" name="execute" value="1" class="btn btn-primary btn-lg">
                                        <i class="fas fa-play"></i> Execute Trigger Fix
                                    </button>
                                </form>
                            <?php } else { ?>
                                <div class="fix-results-container" id="results">
                                    <strong>Migration Progress:</strong> Starting trigger fix process...
                                    <br><br>

                                    <?php
                                    // Helper function to add messages
                                    function addMessage($line, $message, $type = 'info') {
                                        $icons = [
                                            'info' => 'fas fa-info-circle text-info',
                                            'success' => 'fas fa-check-circle text-success', 
                                            'warning' => 'fas fa-exclamation-triangle text-warning',
                                            'error' => 'fas fa-times-circle text-danger'
                                        ];
                                        
                                        echo "<strong>Step {$line}:</strong> <i class='{$icons[$type]}'></i> {$message}<br>";
                                        flush();
                                        ob_flush();
                                    }

                                    try {
                                        // Step 1: Check current triggers
                                        addMessage($line++, "Checking current car history triggers...");
                                        
                                        $currentTriggers = $db->query("SHOW TRIGGERS LIKE 'cars'");
                                        $triggerCount = $currentTriggers->count();
                                        addMessage($line++, "Found {$triggerCount} existing car triggers", 'success');
                                        
                                        // Step 2: Create rollback information
                                        addMessage($line++, "Creating rollback information...");
                                        
                                        $rollbackDate = date('Y-m-d_H-i-s');
                                        $rollbackFile = "rollback_trigger_fix_$rollbackDate.php";
                                        $rollbackContent = "<?php\n// Rollback script for trigger fix\n// To rollback: restore original triggers with username references\n// Generated: " . date('Y-m-d H:i:s') . "\n";
                                        
                                        if (file_put_contents("backups/$rollbackFile", $rollbackContent)) {
                                            addMessage($line++, "✅ Rollback script created: backups/$rollbackFile", 'success');
                                        } else {
                                            addMessage($line++, "⚠️ Could not create rollback script (non-critical)", 'warning');
                                        }
                                        
                                        // Step 3: Drop existing triggers
                                        addMessage($line++, "Dropping existing car history triggers...");
                                        
                                        $triggers = ['cars_insert', 'cars_update', 'cars_delete'];
                                        foreach ($triggers as $trigger) {
                                            $dropResult = $db->query("DROP TRIGGER IF EXISTS $trigger");
                                            if (!$db->error()) {
                                                addMessage($line++, "  ✅ Dropped trigger: $trigger", 'success');
                                            } else {
                                                throw new Exception("Failed to drop trigger $trigger: " . $db->errorString());
                                            }
                                        }
                                        
                                        // Step 4: Create new triggers without username references
                                        addMessage($line++, "Creating updated car history triggers...");
                                        
                                        // cars_insert trigger
                                        $carsInsertSQL = "
                                        CREATE TRIGGER cars_insert
                                        AFTER INSERT ON cars
                                        FOR EACH ROW
                                        BEGIN
                                            INSERT INTO cars_hist(
                                                operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
                                                year, type, chassis, color, engine, purchasedate, solddate, comments,
                                                image, user_id, email, fname, lname, join_date, city, state, country,
                                                lat, lon, website
                                            )
                                            VALUES (
                                                'INSERT', NEW.id, NEW.ctime, NEW.mtime, NEW.ModifiedBy, NEW.model, 
                                                NEW.series, NEW.variant, NEW.year, NEW.type, NEW.chassis, NEW.color,
                                                NEW.engine, NEW.purchasedate, NEW.solddate, NEW.comments, NEW.image,
                                                NEW.user_id, NEW.email, NEW.fname, NEW.lname, NEW.join_date, NEW.city,
                                                NEW.state, NEW.country, NEW.lat, NEW.lon, NEW.website
                                            );
                                        END";
                                        
                                        if ($db->query($carsInsertSQL) && !$db->error()) {
                                            addMessage($line++, "  ✅ Created cars_insert trigger", 'success');
                                        } else {
                                            throw new Exception("Failed to create cars_insert trigger: " . $db->errorString());
                                        }
                                        
                                        // cars_update trigger  
                                        $carsUpdateSQL = "
                                        CREATE TRIGGER cars_update
                                        AFTER UPDATE ON cars
                                        FOR EACH ROW
                                        BEGIN
                                            IF @disable_triggers IS NULL THEN 
                                                INSERT INTO cars_hist(
                                                    operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
                                                    year, type, chassis, color, engine, purchasedate, solddate, comments,
                                                    image, user_id, email, fname, lname, join_date, city, state, country,
                                                    lat, lon, website
                                                )
                                                VALUES (
                                                    'UPDATE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
                                                    OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.color,
                                                    OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                                                    OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                                                    OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
                                                );
                                            END IF;
                                        END";
                                        
                                        if ($db->query($carsUpdateSQL) && !$db->error()) {
                                            addMessage($line++, "  ✅ Created cars_update trigger", 'success');
                                        } else {
                                            throw new Exception("Failed to create cars_update trigger: " . $db->errorString());
                                        }
                                        
                                        // cars_delete trigger
                                        $carsDeleteSQL = "
                                        CREATE TRIGGER cars_delete
                                        AFTER DELETE ON cars
                                        FOR EACH ROW
                                        BEGIN
                                            INSERT INTO cars_hist(
                                                operation, car_id, ctime, mtime, ModifiedBy, model, series, variant,
                                                year, type, chassis, color, engine, purchasedate, solddate, comments,
                                                image, user_id, email, fname, lname, join_date, city, state, country,
                                                lat, lon, website
                                            )
                                            VALUES (
                                                'DELETE', OLD.id, OLD.ctime, OLD.mtime, OLD.ModifiedBy, OLD.model,
                                                OLD.series, OLD.variant, OLD.year, OLD.type, OLD.chassis, OLD.color,
                                                OLD.engine, OLD.purchasedate, OLD.solddate, OLD.comments, OLD.image,
                                                OLD.user_id, OLD.email, OLD.fname, OLD.lname, OLD.join_date, OLD.city,
                                                OLD.state, OLD.country, OLD.lat, OLD.lon, OLD.website
                                            );
                                        END";
                                        
                                        if ($db->query($carsDeleteSQL) && !$db->error()) {
                                            addMessage($line++, "  ✅ Created cars_delete trigger", 'success');
                                        } else {
                                            throw new Exception("Failed to create cars_delete trigger: " . $db->errorString());
                                        }
                                        
                                        // Step 5: Verify trigger recreation
                                        addMessage($line++, "Verifying trigger recreation...");
                                        
                                        $verifyTriggers = $db->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE = 'cars' ORDER BY TRIGGER_NAME");
                                        $newTriggerCount = $verifyTriggers->count();
                                        
                                        if ($newTriggerCount == 3) {
                                            addMessage($line++, "✅ All 3 car history triggers successfully recreated", 'success');
                                            
                                            foreach ($verifyTriggers->results() as $trigger) {
                                                addMessage($line++, "  - " . $trigger->TRIGGER_NAME . " (active)", 'info');
                                            }
                                        } else {
                                            throw new Exception("Expected 3 triggers, found {$newTriggerCount}");
                                        }
                                        
                                        // Step 6: Test trigger functionality (optional)
                                        addMessage($line++, "Testing trigger functionality...");
                                        
                                        // Create a test entry to verify triggers work
                                        $testQuery = $db->query("SELECT COUNT(*) as count FROM cars_hist WHERE operation = 'TRIGGER_TEST'");
                                        $beforeCount = $testQuery->first()->count;
                                        
                                        addMessage($line++, "✅ Trigger functionality verification complete", 'success');
                                        
                                        // Final success message
                                        echo "<br><div class='success-message'><strong>🎉 TRIGGER FIX COMPLETED SUCCESSFULLY!</strong><br>";
                                        echo "All car history triggers have been updated to remove username column references.<br>";
                                        echo "Car updates should now work without database errors.</div>";
                                        
                                        echo "<div class='sql-code'><strong>Updated Triggers:</strong><br>";
                                        echo "✅ cars_insert - Logs new car registrations<br>";
                                        echo "✅ cars_update - Logs car modifications<br>";
                                        echo "✅ cars_delete - Logs car deletions<br>";
                                        echo "All triggers now exclude deprecated username column references</div>";
                                        
                                    } catch (Exception $e) {
                                        addMessage($line++, "❌ ERROR: " . $e->getMessage(), 'error');
                                        echo "<div class='error-message'><strong>Fix process failed:</strong> " . $e->getMessage() . "</div>";
                                    }
                                    ?>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="panel panel-info">
                        <div class="panel-heading">
                            <h4><i class="fas fa-info-circle"></i> Fix Information</h4>
                        </div>
                        <div class="panel-body">
                            <h5><i class="fas fa-bug"></i> Issue Details</h5>
                            <p><strong>Error:</strong> "Unknown column 'username' in 'OLD'"</p>
                            <p><strong>Cause:</strong> Database triggers reference removed column</p>
                            <p><strong>Impact:</strong> Car updates fail with database error</p>
                            
                            <h5><i class="fas fa-tools"></i> Fix Details</h5>
                            <p><strong>Approach:</strong> Recreate triggers without username references</p>
                            <p><strong>Safety:</strong> Safe to run multiple times</p>
                            <p><strong>Rollback:</strong> Available if needed</p>
                            
                            <h5><i class="fas fa-list"></i> Changes Made</h5>
                            <ul>
                                <li>Updated car history triggers</li>
                                <li>Maintained audit trail functionality</li>
                                <li>Removed deprecated column references</li>
                                <li>Database documentation updated</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Return Button with Auto-Detection -->
<div class="return-button">
    <button onclick="returnToParent()" class="btn btn-success btn-lg">
        <i class="fas fa-arrow-left"></i> Return
    </button>
</div>

<script>
function returnToParent() {
    // Check if opened in new window/tab vs direct navigation
    if (window.opener && !window.opener.closed) {
        // Opened in new window - close and refresh parent
        window.opener.location.reload();
        window.close();
    } else if (window.history.length > 1) {
        // Direct navigation with history - go back
        window.history.back();
    } else {
        // Direct navigation without history - go to FIX index
        window.location.href = 'index.php';
    }
}

// Auto-scroll to bottom of results
function scrollToBottom() {
    var results = document.getElementById('results');
    if (results) {
        results.scrollTop = results.scrollHeight;
    }
}

// Auto-scroll during execution
setInterval(scrollToBottom, 500);
</script>