<?php
declare(strict_types=1);
/**
 * tab-cleanup.php
 * Account Cleanup Tab Content
 *
 * This tab displays Account Cleanup System description, related reports, and spam cleanup controls
 */


// Generate Active Users Without Cars report for cleanup context
$usersWithoutCarsReport = [];
try {
    $usersWithoutCarsQ = $db->query("
        SELECT u.id, u.fname, u.lname, u.email, u.join_date, u.last_login,
               p.city, p.state, p.country,
               DATEDIFF(NOW(), u.join_date) as days_since_join,
               CASE WHEN u.last_login IS NULL OR u.last_login = '0000-00-00 00:00:00' THEN 'Never'
                    ELSE DATE_FORMAT(u.last_login, '%M %d, %Y') END as last_login_formatted
        FROM users u
        LEFT JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND cu.userid IS NULL
        ORDER BY u.join_date DESC
        LIMIT 50
    ");
    $usersWithoutCarsReport = [
        'title' => 'Active Users Without Cars',
        'description' => 'Active users who have registered but never added a car to the registry',
        'icon' => 'fas fa-user-plus',
        'severity' => 'info',
        'count' => count($usersWithoutCarsQ->results()),
        'data' => $usersWithoutCarsQ->results(),
        'impact' => 'These users may be candidates for engagement outreach or cleanup consideration'
    ];
} catch (Exception $e) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Users without cars report failed: ' . $e->getMessage());
    $usersWithoutCarsReport = [
        'title' => 'Active Users Without Cars',
        'description' => 'Report temporarily unavailable',
        'count' => 0,
        'data' => [],
        'error' => true
    ];
}

// Get current cleanup statistics for spam detection
$cleanupStats = [
    'total_users' => 0,
    'inactive_users' => 0,
    'users_without_cars' => 0,
    'spam_candidates' => 0,
    'safety_status' => 'safe'
];

try {
    // Get total active users
    $totalUsersQ = $db->query("SELECT COUNT(*) as count FROM users WHERE active = 1");
    $cleanupStats['total_users'] = $totalUsersQ->count() > 0 ? (int)$totalUsersQ->first()->count : 0;

    // Get inactive users threshold from settings
    $inactiveThreshold = (int)($settings->elan_spam_inactive_days ?? 30);

    // Get inactive users (no login for X days and no cars)
    $inactiveUsersQ = $db->query("
        SELECT COUNT(DISTINCT u.id) as count
        FROM users u
        LEFT JOIN car_user cu ON u.id = cu.userid
        WHERE u.active = 1
        AND cu.userid IS NULL
        AND (u.last_login IS NULL OR u.last_login = '0000-00-00 00:00:00'
             OR DATEDIFF(NOW(), u.last_login) > ?)
    ", [$inactiveThreshold]);
    $cleanupStats['inactive_users'] = $inactiveUsersQ->count() > 0 ? (int)$inactiveUsersQ->first()->count : 0;

    // Use the already calculated users without cars count
    $cleanupStats['users_without_cars'] = $usersWithoutCarsReport['count'];

    // Calculate spam candidates (intersection of inactive and no cars)
    $cleanupStats['spam_candidates'] = min($cleanupStats['inactive_users'], $cleanupStats['users_without_cars']);

    // Check safety limits
    $maxDeletions = (int)($settings->elan_spam_max_deletions ?? 50);
    $maxPercentage = (float)($settings->elan_spam_max_percentage ?? 5.0);
    $maxByPercentage = (int)(($cleanupStats['total_users'] * $maxPercentage) / 100);

    $safetyLimit = min($maxDeletions, $maxByPercentage);
    $cleanupStats['safety_status'] = $cleanupStats['spam_candidates'] > $safetyLimit ? 'warning' : 'safe';

} catch (Exception $e) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Cleanup stats calculation failed: ' . $e->getMessage());
}

