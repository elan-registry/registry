<?php
declare(strict_types=1);

use ElanRegistry\Exceptions\AdminOperationException;
use ElanRegistry\Exceptions\BackupException;

require_once $abs_us_root . $us_url_root . 'app/admin/includes/system/script-enumeration.php';

/**
 * tab-maintenance.php
 * Maintenance Tab Content
 *
 * Backups, one-time migration scripts, and recurring maintenance tasks.
 */

$backupManager = new BackupManager($db, $abs_us_root . $us_url_root . BACKUP_BASE_DIR, (int)$user->data()->id);

$fixDirectory = $abs_us_root . $us_url_root . 'app/admin/scripts/fix/';
$fixScripts   = enumerateScriptFiles($fixDirectory);

rsort($fixScripts, SORT_NATURAL);

$maintenanceDirectory = $abs_us_root . $us_url_root . 'app/admin/scripts/maintenance/';
$maintenanceScripts   = enumerateScriptFiles($maintenanceDirectory);

sort($maintenanceScripts, SORT_NATURAL);

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

$scriptRunStatus = array_fill_keys($fixScripts, ['has_run' => false, 'last_run' => null]);
$maintenanceRunStatus = array_fill_keys($maintenanceScripts, ['has_run' => false, 'last_run' => null]);

$allScriptNames = array_merge($fixScripts, $maintenanceScripts);
$scriptRunStatusError = false;
if (!empty($allScriptNames)) {
    try {
        $placeholders = implode(',', array_fill(0, count($allScriptNames), '?'));
        $sql = "SELECT script_name, MAX(completed_at) as last_run FROM fix_script_runs WHERE script_name IN (" . $placeholders . ") GROUP BY script_name";
        $runs = $db->query($sql, $allScriptNames)->results();
        foreach ($runs as $row) {
            if (isset($scriptRunStatus[$row->script_name])) {
                $scriptRunStatus[$row->script_name] = ['has_run' => true, 'last_run' => $row->last_run];
            }
            if (isset($maintenanceRunStatus[$row->script_name])) {
                $maintenanceRunStatus[$row->script_name] = ['has_run' => true, 'last_run' => $row->last_run];
            }
        }
    } catch (\Exception $e) {
        $scriptRunStatusError = true;
        logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_MAINTENANCE, 'Failed to batch-check script run status: ' . $e->getMessage());
    }
}

$pendingFixScripts   = array_filter($fixScripts, fn($s) => !$scriptRunStatus[$s]['has_run']);
$completedFixScripts = array_filter($fixScripts, fn($s) =>  $scriptRunStatus[$s]['has_run']);

