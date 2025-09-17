<?php
declare(strict_types=1);
/**
 * tab-cleanup.php
 * Account Cleanup Tab Content
 *
 * Phase 1A: Placeholder content for account cleanup functionality
 * Will be implemented in Phase 2A with enhanced spam cleanup system
 */
?>

<div class="alert alert-warning">
    <h4><i class="fas fa-shield-alt"></i> Account Cleanup - Phase 2A Development</h4>
    <p class="mb-2">This tab will contain the enhanced account cleanup and spam management system:</p>
    <ul class="mb-2">
        <li>Advanced SPAM detection algorithms with whitelist/blacklist management</li>
        <li>Safety limits and percentage-based deletion controls</li>
        <li>Enhanced dry run mode with detailed reporting</li>
        <li>User re-engagement campaigns and account recovery workflows</li>
        <li>Integration with user management for bulk operations</li>
    </ul>
    <p class="mb-0"><strong>Current cleanup settings are in:</strong>
        <a href="../../users/admin.php" class="btn btn-secondary btn-sm ml-2">
            <i class="fas fa-external-link-alt"></i> UserSpice Admin Panel
        </a>
    </p>
</div>

<div class="row">
    <div class="col-md-6">
        <!-- SPAM Detection Controls -->
        <div class="card border-danger mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="fas fa-user-times"></i> SPAM Detection</h5>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="mb-0">Enable Automated Cleanup</label>
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="cleanupEnabled" disabled>
                            <label class="custom-control-label" for="cleanupEnabled"></label>
                        </div>
                    </div>
                    <small class="form-text text-muted">Master switch for cleanup system</small>
                </div>

                <div class="form-group">
                    <div class="d-flex justify-content-between align-items-center">
                        <label class="mb-0">Dry Run Mode</label>
                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="custom-control-input" id="dryRunMode" disabled>
                            <label class="custom-control-label" for="dryRunMode"></label>
                        </div>
                    </div>
                    <small class="form-text text-muted">Test mode - logs without deleting</small>
                </div>

                <div class="form-group">
                    <label>Inactive User Threshold</label>
                    <div class="input-group">
                        <input type="number" class="form-control" placeholder="30" disabled>
                        <div class="input-group-append">
                            <span class="input-group-text">days</span>
                        </div>
                    </div>
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
                <div class="form-group">
                    <label>Max Deletions Per Run</label>
                    <div class="input-group">
                        <input type="number" class="form-control" placeholder="50" disabled>
                        <div class="input-group-append">
                            <span class="input-group-text">users</span>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Max Cleanup Percentage</label>
                    <div class="input-group">
                        <input type="number" class="form-control" placeholder="5.0" step="0.1" disabled>
                        <div class="input-group-append">
                            <span class="input-group-text">% of users</span>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info">
                    <h6><i class="fas fa-info-circle"></i> Current Status</h6>
                    <ul class="mb-0">
                        <li>System statistics: <strong>Coming in Phase 2A</strong></li>
                        <li>Cleanup candidates: <strong>--</strong></li>
                        <li>Safety status: <strong>--</strong></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="text-center">
    <button class="btn btn-warning mr-2" disabled>
        <i class="fas fa-play"></i> Run Cleanup (Coming Phase 2A)
    </button>
    <button class="btn btn-outline-secondary" disabled>
        <i class="fas fa-list"></i> View Cleanup Logs (Coming Phase 2A)
    </button>
</div>

<div class="alert alert-info mt-4">
    <div class="text-center">
        <i class="fas fa-info-circle"></i> <strong>Enhanced Cleanup System:</strong>
        All current safety mechanisms will be preserved and enhanced with advanced features.
        <div class="mt-2">
            <span class="badge badge-warning">Phase 2A Feature</span>
            <span class="badge badge-primary">Issue #335</span>
        </div>
    </div>
</div>