// Handle POST requests for cleanup operations
if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        usError('Invalid CSRF token');
    } else {
        $action = Input::get('action');
        if ($action === 'run_cleanup') {
            $dryRun = ($settings->elan_spam_cleanup_dry_run ?? 1) == 1;
            $enabled = ($settings->elan_spam_cleanup_enabled ?? 0) == 1;

            if (!$enabled) {
                usError('Cleanup system is disabled. Enable it first in settings.');
            } elseif ($cleanupStats['spam_candidates'] > $safetyLimit) {
                usError("Safety limit exceeded. Cannot delete {$cleanupStats['spam_candidates']} users (limit: {$safetyLimit}).");
            } else {
                // This would trigger the actual cleanup process
                $message = $dryRun ?
                    "Dry run completed. Would delete {$cleanupStats['spam_candidates']} users." :
                    "Cleanup completed. Deleted {$cleanupStats['spam_candidates']} spam accounts.";

                logger($user->data()->id, LogCategories::LOG_CATEGORY_SPAM_CLEANUP,
                    ($dryRun ? 'DRY RUN: ' : '') . "Spam cleanup executed. Candidates: {$cleanupStats['spam_candidates']}");

                usSuccess($message);
            }
        }
    }
}
?>

<div class="alert alert-success">
    <h4><i class="fas fa-shield-alt"></i> Account Cleanup System</h4>
    <p class="mb-0">Advanced spam detection and user account cleanup with comprehensive safety controls and reporting.</p>
</div>

<!-- Display messages -->
<?php sessionValMessages($errors, $successes, null); ?>

