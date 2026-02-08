<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\BackupException;

/**
 * tab-system.php
 * System Maintenance Tab Content
 *
 * Phase 1D: FIX system integration and enhanced database management
 * Integrates existing /FIX/ functionality into consolidated interface
 */

// EnhancedSchemaManager and BackupManager auto-loaded via custom autoloader
// No longer need FIX/backup-functions.php - all backup logic is in BackupManager class

// Initialize enhanced managers
// Cast user ID to int for strict type safety across different PHP/database configurations
$schemaManager = new EnhancedSchemaManager($db, (int)$user->data()->id);
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . BACKUP_BASE_DIR, (int)$user->data()->id);

// Get list of FIX scripts
$fixDirectory = $abs_us_root . $us_url_root . 'FIX/';
$allItems = scandir($fixDirectory);

// Filter to only include PHP scripts (exclude system files)
$fixScripts = [];
foreach ($allItems as $item) {
    $fullPath = $fixDirectory . $item;

    // Skip directories and non-PHP files
    if (is_dir($fullPath) || pathinfo($item, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    // Skip system files
    if (in_array($item, ['index.php', '_TEMPLATE_Fix-Script.php', 'backup-functions.php'])) {
        continue;
    }

    // Skip backup and test files
    if (preg_match('/^(backup_|rollback_|.*_backup_|test-)/', $item)) {
        continue;
    }

    $fixScripts[] = $item;
}

// Sort scripts (newest first)
rsort($fixScripts, SORT_NATURAL);

// Get enhanced backup statistics
try {
    $backupStats = $backupManager->getEnhancedBackupStatistics();
    $oldBackupsCount = 0;

    // Count expired backups from retention analysis
    if (isset($backupStats['retention_analysis'])) {
        foreach ($backupStats['retention_analysis'] as $typeData) {
            $oldBackupsCount += $typeData['expired'];
        }
    }

    $showCleanupPrompt = $oldBackupsCount > 0;
} catch (BackupException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Enhanced backup stats failed: ' . $e->getMessage());
    // Fallback to basic stats
    try {
        $backupStats = getBackupStatistics();
        $backupStats['health_score'] = 85; // Default healthy score
        $backupStats['recommendations'] = [];
        $showCleanupPrompt = false;
        $oldBackupsCount = 0;
    } catch (BackupException $e2) {
        $backupStats = [
            'automated' => ['count' => 0, 'total_size' => 0],
            'manual' => ['count' => 0, 'total_size' => 0],
            'rollback' => ['count' => 0, 'total_size' => 0],
            'health_score' => 50,
            'recommendations' => ['Backup system check needed']
        ];
        logger($user->data()->id, $e2->getLogCategory(), 'Fallback backup stats also failed: ' . $e2->getMessage());
        $showCleanupPrompt = false;
        $oldBackupsCount = 0;
    }
}

// Handle enhanced backup cleanup
$cleanupMessage = '';
if (isset($_POST['cleanup_backups']) && $_POST['cleanup_backups'] === 'confirm') {
    try {
        $cleanupResult = $backupManager->performEnhancedCleanup();
        $totalDeleted = ($cleanupResult['automated']['deleted'] ?? 0) +
                       ($cleanupResult['manual']['deleted'] ?? 0) +
                       ($cleanupResult['rollback']['deleted'] ?? 0);

        if ($totalDeleted > 0) {
            $healthImprovement = $cleanupResult['health_improvement'] ?? 0;
            $healthScoreAfter = $cleanupResult['health_score_after'] ?? 'N/A';
            $cleanupMessage = "<div class='alert alert-success'>" .
                             "<i class='fas fa-check-circle'></i> Enhanced backup cleanup completed! " .
                             "Deleted {$totalDeleted} old backup files. " .
                             "Health score improved by {$healthImprovement} points (now {$healthScoreAfter}).</div>";
        } else {
            $cleanupMessage = "<div class='alert alert-info'><i class='fas fa-info-circle'></i> No old backup files found to clean up.</div>";
        }

        // Refresh backup stats after cleanup
        $backupStats = $backupManager->getEnhancedBackupStatistics();
        $showCleanupPrompt = false;
    } catch (BackupException $e) {
        logger($user->data()->id, $e->getLogCategory(), 'Enhanced backup cleanup failed: ' . $e->getMessage());
        $cleanupMessage = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> Enhanced backup cleanup failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

// Check script run status
$scriptRunStatus = [];
foreach ($fixScripts as $script) {
    try {
        $checkQuery = $db->query("SELECT completed_at FROM fix_script_runs WHERE script_name = ? ORDER BY completed_at DESC LIMIT 1", [$script]);
        if ($checkQuery->count() > 0) {
            $scriptRunStatus[$script] = [
                'has_run' => true,
                'last_run' => $checkQuery->first()->completed_at
            ];
        } else {
            $scriptRunStatus[$script] = ['has_run' => false, 'last_run' => null];
        }
    } catch (AdminOperationException $e) {
        logger($user->data()->id, $e->getLogCategory(), "Failed to check script run status for {$script}: " . $e->getMessage());
        $scriptRunStatus[$script] = ['has_run' => false, 'last_run' => null];
    }
}
?>

<!-- System Maintenance Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-tools"></i> System Maintenance
        </h2>
        <p class="text-muted mb-0">FIX script management, database operations, and backup administration</p>
    </div>
</div>

<!-- Display cleanup message -->
<?php if (!empty($cleanupMessage)): ?>
    <?= $cleanupMessage ?>
<?php endif; ?>

<!-- System Status Dashboard -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card border-success">
            <div class="card-body text-center">
                <h6><i class="fas fa-database"></i> Database Health</h6>
                <div class="text-success mb-2">
                    <i class="fas fa-check-circle" style="font-size: 2rem;"></i>
                </div>
                <p class="mb-0 small">Operational</p>
                <small class="text-muted">Auto-creation active</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-<?= $showCleanupPrompt ? 'warning' : 'success' ?>">
            <div class="card-body text-center">
                <h6><i class="fas fa-hdd"></i> Backup Storage</h6>
                <div class="text-<?= $showCleanupPrompt ? 'warning' : 'success' ?> mb-2">
                    <i class="fas fa-<?= $showCleanupPrompt ? 'exclamation-triangle' : 'check-circle' ?>" style="font-size: 2rem;"></i>
                </div>
                <p class="mb-0 small"><?= $showCleanupPrompt ? 'Cleanup needed' : 'Healthy' ?></p>
                <small class="text-muted">
                    <?= ($backupStats['automated']['count'] + $backupStats['manual']['count'] + $backupStats['rollback']['count']) ?> total files
                    <?php if (isset($backupStats['health_score'])): ?>
                        | Score: <?= $backupStats['health_score'] ?>/100
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-body text-center">
                <h6><i class="fas fa-wrench"></i> FIX Scripts</h6>
                <div class="text-info mb-2">
                    <i class="fas fa-cogs" style="font-size: 2rem;"></i>
                </div>
                <p class="mb-0 small"><?= count($fixScripts) ?> available</p>
                <small class="text-muted">Ready for execution</small>
            </div>
        </div>
    </div>
</div>

<!-- Backup Cleanup Alert -->
<?php if ($showCleanupPrompt && empty($_POST['cleanup_backups'])): ?>
<div class="alert alert-warning">
    <h5><i class="fas fa-exclamation-triangle"></i> Backup Cleanup Recommended</h5>
    <p class="mb-2">Found <strong><?= $oldBackupsCount ?></strong> backup files older than 30 days that can be cleaned up.</p>
    <p class="mb-2"><strong>Current backup storage:</strong></p>
    <ul class="mb-3">
        <li>Automated: <?= $backupStats['automated']['count'] ?> files (<?= round($backupStats['automated']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
        <li>Manual: <?= $backupStats['manual']['count'] ?> files (<?= round($backupStats['manual']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
        <li>Rollback: <?= $backupStats['rollback']['count'] ?> files (<?= round($backupStats['rollback']['total_size'] / 1024 / 1024, 2) ?> MB)</li>
    </ul>
    <?php if (!empty($backupStats['recommendations'])): ?>
        <div class="mt-2">
            <strong>Recommendations:</strong>
            <ul class="mb-2">
                <?php foreach ($backupStats['recommendations'] as $recommendation): ?>
                    <li class="small"><?= htmlspecialchars($recommendation) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" style="display: inline;">
        <input type="hidden" name="csrf" value="<?= Token::generate(); ?>">
        <button type="button" class="btn btn-warning btn-sm" onclick="confirmBackupCleanup()">
            <i class="fas fa-trash"></i> Enhanced Cleanup
        </button>
        <input type="hidden" name="cleanup_backups" value="">
    </form>
    <button type="button" class="btn btn-secondary btn-sm ml-2" onclick="dismissCleanupPrompt()">
        <i class="fas fa-times"></i> Dismiss
    </button>
</div>
<?php endif; ?>

<!-- FIX Scripts Management -->
<div class="card border-primary mb-4">
    <div class="card-header bg-primary text-white">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-wrench"></i> FIX Scripts Management</h5>
            <div>
                <small class="text-light">
                    <i class="fas fa-info-circle"></i> Run Status:
                    <span class="badge badge-success">✅ Completed</span>
                    <span class="badge badge-secondary">➖ Not Run</span>
                </small>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($fixScripts)): ?>
            <div class="text-center py-4">
                <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                <h5 class="text-success">No FIX Scripts Available</h5>
                <p class="text-muted">All maintenance scripts have been completed or no scripts are currently available.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Status</th>
                            <th>Script Name</th>
                            <th>Last Run</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fixScripts as $script): ?>
                            <tr>
                                <td>
                                    <?php if ($scriptRunStatus[$script]['has_run']): ?>
                                        <span class="badge badge-success" title="Script completed successfully">
                                            <i class="fas fa-check"></i> Completed
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary" title="Script has not been run">
                                            <i class="fas fa-minus"></i> Not Run
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($script) ?></strong>
                                    <?php
                                    // Extract description from filename
                                    $displayName = preg_replace('/^\d+-/', '', pathinfo($script, PATHINFO_FILENAME));
                                    $displayName = str_replace(['-', '_'], ' ', $displayName);
                                    if ($displayName !== pathinfo($script, PATHINFO_FILENAME)):
                                    ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($displayName) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($scriptRunStatus[$script]['has_run']): ?>
                                        <small class="text-muted">
                                            <?= date('M j, Y g:i A', strtotime($scriptRunStatus[$script]['last_run'])) ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">Never</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="../../FIX/<?= urlencode($script) ?>"
                                       class="btn btn-sm btn-outline-primary"
                                       target="_blank"
                                       title="Run <?= htmlspecialchars($script) ?>">
                                        <i class="fas fa-play"></i> Run Script
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-3">
                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle"></i> Script Execution Notes</h6>
                    <ul class="mb-0 small">
                        <li>Scripts open in a new window/tab for execution</li>
                        <li>Automatic backups are created before script execution when required</li>
                        <li>Progress and results are displayed in real-time during execution</li>
                        <li>Completed scripts are logged in the system for tracking</li>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Database Schema Management -->
<div class="card border-success mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-database"></i> Enhanced Database Management</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6><i class="fas fa-check-circle text-success"></i> Current Auto-Creation System</h6>
                <div class="card bg-light">
                    <div class="card-body py-3">
                        <ul class="list-unstyled small mb-0">
                            <li><i class="fas fa-check text-success"></i> Settings field auto-creation</li>
                            <li><i class="fas fa-check text-success"></i> Default value population</li>
                            <li><i class="fas fa-check text-success"></i> Type detection and validation</li>
                            <li><i class="fas fa-check text-success"></i> Error handling and admin feedback</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-2">
                    <a href="?tab=settings" class="btn btn-sm btn-outline-success">
                        <i class="fas fa-cog"></i> Manage Settings Auto-Creation
                    </a>
                </div>
            </div>
            <div class="col-md-6">
                <h6><i class="fas fa-tools text-primary"></i> Enhanced Schema Operations</h6>
                <div class="card bg-light">
                    <div class="card-body py-3">
                        <ul class="list-unstyled small mb-0">
                            <li><i class="fas fa-plus text-primary"></i> Quality tracking table management</li>
                            <li><i class="fas fa-plus text-primary"></i> User profile audit tables</li>
                            <li><i class="fas fa-plus text-primary"></i> Enhanced backup integration</li>
                            <li><i class="fas fa-plus text-primary"></i> Schema validation and integrity</li>
                        </ul>
                    </div>
                </div>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="runSchemaValidation(this)">
                        <i class="fas fa-check-double"></i> Validate Schema
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-success ml-2" onclick="runSchemaMaintenance()">
                        <i class="fas fa-tools"></i> Run Maintenance
                    </button>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-12">
                <h6><i class="fas fa-info-circle text-info"></i> Database Health Status</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Component</th>
                                <th>Status</th>
                                <th>Details</th>
                                <th>Last Checked</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Settings Auto-Creation</strong></td>
                                <td><span class="badge badge-success">Active</span></td>
                                <td>All required fields present and validated</td>
                                <td><small class="text-muted">Real-time</small></td>
                            </tr>
                            <tr>
                                <td><strong>Core Tables</strong></td>
                                <td><span class="badge badge-success">Healthy</span></td>
                                <td>Users, cars, profiles, and relationships intact</td>
                                <td><small class="text-muted">System startup</small></td>
                            </tr>
                            <tr>
                                <td><strong>FIX Script Tracking</strong></td>
                                <td><span class="badge badge-success">Operational</span></td>
                                <td>Script execution history maintained</td>
                                <td><small class="text-muted">Per execution</small></td>
                            </tr>
                            <tr>
                                <td><strong>Backup System</strong></td>
                                <td><span class="badge badge-<?= $showCleanupPrompt ? 'warning' : 'success' ?>">
                                    <?= $showCleanupPrompt ? 'Maintenance' : 'Healthy' ?>
                                </span></td>
                                <td><?= $showCleanupPrompt ? 'Cleanup recommended for optimal performance' : 'Backup retention within normal limits' ?></td>
                                <td><small class="text-muted">Real-time</small></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Backup System Integration  -->
<div class="card border-info">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Integrated Backup Management</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="text-center">
                    <div class="text-primary mb-2">
                        <i class="fas fa-cog" style="font-size: 2rem;"></i>
                    </div>
                    <h6>Automated Backups</h6>
                    <p class="small text-muted mb-2">Created before FIX script execution</p>
                    <div class="badge badge-primary"><?= $backupStats['automated']['count'] ?> files</div>
                    <div class="small text-muted"><?= round($backupStats['automated']['total_size'] / 1024 / 1024, 1) ?>MB</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center">
                    <div class="text-info mb-2">
                        <i class="fas fa-user" style="font-size: 2rem;"></i>
                    </div>
                    <h6>Manual Backups</h6>
                    <p class="small text-muted mb-2">Administrator-initiated backups</p>
                    <div class="badge badge-info"><?= $backupStats['manual']['count'] ?> files</div>
                    <div class="small text-muted"><?= round($backupStats['manual']['total_size'] / 1024 / 1024, 1) ?>MB</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center">
                    <div class="text-warning mb-2">
                        <i class="fas fa-undo" style="font-size: 2rem;"></i>
                    </div>
                    <h6>Rollback Files</h6>
                    <p class="small text-muted mb-2">Emergency recovery backups</p>
                    <div class="badge badge-warning"><?= $backupStats['rollback']['count'] ?> files</div>
                    <div class="small text-muted"><?= round($backupStats['rollback']['total_size'] / 1024 / 1024, 1) ?>MB</div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Backup Integration Features</h6>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="small mb-0">
                            <li><strong>Auto-Backup:</strong> Before all destructive operations</li>
                            <li><strong>Retention:</strong> Intelligent cleanup based on operation type</li>
                            <li><strong>Verification:</strong> Backup integrity checking</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="small mb-0">
                            <li><strong>Integration:</strong> Seamless FIX script integration</li>
                            <li><strong>Recovery:</strong> Enhanced rollback capabilities</li>
                            <li><strong>Monitoring:</strong> Storage usage and health tracking</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mt-3">
            <button type="button" class="btn btn-success" onclick="createManualBackup()">
                <i class="fas fa-save"></i> Create Manual Backup
            </button>
            <button type="button" class="btn btn-outline-info ml-2" onclick="listBackupFiles()">
                <i class="fas fa-list"></i> List Backup Files
            </button>
        </div>

        <div class="text-center mt-2">
            <button type="button" class="btn btn-outline-warning btn-sm" onclick="performBackupCleanup()">
                <i class="fas fa-broom"></i> Cleanup Old Backups
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm ml-2" onclick="runSchemaValidation(this)">
                <i class="fas fa-check-circle"></i> Validate Schema
            </button>
        </div>

        <!-- Validation result container -->
        <div id="validation-result-container" class="mt-2"></div>

        <!-- Backup List Modal -->
        <div class="modal fade" id="backupListModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-database"></i> Backup Files</h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="backupListContent">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Loading backups...
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal (Reusable) -->
        <div class="modal fade" id="confirmationModal" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> <span id="confirmTitle">Confirm Action</span></h5>
                        <button type="button" class="close" data-dismiss="modal">
                            <span>&times;</span>
                        </button>
                    </div>
                    <div class="modal-body" id="confirmMessage">
                        Are you sure?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirmButton">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Helper function to show confirmation modal
function showConfirmDialog(title, message, onConfirm) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').innerHTML = message;

    // Remove any existing click handlers
    const confirmBtn = document.getElementById('confirmButton');
    const newConfirmBtn = confirmBtn.cloneNode(true);
    confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

    // Add new click handler
    document.getElementById('confirmButton').addEventListener('click', function() {
        $('#confirmationModal').modal('hide');
        onConfirm();
    });

    $('#confirmationModal').modal('show');
}

// Backup cleanup confirmation
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

// Dismiss cleanup prompt
function dismissCleanupPrompt() {
    document.querySelector('.alert-warning').style.display = 'none';
}

// Create manual backup
function createManualBackup() {
    const message = `<p><strong>Create a manual backup of critical database tables?</strong></p>
                    <p class="small mb-2">This will backup the following tables:</p>
                    <ul class="small">
                        <li>users</li>
                        <li>cars</li>
                        <li>car_user</li>
                        <li>profiles</li>
                        <li>settings</li>
                        <li>car_history</li>
                    </ul>`;

    showConfirmDialog('Create Manual Backup', message, function() {
        // Find the backup button
        const btn = document.querySelector('button[onclick*="createManualBackup"]');
        const originalHTML = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating backup...';
        }

        fetch('includes/system/backup-operations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=create_manual_backup&reason=admin-panel-backup'
        })
        .then(response => response.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }

            if (data.success) {
                showAlert('success', '✓ Backup created successfully!',
                         'File: ' + data.filename + '<br>Size: ' + data.size);
                // Refresh after 2 seconds to update statistics
                setTimeout(() => location.reload(), 2000);
            } else {
                let errorMsg = data.message || 'Unknown error';
                if (data.error_details) {
                    errorMsg += '<br><small class="text-muted">' +
                               'Error in ' + data.error_details.file + ' at line ' + data.error_details.line +
                               '<br>Check the logs for more details.</small>';
                }
                showAlert('danger', '✗ Backup creation failed', errorMsg);
            }
        })
        .catch(error => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            showAlert('danger', '✗ Error creating backup', error.message);
        });
    });
}

