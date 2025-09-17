<?php
declare(strict_types=1);
/**
 * tab-user_mgmt.php
 * User Management Tab Content
 *
 * Phase 1A: Placeholder content for user management functionality
 * Will be implemented in Phase 2 (Issue #269) as dedicated ElanRegistry interface
 */
?>

<div class="alert alert-warning">
    <h4><i class="fas fa-users"></i> User Management - Phase 2 Development</h4>
    <p class="mb-2">This tab will replace UserSpice admin interface limitations with dedicated ElanRegistry user management:</p>
    <ul class="mb-2">
        <li>Combined user + profile editing in single interface</li>
        <li>Location fields with real-time geocoding integration</li>
        <li>Car ownership overview and management</li>
        <li>Data quality indicators for profile completeness</li>
        <li>Direct links from data quality dashboard</li>
    </ul>
    <p class="mb-0"><strong>Current user management:</strong>
        <a href="../../users/admin.php" class="btn btn-secondary btn-sm ml-2">
            <i class="fas fa-external-link-alt"></i> UserSpice Admin
        </a>
    </p>
</div>

<!-- Preview of user management interface -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-search"></i> User Search & Management</h5>
            </div>
            <div class="card-body">
                <div class="input-group mb-3">
                    <input type="text" class="form-control" placeholder="Search users by name, email, or location..." disabled>
                    <div class="input-group-append">
                        <button class="btn btn-primary" type="button" disabled>
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="text-center py-4">
                    <i class="fas fa-cog fa-spin fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Enhanced user search and management coming in Phase 2</p>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-info">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="fas fa-chart-pie"></i> User Statistics</h6>
            </div>
            <div class="card-body">
                <div class="text-center">
                    <h3 class="text-primary"><?= number_format($systemStatus['total_users']) ?></h3>
                    <p class="mb-0">Active Users</p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-user-edit"></i> Profile Management</h5>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h6 class="text-primary"><i class="fas fa-user"></i> Coming in Phase 2</h6>
                <ul class="list-unstyled">
                    <li><i class="fas fa-check text-muted"></i> Real-time profile editing</li>
                    <li><i class="fas fa-check text-muted"></i> Location geocoding integration</li>
                    <li><i class="fas fa-check text-muted"></i> Profile quality scoring</li>
                    <li><i class="fas fa-check text-muted"></i> Data quality integration</li>
                </ul>
            </div>
            <div class="col-md-6">
                <h6 class="text-success"><i class="fas fa-car"></i> Car Ownership</h6>
                <ul class="list-unstyled">
                    <li><i class="fas fa-check text-muted"></i> Complete ownership history</li>
                    <li><i class="fas fa-check text-muted"></i> Shared car management</li>
                    <li><i class="fas fa-check text-muted"></i> Transfer history tracking</li>
                    <li><i class="fas fa-check text-muted"></i> Direct car management links</li>
                </ul>
            </div>
        </div>
        <div class="text-center mt-3">
            <span class="badge badge-warning">Issue #269</span>
            <span class="badge badge-info">Phase 2 Feature</span>
        </div>
    </div>
</div>