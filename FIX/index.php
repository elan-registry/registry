<?php

declare(strict_types=1);

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

if (!securePage($php_self)) {
    die();
}

// Check if user should be redirected to new consolidated interface
if (!isset($_GET['legacy']) && !isset($_GET['direct'])) {
    // Redirect to the new consolidated admin interface
    Redirect::to('../app/admin/manage-consolidated.php?tab=system');
}

// Get list of files in the FIX directory
$directory    = $abs_us_root . $us_url_root . 'FIX/';
$all_items = scandir($directory);

// Filter to only include PHP files (exclude directories, non-PHP files, backup files, and specific files)
$scanned_directory = [];
foreach ($all_items as $item) {
    $full_path = $directory . $item;

    // Skip directories (backups, _ARCHIVE)
    if (is_dir($full_path)) {
        continue;
    }

    // Skip non-PHP files
    if (pathinfo($item, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    // Skip system files
    if (in_array($item, ['index.php', '_TEMPLATE_Fix-Script.php', 'backup-functions.php'])) {
        continue;
    }

    // Skip backup files and test files by pattern matching
    if (preg_match('/^(backup_|rollback_|.*_backup_|test-)/', $item)) {
        continue;
    }

    // Only include actual FIX script files
    $scanned_directory[] = $item;
}

// Sort files newest first (reverse natural order)
rsort($scanned_directory, SORT_NATURAL);

// Get database instance for checking run status
$db = DB::getInstance();

// Include backup management functions
require_once 'backup-functions.php';

// Handle backup cleanup if requested
$cleanupMessage = '';
if (isset($_POST['cleanup_backups']) && $_POST['cleanup_backups'] === 'confirm') {
    try {
        $cleanupSummary = cleanupOldBackups();
        $totalDeleted = $cleanupSummary['automated']['deleted'] +
                       $cleanupSummary['manual']['deleted'] +
                       $cleanupSummary['rollback']['deleted'];

        if ($totalDeleted > 0) {
            $cleanupMessage = "<div class='alert alert-success'>" .
                             "<i class='fa fa-check-circle'></i> Backup cleanup completed! " .
                             "Deleted {$totalDeleted} old backup files: " .
                             "{$cleanupSummary['automated']['deleted']} automated, " .
                             "{$cleanupSummary['manual']['deleted']} manual, " .
                             "{$cleanupSummary['rollback']['deleted']} rollback." .
                             "</div>";
        } else {
            $cleanupMessage = "<div class='alert alert-info'>" .
                             "<i class='fa fa-info-circle'></i> No old backup files found to clean up." .
                             "</div>";
        }
    } catch (Exception $e) {
        $cleanupMessage = "<div class='alert alert-danger'>" .
                         "<i class='fa fa-exclamation-triangle'></i> Backup cleanup failed: " .
                         htmlspecialchars($e->getMessage()) .
                         "</div>";
    }
}

// Check if backup cleanup is needed
$backupStats = getBackupStatistics();
$showCleanupPrompt = false;
$oldBackupsCount = 0;

try {
    // Check for old backups (using 30 days as threshold for prompt)
    $backupBaseDir = $abs_us_root . $us_url_root . BACKUP_BASE_DIR;
    $types = ['automated', 'manual', 'rollback'];
    $cutoffTime = time() - (30 * 24 * 60 * 60); // 30 days ago

    foreach ($types as $type) {
        $typeDir = $backupBaseDir . $type . '/';
        if (is_dir($typeDir)) {
            $files = glob($typeDir . '*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    $oldBackupsCount++;
                }
            }
        }
    }

    $showCleanupPrompt = $oldBackupsCount > 0;
} catch (Exception $e) {
    // Silently fail - cleanup prompt is optional
}

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
function getScriptRunStatus($scriptName): array {
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
function getScriptDescription($filename): string {
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
                            <!-- New Consolidated Interface Notice -->
                            <div class="alert alert-info">
                                <h4><i class="fa fa-info-circle"></i> Enhanced Admin Interface Available</h4>
                                <p class="mb-2">This legacy interface has been superseded by the new consolidated admin interface with enhanced features:</p>
                                <ul class="mb-3">
                                    <li>Real-time script status tracking</li>
                                    <li>Enhanced backup management with health scoring</li>
                                    <li>Schema validation and maintenance tools</li>
                                    <li>Integrated dashboard with system health monitoring</li>
                                </ul>
                                <div class="text-center">
                                    <a href="../app/admin/manage-consolidated.php?tab=system" class="btn btn-success">
                                        <i class="fa fa-arrow-right"></i> Go to New Admin Interface
                                    </a>
                                    <small class="d-block mt-2 text-muted">
                                        This legacy interface will remain available for direct script access
                                    </small>
                                </div>
                            </div>
                            <!-- Display cleanup message if any -->
                            <?php if (!empty($cleanupMessage)): ?>
                                <?= $cleanupMessage ?>
                            <?php endif; ?>

                            <!-- Backup cleanup prompt if needed -->
                            <?php if ($showCleanupPrompt && empty($_POST['cleanup_backups'])): ?>
                                <div class="alert alert-warning">
                                    <h5><i class="fa fa-exclamation-triangle"></i> Backup Cleanup Recommended</h5>
                                    <p class="mb-2">Found <strong><?= $oldBackupsCount ?></strong> backup files older than 30 days that can be cleaned up.</p>
                                    <p class="mb-2"><strong>Current backup storage:</strong></p>
                                    <ul class="mb-3">
                                        <li>Automated: <?= $backupStats['automated']['count'] ?> files (<?= round($backupStats['automated']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
                                        <li>Manual: <?= $backupStats['manual']['count'] ?> files (<?= round($backupStats['manual']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
                                        <li>Rollback: <?= $backupStats['rollback']['count'] ?> files (<?= round($backupStats['rollback']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
                                    </ul>
                                    <form method="post" style="display: inline;">
                                        <button type="button" class="btn btn-warning btn-sm" onclick="confirmBackupCleanup()">
                                            <i class="fa fa-trash"></i> Clean Up Old Backups
                                        </button>
                                        <input type="hidden" name="cleanup_backups" value="">
                                    </form>
                                    <button type="button" class="btn btn-secondary btn-sm ml-2" onclick="dismissCleanupPrompt()">
                                        <i class="fa fa-times"></i> Dismiss
                                    </button>
                                </div>

                                <script>
                                function confirmBackupCleanup() {
                                    const message = `⚠️ BACKUP CLEANUP CONFIRMATION\n\n` +
                                                   `This will permanently delete <?= $oldBackupsCount ?> backup files older than 30 days.\n\n` +
                                                   `Files will be deleted based on retention policies:\n` +
                                                   `• Automated backups: 30 days (production), 7 days (development)\n` +
                                                   `• Manual backups: 90 days (production), 14 days (development)\n` +
                                                   `• Rollback files: 60 days (production), 14 days (development)\n\n` +
                                                   `This action cannot be undone.\n\n` +
                                                   `Continue with cleanup?`;

                                    if (confirm(message)) {
                                        document.querySelector('input[name="cleanup_backups"]').value = 'confirm';
                                        document.querySelector('form').submit();
                                    }
                                }

                                function dismissCleanupPrompt() {
                                    document.querySelector('.alert-warning').style.display = 'none';
                                }
                                </script>
                            <?php endif; ?>

                            <!-- Status Information Row -->
                            <div class="row mb-4">
                                <!-- Run Status Indicators -->
                                <div class="col-md-6">
                                    <div class="alert alert-info h-100">
                                        <h5>📋 Run Status Indicators:</h5>
                                        <ul class="mb-0">
                                            <li><span class="badge badge-success">✅</span> Script has been run successfully</li>
                                            <li><span class="badge badge-secondary">➖</span> Script has not been run yet</li>
                                            <li>Last run time is displayed when available</li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Backup Status Card -->
                                <div class="col-md-6">
                                    <div class="card h-100" style="border-left: 4px solid <?= $showCleanupPrompt ? '#f39c12' : '#28a745' ?>;">
                                        <div class="card-header py-2" style="background-color: <?= $showCleanupPrompt ? '#fff3cd' : '#d4edda' ?>;">
                                            <h5 class="mb-0">
                                                <?php if ($showCleanupPrompt): ?>
                                                    <i class="fa fa-exclamation-triangle text-warning"></i> Backup Storage - Cleanup Needed
                                                <?php else: ?>
                                                    <i class="fa fa-check-circle text-success"></i> Backup Storage - Healthy
                                                <?php endif; ?>
                                            </h5>
                                        </div>
                                        <div class="card-body py-2">
                                            <div class="row text-center">
                                                <!-- Automated -->
                                                <div class="col-4">
                                                    <div class="text-primary">
                                                        <i class="fa fa-cog"></i>
                                                    </div>
                                                    <small>Automated</small>
                                                    <div class="font-weight-bold"><?= $backupStats['automated']['count'] ?></div>
                                                    <small class="text-muted"><?= round($backupStats['automated']['total_size'] / 1024 / 1024, 1) ?>MB</small>
                                                </div>

                                                <!-- Manual -->
                                                <div class="col-4">
                                                    <div class="text-info">
                                                        <i class="fa fa-user"></i>
                                                    </div>
                                                    <small>Manual</small>
                                                    <div class="font-weight-bold"><?= $backupStats['manual']['count'] ?></div>
                                                    <small class="text-muted"><?= round($backupStats['manual']['total_size'] / 1024 / 1024, 1) ?>MB</small>
                                                </div>

                                                <!-- Rollback -->
                                                <div class="col-4">
                                                    <div class="text-secondary">
                                                        <i class="fa fa-undo"></i>
                                                    </div>
                                                    <small>Rollback</small>
                                                    <div class="font-weight-bold"><?= $backupStats['rollback']['count'] ?></div>
                                                    <small class="text-muted"><?= round($backupStats['rollback']['total_size'] / 1024 / 1024, 1) ?>MB</small>
                                                </div>
                                            </div>

                                            <!-- Status Summary -->
                                            <div class="border-top mt-2 pt-2">
                                                <?php
                                                $totalFiles = $backupStats['automated']['count'] + $backupStats['manual']['count'] + $backupStats['rollback']['count'];
                                                $totalSize = ($backupStats['automated']['total_size'] + $backupStats['manual']['total_size'] + $backupStats['rollback']['total_size']) / 1024 / 1024;
                                                ?>
                                                <div class="row align-items-center">
                                                    <div class="col-7">
                                                        <small><strong>Total:</strong> <?= $totalFiles ?> files, <?= round($totalSize, 1) ?>MB</small>
                                                    </div>
                                                    <div class="col-5 text-right">
                                                        <?php if ($showCleanupPrompt): ?>
                                                            <span class="badge badge-warning badge-sm">
                                                                <?= $oldBackupsCount ?> old
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge badge-success badge-sm">
                                                                <i class="fa fa-check"></i> Clean
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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
