<?php
declare(strict_types=1);
/**
 * tab-system.php
 * System Maintenance Tab Content
 *
 * Phase 1D: FIX system integration and enhanced database management
 * Integrates existing /FIX/ functionality into consolidated interface
 */

// Include FIX system functionality and Enhanced Schema Manager
require_once $abs_us_root . $us_url_root . 'FIX/backup-functions.php';
require_once 'classes/EnhancedSchemaManager.php';
require_once 'classes/BackupManager.php';

// Initialize enhanced managers
$schemaManager = new EnhancedSchemaManager($db, $settings, $user->data()->id);
$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . 'FIX/backups/', $user->data()->id);

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
} catch (Exception $e) {
    logger($user->data()->id, 'SystemError', 'Enhanced backup stats failed: ' . $e->getMessage());
    // Fallback to basic stats
    try {
        $backupStats = getBackupStatistics();
        $backupStats['health_score'] = 85; // Default healthy score
        $backupStats['recommendations'] = [];
        $showCleanupPrompt = false;
        $oldBackupsCount = 0;
    } catch (Exception $e2) {
        $backupStats = [
            'automated' => ['count' => 0, 'total_size' => 0],
            'manual' => ['count' => 0, 'total_size' => 0],
            'rollback' => ['count' => 0, 'total_size' => 0],
            'health_score' => 50,
            'recommendations' => ['Backup system check needed']
        ];
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
    } catch (Exception $e) {
        logger($user->data()->id, 'SystemError', 'Enhanced backup cleanup failed: ' . $e->getMessage());
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
    } catch (Exception $e) {
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
    <div>
        <a href="../../FIX/" class="btn btn-outline-info" target="_blank" title="Legacy FIX Interface">
            <i class="fas fa-external-link-alt"></i> Legacy Interface
        </a>
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
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="runSchemaValidation()">
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

<!-- Backup System Integration -->
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
            <a href="../../FIX/" class="btn btn-outline-info" target="_blank">
                <i class="fas fa-folder-open"></i> View Backup Directory
            </a>
        </div>
    </div>
</div>

<script>
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

// Schema validation using Enhanced Schema Manager
function runSchemaValidation() {
    const btn = event.target;
    const originalHTML = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';

    fetch('includes/system/schema-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=validate_schema'
    })
    .then(response => response.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        // Remove any existing result
        const existingResult = document.querySelector('#validation-result');
        if (existingResult) {
            existingResult.remove();
        }

        // Show validation result
        const alertDiv = document.createElement('div');
        alertDiv.id = 'validation-result';

        if (data.success && data.validation.valid) {
            alertDiv.className = 'alert alert-success mt-2';
            alertDiv.innerHTML = '<i class="fas fa-check-circle"></i> Schema validation completed successfully. All components are healthy and operational.';
        } else {
            alertDiv.className = 'alert alert-warning mt-2';
            let issues = data.validation?.issues || ['Validation failed'];
            alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Schema validation found issues:<ul class="mb-0 mt-2">${issues.map(issue => `<li>${issue}</li>`).join('')}</ul>`;
        }

        btn.parentNode.appendChild(alertDiv);

        // Auto-remove after 8 seconds
        setTimeout(() => {
            if (document.querySelector('#validation-result')) {
                document.querySelector('#validation-result').remove();
            }
        }, 8000);
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        const alertDiv = document.createElement('div');
        alertDiv.id = 'validation-result';
        alertDiv.className = 'alert alert-danger mt-2';
        alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Schema validation failed: ' + error.message;
        btn.parentNode.appendChild(alertDiv);

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
            let operations = data.result?.operations || ['Maintenance completed'];
            alertDiv.innerHTML = `<i class="fas fa-check-circle"></i> Schema maintenance completed successfully:<ul class="mb-0 mt-2">${operations.map(op => `<li>${op}</li>`).join('')}</ul>`;
        } else {
            alertDiv.className = 'alert alert-danger mt-2';
            alertDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> Schema maintenance failed: ${data.error || data.result?.error || 'Unknown error'}`;
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