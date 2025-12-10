<?php
declare(strict_types=1);
/**
 * tab-placeholder.php
 * Placeholder content for consolidated interface tabs
 *
 * This file shows when a specific tab content file is not found.
 * Phase 1A infrastructure - will be replaced by actual content in subsequent phases.
 */
?>

<div class="alert alert-info">
    <h4><i class="fas fa-info-circle"></i> Tab Under Development</h4>
    <p class="mb-2">This tab is part of the consolidated management interface currently under development.</p>
    <p class="mb-0">
        <strong>Current Tab:</strong> <?= ucwords(str_replace(['-', '_'], ' ', $activeTab)) ?><br>
        <strong>Status:</strong> Phase 1A - Infrastructure Complete
    </p>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-wrench"></i> Development Status</h5>
            </div>
            <div class="card-body">
                <h6>Phase 1A: Interface Foundation</h6>
                <div class="progress mb-3">
                    <div class="progress-bar bg-success" style="width: 100%">100%</div>
                </div>
                <ul class="list-unstyled">
                    <li><i class="fas fa-check text-success"></i> Tabbed interface structure</li>
                    <li><i class="fas fa-check text-success"></i> URL-based routing</li>
                    <li><i class="fas fa-check text-success"></i> Security integration</li>
                    <li><i class="fas fa-check text-success"></i> UserSpice framework integration</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-map-signs"></i> Coming Soon</h5>
            </div>
            <div class="card-body">
                <h6>Planned Tab Content</h6>
                <div class="small">
                    <?php
                    $tabDescriptions = [
                        'car-mgmt' => 'Car reassignment, deletion, and transfer request management',
                        'data-quality' => 'Comprehensive data quality dashboard and reporting',
                        'duplicates' => 'Enhanced duplicate detection and resolution tools',
                        'user-mgmt' => 'User and profile management with geocoding integration',
                        'system' => 'FIX scripts, database management, and system maintenance',
                        'settings' => 'Registry settings and configuration management',
                        'cleanup' => 'Account cleanup and spam management tools'
                    ];

                    if (isset($tabDescriptions[$activeTab])) {
                        echo '<p class="text-primary mb-0">' . $tabDescriptions[$activeTab] . '</p>';
                    } else {
                        echo '<p class="text-muted mb-0">Tab content will be implemented in future development phases.</p>';
                    }
                    ?>
                </div>

                <div class="mt-3">
                    <a href="../cars/manage.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-external-link-alt"></i> Current Car Management
                    </a>
                    <a href="../reports/data-quality.php" class="btn btn-outline-info btn-sm ml-2">
                        <i class="fas fa-chart-line"></i> Current Data Quality
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card border-secondary">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-code"></i> Technical Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <h6>Current Implementation</h6>
                        <ul class="list-unstyled small">
                            <li><strong>File:</strong> manage-consolidated.php</li>
                            <li><strong>Tab:</strong> <?= htmlspecialchars($activeTab) ?></li>
                            <li><strong>Include:</strong> tab-<?= str_replace('-', '_', $activeTab) ?>.php</li>
                            <li><strong>Framework:</strong> UserSpice 5.x</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6>URL Routing</h6>
                        <ul class="list-unstyled small">
                            <li><strong>Base URL:</strong> manage-consolidated.php</li>
                            <li><strong>Parameter:</strong> ?tab=<?= htmlspecialchars($activeTab) ?></li>
                            <li><strong>Valid Tabs:</strong> <?= count($validTabs) ?></li>
                            <li><strong>Default:</strong> car-mgmt</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <h6>Security</h6>
                        <ul class="list-unstyled small">
                            <li><strong>Auth Required:</strong> Yes</li>
                            <li><strong>CSRF Protection:</strong> Active</li>
                            <li><strong>Admin Only:</strong> Yes</li>
                            <li><strong>Session:</strong> UserSpice</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>