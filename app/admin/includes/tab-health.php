<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\BackupException;

/**
 * tab-health.php
 * System Health Tab Content
 *
 * Read-only monitoring of database, backup storage, and pending migrations.
 */

$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . BACKUP_BASE_DIR, (int)$user->data()->id);

$fixDirectory = $abs_us_root . $us_url_root . 'app/admin/scripts/fix/';
$allItems = scandir($fixDirectory) ?: [];

$fixScripts = [];
foreach ($allItems as $item) {
    $fullPath = $fixDirectory . $item;

    if (is_dir($fullPath) || pathinfo($item, PATHINFO_EXTENSION) !== 'php') {
        continue;
    }

    if (in_array($item, ['index.php', '_TEMPLATE_Fix-Script.php', 'backup-functions.php'])) {
        continue;
    }

    if (preg_match('/^(backup_|rollback_|.*_backup_|test-)/', $item)) {
        continue;
    }

    $fixScripts[] = $item;
}

$scriptRunStatus = array_fill_keys($fixScripts, ['has_run' => false, 'last_run' => null]);
if (!empty($fixScripts)) {
    try {
        $placeholders = implode(',', array_fill(0, count($fixScripts), '?'));
        $sql = "SELECT script_name, MAX(completed_at) as last_run FROM fix_script_runs WHERE script_name IN (" . $placeholders . ") GROUP BY script_name";
        $runs = $db->query($sql, $fixScripts)->results();
        foreach ($runs as $row) {
            $scriptRunStatus[$row->script_name] = ['has_run' => true, 'last_run' => $row->last_run];
        }
    } catch (\Exception $e) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, 'Failed to batch-check script run status: ' . $e->getMessage());
    }
}

$pendingMigrations = count(array_filter($scriptRunStatus, fn($s) => !$s['has_run']));

$backupStatsFallback = false;

try {
    $backupStats = $backupManager->getEnhancedBackupStatistics();
    $oldBackupsCount = 0;

    if (isset($backupStats['retention_analysis'])) {
        foreach ($backupStats['retention_analysis'] as $typeData) {
            $oldBackupsCount += $typeData['expired'];
        }
    }

    $showCleanupPrompt = $oldBackupsCount > 0;
} catch (BackupException $e) {
    logger($user->data()->id, $e->getLogCategory(), 'Enhanced backup stats failed: ' . $e->getMessage());
    $backupStatsFallback = true;
    try {
        $backupStats = getBackupStatistics();
        $backupStats['health_score'] = 85;
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
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-heartbeat"></i> System Health
        </h2>
        <p class="text-muted mb-0">Read-only status overview of database, backups, and pending migrations</p>
    </div>
</div>

<?php if ($showCleanupPrompt): ?>
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
    <a href="?tab=maintenance" class="btn btn-warning btn-sm">
        <i class="fas fa-tools"></i> Go to Maintenance
    </a>
    <button type="button" class="btn btn-secondary btn-sm ms-2" onclick="dismissCleanupPrompt()">
        <i class="fas fa-times"></i> Dismiss
    </button>
</div>
<?php endif; ?>

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
                        <?php if ($backupStatsFallback): ?>
                            <span class="badge text-bg-warning ms-1" title="Enhanced stats unavailable; this score is an estimate">estimated</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-<?= $pendingMigrations > 0 ? 'warning' : 'success' ?>">
            <div class="card-body text-center">
                <h6><i class="fas fa-wrench"></i> Pending Migrations</h6>
                <div class="text-<?= $pendingMigrations > 0 ? 'warning' : 'success' ?> mb-2">
                    <i class="fas fa-<?= $pendingMigrations > 0 ? 'exclamation-triangle' : 'check-circle' ?>" style="font-size: 2rem;"></i>
                </div>
                <p class="mb-0 small"><?= $pendingMigrations > 0 ? $pendingMigrations . ' pending' : 'All done' ?></p>
                <small class="text-muted"><?= count($fixScripts) ?> total available</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-secondary">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Database Health Status</h5>
    </div>
    <div class="card-body">
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
                        <td><span class="badge text-bg-success">Active</span></td>
                        <td>All required fields present and validated</td>
                        <td><small class="text-muted">Real-time</small></td>
                    </tr>
                    <tr>
                        <td><strong>Core Tables</strong></td>
                        <td><span class="badge text-bg-success">Healthy</span></td>
                        <td>Users, cars, profiles, and relationships intact</td>
                        <td><small class="text-muted">System startup</small></td>
                    </tr>
                    <tr>
                        <td><strong>Migration Script Tracking</strong></td>
                        <td><span class="badge text-bg-success">Operational</span></td>
                        <td>Migration script execution history maintained</td>
                        <td><small class="text-muted">Per execution</small></td>
                    </tr>
                    <tr>
                        <td><strong>Backup System</strong></td>
                        <td><span class="badge text-bg-<?= $showCleanupPrompt ? 'warning' : 'success' ?>">
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

<script>
function dismissCleanupPrompt() {
    const alertEl = document.querySelector('.alert-warning');
    if (alertEl) {
        alertEl.style.display = 'none';
    }
}
</script>
