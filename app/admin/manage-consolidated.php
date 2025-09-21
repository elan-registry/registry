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

// Ensure user object is available and get current user ID
$currentUserId = null;
if (isset($user) && $user->data() && $user->data()->id) {
    $currentUserId = $user->data()->id;
} else {
    // Fallback: try to get user ID from session or other methods
    if (isset($_SESSION['user']) && isset($_SESSION['user']['id'])) {
        $currentUserId = $_SESSION['user']['id'];
    } else {
        // Last resort: set a default admin user ID
        $currentUserId = 1; // Assuming admin user ID 1 exists
    }
}

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

// Get system status for header
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

    // Calculate quality issues for data quality tab
    $qualityIssues = 0;

    // Count missing chassis numbers
    $missingChassisStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE chassis IS NULL OR chassis = ''");
    $missingChassisResult = $missingChassisStmt->first();
    $qualityIssues += $missingChassisResult ? (int)$missingChassisResult->count : 0;

    // Count invalid model data
    $invalidModelStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE model = '||' OR model LIKE '%test%' OR model LIKE '%placeholder%'");
    $invalidModelResult = $invalidModelStmt->first();
    $qualityIssues += $invalidModelResult ? (int)$invalidModelResult->count : 0;

    // Count owners missing critical information
    $ownersStmt = $db->query("
        SELECT COUNT(DISTINCT u.id) as count
        FROM users u
        JOIN car_user cu ON u.id = cu.userid
        LEFT JOIN profiles p ON u.id = p.user_id
        WHERE u.active = 1 AND (
            (u.fname IS NULL OR u.fname = '') OR
            (u.lname IS NULL OR u.lname = '') OR
            (p.city IS NULL OR p.city = '') OR
            (p.lat IS NULL OR p.lon IS NULL)
        )
    ");
    $ownersResult = $ownersStmt->first();
    $qualityIssues += $ownersResult ? (int)$ownersResult->count : 0;

    // Count duplicate emails
    $duplicateEmailsStmt = $db->query("
        SELECT COUNT(*) as count FROM (
            SELECT email FROM users WHERE active = 1 GROUP BY email HAVING COUNT(*) > 1
        ) as duplicates
    ");
    $duplicateEmailsResult = $duplicateEmailsStmt->first();
    $qualityIssues += $duplicateEmailsResult ? (int)$duplicateEmailsResult->count : 0;

    $systemStatus['quality_issues'] = $qualityIssues;

} catch (PDOException $e) {
    // Fail silently for header stats - main functionality should still work
    error_log("Database error getting system status: " . $e->getMessage());
} catch (RuntimeException $e) {
    // Handle database connection or other runtime errors
    error_log("Runtime error getting system status: " . $e->getMessage());
}

// Process form submissions for car management tab
$errors = [];
$successes = [];

if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {
        $command = Input::get('command');

        if ($command) {
            switch ($command) {
                // Car reassignment
                case "reassign":
                    $user_id = (int) Input::get('user_id');
                    $car_id  = (int) Input::get('car_id');

                    if (!$user_id || !$car_id) {
                        $errors[] = 'Please provide valid car ID and user ID';
                        break;
                    }

                    try {
                        $car = new Car($car_id);
                        $targetUser = getUserWithProfile($user_id);
                        $targetName = $targetUser && $targetUser->fname && $targetUser->lname
                            ? "{$targetUser->fname} {$targetUser->lname}"
                            : "User ID $user_id";

                        $reason = "Car was reassigned to $targetName (User ID: $user_id) by admin " . $currentUserId;
                        $transferSuccess = $car->transfer($user_id, $reason);

                        if ($transferSuccess) {
                            $successes[] = "Car ID $car_id successfully reassigned to $targetName";
                            logger($currentUserId, "ElanRegistry", "Car ID $car_id reassigned to User ID $user_id");
                        } else {
                            $errors[] = "Failed to reassign car ID $car_id";
                        }
                    } catch (Exception $e) {
                        $errors[] = "Transfer failed: " . $e->getMessage();
                        logger($currentUserId, "ElanRegistry", "Car reassignment failed for Car ID $car_id: " . $e->getMessage());
                    }
                    break;

                // Car deletion
                case "delete":
                    $car_id = (int) Input::get('car_id');
                    $confirmation = Input::get('confirmation');

                    if (!$car_id) {
                        $errors[] = 'Please provide a valid car ID';
                        break;
                    }

                    if ($confirmation !== 'DELETE') {
                        $errors[] = 'Please type DELETE in the confirmation field to proceed';
                        break;
                    }

                    // Get car details before deletion for logging
                    $carQ = $db->query("SELECT * FROM cars WHERE id = ?", [$car_id]);
                    if ($carQ->count() === 0) {
                        $errors[] = "Car ID $car_id not found";
                        break;
                    }

                    $carData = $carQ->first();

                    // Add deletion record to history before removing the car
                    $fields = [];
                    $fields['car_id'] = $car_id;
                    $fields['comments'] = "Car ID $car_id ({$carData->chassis}) permanently deleted by admin " . $currentUserId . ". Reason: Administrative deletion via consolidated management.";
                    $fields['operation'] = "DELETE";
                    $fields['ctime'] = date('Y-m-d G:i:s');
                    $fields['mtime'] = $fields['ctime'];

                    $db->insert("cars_hist", $fields);

                    // Remove from car_user relationship table
                    $db->query("DELETE FROM car_user WHERE car_id = ?", [$car_id]);

                    // Remove the car record
                    $result = $db->query("DELETE FROM cars WHERE id = ?", [$car_id]);

                    if ($db->error()) {
                        $errors[] = "Failed to delete car: " . $db->errorString();
                        logger($currentUserId, "ElanRegistry", "FAILED: Delete car ID $car_id - " . $db->errorString());
                    } else {
                        $successes[] = "Car ID $car_id ({$carData->chassis}) has been permanently deleted";
                        logger($currentUserId, "ElanRegistry", "SUCCESS: Deleted car ID $car_id ({$carData->chassis})");
                    }
                    break;

                // Get car details for confirmation (AJAX endpoint)
                case "getCarDetails":
                    if (ob_get_level()) {
                        ob_clean();
                    }
                    header('Content-Type: application/json');

                    $car_id = (int) Input::get('car_id');

                    if (!$car_id) {
                        echo json_encode(['success' => false, 'error' => 'Invalid car ID']);
                        exit;
                    }

                    try {
                        $carQ = $db->query("SELECT * FROM cars WHERE id = ?", [$car_id]);
                        if ($carQ->count() === 0) {
                            echo json_encode(['success' => false, 'error' => 'Car not found']);
                            exit;
                        }

                        $car = $carQ->first();
                        echo json_encode([
                            'success' => true,
                            'car' => [
                                'id' => $car->id,
                                'year' => $car->year,
                                'type' => $car->type,
                                'chassis' => $car->chassis,
                                'color' => $car->color,
                                'series' => $car->series,
                                'fname' => $car->fname,
                                'lname' => $car->lname,
                                'email' => $car->email,
                                'city' => $car->city,
                                'state' => $car->state,
                                'country' => $car->country,
                                'ctime' => $car->ctime,
                                'mtime' => $car->mtime
                            ]
                        ]);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                    }
                    exit;

                // Get user details for reassignment (AJAX endpoint)
                case "getUserDetails":
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/json');

                    $user_id = (int) Input::get('user_id');

                    if (!$user_id) {
                        echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
                        exit;
                    }

                    try {
                        $user = getUserWithProfile($user_id);

                        if (!$user) {
                            echo json_encode(['success' => false, 'error' => 'User not found']);
                            exit;
                        }
                        echo json_encode([
                            'success' => true,
                            'user' => [
                                'id' => $user->id,
                                'fname' => $user->fname,
                                'lname' => $user->lname,
                                'email' => $user->email,
                                'city' => $user->city,
                                'state' => $user->state,
                                'country' => $user->country,
                                'join_date' => $user->join_date
                            ]
                        ]);
                    } catch (Exception $e) {
                        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
                    }
                    exit;

                // Approve transfer request
                case "approve_transfer":
                    $transfer_id = (int) Input::get('transfer_id');

                    if (!$transfer_id) {
                        $errors[] = 'Invalid transfer request ID';
                        break;
                    }

                    $transferQuery = $db->query(
                        'SELECT ctr.*, c.id as car_id, c.user_id as current_owner_id
                         FROM car_transfer_requests ctr
                         JOIN cars c ON ctr.existing_car_id = c.id
                         WHERE ctr.id = ? AND ctr.status = "pending"',
                        [$transfer_id]
                    );

                    if ($transferQuery->count() === 0) {
                        $errors[] = 'Transfer request not found or already processed';
                        break;
                    }

                    $transfer = $transferQuery->first();

                    try {
                        $car = new Car($transfer->car_id);
                        $targetUser = getUserWithProfile($transfer->requested_by_user_id);
                        $targetName = $targetUser && $targetUser->fname && $targetUser->lname
                            ? "{$targetUser->fname} {$targetUser->lname}"
                            : "User ID {$transfer->requested_by_user_id}";

                        $reason = "Car was reassigned to $targetName (User ID: {$transfer->requested_by_user_id}) by admin " . $currentUserId;
                        $transferSuccess = $car->transfer($transfer->requested_by_user_id, $reason);

                        if ($transferSuccess) {
                            $db->query(
                                'UPDATE car_transfer_requests SET status = "completed", completed_date = NOW(), admin_notes = ? WHERE id = ?',
                                ["Approved by admin user {$currentUserId}", $transfer_id]
                            );

                            $successes[] = "Transfer request approved successfully. Car ownership has been transferred to $targetName.";
                            logger($currentUserId, 'CarTransfer', "Transfer request #{$transfer_id} approved - Car {$transfer->car_id} transferred to user {$transfer->requested_by_user_id}");

                            // Send approval notification
                            require_once '../../usersc/includes/transfer_email_notifications.php';
                            $notificationSent = sendTransferResponseNotification($transfer_id, true, "Approved by admin user {$currentUserId}", $transfer->current_owner_id);

                            if ($notificationSent) {
                                logger($currentUserId, 'EmailSuccess', "Transfer approval notification sent for request #$transfer_id");
                            } else {
                                logger($currentUserId, 'EmailError', "Failed to send transfer approval notification for request #$transfer_id");
                            }
                        } else {
                            $errors[] = "Failed to process transfer for Car ID {$transfer->car_id}";
                            logger($currentUserId, 'CarTransferError', "Transfer approval failed for request #{$transfer_id}");
                        }
                    } catch (Exception $e) {
                        $errors[] = "Transfer failed: " . $e->getMessage();
                        logger($currentUserId, 'CarTransferError', "Transfer approval failed for request #{$transfer_id}: " . $e->getMessage());
                    }
                    break;

                // Deny transfer request
                case "deny_transfer":
                    $transfer_id = (int) Input::get('transfer_id');

                    if (!$transfer_id) {
                        $errors[] = 'Invalid transfer request ID';
                        break;
                    }

                    $updateResult = $db->query(
                        'UPDATE car_transfer_requests SET status = "denied", admin_notes = ?, completed_date = NOW() WHERE id = ? AND status = "pending"',
                        ["Denied by admin user {$currentUserId}", $transfer_id]
                    );

                    if ($db->error()) {
                        $errors[] = "Failed to deny transfer request: " . $db->errorString();
                    } else {
                        $successes[] = "Transfer request denied.";
                        logger($currentUserId, 'CarTransfer', "Transfer request #{$transfer_id} denied by admin");

                        // Send denial notification
                        require_once '../../usersc/includes/transfer_email_notifications.php';
                        $notificationSent = sendTransferResponseNotification($transfer_id, false, "Denied by admin user {$currentUserId}");

                        if ($notificationSent) {
                            logger($currentUserId, 'EmailSuccess', "Transfer denial notification sent for request #$transfer_id");
                        } else {
                            logger($currentUserId, 'EmailError', "Failed to send transfer denial notification for request #$transfer_id");
                        }
                    }
                    break;
            }
        }
    }

    // Convert error/success arrays to UserSpice session messages
    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
        }
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

