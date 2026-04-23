<?php
declare(strict_types=1);
/**
 * tab-car_mgmt.php
 * Car Management Tab Content
 *
 * Phase 1B: Enhanced car management functionality with transfer request emphasis
 * Migrated from existing manage.php with improved UX and AJAX features
 */

// Check for car_id parameter from data quality integration
$preloadCarId = isset($_GET['car_id']) ? (int)$_GET['car_id'] : null;
$preloadCarData = null;

if ($preloadCarId) {
    // Pre-load car data for integration from data quality tab using Car class
    try {
        $preloadCarData = new Car($preloadCarId);
        if (!$preloadCarData->data()) {
            $preloadCarData = null; // Car not found
        }
    } catch (Exception $e) {
        // Fail silently if car not found
        $preloadCarData = null;
        logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_CAR_ERRORS,
               "Car preload error: " . $e->getMessage());
    }
}

// Get pending transfer requests with enhanced details
$transferQuery = $db->query(
    'SELECT ctr.*,
            c.chassis, c.year, c.type, c.color, c.series,
            current_owner.fname as current_fname, current_owner.lname as current_lname, current_owner.email as current_email,
            requester.fname as requester_fname, requester.lname as requester_lname, requester.email as requester_email
     FROM car_transfer_requests ctr
     JOIN cars c ON ctr.existing_car_id = c.id
     JOIN users current_owner ON c.user_id = current_owner.id
     JOIN users requester ON ctr.requested_by_user_id = requester.id
     WHERE ctr.status = "pending" AND ctr.expires_at > NOW()
     ORDER BY ctr.request_date DESC'
);
$transfers = $transferQuery->results();

// Get transfer statistics
$transferStats = [
    'pending' => count($transfers),
    'completed_today' => 0,
    'denied_today' => 0
];

// Get today's completed/denied transfers
try {
    $todayStatsQuery = $db->query(
        'SELECT status, COUNT(*) as count
         FROM car_transfer_requests
         WHERE DATE(completed_date) = CURDATE() AND status IN ("completed", "denied")
         GROUP BY status'
    );
    foreach ($todayStatsQuery->results() as $stat) {
        if ($stat->status === 'completed') {
            $transferStats['completed_today'] = (int)$stat->count;
        } elseif ($stat->status === 'denied') {
            $transferStats['denied_today'] = (int)$stat->count;
        }
    }
} catch (Exception $e) {
    // Fail silently for stats
}
?>

<!-- Messages and Alerts Section -->
<div class="row mb-4">
    <div class="col-12">
        <div id="messageContainer">
            <!-- UserSpice session messages will appear here automatically -->
            <!-- Custom AJAX messages will be added here dynamically -->
        </div>
    </div>
</div>

