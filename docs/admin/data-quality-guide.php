<?php
/**
 * Data Quality Reports - Administrator Guide
 *
 * This page provides comprehensive documentation for administrators on using
 * the Data Quality Reports dashboard to maintain and improve registry data quality.
 *
 * @package ElanRegistry
 * @version 2.9.0
 * @author Registry Admin Team
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Admin permission check (Administrator or Editor roles)
if (!hasPerm([1, 2])) {
    usError('Access denied. Administrator or Editor privileges required.');
    Redirect::to($us_url_root . 'users/login.php');
}

?>

<style>
    .screenshot-img {
        max-width: 100%;
        height: auto;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        margin: 20px 0;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    .section-header {
        border-left: 4px solid #007bff;
        padding-left: 15px;
        margin: 30px 0 20px 0;
    }
    .feature-box {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin: 15px 0;
        border-left: 4px solid #28a745;
    }
    .warning-box {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 8px;
        padding: 15px;
        margin: 15px 0;
    }
    .quality-metric {
        display: inline-block;
        padding: 5px 10px;
        border-radius: 15px;
        margin: 5px;
        font-weight: bold;
    }
    .quality-good { background: #d4edda; color: #155724; }
    .quality-warning { background: #fff3cd; color: #856404; }
    .quality-danger { background: #f8d7da; color: #721c24; }
    .toc {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 30px;
    }
    .toc ul {
        list-style-type: none;
        padding-left: 0;
    }
    .toc li {
        padding: 5px 0;
    }
    .toc a {
        text-decoration: none;
        color: #007bff;
    }
    .toc a:hover {
        text-decoration: underline;
    }
</style>

<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>

        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h1 class="mb-0">
                                    <i class="fas fa-chart-line text-primary"></i> Data Quality Reports
                                </h1>
                                <p class="text-muted mb-0">Administrator Guide for Registry Data Quality Management</p>
                            </div>
                            <div>
                                <a href="<?= $us_url_root ?>app/admin/manage-consolidated.php?tab=data-quality" class="btn btn-primary">
                                    <i class="fas fa-external-link-alt"></i> Open Data Quality Tab
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table of Contents -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Table of Contents</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <ul>
                                    <li><a href="#overview">1. Overview</a></li>
                                    <li><a href="#access">2. Accessing the Dashboard</a></li>
                                    <li><a href="#metrics">3. Understanding Quality Metrics</a></li>
                                    <li><a href="#owner-reports">4. Owner Quality Reports</a></li>
                                    <li><a href="#car-reports">5. Car Quality Reports</a></li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul>
                                    <li><a href="#actions">6. Administrative Actions</a></li>
                                    <li><a href="#contact-system">7. Owner Contact System</a></li>
                                    <li><a href="#workflows">8. Quality Management Workflows</a></li>
                                    <li><a href="#best-practices">9. Best Practices</a></li>
                                    <li><a href="#troubleshooting">10. Troubleshooting</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overview Section -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="overview">
                    <div class="card-header">
                        <h2>1. Overview</h2>
                    </div>
                    <div class="card-body">
                        <p>The Data Quality Reports tab provides a comprehensive dashboard for monitoring and improving the quality of data in the Lotus Elan Registry. This tool helps administrators identify missing information, data inconsistencies, and opportunities for registry improvement.</p>

                        <div class="feature-box">
                            <h4><i class="fas fa-bullseye"></i> Key Purposes</h4>
                            <ul>
                                <li><strong>Data Health Monitoring:</strong> Track overall registry data quality with visual metrics</li>
                                <li><strong>Issue Identification:</strong> Automatically detect missing or invalid data</li>
                                <li><strong>Actionable Workflows:</strong> Provide direct tools for resolving quality issues</li>
                                <li><strong>Owner Communication:</strong> Professional email system for requesting data updates</li>
                                <li><strong>Progress Tracking:</strong> Monitor quality improvements over time</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Access Requirements -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="access">
                    <div class="card-header">
                        <h2>2. Accessing the Dashboard</h2>
                    </div>
                    <div class="card-body">
                        <div class="warning-box">
                            <h5><i class="fas fa-shield-alt"></i> Permission Requirements</h5>
                            <p>Access to the Data Quality Reports requires <strong>Administrator</strong> or <strong>Editor</strong> permissions. Users without these roles will not see this tab.</p>
                        </div>

                        <h4>Navigation Steps:</h4>
                        <ol>
                            <li>Log in with an Administrator or Editor account</li>
                            <li>Navigate to the administrative interface</li>
                            <li>Click on the "Data Quality" tab in the consolidated management interface</li>
                        </ol>

                        <img src="<?= $us_url_root ?>docs/admin/screenshots/navigation-to-data-quality-tab.png" alt="Navigation to Data Quality Tab" class="screenshot-img">
                        <p class="text-muted"><em>The consolidated admin interface with the Data Quality tab highlighted</em></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quality Metrics -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="metrics">
                    <div class="card-header">
                        <h2>3. Understanding Quality Metrics</h2>
                    </div>
                    <div class="card-body">
                        <p>The dashboard displays four key metric cards that provide an at-a-glance view of your registry's data quality:</p>

                        <img src="<?= $us_url_root ?>docs/admin/screenshots/quality-metrics-dashboard.png" alt="Quality Metrics Dashboard" class="screenshot-img">
                        <p class="text-muted"><em>The four metric cards showing Data Health, Warning Issues, Critical Issues, and Total Issues</em></p>

                        <h4>Metric Explanations:</h4>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="card border-success mb-3">
                                    <div class="card-header bg-success text-white">
                                        <h5><i class="fas fa-check-circle"></i> Data Health Score</h5>
                                    </div>
                                    <div class="card-body">
                                        <p>A percentage score indicating overall data quality:</p>
                                        <ul>
                                            <li><span class="quality-metric quality-good">80-100%</span> Excellent quality</li>
                                            <li><span class="quality-metric quality-warning">60-79%</span> Needs attention</li>
                                            <li><span class="quality-metric quality-danger">0-59%</span> Poor quality</li>
                                        </ul>
                                        <p><strong>Calculation:</strong> Based on the ratio of total issues to total records</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card border-warning mb-3">
                                    <div class="card-header bg-warning text-dark">
                                        <h5><i class="fas fa-exclamation-triangle"></i> Issue Categories</h5>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Warning Issues:</strong> Non-critical data problems that should be addressed</p>
                                        <p><strong>Critical Issues:</strong> Serious data problems requiring immediate attention</p>
                                        <p><strong>Total Issues:</strong> Combined count of all identified data quality problems</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Owner Reports -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="owner-reports">
                    <div class="card-header">
                        <h2>4. Owner Quality Reports</h2>
                    </div>
                    <div class="card-body">
                        <p>Owner quality reports focus on user account and profile data issues. These reports help ensure complete owner information for effective communication and registry management.</p>

                        <img src="<?= $us_url_root ?>docs/admin/screenshots/owner-quality-reports.png" alt="Owner Quality Reports" class="screenshot-img">
                        <p class="text-muted"><em>Owner reports section showing cards with sample counts and severities</em></p>

                        <h4>Available Owner Reports:</h4>

                        <div class="accordion" id="ownerReportsAccordion">
                            <div class="card">
                                <div class="card-header" id="ownersMissing">
                                    <h5 class="mb-0">
                                        <button class="btn btn-link" type="button" data-toggle="collapse" data-target="#collapseOwnersMissing">
                                            <i class="fas fa-user-times text-warning"></i> Car Owners Missing Information
                                        </button>
                                    </h5>
                                </div>
                                <div id="collapseOwnersMissing" class="collapse" data-parent="#ownerReportsAccordion">
                                    <div class="card-body">
                                        <p><strong>Purpose:</strong> Identifies car owners with incomplete profile information</p>
                                        <p><strong>Criteria:</strong> Missing first name, last name, city, or coordinates</p>
                                        <p><strong>Impact:</strong> Affects contact capabilities and location-based features</p>
                                        <p><strong>Actions:</strong> Contact owner button, edit profile link</p>

                                        <img src="<?= $us_url_root ?>docs/admin/screenshots/owners-missing-info-report.png" alt="Owners Missing Information Report" class="screenshot-img">
                                        <p class="text-muted"><em>Expanded report showing sample data and action buttons</em></p>
                                    </div>
                                </div>
                            </div>

                            <div class="card">
                                <div class="card-header" id="duplicateEmails">
                                    <h5 class="mb-0">
                                        <button class="btn btn-link" type="button" data-toggle="collapse" data-target="#collapseDuplicateEmails">
                                            <i class="fas fa-envelope-duplicate text-warning"></i> Duplicate Email Addresses
                                        </button>
                                    </h5>
                                </div>
                                <div id="collapseDuplicateEmails" class="collapse" data-parent="#ownerReportsAccordion">
                                    <div class="card-body">
                                        <p><strong>Purpose:</strong> Identifies potential duplicate accounts</p>
                                        <p><strong>Criteria:</strong> Multiple active accounts with the same email</p>
                                        <p><strong>Impact:</strong> May indicate account confusion or data integrity issues</p>
                                        <p><strong>Actions:</strong> Contact users for account consolidation</p>

                                        <img src="<?= $us_url_root ?>docs/admin/screenshots/duplicate-emails-report.png" alt="Duplicate Emails Report" class="screenshot-img">
                                        <p class="text-muted"><em>Duplicate email entries showing user lists and contact buttons</em></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Car Reports -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="car-reports">
                    <div class="card-header">
                        <h2>5. Car Quality Reports</h2>
                    </div>
                    <div class="card-body">
                        <p>Car quality reports focus on vehicle data integrity, ensuring accurate and complete information for all registered cars.</p>

                        <img src="<?= $us_url_root ?>docs/admin/screenshots/car-quality-reports.png" alt="Car Quality Reports" class="screenshot-img">
                        <p class="text-muted"><em>Car reports section showing cards with sample counts and severities</em></p>

                        <div class="feature-box">
                            <h5><i class="fas fa-cog"></i> ChassisValidator Integration</h5>
                            <p>The system uses advanced validation rules based on model year and series to verify chassis number formats. Invalid entries are flagged for review with detailed validation information.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Administrative Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="actions">
                    <div class="card-header">
                        <h2>6. Administrative Actions</h2>
                    </div>
                    <div class="card-body">
                        <p>Each quality report provides direct action buttons to resolve identified issues efficiently.</p>

                        <h4>Available Actions:</h4>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="card border-primary mb-3">
                                    <div class="card-header bg-primary text-white">
                                        <h5><i class="fas fa-edit"></i> Edit Car</h5>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Purpose:</strong> Direct editing of car information</p>
                                        <p><strong>When to use:</strong> For obvious data corrections</p>
                                        <p><strong>Access:</strong> Admin override with warning banner</p>
                                        <p><strong>Audit:</strong> All edits are logged for accountability</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-warning mb-3">
                                    <div class="card-header bg-warning text-dark">
                                        <h5><i class="fas fa-envelope"></i> Contact Owner</h5>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Purpose:</strong> Professional email to car owner</p>
                                        <p><strong>When to use:</strong> Request information updates</p>
                                        <p><strong>Features:</strong> Quality issue pre-population</p>
                                        <p><strong>Format:</strong> Registry-branded email template</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card border-success mb-3">
                                    <div class="card-header bg-success text-white">
                                        <h5><i class="fas fa-user-edit"></i> Edit Profile</h5>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Purpose:</strong> Direct profile information editing</p>
                                        <p><strong>When to use:</strong> Update owner information</p>
                                        <p><strong>Navigation:</strong> Links to user management</p>
                                        <p><strong>Scope:</strong> Contact info, location, preferences</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="warning-box">
                            <h5><i class="fas fa-shield-alt"></i> Admin Override Warnings</h5>
                            <p>When editing cars you don't own, the system displays warning banners to ensure you understand you're overriding normal ownership restrictions. All override actions are logged for audit purposes.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contact System -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="contact-system">
                    <div class="card-header">
                        <h2>7. Owner Contact System</h2>
                    </div>
                    <div class="card-body">
                        <p>The integrated contact system provides professional email communication with car owners for data quality requests.</p>

                        <img src="<?= $us_url_root ?>docs/admin/screenshots/contact-owner-modal.png" alt="Contact Owner Modal" class="screenshot-img">
                        <p class="text-muted"><em>Contact modal showing quality issue pre-population and message composition</em></p>

                        <h4>Contact System Features:</h4>

                        <div class="feature-box">
                            <h5><i class="fas fa-magic"></i> Quality Issue Pre-population</h5>
                            <p>Contact forms automatically pre-populate with the specific data quality issue identified, saving time and ensuring consistent communication.</p>
                        </div>

                        <div class="feature-box">
                            <h5><i class="fas fa-envelope-open-text"></i> Professional Email Templates</h5>
                            <p>All emails use registry-branded templates with proper formatting, administrator credentials, and reply-to functionality.</p>
                        </div>

                        <h4>Contact Process:</h4>
                        <ol>
                            <li>Click "Contact Owner" button on any quality report item</li>
                            <li>Review pre-populated quality issue and car/owner information</li>
                            <li>Customize the message as needed (optional)</li>
                            <li>Send the email using registry credentials</li>
                            <li>Email includes reply-to for direct administrator communication</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Best Practices -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card" id="best-practices">
                    <div class="card-header">
                        <h2>9. Best Practices</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="feature-box">
                                    <h5><i class="fas fa-thumbs-up"></i> Communication Best Practices</h5>
                                    <ul>
                                        <li>Use professional, friendly tone</li>
                                        <li>Clearly explain why information is needed</li>
                                        <li>Provide specific guidance on what to update</li>
                                        <li>Thank owners for their participation</li>
                                        <li>Follow up appropriately on requests</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-box">
                                    <h5><i class="fas fa-cogs"></i> Technical Best Practices</h5>
                                    <ul>
                                        <li>Use admin override sparingly</li>
                                        <li>Document reasons for manual changes</li>
                                        <li>Verify data before making corrections</li>
                                        <li>Monitor quality trends over time</li>
                                        <li>Maintain audit trail consistency</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="warning-box">
                            <h5><i class="fas fa-balance-scale"></i> Owner vs Admin Actions</h5>
                            <p><strong>Contact owners first</strong> for information they should provide themselves. Use admin override only for obvious corrections or when owner contact fails.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Need Help?</h5>
                                <p>If you encounter issues not covered in this guide:</p>
                                <ul>
                                    <li>Contact the technical team</li>
                                    <li>Check the administrator forums</li>
                                    <li>Review system status page</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h5>Quick Links</h5>
                                <ul>
                                    <li><a href="<?= $us_url_root ?>app/admin/manage-consolidated.php?tab=data-quality">Data Quality Dashboard</a></li>
                                    <li><a href="<?= $us_url_root ?>app/admin/manage-consolidated.php?tab=car-mgmt">Car Management</a></li>
                                    <li><a href="<?= $us_url_root ?>app/admin/manage-consolidated.php">Admin Home</a></li>
                                </ul>
                            </div>
                        </div>
                        <hr>
                        <p class="text-muted text-center">
                            Data Quality Reports Administrator Guide - Version 2.9.0<br>
                            Last Updated: <?= date('F Y') ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /.container -->
</div><!-- /.page-wrapper -->

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Smooth scrolling for anchor links
    $('a[href^="#"]').on('click', function(event) {
        var target = $(this.getAttribute('href'));
        if( target.length ) {
            event.preventDefault();
            $('html, body').stop().animate({
                scrollTop: target.offset().top - 80
            }, 1000);
        }
    });

    // Auto-expand sections based on URL hash
    $(document).ready(function() {
        if(window.location.hash) {
            var target = $(window.location.hash);
            if(target.length && target.hasClass('collapse')) {
                target.collapse('show');
            }
        }
    });
</script>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>