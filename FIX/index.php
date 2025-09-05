<?php

/**
 * FIX Directory Index
 *
 * Lists available administrative cleanup scripts in the FIX directory.
 * Requires authentication and displays each script as a button for easy access.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get list of files in the FIX directory
$directory    = $abs_us_root . $us_url_root . 'FIX/';
$all_items = scandir($directory);

// Filter to only include PHP files (exclude directories, non-PHP files, and specific files)
$scanned_directory = [];
foreach ($all_items as $item) {
    $full_path = $directory . $item;
    if (is_file($full_path) && 
        pathinfo($item, PATHINFO_EXTENSION) === 'php' && 
        !in_array($item, ['index.php', '_TEMPLATE_Fix-Script.php'])) {
        $scanned_directory[] = $item;
    }
}

// Sort files newest first (reverse natural order)
rsort($scanned_directory, SORT_NATURAL);

// Get database instance for checking run status
$db = DB::getInstance();

// Ensure fix_script_runs table exists
try {
    $db->query("CREATE TABLE IF NOT EXISTS fix_script_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        script_name VARCHAR(255) NOT NULL,
        completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_script_name (script_name)
    )");
} catch (Exception $e) {
    // Table creation failed - will affect run status display
}

// Function to check if script has been run
function getScriptRunStatus($scriptName) {
    global $db;
    
    try {
        // Check if this script has a completion record
        $result = $db->query("SELECT completed_at FROM fix_script_runs WHERE script_name = ? ORDER BY completed_at DESC LIMIT 1", [$scriptName]);
        
        if ($result->count() > 0) {
            return [
                'has_run' => true,
                'last_run' => $result->first()->completed_at
            ];
        }
        
        return ['has_run' => false, 'last_run' => null];
        
    } catch (Exception) {
        return ['has_run' => false, 'last_run' => null];
    }
}

// Function to extract script description from file
function getScriptDescription($filename) {
    global $abs_us_root, $us_url_root;
    
    $descriptions = [
        '02-Cleanup-Orphaned-Profiles.php' => 'Clean up orphaned user profile records and reassign cars',
        '03-Remove-Duplicate-History.php' => 'Remove duplicate entries from cars_hist table',
        '04-Regeocode-Null-Coordinates.php' => 'Fix missing geocoding data for user locations', 
        '05-Database-Column-Standardization-carid-to-car_id.php' => 'Standardize column naming from carid to car_id',
        '06-Cleanup-Orphaned-Car-User-Records.php' => 'Clean up orphaned car-user relationship records'
    ];
    
    // Return predefined description if available
    if (isset($descriptions[$filename])) {
        return $descriptions[$filename];
    }
    
    // Try to extract description from file comments
    $filePath = $abs_us_root . $us_url_root . 'FIX/' . $filename;
    if (file_exists($filePath) && is_file($filePath)) {
        $content = file_get_contents($filePath);
        
        // Look for description in comment block
        if (preg_match('/\* Administrative script to (.+?)\./', $content, $matches)) {
            return ucfirst($matches[1]);
        }
        
        // Look for description in header comment
        if (preg_match('/\* (.+?) Script/', $content, $matches)) {
            return $matches[1] . ' operations';
        }
    }
    
    return 'Administrative cleanup script';
}

?>
<div id="page-wrapper">
    <div class="container-fluid">
        <div class="well">
            <div class="row">
                <div class="col-12">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Administrative Cleanup</strong></h2>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h5>📋 Run Status Indicators:</h5>
                                <ul class="mb-0">
                                    <li><span class="badge badge-success">✅</span> Script has been run successfully</li>
                                    <li><span class="badge badge-secondary">➖</span> Script has not been run yet</li>
                                    <li>Last run time is displayed when available</li>
                                </ul>
                            </div>
                            
                            <table class="table table-striped table-hover">
                                <thead class="thead-dark">
                                    <tr>
                                        <th>Status</th>
                                        <th>Script Name</th>
                                        <th>Description</th>
                                        <th>Last Run</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    foreach ($scanned_directory as $file) {
                                        $runStatus = getScriptRunStatus($file);
                                        $statusBadge = $runStatus['has_run'] ?
                                            '<span class="badge badge-success">✅</span>' :
                                            '<span class="badge badge-secondary">➖</span>';

                                        $lastRunText = $runStatus['has_run'] && $runStatus['last_run'] ?
                                            date('M j, Y g:i A', strtotime($runStatus['last_run'])) :
                                            'Never run';

                                        $description = getScriptDescription($file);
                                    ?>
                                    <tr>
                                        <td><?= $statusBadge ?></td>
                                        <td><strong><?= $file ?></strong></td>
                                        <td><span class="text-info"><?= htmlspecialchars($description) ?></span></td>
                                        <td><span class="text-muted"><?= $lastRunText ?></span></td>
                                        <td>
                                            <button class="btn btn-outline-danger btn-sm" onclick="window.open('<?= $file ?>','_blank')">
                                                <i class="fa fa-external-link" aria-hidden="true"></i> Run Script
                                            </button>
                                        </td>
                                    </tr>
                                    <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->

            </div> <!-- row -->

        </div> <!-- well -->
    </div><!-- Container -->
</div><!-- page -->


<!-- Javascript -->



<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>
