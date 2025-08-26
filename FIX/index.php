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
$scanned_directory = array_diff(scandir($directory), array('..', '.', '.htaccess', 'index.php', '_TEMPLATE_Fix-Script.php'));

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
                                    ?>
                                    <tr>
                                        <td><?= $statusBadge ?></td>
                                        <td><strong><?= $file ?></strong></td>
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
