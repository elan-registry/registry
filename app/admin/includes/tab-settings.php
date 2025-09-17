<?php
declare(strict_types=1);
/**
 * tab-settings.php
 * Registry Settings Tab Content
 *
 * Phase 1A: Placeholder content for settings functionality
 * Will be implemented in Phase 2A with migrated admin settings
 */
?>

<div class="alert alert-success">
    <h4><i class="fas fa-cog"></i> Registry Settings - Phase 2A Development</h4>
    <p class="mb-2">This tab will consolidate all ElanRegistry settings from the admin panel:</p>
    <ul class="mb-2">
        <li>Google Services Integration (Maps API, Geocoding API)</li>
        <li>Media Management (upload directories, file limits)</li>
        <li>Email Configuration and notification settings</li>
        <li>System Maintenance (backup retention, CDN URLs)</li>
        <li>Enhanced auto-creation with configuration versioning</li>
    </ul>
    <p class="mb-0"><strong>Current settings are available in:</strong>
        <a href="../../users/admin.php" class="btn btn-secondary btn-sm ml-2">
            <i class="fas fa-external-link-alt"></i> UserSpice Admin Panel
        </a>
    </p>
</div>

<!-- Settings Categories Preview -->
<div class="row">
    <div class="col-md-6">
        <!-- Google Services -->
        <div class="card border-info mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fab fa-google"></i> Google Services</h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Google Maps API Key</label>
                    <input type="text" class="form-control" placeholder="Coming in Phase 2A..." disabled>
                </div>
                <div class="form-group">
                    <label>Google Geocoding API Key</label>
                    <input type="text" class="form-control" placeholder="Coming in Phase 2A..." disabled>
                </div>
            </div>
        </div>

        <!-- System Maintenance -->
        <div class="card border-dark">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="fas fa-tools"></i> System Settings</h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Backup Retention Period</label>
                    <div class="input-group">
                        <input type="number" class="form-control" placeholder="30" disabled>
                        <div class="input-group-append">
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Chart.js CDN URL</label>
                    <input type="text" class="form-control" placeholder="Coming in Phase 2A..." disabled>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <!-- Media Management -->
        <div class="card border-warning mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-images"></i> Media Management</h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Image Upload Directory</label>
                    <input type="text" class="form-control" placeholder="Coming in Phase 2A..." disabled>
                </div>
                <div class="form-group">
                    <label>Maximum Photos per Car</label>
                    <input type="number" class="form-control" placeholder="10" disabled>
                </div>
                <div class="form-group">
                    <label>Maximum Upload File Size (MB)</label>
                    <input type="number" class="form-control" placeholder="2.0" step="0.1" disabled>
                </div>
            </div>
        </div>

        <!-- Email Configuration -->
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-envelope"></i> Email Settings</h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Admin Email Addresses</label>
                    <textarea rows="3" class="form-control" placeholder="Coming in Phase 2A..." disabled></textarea>
                    <small class="form-text text-muted">Comma-separated addresses for notifications</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="text-center mt-4">
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> <strong>Auto-Creation Active:</strong>
        Current database field auto-creation will be preserved and enhanced with configuration versioning.
        <div class="mt-2">
            <span class="badge badge-info">Phase 2A Feature</span>
            <span class="badge badge-primary">Issue #335</span>
        </div>
    </div>
</div>