<?php
declare(strict_types=1);

/**
 * manage-consolidated.php
 * Consolidated Management Interface
 *
 * Unified administrative interface that consolidates:
 * - Car management (reassignment, deletion, transfers)
 * - Data quality dashboard and reporting
 * - Duplicate detection and resolution
 * - User and profile management
 * - System maintenance and FIX scripts
 * - Registry settings and configuration
 * - Account cleanup and spam management
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 * @issue #270 - Consolidated Management Interface
 * @issue #331 - Phase 1A: Create Unified Tabbed Interface Foundation
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Initialize database connection
$db = DB::getInstance();

// Tab routing - determine which tab to show
$validTabs = [
    'car-mgmt' => 'Car Management',
    'data-quality' => 'Data Quality',
    'duplicates' => 'Duplicate Detection',
    'user-mgmt' => 'User Management',
    'system' => 'System Maintenance',
    'settings' => 'Settings',
    'cleanup' => 'Account Cleanup'
];

$activeTab = isset($_GET['tab']) && array_key_exists($_GET['tab'], $validTabs) ? $_GET['tab'] : 'car-mgmt';
$pageTitle = 'Registry Management - ' . $validTabs[$activeTab];

// Generate CSRF token for forms
$csrfToken = Token::generate();

// Get system status for header - cached for performance
$cacheKey = 'admin_system_status';
$systemStatus = apcu_exists($cacheKey) ? apcu_fetch($cacheKey) : null;

if ($systemStatus === null) {
    $systemStatus = [
        'total_cars' => 0,
        'total_users' => 0,
        'pending_transfers' => 0,
        'quality_issues' => 0,
        'last_updated' => date('Y-m-d H:i:s')
    ];

    try {
        // Get basic counts for header display with prepared statements
        $carCountStmt = $db->query("SELECT COUNT(*) as count FROM cars");
        $carCount = $carCountStmt->first();
        $systemStatus['total_cars'] = $carCount ? (int)$carCount->count : 0;

        $userCountStmt = $db->query("SELECT COUNT(*) as count FROM users WHERE active = ?", [1]);
        $userCount = $userCountStmt->first();
        $systemStatus['total_users'] = $userCount ? (int)$userCount->count : 0;

        // Check if transfer requests table exists
        $transferCount = 0;
        $tablesStmt = $db->query("SHOW TABLES LIKE ?", ['car_transfer_requests']);
        $tables = $tablesStmt->results();
        if (!empty($tables)) {
            $transferStmt = $db->query("SELECT COUNT(*) as count FROM car_transfer_requests WHERE status = ?", ['pending']);
            $transferResult = $transferStmt->first();
            $transferCount = $transferResult ? (int)$transferResult->count : 0;
        }
        $systemStatus['pending_transfers'] = $transferCount;

        // Cache for 5 minutes to improve performance
        if (function_exists('apcu_store')) {
            apcu_store($cacheKey, $systemStatus, 300);
        }

    } catch (PDOException $e) {
        // Fail silently for header stats - main functionality should still work
        error_log("Database error getting system status: " . $e->getMessage());
    } catch (RuntimeException $e) {
        // Handle database connection or other runtime errors
        error_log("Runtime error getting system status: " . $e->getMessage());
    }
}

?>

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">

            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-sm-flex align-items-center justify-content-between">
                        <div>
                            <h1 class="h2 mb-2 text-gray-800">
                                <i class="fas fa-cogs"></i> Registry Management
                            </h1>
                            <p class="text-muted mb-0">Comprehensive administrative tools for car registry, data quality, and system maintenance</p>
                        </div>
                        <div class="text-right">
                            <div class="mb-2">
                                <span class="badge badge-success badge-lg">
                                    <i class="fas fa-check-circle"></i> System Operational
                                </span>
                            </div>
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-database"></i> <?= number_format($systemStatus['total_cars']) ?> cars &nbsp;
                                    <i class="fas fa-users"></i> <?= number_format($systemStatus['total_users']) ?> users
                                    <?php if ($systemStatus['pending_transfers'] > 0) { ?>
                                        &nbsp;<i class="fas fa-exchange-alt"></i> <?= $systemStatus['pending_transfers'] ?> pending transfers
                                    <?php } ?>
                                    <br><i class="fas fa-clock"></i> Updated: <?= date('M j, Y g:i A', strtotime($systemStatus['last_updated'])) ?>
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

                                <!-- Car Management Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'car-mgmt' ? 'active' : '' ?>"
                                       href="?tab=car-mgmt" role="tab">
                                        <i class="fas fa-car"></i> Car Management
                                        <?php if ($systemStatus['pending_transfers'] > 0) { ?>
                                            <span class="badge badge-info badge-sm ml-1"><?= $systemStatus['pending_transfers'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Data Quality Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'data-quality' ? 'active' : '' ?>"
                                       href="?tab=data-quality" role="tab">
                                        <i class="fas fa-clipboard-check"></i> Data Quality
                                        <?php if ($systemStatus['quality_issues'] > 0) { ?>
                                            <span class="badge badge-warning badge-sm ml-1"><?= $systemStatus['quality_issues'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Duplicate Detection Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'duplicates' ? 'active' : '' ?>"
                                       href="?tab=duplicates" role="tab">
                                        <i class="fas fa-search"></i> Duplicate Detection
                                    </a>
                                </li>

                                <!-- User Management Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'user-mgmt' ? 'active' : '' ?>"
                                       href="?tab=user-mgmt" role="tab">
                                        <i class="fas fa-users"></i> User Management
                                    </a>
                                </li>

                                <!-- System Maintenance Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'system' ? 'active' : '' ?>"
                                       href="?tab=system" role="tab">
                                        <i class="fas fa-tools"></i> System Maintenance
                                    </a>
                                </li>

                                <!-- Settings Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'settings' ? 'active' : '' ?>"
                                       href="?tab=settings" role="tab">
                                        <i class="fas fa-cog"></i> Settings
                                    </a>
                                </li>

                                <!-- Account Cleanup Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'cleanup' ? 'active' : '' ?>"
                                       href="?tab=cleanup" role="tab">
                                        <i class="fas fa-shield-alt"></i> Account Cleanup
                                    </a>
                                </li>

                            </ul>
                        </div>

                        <!-- Tab Content -->
                        <div class="card-body">
                            <div class="tab-content" id="managementTabContent">

                                <?php
                                // Include the appropriate tab content
                                $tabFile = 'includes/tab-' . str_replace('-', '_', $activeTab) . '.php';
                                $tabPath = __DIR__ . '/' . $tabFile;

                                if (file_exists($tabPath)) {
                                    include $tabPath;
                                } else {
                                    // Fallback placeholder content
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

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<!-- Include custom CSS and JavaScript -->
<link rel="stylesheet" href="assets/manage-consolidated.css">
<script src="assets/manage-consolidated.js"></script>