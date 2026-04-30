<?php
declare(strict_types=1);

/**
 * manage-maintenance.php
 * Registry Maintenance Interface
 *
 * Admin-only management interface focused on system maintenance and
 * registry-wide configuration. Split out from manage-consolidated.php to
 * separate operational maintenance from day-to-day car/owner administration.
 *
 * TAB 1: Health - Read-only system health monitoring
 * TAB 2: Maintenance - Backups, one-time migrations, recurring maintenance tasks
 * TAB 3: Configuration - ElanRegistry settings (Google APIs, CDNs, media, email)
 *
 * Access control is enforced by PageManager (admin-only). All state-changing
 * operations on this page are performed via AJAX endpoints
 * (backup-operations.php, schema-operations.php), so this page does not
 * process any direct form submissions.
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

// securePage() enforces access via the UserSpice permission_page_matches table.
// This page must be registered with admin-only (permission 2) in the UserSpice
// pages table — the restriction is a DB configuration, not a code-level hard gate.
if (!securePage($php_self)) {
    die();
}

$db = DB::getInstance();

// securePage() handles auth/permission. This guard covers the narrow edge case
// where the session passes securePage() but is corrupt — ensuring the audit trail
// always records a real user ID.
try {
    $currentUserId = currentUserId();
} catch (RuntimeException $e) {
    logger(0, LogCategories::LOG_CATEGORY_SECURITY,
        "Admin page accessed with invalid user session on $php_self");
    Redirect::to($us_url_root . 'users/login.php');
    die();
}

$validTabs = [
    'health'      => 'Health',
    'maintenance' => 'Maintenance',
    'settings'    => 'Configuration',
];

$activeTab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $validTabs) ? $_GET['tab'] : 'health';
$pageTitle = 'Registry Maintenance - ' . $validTabs[$activeTab];

// Generate CSRF token for AJAX requests
$csrfToken = Token::generate();

// Get system status for header (cars + active users only — other counts not
// relevant to maintenance/settings view)
$systemStatus = [
    'total_cars' => 0,
    'total_users' => 0,
    'last_updated' => date('Y-m-d H:i:s')
];

try {
    $carCountStmt = $db->query("SELECT COUNT(*) as count FROM cars");
    $carCount = $carCountStmt->first();
    $systemStatus['total_cars'] = $carCount ? (int)$carCount->count : 0;

    $userCountStmt = $db->query("SELECT COUNT(*) as count FROM users WHERE active = ?", [1]);
    $userCount = $userCountStmt->first();
    $systemStatus['total_users'] = $userCount ? (int)$userCount->count : 0;
} catch (PDOException $e) {
    // Header stats are cosmetic — a PDOException here may indicate broader DB issues
    // affecting maintenance operations on this page.
    logger($currentUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
           "Database error getting system status: " . $e->getMessage());
} catch (RuntimeException $e) {
    logger($currentUserId ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
           "Runtime error getting system status: " . $e->getMessage());
}

?>

<div class="page-wrapper">
    <!-- Hidden CSRF token for AJAX requests -->
    <input type="hidden" name="csrf" value="<?= $csrfToken ?>" />

    <div class="container-fluid">
        <div class="page-container">

            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="h2 mb-2 text-gray-800">
                                <i class="fas fa-tools"></i> Registry Maintenance
                            </h1>
                            <p class="text-muted mb-0">System maintenance, database operations, and registry settings</p>
                        </div>
                        <div class="text-end">
                            <div class="mb-2">
                                <span class="badge text-bg-success badge-lg">
                                    <i class="fas fa-check-circle"></i> System Operational
                                </span>
                            </div>
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-database"></i> <?= number_format($systemStatus['total_cars']) ?> cars &nbsp;
                                    <i class="fas fa-users"></i> <?= number_format($systemStatus['total_users']) ?> users
                                    <br><i class="fas fa-clock"></i> Updated: <?= date('M j, Y g:i A', strtotime($systemStatus['last_updated'])) ?>
                                    <br><i class="fas fa-code-branch"></i> <?= htmlspecialchars(ApplicationVersion::get()) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Interface Card -->
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">

                        <!-- Navigation Tabs -->
                        <div class="card-header p-0">
                            <ul class="nav nav-tabs card-header-tabs" id="managementTabs" role="tablist">

                                <!-- Health Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'health' ? 'active' : '' ?>"
                                       href="?tab=health" role="tab">
                                        <i class="fas fa-heartbeat"></i> Health
                                    </a>
                                </li>

                                <!-- Maintenance Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'maintenance' ? 'active' : '' ?>"
                                       href="?tab=maintenance" role="tab">
                                        <i class="fas fa-tools"></i> Maintenance
                                    </a>
                                </li>

                                <!-- Configuration Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>"
                                       href="?tab=settings" role="tab">
                                        <i class="fas fa-cog"></i> Configuration
                                    </a>
                                </li>

                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="card-body">
                            <div class="tab-content" id="managementTabContent">

                                <?php
                                $tabFile = 'includes/tab-' . str_replace('-', '_', $activeTab) . '.php';
                                $tabPath = __DIR__ . '/' . $tabFile;

                                if (file_exists($tabPath)) {
                                    include $tabPath;
                                } else {
                                    include 'includes/tab-placeholder.php';
                                }
                                ?>

                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include 'includes/confirmation-modal.php'; ?>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<link rel="stylesheet" href="assets/manage-consolidated.min.css">
<script>
    window.elanUrlRoot = '<?= $us_url_root ?>';
    // Make CSRF token available to ElanRegistryAPI client
    document.documentElement.setAttribute('data-csrf-token', '<?= $csrfToken ?>');
</script>
<script src="assets/manage-consolidated.min.js"></script>
<script src="assets/backup-operations.min.js"></script>