<!-- Bootstrap Modals for Confirmations -->

<!-- Car Reassignment Confirmation Modal -->
<div class="modal fade" id="reassignConfirmModal" tabindex="-1" role="dialog" aria-labelledby="reassignConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="reassignConfirmModalLabel">
                    <i class="fas fa-user-friends"></i> Confirm Car Reassignment
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle"></i> <strong>Administrative Transfer:</strong> This will immediately transfer car ownership and log the change in the car's history.
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-car"></i> Car Details</h6>
                        <div id="modal-car-info" class="card border-primary">
                            <div class="card-body p-3">
                                <div id="modal-car-details"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-success"><i class="fas fa-user"></i> New Owner</h6>
                        <div id="modal-user-info" class="card border-success">
                            <div class="card-body p-3">
                                <div id="modal-user-details"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <p class="mb-2"><strong>This action will:</strong></p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success"></i> Transfer ownership immediately</li>
                        <li><i class="fas fa-check text-success"></i> Log the change in car history</li>
                        <li><i class="fas fa-check text-success"></i> Update all registry records</li>
                        <li><i class="fas fa-exclamation-triangle text-warning"></i> Cannot be undone easily</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-warning" id="confirmReassignBtn">
                    <i class="fas fa-user-friends"></i> Confirm Reassignment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Car Deletion Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteConfirmModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Permanent Car Deletion Warning
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger mb-3">
                    <h6 class="alert-heading"><i class="fas fa-skull-crossbones"></i> EXTREME CAUTION REQUIRED</h6>
                    <p class="mb-0">This action will <strong>permanently delete</strong> the car record and cannot be undone. Use only for spam or test data.</p>
                </div>

                <div class="card border-danger mb-3">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0"><i class="fas fa-car"></i> Car to be Deleted</h6>
                    </div>
                    <div class="card-body">
                        <div id="modal-delete-car-details"></div>
                    </div>
                </div>

                <div class="mb-3">
                    <p class="mb-2"><strong class="text-danger">This action will permanently:</strong></p>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-times text-danger"></i> Delete the car record</li>
                        <li><i class="fas fa-times text-danger"></i> Remove all user-car relationships</li>
                        <li><i class="fas fa-times text-danger"></i> Delete all uploaded images</li>
                        <li><i class="fas fa-times text-danger"></i> Remove from all statistics</li>
                        <li><i class="fas fa-exclamation-triangle text-warning"></i> <strong>Cannot be recovered</strong></li>
                    </ul>
                </div>

                <div class="form-group">
                    <label for="modal-delete-confirmation" class="font-weight-bold">
                        Type <code>DELETE PERMANENTLY</code> to confirm:
                    </label>
                    <input type="text" class="form-control" id="modal-delete-confirmation"
                           placeholder="Type: DELETE PERMANENTLY" autocomplete="off">
                    <small class="form-text text-muted">This confirmation is required to prevent accidental deletions.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-shield-alt"></i> Cancel (Safe)
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                    <i class="fas fa-skull-crossbones"></i> Permanently Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Decision Confirmation Modal -->
<div class="modal fade" id="transferDecisionModal" tabindex="-1" role="dialog" aria-labelledby="transferDecisionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header" id="transferDecisionModalHeader">
                <h5 class="modal-title" id="transferDecisionModalLabel">
                    <i class="fas fa-exchange-alt"></i> <span id="transferDecisionTitle">Confirm Transfer Decision</span>
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="transferDecisionMessage" class="alert mb-3">
                    <i class="fas fa-info-circle"></i> <span id="transferDecisionMessageText">Please confirm your decision on this transfer request.</span>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-primary"><i class="fas fa-car"></i> Car Details</h6>
                        <div id="modal-transfer-car-info" class="card border-primary">
                            <div class="card-body p-3">
                                <div id="modal-transfer-car-details"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-info"><i class="fas fa-users"></i> Transfer Parties</h6>
                        <div class="mb-3">
                            <strong class="text-muted">Current Owner:</strong>
                            <div id="modal-current-owner-info" class="card border-secondary mt-1">
                                <div class="card-body p-2">
                                    <div id="modal-current-owner-details"></div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <strong class="text-success">Requesting User:</strong>
                            <div id="modal-requester-info" class="card border-success mt-1">
                                <div class="card-body p-2">
                                    <div id="modal-requester-details"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <h6><i class="fas fa-calendar-alt"></i> Request Information</h6>
                    <div id="modal-transfer-request-info" class="card border-info">
                        <div class="card-body p-3">
                            <div id="modal-transfer-request-details"></div>
                        </div>
                    </div>
                </div>

                <div class="mt-3" id="transferDecisionConsequences">
                    <p class="mb-2"><strong>This action will:</strong></p>
                    <ul class="list-unstyled" id="transferDecisionEffects">
                        <!-- Dynamic content based on approve/deny -->
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" id="confirmTransferDecisionBtn">
                    <i class="fas fa-check"></i> <span id="confirmTransferDecisionText">Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<!-- Include custom CSS and JavaScript -->
<link rel="stylesheet" href="assets/manage-consolidated.css">
<script src="assets/manage-consolidated.js"></script>