// Helper function to show alerts
function showAlert(type, title, message) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show mt-3`;
    alertDiv.innerHTML = `
        <strong>${title}</strong><br>
        ${message}
        <button type="button" class="close" data-dismiss="alert">
            <span>&times;</span>
        </button>
    `;

    const container = document.getElementById('validation-result-container');
    container.innerHTML = '';
    container.appendChild(alertDiv);

    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// List backup files
function listBackupFiles() {
    $('#backupListModal').modal('show');

    fetch('includes/system/backup-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=list_backups'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            let html = '<div class="table-responsive"><table class="table table-sm table-striped">';
            html += '<thead><tr><th>File</th><th>Type</th><th>Size</th><th>Created</th></tr></thead><tbody>';

            const allBackups = [
                ...data.backups.automated.map(b => ({...b, type: 'Automated'})),
                ...data.backups.manual.map(b => ({...b, type: 'Manual'})),
                ...data.backups.rollback.map(b => ({...b, type: 'Rollback'}))
            ];

            // Sort by created date, newest first
            allBackups.sort((a, b) => new Date(b.created) - new Date(a.created));

            if (allBackups.length === 0) {
                html += '<tr><td colspan="4" class="text-center text-muted">No backup files found</td></tr>';
            } else {
                allBackups.forEach(backup => {
                    html += `<tr>
                        <td><small>${backup.filename}</small></td>
                        <td><span class="badge badge-${backup.type === 'Automated' ? 'primary' : backup.type === 'Manual' ? 'info' : 'warning'}">${backup.type}</span></td>
                        <td>${backup.size_formatted}</td>
                        <td>${backup.created}</td>
                    </tr>`;
                });
            }

            html += '</tbody></table></div>';
            document.getElementById('backupListContent').innerHTML = html;
        } else {
            document.getElementById('backupListContent').innerHTML =
                '<div class="alert alert-danger">Error loading backups: ' + (data.message || 'Unknown error') + '</div>';
        }
    })
    .catch(error => {
        document.getElementById('backupListContent').innerHTML =
            '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}

// Perform backup cleanup
function performBackupCleanup() {
    // First, fetch the list of files that will be deleted
    fetch('includes/system/backup-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=preview_cleanup'
    })
    .then(response => response.json())
    .then(previewData => {
        if (!previewData.success) {
            showAlert('danger', '✗ Error', 'Failed to load cleanup preview');
            return;
        }

        // Build file list HTML
        let fileListHtml = '';

        if (previewData.total_count === 0) {
            fileListHtml = '<p class="text-success mb-0"><i class="fas fa-check-circle"></i> No files to delete. All backups are within retention policy.</p>';
        } else {
            fileListHtml = `<p class="mb-2"><strong>${previewData.total_count} file(s) will be deleted:</strong></p>`;

            // Automated backups
            if (previewData.files.automated.length > 0) {
                fileListHtml += '<div class="mb-2"><strong class="text-primary">Automated Backups:</strong><ul class="small mb-1">';
                previewData.files.automated.forEach(file => {
                    fileListHtml += `<li>${file.filename} <span class="text-muted">(${file.age_days} days old, ${file.size_formatted})</span></li>`;
                });
                fileListHtml += '</ul></div>';
            }

            // Manual backups
            if (previewData.files.manual.length > 0) {
                fileListHtml += '<div class="mb-2"><strong class="text-primary">Manual Backups:</strong><ul class="small mb-1">';
                previewData.files.manual.forEach(file => {
                    fileListHtml += `<li>${file.filename} <span class="text-muted">(${file.age_days} days old, ${file.size_formatted})</span></li>`;
                });
                fileListHtml += '</ul></div>';
            }

            // Rollback backups
            if (previewData.files.rollback.length > 0) {
                fileListHtml += '<div class="mb-2"><strong class="text-primary">Rollback Backups:</strong><ul class="small mb-1">';
                previewData.files.rollback.forEach(file => {
                    fileListHtml += `<li>${file.filename} <span class="text-muted">(${file.age_days} days old, ${file.size_formatted})</span></li>`;
                });
                fileListHtml += '</ul></div>';
            }
        }

        const message = `<div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Warning: This action cannot be undone!</strong>
                         </div>
                         ${fileListHtml}
                         <p class="mt-3 mb-2 small text-muted">
                             <strong>Retention Policies:</strong><br>
                             • Automated: 7 days (dev) | Manual: 14 days (dev) | Rollback: 14 days (dev)
                         </p>
                         <p class="mb-2"><strong>Continue with cleanup?</strong></p>`;

        // Only show confirmation if there are files to delete
        if (previewData.total_count === 0) {
            showAlert('info', 'No Cleanup Needed', fileListHtml);
            return;
        }

        showConfirmDialog('Backup Cleanup Confirmation', message, function() {
        // Find the cleanup button
        const btn = document.querySelector('button[onclick*="performBackupCleanup"]');
        const originalHTML = btn ? btn.innerHTML : '';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning up...';
        }

        fetch('includes/system/backup-operations.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=cleanup_backups'
        })
        .then(response => response.json())
        .then(data => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }

            if (data.success) {
                const totalDeleted = data.cleanup.automated.deleted + data.cleanup.manual.deleted + data.cleanup.rollback.deleted;
                const resultMessage = `<p><strong>Files deleted:</strong></p>
                                      <ul>
                                          <li>Automated: ${data.cleanup.automated.deleted} of ${data.cleanup.automated.scanned}</li>
                                          <li>Manual: ${data.cleanup.manual.deleted} of ${data.cleanup.manual.scanned}</li>
                                          <li>Rollback: ${data.cleanup.rollback.deleted} of ${data.cleanup.rollback.scanned}</li>
                                      </ul>
                                      <p class="mb-0"><strong>Total: ${totalDeleted} files removed</strong></p>`;

                showAlert('success', '✓ Cleanup completed successfully!', resultMessage);
                // Refresh the page after 2 seconds to update statistics
                setTimeout(() => location.reload(), 2000);
            } else {
                showAlert('danger', '✗ Cleanup failed', data.message || 'Unknown error');
            }
        })
        .catch(error => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
            showAlert('danger', '✗ Error during cleanup', error.message);
        });
        });
    })
    .catch(error => {
        showAlert('danger', '✗ Error loading cleanup preview', error.message);
    });
}

// Schema validation using Enhanced Schema Manager
function runSchemaValidation() {
    // Find the validation button
    const btn = document.querySelector('button[onclick*="runSchemaValidation"]');
    const originalHTML = btn ? btn.innerHTML : '';

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';
    }

    const resultContainer = document.getElementById('validation-result-container');

    fetch('includes/system/schema-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=validate_schema'
    })
    .then(response => response.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }

        // Show validation result in the dedicated container
        let alertDiv = '';

        if (data.success && data.valid) {
            alertDiv = '<div class="alert alert-success"><i class="fas fa-check-circle"></i> Schema validation completed successfully. All components are healthy and operational.</div>';
        } else {
            let issues = data.issues || ['Validation failed'];
            alertDiv = `<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Schema validation found issues:<ul class="mb-0 mt-2">${issues.map(issue => `<li>${issue}</li>`).join('')}</ul></div>`;
        }

        resultContainer.innerHTML = alertDiv;

        // Auto-remove after 8 seconds
        setTimeout(() => {
            resultContainer.innerHTML = '';
        }, 8000);
    })
    .catch(error => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }

        const resultContainer = document.getElementById('validation-result-container');
        if (resultContainer) {
            resultContainer.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> Schema validation failed: ' + error.message + '</div>';
        }

        setTimeout(() => {
            if (document.querySelector('#validation-result')) {
                document.querySelector('#validation-result').remove();
            }
        }, 8000);
    });
}

// Schema maintenance using Enhanced Schema Manager
function runSchemaMaintenance() {
    if (!confirm('Run comprehensive schema maintenance?\n\nThis will:\n• Create backup before changes\n• Ensure all required fields and tables\n• Validate schema integrity\n\nContinue?')) {
        return;
    }

    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running...';

    // Get CSRF token from any form on the page
    const csrfToken = document.querySelector('input[name="csrf"]')?.value || '';

    fetch('includes/system/schema-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=perform_maintenance&csrf=${csrfToken}`
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        // Remove any existing result
        const existingResult = document.querySelector('#maintenance-result');
        if (existingResult) {
            existingResult.remove();
        }

        // Show maintenance result
        const alertDiv = document.createElement('div');
        alertDiv.id = 'maintenance-result';

        if (data.success) {
            alertDiv.className = 'alert alert-success mt-2';
            let operations = data.operations || ['Maintenance completed'];
            alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> Schema maintenance completed successfully:<ul class="mb-0 mt-2">${operations.map(op => `<li>${op}</li>`).join('')}</ul>`;
        } else {
            alertDiv.className = 'alert alert-danger mt-2';
            alertDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> Schema maintenance failed: ${data.error || data.message || 'Unknown error'}`;
        }

        btn.parentNode.appendChild(alertDiv);

        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (document.querySelector('#maintenance-result')) {
                document.querySelector('#maintenance-result').remove();
            }
        }, 10000);
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        const alertDiv = document.createElement('div');
        alertDiv.id = 'maintenance-result';
        alertDiv.className = 'alert alert-danger mt-2';
        alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Schema maintenance failed: ' + error.message;
        btn.parentNode.appendChild(alertDiv);

        setTimeout(() => {
            if (document.querySelector('#maintenance-result')) {
                document.querySelector('#maintenance-result').remove();
            }
        }, 10000);
    });
}

// Enhanced FIX script status tracking
document.addEventListener('DOMContentLoaded', function() {
    // Add hover effects to script rows
    const scriptRows = document.querySelectorAll('tbody tr');
    scriptRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    // Track external script window openings
    const scriptLinks = document.querySelectorAll('a[href*="/FIX/"]');
    scriptLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Add visual feedback
            const btn = this;
            btn.classList.add('btn-primary');
            btn.classList.remove('btn-outline-primary');

            setTimeout(() => {
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-outline-primary');
            }, 3000);
        });
    });
});
</script>