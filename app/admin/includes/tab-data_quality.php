<?php
declare(strict_types=1);
/**
 * tab-data_quality.php
 * Data Quality Dashboard Tab Content
 *
 * Phase 1A: Placeholder content for data quality functionality
 * Will be replaced in Phase 1C with actual migrated functionality from data-quality.php
 */
?>

<div class="alert alert-info">
    <h4><i class="fas fa-clipboard-check"></i> Data Quality Dashboard - Phase 1C Development</h4>
    <p class="mb-2">This tab will contain the comprehensive data quality reporting system:</p>
    <ul class="mb-2">
        <li>Live data quality metrics and health scoring</li>
        <li>Actionable quality reports with direct editing links</li>
        <li>Priority-based action recommendations</li>
        <li>Integration with car and user management workflows</li>
    </ul>
    <p class="mb-0"><strong>Current functionality is available at:</strong>
        <a href="../reports/data-quality.php" class="btn btn-info btn-sm ml-2">
            <i class="fas fa-external-link-alt"></i> Current Data Quality Reports
        </a>
    </p>
</div>

<!-- Preview of quality metrics cards -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-success">
            <div class="card-body text-center">
                <div class="text-success mb-3">
                    <i class="fas fa-check-circle" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Data Health</h5>
                <h3 class="text-success mb-2">---%</h3>
                <p class="small text-muted">Overall quality score</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-warning">
            <div class="card-body text-center">
                <div class="text-warning mb-3">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Missing Data</h5>
                <h3 class="text-warning mb-2">---</h3>
                <p class="small text-muted">Records needing attention</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-danger">
            <div class="card-body text-center">
                <div class="text-danger mb-3">
                    <i class="fas fa-times-circle" style="font-size: 2.5rem;"></i>
                </div>
                <h5>Invalid Data</h5>
                <h3 class="text-danger mb-2">---</h3>
                <p class="small text-muted">Critical data issues</p>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-info">
            <div class="card-body text-center">
                <div class="text-info mb-3">
                    <i class="fas fa-users" style="font-size: 2.5rem;"></i>
                </div>
                <h5>User Issues</h5>
                <h3 class="text-info mb-2">---</h3>
                <p class="small text-muted">Profile data incomplete</p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Quality Reports</h5>
    </div>
    <div class="card-body">
        <div class="text-center py-5">
            <i class="fas fa-cog fa-spin fa-3x text-muted mb-3"></i>
            <h5 class="text-muted">Data Quality Integration Coming in Phase 1C</h5>
            <p class="text-muted">All current data quality reports will be integrated with enhanced actionable workflows</p>
        </div>
    </div>
</div>