<!-- Transfer Request Management Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> Pending Transfer Requests</h5>
            </div>
            <div class="card-body">
                <?php if (empty($transfers)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> No pending transfer requests at this time.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Request Date</th>
                                    <th>Car Details</th>
                                    <th>Current Owner</th>
                                    <th>Requesting User</th>
                                    <th>Expires</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transfers as $transfer): ?>
                                    <tr>
                                        <td><?= date('M j, Y', strtotime($transfer->request_date)) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($transfer->year) ?> <?= htmlspecialchars($transfer->type) ?></strong><br>
                                            <small>Chassis: <?= htmlspecialchars($transfer->chassis) ?></small>
                                            <?php if ($transfer->color): ?>
                                                <br><small>Color: <?= htmlspecialchars($transfer->color) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($transfer->current_fname . ' ' . $transfer->current_lname) ?><br>
                                            <small><?= htmlspecialchars($transfer->current_email) ?></small>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($transfer->requester_fname . ' ' . $transfer->requester_lname) ?><br>
                                            <small><?= htmlspecialchars($transfer->requester_email) ?></small>
                                        </td>
                                        <td>
                                            <?= date('M j, Y', strtotime($transfer->expires_at)) ?>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-info btn-sm mb-1 view-transfer-details"
                                                    data-transfer-id="<?= $transfer->id ?>"
                                                    data-car-id="<?= $transfer->existing_car_id ?>"
                                                    data-chassis="<?= htmlspecialchars($transfer->chassis) ?>"
                                                    data-year="<?= htmlspecialchars($transfer->year) ?>"
                                                    data-type="<?= htmlspecialchars($transfer->type) ?>"
                                                    data-color="<?= htmlspecialchars($transfer->color ?? '') ?>"
                                                    data-series="<?= htmlspecialchars($transfer->series ?? '') ?>"
                                                    data-current-owner="<?= htmlspecialchars($transfer->current_fname . ' ' . $transfer->current_lname) ?>"
                                                    data-current-email="<?= htmlspecialchars($transfer->current_email) ?>"
                                                    data-requester="<?= htmlspecialchars($transfer->requester_fname . ' ' . $transfer->requester_lname) ?>"
                                                    data-requester-email="<?= htmlspecialchars($transfer->requester_email) ?>"
                                                    data-request-date="<?= htmlspecialchars($transfer->request_date) ?>"
                                                    data-expires-at="<?= htmlspecialchars($transfer->expires_at) ?>"
                                                    data-comments="<?= htmlspecialchars($transfer->submitted_comments ?? '') ?>"
                                                    data-submitted-chassis="<?= htmlspecialchars($transfer->submitted_chassis ?? '') ?>"
                                                    data-submitted-year="<?= htmlspecialchars($transfer->submitted_year ?? '') ?>"
                                                    data-submitted-model="<?= htmlspecialchars($transfer->submitted_model ?? '') ?>"
                                                    data-submitted-color="<?= htmlspecialchars($transfer->submitted_color ?? '') ?>"
                                                    data-submitted-engine="<?= htmlspecialchars($transfer->submitted_engine ?? '') ?>">
                                                <i class="fas fa-info-circle"></i> View Details
                                            </button>
                                            <br>
                                            <button type="button" class="btn btn-success btn-sm transfer-approve-btn"
                                                    data-transfer-id="<?= $transfer->id ?>"
                                                    data-car-year="<?= htmlspecialchars($transfer->year) ?>"
                                                    data-car-type="<?= htmlspecialchars($transfer->type) ?>"
                                                    data-car-series="<?= htmlspecialchars($transfer->series ?? '') ?>"
                                                    data-car-chassis="<?= htmlspecialchars($transfer->chassis) ?>"
                                                    data-car-color="<?= htmlspecialchars($transfer->color ?? '') ?>"
                                                    data-current-owner="<?= htmlspecialchars($transfer->current_fname . ' ' . $transfer->current_lname) ?>"
                                                    data-current-email="<?= htmlspecialchars($transfer->current_email) ?>"
                                                    data-requester-name="<?= htmlspecialchars($transfer->requester_fname . ' ' . $transfer->requester_lname) ?>"
                                                    data-requester-email="<?= htmlspecialchars($transfer->requester_email) ?>"
                                                    data-request-date="<?= htmlspecialchars($transfer->request_date) ?>"
                                                    data-expires-date="<?= htmlspecialchars($transfer->expires_at) ?>"
                                                    data-comments="<?= htmlspecialchars($transfer->submitted_comments ?? '') ?>">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm transfer-deny-btn"
                                                    data-transfer-id="<?= $transfer->id ?>"
                                                    data-car-year="<?= htmlspecialchars($transfer->year) ?>"
                                                    data-car-type="<?= htmlspecialchars($transfer->type) ?>"
                                                    data-car-series="<?= htmlspecialchars($transfer->series ?? '') ?>"
                                                    data-car-chassis="<?= htmlspecialchars($transfer->chassis) ?>"
                                                    data-car-color="<?= htmlspecialchars($transfer->color ?? '') ?>"
                                                    data-current-owner="<?= htmlspecialchars($transfer->current_fname . ' ' . $transfer->current_lname) ?>"
                                                    data-current-email="<?= htmlspecialchars($transfer->current_email) ?>"
                                                    data-requester-name="<?= htmlspecialchars($transfer->requester_fname . ' ' . $transfer->requester_lname) ?>"
                                                    data-requester-email="<?= htmlspecialchars($transfer->requester_email) ?>"
                                                    data-request-date="<?= htmlspecialchars($transfer->request_date) ?>"
                                                    data-expires-date="<?= htmlspecialchars($transfer->expires_at) ?>"
                                                    data-comments="<?= htmlspecialchars($transfer->submitted_comments ?? '') ?>">
                                                <i class="fas fa-times"></i> Deny
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Administrative Tools - Secondary Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-shield-alt"></i> Administrative Tools
                    <small class="ml-2 text-muted">For exceptional cases only</small>
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Note:</strong> These tools are for rare administrative cases.
                    Most ownership changes should go through the transfer request system above.
                </div>

                <div class="row">
                    <!-- Car Reassignment - Administrative Exception -->
                    <div class="col-lg-8 col-md-12 mb-4">
                        <div class="card border-warning">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0">
                                    <i class="fas fa-user-friends"></i> Manual Car Reassignment
                                    <span class="badge badge-warning badge-sm ml-2">Administrative Use Only</span>
                                </h6>
                            </div>
                            <div class="card-body">
                                <form name="assignCar" action="" method="POST" class="reassign-form needs-validation" novalidate>
                                    <div class="row">
                                        <!-- Car Selection -->
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="reassign_car_id" class="form-label">Car ID</label>
                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="reassign_car_id" name="car_id"
                                                           placeholder="Enter Car ID" required>
                                                    <div class="input-group-append">
                                                        <button class="btn btn-outline-info" type="button" id="lookupCarBtn">
                                                            <i class="fas fa-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="invalid-feedback">Please provide a valid car ID.</div>

                                                <!-- Car Details Display -->
                                                <div id="carDetails" class="mt-2" style="display: none;">
                                                    <div class="alert alert-info alert-sm">
                                                        <h6 class="alert-heading mb-1"><i class="fas fa-car"></i> Car Details</h6>
                                                        <div id="carInfo"></div>
                                                        <div class="mt-2">
                                                            <strong>Current Owner:</strong> <span id="currentOwner"></span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- User Selection -->
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label for="reassign_user_id" class="form-label">New Owner ID</label>

                                                <div class="input-group">
                                                    <input type="number" class="form-control" id="reassign_user_id" name="user_id"
                                                           placeholder="Enter User ID" required>
                                                    <div class="input-group-append">
                                                        <button class="btn btn-outline-info" type="button" id="lookupUserBtn">
                                                            <i class="fas fa-search"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="invalid-feedback">Please provide a valid user ID.</div>

                                                <!-- No Owner Checkbox -->
                                                <div class="form-check mt-2">
                                                    <input class="form-check-input" type="checkbox" id="noOwnerCheckbox" value="83">
                                                    <label class="form-check-label" for="noOwnerCheckbox">
                                                        <i class="fas fa-user-slash text-muted"></i> Assign to "No Owner" (ID: 83)
                                                    </label>
                                                </div>

                                                <!-- User Details Display -->
                                                <div id="userDetails" class="mt-2" style="display: none;">
                                                    <div class="alert alert-success alert-sm">
                                                        <h6 class="alert-heading mb-1"><i class="fas fa-user"></i> New Owner</h6>
                                                        <div id="userInfo"></div>
                                                    </div>
                                                </div>

                                                <!-- No Owner Display -->
                                                <div id="noOwnerDetails" class="mt-2" style="display: none;">
                                                    <div class="alert alert-warning alert-sm">
                                                        <h6 class="alert-heading mb-1"><i class="fas fa-user-slash"></i> No Owner</h6>
                                                        <div>Car will be assigned to <strong>"No Owner"</strong> registry account.<br>
                                                        <small class="text-muted">Used for cars without current owner information.</small></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                    <input type="hidden" name="command" value="reassign" />
                                    <button type="submit" class="btn btn-warning btn-sm" id="reassignBtn" disabled>
                                        <i class="fas fa-user-friends"></i> Administrative Reassignment
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Car Deletion - Rare Administrative Action -->
                    <div class="col-lg-4 col-md-12 mb-4">
                        <div class="card border-danger">
                            <div class="card-header bg-danger text-white">
                                <h6 class="mb-0">
                                    <i class="fas fa-trash-alt"></i> Permanent Car Deletion
                                    <span class="badge badge-light badge-sm ml-2">Extremely Rare</span>
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-danger alert-sm mb-3">
                                    <p class="mb-1"><strong><i class="fas fa-exclamation-triangle"></i> Extreme Caution</strong></p>
                                    <p class="mb-0 small">Permanently deletes car record. Only use for spam or test data.</p>
                                </div>

                                <form name="deleteCar" action="" method="POST" class="delete-form needs-validation" novalidate>
                                    <div class="form-group">
                                        <label for="delete_car_id" class="form-label">Car ID to Delete</label>
                                        <div class="input-group">
                                            <input type="number" class="form-control" id="delete_car_id" name="car_id"
                                                   placeholder="Car ID" required>
                                            <div class="input-group-append">
                                                <button class="btn btn-outline-info" type="button" id="lookupDeleteCarBtn">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="invalid-feedback">Please provide a valid car ID.</div>

                                        <!-- Car Details Display -->
                                        <div id="deleteCarDetails" class="mt-2" style="display: none;">
                                            <div class="alert alert-warning alert-sm">
                                                <h6 class="alert-heading mb-1"><i class="fas fa-car"></i> Car to Delete</h6>
                                                <div id="deleteCarInfo"></div>
                                                <div class="mt-2">
                                                    <strong>Current Owner:</strong> <span id="deleteCurrentOwner"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="delete_confirmation" class="form-label">Type "DELETE" to confirm</label>
                                        <input type="text" class="form-control" id="delete_confirmation" name="confirmation"
                                               placeholder="Type DELETE" required>
                                        <div class="invalid-feedback">You must type DELETE to confirm deletion.</div>
                                    </div>
                                    <button type="submit" class="btn btn-danger btn-sm w-100" id="deleteBtn" disabled>
                                        <i class="fas fa-trash-alt"></i> Permanent Delete
                                    </button>
                                    <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                    <input type="hidden" name="command" value="delete" />
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Statistics -->
<div class="row">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <h5><i class="fas fa-hourglass-half"></i> Pending</h5>
                <h2><?= $transferStats['pending'] ?></h2>
                <small>Transfer Requests</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <h5><i class="fas fa-check-circle"></i> Approved</h5>
                <h2><?= $transferStats['completed_today'] ?></h2>
                <small>Today</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body text-center">
                <h5><i class="fas fa-times-circle"></i> Denied</h5>
                <h2><?= $transferStats['denied_today'] ?></h2>
                <small>Today</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <h5><i class="fas fa-car"></i> Total Cars</h5>
                <h2><?= number_format($systemStatus['total_cars']) ?></h2>
                <small>In Registry</small>
            </div>
        </div>
    </div>
