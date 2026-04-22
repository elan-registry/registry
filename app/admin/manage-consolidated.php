<?php
declare(strict_types=1);

/**
 * manage-consolidated.php
 * Consolidated Management Interface
 *
 * Unified administrative interface with tabbed structure:
 *
 * TAB 1: Car/Owner Relationships - Car reassignment, deletion, and ownership transfers
 * TAB 2: Manage Cars - Individual car management and bulk operations
 * TAB 3: Manage Owners - User profile management and owner data administration
 * TAB 4: System Maintenance - Database maintenance, FIX scripts, and system utilities
 * TAB 5: Owner Cleanup - User account cleanup information and spam management overview
 * TAB 6: Settings - Complete ElanRegistry configuration (Google APIs, CDNs, media, email)
 *
 * @author Elan Registry Development Team
 * @copyright 2025
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check
if (!securePage($php_self)) {
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
    'car-mgmt' => 'Car/Owner Relationships',
    'manage-cars' => 'Manage Cars',
    'owner-mgmt' => 'Manage Owners',
    'cleanup' => 'Owner Cleanup',
    'system' => 'System Maintenance',
    'settings' => 'Settings'
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

    // Calculate quality issues separated by type using basic counts
    $carIssues = 0;
    $ownerIssues = 0;

    // Car-specific critical issues only (for tab badge)
    // Count missing chassis numbers (critical)
    $missingChassisStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE chassis IS NULL OR chassis = ''");
    $missingChassisResult = $missingChassisStmt->first();
    $carIssues += $missingChassisResult ? (int)$missingChassisResult->count : 0;

    // Count invalid model data (critical)
    $invalidModelStmt = $db->query("SELECT COUNT(*) as count FROM cars WHERE model = '||' OR model LIKE '%test%' OR model LIKE '%placeholder%'");
    $invalidModelResult = $invalidModelStmt->first();
    $carIssues += $invalidModelResult ? (int)$invalidModelResult->count : 0;

    // Count cars with multiple critical missing fields (critical)
    $multipleMissingStmt = $db->query("
        SELECT COUNT(*) as count FROM cars
        WHERE (CASE WHEN series IS NULL OR series = '' THEN 1 ELSE 0 END) +
              (CASE WHEN chassis IS NULL OR chassis = '' THEN 1 ELSE 0 END) +
              (CASE WHEN model = '||' THEN 1 ELSE 0 END) >= 2
    ");
    $multipleMissingResult = $multipleMissingStmt->first();
    $carIssues += $multipleMissingResult ? (int)$multipleMissingResult->count : 0;

    // Note: Missing series alone is informational, invalid chassis is warning - not included in critical count

    // Owner-specific issues
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
    $ownerIssues += $ownersResult ? (int)$ownersResult->count : 0;

    // Count duplicate emails
    $duplicateEmailsStmt = $db->query("
        SELECT COUNT(*) as count FROM (
            SELECT email FROM users WHERE active = 1 GROUP BY email HAVING COUNT(*) > 1
        ) as duplicates
    ");
    $duplicateEmailsResult = $duplicateEmailsStmt->first();
    $duplicateEmailCount = $duplicateEmailsResult ? (int)$duplicateEmailsResult->count : 0;
    $ownerIssues += $duplicateEmailCount;

    // Store separated counts
    $systemStatus['car_issues'] = $carIssues;
    $systemStatus['owner_issues'] = $ownerIssues;
    $systemStatus['quality_issues'] = $carIssues + $ownerIssues; // Total for backwards compatibility

} catch (PDOException $e) {
    // Fail silently for header stats - main functionality should still work
    logger($currentUserId ?? 0, LogCategories::LOG_CATEGORY_DATABASE_ERROR,
           "Database error getting system status: " . $e->getMessage());
} catch (RuntimeException $e) {
    // Handle database connection or other runtime errors
    logger($currentUserId ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR,
           "Runtime error getting system status: " . $e->getMessage());
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
                        $car = new Car((int)$car_id);
                        $targetUser = getUserWithProfile($user_id);
                        $targetName = $targetUser && $targetUser->fname && $targetUser->lname
                            ? "{$targetUser->fname} {$targetUser->lname}"
                            : "User ID $user_id";

                        $reason = "Car was reassigned to $targetName (User ID: $user_id) by admin " . $currentUserId;
                        $transferSuccess = $car->transfer((int) $user_id, $reason);

                        if ($transferSuccess) {
                            $successes[] = "Car ID $car_id successfully reassigned to $targetName";
                            logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car ID $car_id reassigned to User ID $user_id");
                        } else {
                            $errors[] = "Failed to reassign car ID $car_id";
                        }
                    } catch (Exception $e) {
                        $errors[] = 'Transfer failed. Please try again.';
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_ACTIONS, "Car reassignment failed for Car ID $car_id: " . $e->getMessage());
                    }
                    break;

                // Car merge
                case "merge":
                    // Validate input
                    $cars = Input::get('cars');
                    $reason = Input::get('reason');
                    if (!$cars || !$reason) {
                        $errors[] = 'Select 2 cars to merge and a reason';
                        break;
                    }

                    if (count($cars) <> 2) {
                        $errors[] = 'Select 2 cars to merge';
                        break;
                    }
                    if (count($reason) <> 1) {
                        $errors[] = 'Select 1 reason code';
                        break;
                    } else {
                        // Build the reason string
                        switch ($reason[0]) {
                            case "duplicate":
                                // Determine the newest car
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                } else {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                }
                                $fields['comments'] = "Car $old_car_id is a duplicate of $new_car_id.  The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "DUPLICATE";
                                break;

                            case "newownerNewToOld":
                                // Determine the newest car
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                } else {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                }
                                $fields['comments'] = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "NEWOWNER";
                                break;

                            case "newownerOldToNew":
                                if ($cars[0] > $cars[1]) {
                                    $new_car_id = $cars[1];
                                    $old_car_id = $cars[0];
                                } else {
                                    $new_car_id = $cars[0];
                                    $old_car_id = $cars[1];
                                }
                                $fields['comments'] = "Car $old_car_id was sold to a new owner and the new owner created a record for the same car as $new_car_id. The history of $old_car_id has been merged with $new_car_id and $old_car_id deleted.";
                                $fields['operation'] = "NEWOWNER";
                                break;

                            default:
                                // This should never happen
                                $fields['comments'] = "Car $old_car_id was merged with $new_car_id.  Car $old_car_id has been deleted.";
                                $fields['operation'] = "DEFAULT";
                                break;
                        }
                    }

                    // Merge the history
                    $db->query("UPDATE cars_hist SET car_id = ? WHERE car_id = ?", [$new_car_id, $old_car_id]);
                    if ($db->error()) {
                        $errors[] = $db->errorString();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, "FAILED: Merged CAR $old_car_id to CAR $new_car_id.");
                    } else {
                        // Unassign from the previous owner
                        $db->query("DELETE FROM car_user WHERE car_id = ?", [$old_car_id]);

                        // Remove old car
                        $db->query("DELETE FROM cars WHERE id = ?", [$old_car_id]);

                        // Add a record to the history with some information on the assignment
                        $fields['car_id'] = $new_car_id;
                        $fields['ctime'] = date(AppConstants::DATETIME_FORMAT); // Set date of this record
                        $fields['mtime'] = $fields['ctime'];

                        $db->insert("cars_hist", $fields);

                        $successes[] = $fields['comments'];
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_MERGE, $fields['comments']);
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
                    $fields['ctime'] = date(AppConstants::DATETIME_FORMAT);
                    $fields['mtime'] = $fields['ctime'];

                    $db->insert("cars_hist", $fields);

                    // Remove from car_user relationship table
                    $db->query("DELETE FROM car_user WHERE car_id = ?", [$car_id]);

                    // Remove the car record
                    $result = $db->query("DELETE FROM cars WHERE id = ?", [$car_id]);

                    if ($db->error()) {
                        $errors[] = "Failed to delete car: " . $db->errorString();
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, "FAILED: Delete car ID $car_id - " . $db->errorString());
                    } else {
                        $successes[] = "Car ID $car_id ({$carData->chassis}) has been permanently deleted";
                        logger($currentUserId, LogCategories::LOG_CATEGORY_CAR_DELETION, "SUCCESS: Deleted car ID $car_id ({$carData->chassis})");
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
                                <i class="fas fa-cogs"></i> Registry Management
                            </h1>
                            <p class="text-muted mb-0">Administrative tools for car registry, data quality, and system maintenance</p>
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
                                        <i class="fas fa-car"></i> Car/Owner Relationships
                                        <?php if ($systemStatus['pending_transfers'] > 0) { ?>
                                            <span class="badge badge-info badge-sm ml-1"><?= $systemStatus['pending_transfers'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Manage Cars Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'manage-cars' ? 'active' : '' ?>"
                                       href="?tab=manage-cars" role="tab">
                                        <i class="fas fa-clipboard-check"></i> Manage Cars
                                        <?php if ($systemStatus['car_issues'] > 0) { ?>
                                            <span class="badge badge-warning badge-sm ml-1"><?= $systemStatus['car_issues'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Manage Owners Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'owner-mgmt' ? 'active' : '' ?>"
                                       href="?tab=owner-mgmt" role="tab">
                                        <i class="fas fa-users"></i> Manage Owners
                                        <?php if ($systemStatus['owner_issues'] > 0) { ?>
                                            <span class="badge badge-warning badge-sm ml-1"><?= $systemStatus['owner_issues'] ?></span>
                                        <?php } ?>
                                    </a>
                                </li>

                                <!-- Owner Cleanup Tab -->
                                <li class="nav-item">
                                    <a class="nav-link <?= $activeTab === 'cleanup' ? 'active' : '' ?>"
                                       href="?tab=cleanup" role="tab">
                                        <i class="fas fa-shield-alt"></i> Owner Cleanup
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

                <div class="mt-3" id="modal-transfer-comments-section" style="display: none;">
                    <h6><i class="fas fa-comment-alt"></i> Requester's Comments</h6>
                    <div class="card border-primary">
                        <div class="card-body p-3">
                            <div id="modal-transfer-comments" class="text-dark" style="white-space: pre-wrap;"></div>
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
                    <i class="fas fa-times"></i> <span id="cancelButtonText">Cancel</span>
                </button>
                <!-- Action buttons for view mode -->
                <div id="transferViewModeButtons" style="display: none;">
                    <button type="button" class="btn btn-success" id="approveTransferFromDetailsBtn">
                        <i class="fas fa-check-circle"></i> Approve Transfer
                    </button>
                    <button type="button" class="btn btn-danger" id="denyTransferFromDetailsBtn">
                        <i class="fas fa-times-circle"></i> Deny Transfer
                    </button>
                </div>
                <!-- Confirm button for decision mode -->
                <button type="button" class="btn btn-primary" id="confirmTransferDecisionBtn">
                    <i class="fas fa-check"></i> <span id="confirmTransferDecisionText">Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Admin Contact Owner Modal -->
<div class="modal fade" id="adminContactModal" tabindex="-1" role="dialog" aria-labelledby="adminContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="adminContactModalLabel">
                    <i class="fas fa-shield-alt"></i> Administrator Contact Owner
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="adminContactForm" method="POST" action="<?= $us_url_root ?>app/admin/includes/process-admin-contact.php">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Administrator Contact:</strong>
                        This will send an email to the car owner.
                    </div>

                    <!-- Owner Information Display -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <h6 class="text-primary">Car Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactCarInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Owner Information</h6>
                            <div class="bg-light p-3 rounded">
                                <div id="contactOwnerInfo">
                                    <!-- Populated by JavaScript -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quality Issue Context -->
                    <div class="mb-3">
                        <label for="qualityIssue" class="form-label">
                            <i class="fas fa-exclamation-triangle text-warning"></i> Data Quality Issue
                        </label>
                        <select class="form-control" id="qualityIssue" name="quality_issue">
                            <option value="">Select the data quality issue (optional)</option>
                            <option value="Missing Information">Missing Critical Information</option>
                            <option value="Invalid Data">Invalid Data Entry</option>
                            <option value="Duplicate Records">Duplicate Records</option>
                            <option value="Duplicate Email Addresses">Duplicate Email Addresses</option>
                            <option value="Missing Chassis">Missing Chassis Number</option>
                            <option value="Invalid Chassis">Invalid Chassis Number</option>
                            <option value="Missing Series">Missing Series Information</option>
                            <option value="Car Registration Encouragement">Car Registration Encouragement</option>
                            <option value="Other">Other Data Quality Issue</option>
                        </select>
                    </div>

                    <!-- Message -->
                    <div class="mb-3">
                        <label for="adminMessage" class="form-label">
                            <i class="fas fa-comment text-primary"></i> Your Message to Owner
                        </label>
                        <textarea class="form-control" id="adminMessage" name="message" rows="6"
                                  placeholder="Enter your message to the car owner..." required></textarea>
                        <div class="form-text">
                            <small class="text-muted">
                                <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Be specific about what information needs to be updated and why.
                            </small>
                        </div>
                    </div>

                    <!-- Hidden fields -->
                    <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                    <input type="hidden" name="action" value="admin_contact_owner" />
                    <input type="hidden" name="car_id" id="contactCarId" value="" />
                    <input type="hidden" name="owner_id" id="contactOwnerId" value="" />
                    <input type="hidden" name="target_email" id="contactTargetEmail" value="" />
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-envelope"></i> Send Administrator Message
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>

<!-- Location Picker Styles -->
<link rel="stylesheet" href="<?=$us_url_root?>app/assets/css/location-picker.css?v=2.11.2">

<!-- Location Picker Script -->
<script src="<?=$us_url_root?>app/assets/js/location-picker.js?v=2.11.2"></script>

<!-- Include custom CSS and JavaScript -->
<link rel="stylesheet" href="assets/manage-consolidated.css">
<script>
    window.elanUrlRoot = '<?= $us_url_root ?>';
    // Make CSRF token available to ElanRegistryAPI client
    document.documentElement.setAttribute('data-csrf-token', '<?= $csrfToken ?>');
</script>
<script src="assets/manage-consolidated.js"></script>
<script src="assets/backup-operations.js"></script>