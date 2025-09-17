<?php
declare(strict_types=1);
/**
 * tab-car_mgmt.php
 * Car Management Tab Content
 *
 * Phase 1A: Placeholder content for car management functionality
 * Will be replaced in Phase 1B with actual migrated functionality
 */
?>

<div class="alert alert-warning">
    <h4><i class="fas fa-wrench"></i> Car Management - Phase 1B Development</h4>
    <p class="mb-2">This tab will contain all car management functionality from the current manage.php:</p>
    <ul class="mb-2">
        <li>Car reassignment with enhanced AJAX lookup</li>
        <li>Car deletion with safety confirmations</li>
        <li>Transfer request approval/denial workflow</li>
        <li>Enhanced user experience improvements</li>
    </ul>
    <p class="mb-0"><strong>Current functionality is available at:</strong>
        <a href="../cars/manage.php" class="btn btn-primary btn-sm ml-2">
            <i class="fas fa-external-link-alt"></i> Current Car Management
        </a>
    </p>
</div>

<!-- Preview of future content structure -->
<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> Car Reassignment</h5>
            </div>
            <div class="card-body">
                <div class="text-center py-4">
                    <i class="fas fa-cog fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Coming in Phase 1B</p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="fas fa-trash-alt"></i> Car Deletion</h5>
            </div>
            <div class="card-body">
                <div class="text-center py-4">
                    <i class="fas fa-cog fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Coming in Phase 1B</p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4 mb-4">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-exchange-alt"></i> Transfer Requests</h5>
            </div>
            <div class="card-body">
                <div class="text-center py-4">
                    <i class="fas fa-cog fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">
                        <?= isset($systemStatus['pending_transfers']) && $systemStatus['pending_transfers'] > 0 ?
                            $systemStatus['pending_transfers'] . ' pending transfers' :
                            'No pending transfers' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>