</div>

<?php if ($preloadCarId && $preloadCarData): ?>
<script>
// Pre-populate car ID from data quality integration
document.addEventListener("DOMContentLoaded", function() {
    const carIdField = document.getElementById("reassign_car_id");
    if (carIdField) {
        carIdField.value = "<?= $preloadCarId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?>";

        // Trigger lookup to display car details
        const lookupBtn = document.getElementById("lookupCarBtn");
        if (lookupBtn) {
            lookupBtn.click();
        }

        // Scroll to the car management section
        const reassignSection = document.querySelector(".reassign-form");
        if (reassignSection) {
            reassignSection.scrollIntoView({ behavior: "smooth", block: "center" });
        }

        // Show alert about data quality integration
        const messageContainer = document.getElementById("messageContainer");
        if (messageContainer) {
            const alertDiv = document.createElement("div");
            alertDiv.className = "alert alert-info alert-dismissible fade show";
            alertDiv.innerHTML = `
                <i class="fas fa-info-circle"></i> <strong>Data Quality Integration:</strong>
                Car ID <?= $preloadCarId // nosemgrep: php.lang.security.taint-unsafe-echo-tag.taint-unsafe-echo-tag ?> has been pre-loaded from the Data Quality dashboard for editing.
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            `;
            messageContainer.appendChild(alertDiv);
        }
    }
});
</script>
<?php endif; ?>