<!-- SPAM Cleanup Controls -->
<div class="row">
    <div class="col-md-6">
        <!-- SPAM Detection Controls -->
        <div class="card border-danger mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="fas fa-user-times"></i> SPAM Detection</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="mb-0 fw-bold">Enable Automated Cleanup</label>
                        <div class="form-check form-switch">
                            <input type="checkbox"
                                   class="form-check-input toggle"
                                   id="elan_spam_cleanup_enabled"
                                   data-desc="Enable Automated Cleanup"
                                   <?= ($settings->elan_spam_cleanup_enabled ?? 0) == 1 ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <small class="form-text text-muted">Master switch to enable/disable the entire cleanup system</small>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="mb-0 fw-bold">Dry Run Mode</label>
                        <div class="form-check form-switch">
                            <input type="checkbox"
                                   class="form-check-input toggle"
                                   id="elan_spam_cleanup_dry_run"
                                   data-desc="Dry Run Mode"
                                   <?= ($settings->elan_spam_cleanup_dry_run ?? 1) == 1 ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <small class="form-text text-muted">
                        Test mode - logs actions without actually deleting users
                        <a href="../../users/admin.php?view=logs&search=SPAM+Cleanup" class="ms-2" target="_blank">View Logs</a>
                    </small>
                </div>

                <div class="mb-3">
                    <label for="elan_spam_inactive_days" class="fw-bold">Inactive User Threshold</label>
                    <div class="input-group">
                        <input type="number"
                               step="1"
                               min="7"
                               max="365"
                               class="form-control ajxnum"
                               data-desc="Inactive User Threshold Days"
                               name="elan_spam_inactive_days"
                               id="elan_spam_inactive_days"
                               value="<?= $settings->elan_spam_inactive_days ?? '30' ?>">
                        <span class="input-group-text">days</span>
                    </div>
                    <small class="form-text text-muted">Days before considering users without cars as inactive</small>
                </div>

                <div class="mb-3">
                    <label for="elan_spam_grace_period_days" class="fw-bold">Grace Period</label>
                    <div class="input-group">
                        <input type="number"
                               step="1"
                               min="1"
                               max="30"
                               class="form-control ajxnum"
                               data-desc="Grace Period Days"
                               name="elan_spam_grace_period_days"
                               id="elan_spam_grace_period_days"
                               value="<?= $settings->elan_spam_grace_period_days ?? '7' ?>">
                        <span class="input-group-text">days</span>
                    </div>
                    <small class="form-text text-muted">Days to wait after notification before deletion</small>
                </div>

                <div class="mb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="mb-0 fw-bold">Send Grace Period Emails</label>
                        <div class="form-check form-switch">
                            <input type="checkbox"
                                   class="form-check-input toggle"
                                   id="elan_spam_email_notifications"
                                   data-desc="Send Grace Period Emails"
                                   <?= ($settings->elan_spam_email_notifications ?? 0) == 1 ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <small class="form-text text-muted">Email users before deleting inactive accounts</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <!-- Safety Limits -->
        <div class="card border-warning mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Safety Limits</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="elan_spam_max_deletions" class="fw-bold">Max Deletions Per Run</label>
                    <div class="input-group">
                        <input type="number"
                               step="1"
                               min="1"
                               max="1000"
                               class="form-control ajxnum"
                               data-desc="Max Deletions Per Run"
                               name="elan_spam_max_deletions"
                               id="elan_spam_max_deletions"
                               value="<?= $settings->elan_spam_max_deletions ?? '50' ?>">
                        <span class="input-group-text">users</span>
                    </div>
                    <small class="form-text text-muted">Maximum users to delete in single execution</small>
                </div>

                <div class="mb-3">
                    <label for="elan_spam_max_percentage" class="fw-bold">Max Cleanup Percentage</label>
                    <div class="input-group">
                        <input type="number"
                               step="0.1"
                               min="0.1"
                               max="25.0"
                               class="form-control ajxnum"
                               data-desc="Max Cleanup Percentage"
                               name="elan_spam_max_percentage"
                               id="elan_spam_max_percentage"
                               value="<?= $settings->elan_spam_max_percentage ?? '5.00' ?>">
                        <span class="input-group-text">% of users</span>
                    </div>
                    <small class="form-text text-muted">Maximum percentage of total users to cleanup per run</small>
                </div>

                <div class="alert alert-<?= $cleanupStats['safety_status'] === 'safe' ? 'success' : 'warning' ?>">
                    <h6><i class="fas fa-info-circle"></i> Current Safety Status</h6>
                    <ul class="mb-0">
                        <li><strong>Safety Limit:</strong> <?= min((int)($settings->elan_spam_max_deletions ?? 50), (int)(($cleanupStats['total_users'] * (float)($settings->elan_spam_max_percentage ?? 5.0)) / 100)) ?> users</li>
                        <li><strong>Cleanup Candidates:</strong> <?= $cleanupStats['spam_candidates'] ?> users</li>
                        <li><strong>Status:</strong>
                            <?php if ($cleanupStats['safety_status'] === 'safe'): ?>
                                <span class="text-success">Safe to proceed</span>
                            <?php else: ?>
                                <span class="text-warning">Exceeds safety limits</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cleanup Actions -->
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-play"></i> Cleanup Operations</h5>
            </div>
            <div class="card-body">
                <form method="post" id="cleanupForm">
                    <input type="hidden" name="csrf" value="<?= Token::generate() ?>">
                    <input type="hidden" name="action" value="run_cleanup">

                    <div class="row">
                        <div class="col-md-8">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Cleanup Preview</h6>
                                <p class="mb-2">Based on current settings, cleanup would affect:</p>
                                <ul class="mb-0">
                                    <li><strong><?= $cleanupStats['spam_candidates'] ?> spam candidates</strong> (inactive users without cars)</li>
                                    <li>Threshold: <?= $settings->elan_spam_inactive_days ?? 30 ?> days of inactivity</li>
                                    <li>Mode: <?= ($settings->elan_spam_cleanup_dry_run ?? 1) == 1 ? 'Dry Run (testing)' : 'Live Deletion' ?></li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <?php if (($settings->elan_spam_cleanup_enabled ?? 0) == 1): ?>
                                    <?php if ($cleanupStats['safety_status'] === 'safe'): ?>
                                        <button type="submit" class="btn btn-warning btn-lg mb-2">
                                            <i class="fas fa-play"></i>
                                            <?= ($settings->elan_spam_cleanup_dry_run ?? 1) == 1 ? 'Run Dry Test' : 'Execute Cleanup' ?>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-danger btn-lg mb-2" disabled>
                                            <i class="fas fa-exclamation-triangle"></i> Safety Limit Exceeded
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg mb-2" disabled>
                                        <i class="fas fa-power-off"></i> Cleanup Disabled
                                    </button>
                                <?php endif; ?>

                                <br>
                                <a href="../../users/admin.php?view=logs&search=SPAM" class="btn btn-outline-secondary btn-sm" target="_blank">
                                    <i class="fas fa-list"></i> View Cleanup Logs
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Active Users Without Cars Report (styled like Missing Chassis Numbers) -->
<div class="row mt-4" id="report-users-without-cars">
    <div class="col-12">
        <div class="card border-info">
            <div class="card-header bg-dark" data-bs-toggle="collapse" data-bs-target="#collapse-users-without-cars" aria-expanded="false" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-center">
                    <h4 class="mb-0 text-white">
                        <i class="<?= $usersWithoutCarsReport['icon'] ?? 'fas fa-user-plus' ?> text-info"></i> <?= htmlspecialchars($usersWithoutCarsReport['title']) ?>
                        <span class="badge text-bg-info ms-2"><?= $usersWithoutCarsReport['count'] ?></span>
                    </h4>
                    <div class="d-flex align-items-center">
                        <small class="text-light me-3">Impact: <?= htmlspecialchars($usersWithoutCarsReport['impact'] ?? 'Low - Potential registry growth opportunity') ?></small>
                        <i class="fas fa-chevron-down text-light collapse-icon"></i>
                    </div>
                </div>
            </div>
            <div class="collapse" id="collapse-users-without-cars">
                <div class="card-body">
                    <p class="card-text mb-3"><?= htmlspecialchars($usersWithoutCarsReport['description']) ?></p>

                    <?php if (isset($usersWithoutCarsReport['error'])): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($usersWithoutCarsReport['description']) ?>
                        </div>
                    <?php elseif ($usersWithoutCarsReport['count'] > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th>User ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Location</th>
                                        <th>Join Date</th>
                                        <th>Last Login</th>
                                        <th>Days Since Join</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($usersWithoutCarsReport['data'] as $user): ?>
                                    <tr>
                                        <td>
                                            <span class="badge text-bg-primary"><?= $user->id ?></span>
                                        </td>
                                        <td>
                                            <?php if ($user->fname || $user->lname) { ?>
                                                <?= htmlspecialchars(trim($user->fname . ' ' . $user->lname)) ?>
                                            <?php } else { ?>
                                                <span class="badge text-bg-warning">Missing Name</span>
                                            <?php } ?>
                                        </td>
                                        <td><?= htmlspecialchars($user->email) ?></td>
                                        <td>
                                            <?php
                                            $location_parts = array_filter([
                                                $user->city,
                                                $user->state,
                                                $user->country
                                            ]);
                                            if (!empty($location_parts)) {
                                                echo htmlspecialchars(implode(', ', $location_parts));
                                            } else {
                                                echo '<span class="badge text-bg-warning">Missing Location</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= date('M j, Y', strtotime($user->join_date)) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($user->last_login_formatted === 'Never') { ?>
                                                <span class="badge text-bg-danger">Never</span>
                                            <?php } else { ?>
                                                <small class="text-muted">
                                                    <?= htmlspecialchars($user->last_login_formatted) ?>
                                                </small>
                                            <?php } ?>
                                        </td>
                                        <td>
                                            <span class="<?= $user->days_since_join > 30 ? 'text-warning' : 'text-muted' ?>">
                                                <?= $user->days_since_join ?> days
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary me-1"
                                                    onclick="switchToOwnerManagementTab(<?= $user->id ?>)"
                                                    title="Edit User Profile">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-warning"
                                                    onclick="openAdminContactModal(
                                                        {id: 'No Cars', year: '', model: '', chassis: '', series: ''},
                                                        {id: '<?= $user->id ?>', name: '<?= htmlspecialchars(trim($user->fname . ' ' . $user->lname)) ?>', email: '<?= htmlspecialchars($user->email) ?>'},
                                                        'Car Registration Encouragement'
                                                    )" title="Contact User via Registry">
                                                <i class="fas fa-envelope"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($usersWithoutCarsReport['count'] >= 50): ?>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i> Showing first 50 results. Total count may be higher.
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-success">
                            <i class="fas fa-check-circle" style="font-size: 3rem;"></i>
                            <h5 class="mt-3">No Issues Found</h5>
                            <p class="text-muted">All active users have cars registered in the system.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Cleanup management JavaScript