function scriptDisplayName(string $filename): string {
    return str_replace(['-', '_'], ' ', preg_replace('/^\d+-/', '', pathinfo($filename, PATHINFO_FILENAME)));
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-tools"></i> Maintenance
        </h2>
        <p class="text-muted mb-0">Backup management, one-time migrations, and recurring maintenance tasks</p>
    </div>
</div>

<!-- Backups -->
<div class="card border-primary mb-4">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-shield-alt"></i> Backups</h5>
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
                    <div class="badge text-bg-primary"><?= $backupStats['automated']['count'] ?> files</div>
                    <div class="small text-muted"><?= round($backupStats['automated']['total_size'] / 1024 / 1024, 1) ?>MB</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center">
                    <div class="text-primary mb-2">
                        <i class="fas fa-user" style="font-size: 2rem;"></i>
                    </div>
                    <h6>Manual Backups</h6>
                    <p class="small text-muted mb-2">Administrator-initiated backups</p>
                    <div class="badge text-bg-primary"><?= $backupStats['manual']['count'] ?> files</div>
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
                    <div class="badge text-bg-warning"><?= $backupStats['rollback']['count'] ?> files</div>
                    <div class="small text-muted"><?= round($backupStats['rollback']['total_size'] / 1024 / 1024, 1) ?>MB</div>
                </div>
            </div>
        </div>

        <div class="text-center mt-3">
            <button type="button" class="btn btn-primary" onclick="createManualBackup(this)">
                <i class="fas fa-save"></i> Create Manual Backup
            </button>
            <button type="button" class="btn btn-outline-primary ms-2" onclick="listBackupFiles()">
                <i class="fas fa-list"></i> List Backup Files
            </button>
            <?php if ($showCleanupPrompt): ?>
                <button type="button" class="btn btn-outline-warning btn-sm ms-2" onclick="performBackupCleanup()">
                    <i class="fas fa-broom"></i> Cleanup Old Backups
                </button>
            <?php else: ?>
                <button type="button" class="btn btn-outline-secondary btn-sm ms-2" disabled title="No old backups to clean up">
                    <i class="fas fa-broom"></i> Cleanup Old Backups
                </button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-primary btn-sm ms-2" onclick="runSchemaValidation(this)">
                <i class="fas fa-check-circle"></i> Validate Schema
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm ms-2" onclick="runSchemaMaintenance(this)">
                <i class="fas fa-tools"></i> Run Maintenance
            </button>
        </div>

        <div id="validation-result-container" class="mt-2"></div>

        <!-- Backup List Modal -->
        <div class="modal fade" id="backupListModal" tabindex="-1" role="dialog">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-database"></i> Backup Files</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="backupListContent">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin"></i> Loading backups...
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($scriptRunStatusError): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    <strong>Script run history unavailable.</strong>
    Could not load completion records from the database — all scripts are shown as pending.
    Do not re-run a script you know has already completed. Check server logs for details.
</div>
<?php endif; ?>

<!-- One-time Migrations -->
<div class="card border-warning mb-4">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="fas fa-file-code"></i> One-time Migrations</h5>
        <small class="text-dark">Scripts that apply structural changes once. Run when needed; archived when done.</small>
    </div>
    <div class="card-body">
        <?php if (empty($pendingFixScripts) && empty($completedFixScripts)): ?>
            <p class="text-muted mb-0">No migration scripts available.</p>
        <?php else: ?>
            <?php if (empty($pendingFixScripts)): ?>
                <div class="alert alert-success mb-3">
                    <i class="fas fa-check-circle"></i> <strong>No pending migrations</strong> &mdash; all migration scripts have been completed.
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
                            <?php foreach ($pendingFixScripts as $script): ?>
                                <tr>
                                    <td>
                                        <span class="badge text-bg-secondary" title="Script has not been run">
                                            <i class="fas fa-minus"></i> Not Run
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($script) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars(scriptDisplayName($script)) ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted">Never</small>
                                    </td>
                                    <td>
                                        <a href="scripts/fix/<?= urlencode($script) ?>"
                                           class="btn btn-sm btn-warning text-dark"
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
            <?php endif; ?>

            <?php if (count($completedFixScripts) > 0): ?>
                <div class="mt-2">
                    <button class="btn btn-sm btn-link text-muted" data-bs-toggle="collapse" data-bs-target="#completedMigrations">
                        Show <?= count($completedFixScripts) ?> completed migrations
                    </button>
                    <div class="collapse" id="completedMigrations">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover text-muted">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Status</th>
                                        <th>Script Name</th>
                                        <th>Last Run</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($completedFixScripts as $script): ?>
                                        <tr>
                                            <td>
                                                <span class="badge text-bg-primary" title="Script completed successfully">
                                                    <i class="fas fa-check"></i> Completed
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($script) ?></strong>
                                                <br><small><?= htmlspecialchars(scriptDisplayName($script)) ?></small>
                                            </td>
                                            <td>
                                                <small>
                                                    <?= date('M j, Y g:i A', strtotime($scriptRunStatus[$script]['last_run'])) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <a href="scripts/fix/<?= urlencode($script) ?>"
                                                   class="btn btn-sm btn-outline-secondary"
                                                   target="_blank"
                                                   title="Re-run <?= htmlspecialchars($script) ?>">
                                                    <i class="fas fa-play"></i> Run Script
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <div class="alert alert-primary">
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

<!-- Maintenance Tasks -->
<div class="card border-primary mb-4">
    <div class="card-header card-header-er-primary">
        <h5 class="mb-0 card-header-er-primary-text"><i class="fas fa-sync-alt"></i> Maintenance Tasks</h5>
        <small class="card-header-er-primary-text">Scripts safe to run anytime to refresh data or fix common issues.</small>
    </div>
    <div class="card-body">
        <?php if (empty($maintenanceScripts)): ?>
            <p class="text-muted mb-0">No maintenance scripts available.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Script Name</th>
                            <th>Description</th>
                            <th>Last Run</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenanceScripts as $script): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($script) ?></strong></td>
                                <td><small class="text-muted"><?= htmlspecialchars(scriptDisplayName($script)) ?></small></td>
                                <td>
                                    <?php if ($maintenanceRunStatus[$script]['has_run']): ?>
                                        <small class="text-muted">
                                            <?= date('M j, Y g:i A', strtotime($maintenanceRunStatus[$script]['last_run'])) ?>
                                        </small>
                                    <?php else: ?>
                                        <small class="text-muted">Never</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="scripts/maintenance/<?= urlencode($script) ?>"
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
        <?php endif; ?>
    </div>
