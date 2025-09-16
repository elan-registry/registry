<?php

declare(strict_types=1);

/**
 * Debug Username Column Drop Script
 *
 * Simple diagnostic script to test dropping the username column from cars table
 * and understand why FIX/07 is not working properly.
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
            <div class="row">
                <div class="col-lg-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2>Debug Username Column Drop</h2>
                        </div>
                        <div class="card-body">

<?php
function outputMessage($line, $message, $percent = null) {
    echo "<div>[$line] $message</div>";
    flush();
}

if (!isset($_POST['start'])) {
    echo "<p>This diagnostic script will test dropping the username column from the cars table.</p>";
    echo "<form method='post'>";
    echo "<button type='submit' name='start' class='btn btn-primary'>Start Debug Test</button>";
    echo "</form>";
} else {
    echo "<h3>Debug Test Results:</h3>";

    // Step 1: Check current table structure
    outputMessage($line++, "=== Step 1: Checking current table structure ===");

    $columns = $db->query("SHOW COLUMNS FROM cars");
    $hasUsername = false;
    foreach ($columns->results() as $column) {
        if ($column->Field === 'username') {
            $hasUsername = true;
            outputMessage($line++, "✅ Found username column: Type={$column->Type}, Null={$column->Null}, Default={$column->Default}");
            break;
        }
    }

    if (!$hasUsername) {
        outputMessage($line++, "❌ Username column not found in cars table");
        echo "</div></div></div></div></div></div>";
        exit;
    }

    // Step 2: Check for indexes on username column
    outputMessage($line++, "=== Step 2: Checking for indexes on username column ===");

    $indexes = $db->query("SHOW INDEX FROM cars WHERE Column_name = 'username'");
    if ($indexes->count() > 0) {
        foreach ($indexes->results() as $index) {
            outputMessage($line++, "⚠️ Found index on username: {$index->Key_name}");
        }
    } else {
        outputMessage($line++, "✅ No indexes found on username column");
    }

    // Step 3: Check for foreign keys
    outputMessage($line++, "=== Step 3: Checking for foreign key constraints ===");

    $fks = $db->query("
        SELECT * FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cars'
        AND COLUMN_NAME = 'username'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");

    if ($fks->count() > 0) {
        foreach ($fks->results() as $fk) {
            outputMessage($line++, "⚠️ Found foreign key: {$fk->CONSTRAINT_NAME} references {$fk->REFERENCED_TABLE_NAME}.{$fk->REFERENCED_COLUMN_NAME}");
        }
    } else {
        outputMessage($line++, "✅ No foreign key constraints found on username column");
    }

    // Step 4: Try to drop the column
    outputMessage($line++, "=== Step 4: Testing column drop ===");

    try {
        outputMessage($line++, "Starting transaction...");
        $db->query("START TRANSACTION");

        outputMessage($line++, "Attempting to drop username column...");
        $result = $db->query("ALTER TABLE cars DROP COLUMN username");

        if ($db->error()) {
            outputMessage($line++, "❌ DROP COLUMN failed: " . $db->errorString());
        } else {
            outputMessage($line++, "✅ DROP COLUMN succeeded");

            // Verify it's gone
            $verify = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
            if ($verify->count() == 0) {
                outputMessage($line++, "✅ Verified: Username column successfully removed");
            } else {
                outputMessage($line++, "❌ Column still exists after drop");
            }
        }

        outputMessage($line++, "Rolling back transaction to restore original state...");
        $db->query("ROLLBACK");

        // Verify rollback worked
        $verify = $db->query("SHOW COLUMNS FROM cars LIKE 'username'");
        if ($verify->count() > 0) {
            outputMessage($line++, "✅ Rollback successful - username column restored");
        } else {
            outputMessage($line++, "❌ Rollback failed - column is gone");
        }

    } catch (Exception $e) {
        outputMessage($line++, "❌ Exception occurred: " . $e->getMessage());
        $db->query("ROLLBACK");
    }

    outputMessage($line++, "=== Debug test complete ===");
}
?>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>