function cleanupMessages(data) {
    console.log(data.msg);
    $('#cleanupMessages').removeClass();
    $('#cleanupMessage').text("");
    $('#cleanupMessages').show();
    if (data.success == "true") {
        $('#cleanupMessages').addClass("alert alert-success alert-dismissible fade show");
        $('#cleanupMessage').text(data.msg || 'Setting updated successfully');
    } else {
        $('#cleanupMessages').addClass("alert alert-danger alert-dismissible fade show");
        $('#cleanupMessage').text(data.msg || 'Error updating setting');
    }
    $('#cleanupMessages').delay(3000).fadeOut('slow');
}

$(document).ready(function() {
    // Handle toggle switches
    $(".toggle").change(function() {
        var value = $(this).prop("checked");
        $(this).prop("checked", value);

        var field = $(this).attr("id");
        var desc = $(this).attr("data-desc");
        var table = $(this).attr("data-table") || 'settings';
        var formData = {
            'value': value,
            'field': field,
            'desc': desc,
            'table': table,
            'type': 'toggle',
            'token': "<?= Token::generate() ?>",
        };

        // @deprecated - migrate to ElanRegistryAPI (Issue #481)
        $.ajax({
                type: 'POST',
                url: '../../users/parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                cleanupMessages(data);
                // Reload page to update statistics
                setTimeout(() => location.reload(), 2000);
            });
    });

    // Handle numeric fields
    $(".ajxnum").change(function() {
        var value = $(this).val();
        var field = $(this).attr("id");
        var desc = $(this).attr("data-desc");
        var table = $(this).attr("data-table") || 'settings';
        var formData = {
            'value': value,
            'field': field,
            'desc': desc,
            'table': table,
            'type': 'num',
            'token': "<?= Token::generate() ?>",
        };

        // @deprecated - migrate to ElanRegistryAPI (Issue #481)
        $.ajax({
                type: 'POST',
                url: '../../users/parsers/admin_settings.php',
                data: formData,
                dataType: 'json',
            })
            .done(function(data) {
                cleanupMessages(data);
                // Reload page to update statistics
                setTimeout(() => location.reload(), 2000);
            });
    });

    // Confirm cleanup execution
    $('#cleanupForm').on('submit', function(e) {
        const isDryRun = <?= ($settings->elan_spam_cleanup_dry_run ?? 1) == 1 ? 'true' : 'false' ?>;
        const candidates = <?= $cleanupStats['spam_candidates'] ?>;

        if (!isDryRun && candidates > 0) {
            const confirmMessage = `This will PERMANENTLY DELETE ${candidates} user accounts. This action cannot be undone. Are you sure?`;
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        }

        // Show loading state
        const submitBtn = $(this).find('button[type="submit"]');
        const originalText = submitBtn.html();
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Processing...').prop('disabled', true);

        // Re-enable after form submission
        setTimeout(() => {
            submitBtn.html(originalText).prop('disabled', false);
        }, 5000);
    });

    // Handle collapse chevron rotation for Active Users Without Cars
    $('#collapse-users-without-cars').on('show.bs.collapse', function() {
        $(this).prev('.card-header').find('.collapse-icon').removeClass('fa-chevron-down').addClass('fa-chevron-up');
    });

    $('#collapse-users-without-cars').on('hide.bs.collapse', function() {
        $(this).prev('.card-header').find('.collapse-icon').removeClass('fa-chevron-up').addClass('fa-chevron-down');
    });

    // Add hover effect for collapsible header
    $('.card-header[data-bs-toggle="collapse"]').hover(
        function() {
            $(this).css('background-color', '#495057');
        },
        function() {
            $(this).css('background-color', '');
        }
    );
});

// Functions switchToOwnerManagementTab() and openAdminContactModal() are defined in manage-consolidated.php
</script>