</div>

<script>
// Helper to build a Font Awesome <i> element with given classes.
function buildIcon(iconClasses) {
    const i = document.createElement('i');
    i.className = iconClasses;
    return i;
}

function runSchemaMaintenance(btn) {
    const originalHTML = btn.innerHTML;

    showConfirmDialog(
        'Run Schema Maintenance',
        'This will:\n\u2022 Create backup before changes\n\u2022 Ensure all required fields and tables\n\u2022 Validate schema integrity\n\nContinue?',
        function() {
            runSchemaMaintenanceConfirmed(btn, originalHTML);
        }
    );
}

function runSchemaMaintenanceConfirmed(btn, originalHTML) {
    const csrfInput = document.querySelector('input[name="csrf"]');
    if (!csrfInput || !csrfInput.value) {
        showNotification('Security token missing or expired. Please reload the page and try again.', 'danger');
        return;
    }
    const csrfToken = csrfInput.value;

    btn.disabled = true;
    btn.replaceChildren(buildIcon('fas fa-spinner fa-spin'), document.createTextNode(' Running...'));

    fetch('includes/system/schema-operations.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=perform_maintenance&csrf=${encodeURIComponent(csrfToken)}`
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`Server error (HTTP ${response.status})`);
        }
        return response.json().catch(() => {
            throw new Error('Server returned an unexpected response. Check server logs before retrying.');
        });
    })
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        const existingResult = document.querySelector('#maintenance-result');
        if (existingResult) {
            existingResult.remove();
        }

        const alertDiv = document.createElement('div');
        alertDiv.id = 'maintenance-result';

        if (data.success) {
            alertDiv.className = 'alert alert-success mt-2';
            alertDiv.append(buildIcon('fas fa-check-circle'),
                document.createTextNode(' Schema maintenance completed successfully:'));
            const ul = document.createElement('ul');
            ul.className = 'mb-0 mt-2';
            (data.operations || ['Maintenance completed']).forEach(op => {
                const li = document.createElement('li');
                li.textContent = op;
                ul.appendChild(li);
            });
            alertDiv.appendChild(ul);
        } else {
            alertDiv.className = 'alert alert-danger mt-2';
            alertDiv.append(buildIcon('fas fa-exclamation-circle'),
                document.createTextNode(' Schema maintenance failed: '));
            const errSpan = document.createElement('span');
            errSpan.textContent = data.error || data.message || 'Unknown error';
            alertDiv.appendChild(errSpan);
        }

        btn.parentNode.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 10000);
    })
    .catch(error => {
        btn.disabled = false;
        btn.innerHTML = originalHTML;

        const alertDiv = document.createElement('div');
        alertDiv.id = 'maintenance-result';
        alertDiv.className = 'alert alert-danger mt-2';
        alertDiv.append(buildIcon('fas fa-exclamation-circle'),
            document.createTextNode(' Schema maintenance failed: '));
        const errSpan = document.createElement('span');
        errSpan.textContent = error.message;
        alertDiv.appendChild(errSpan);
        btn.parentNode.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 10000);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const scriptRows = document.querySelectorAll('tbody tr');
    scriptRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });

    const scriptLinks = document.querySelectorAll('a[href*="/scripts/fix/"], a[href*="/scripts/maintenance/"]');
    scriptLinks.forEach(link => {
        link.addEventListener('click', function(e) {
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
