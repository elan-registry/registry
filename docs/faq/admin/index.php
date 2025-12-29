<?php
/**
 * Administrative Documentation - Lotus Elan Registry
 *
 * This page displays administrator and technical documentation.
 * Requires administrator privileges to access.
 *
 * @package ElanRegistry
 * @version 2.9.0
 * @author Jim Boone
 */

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Additional admin check (will be enforced by UserSpice page management in production)
if (!$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to($us_url_root . 'users/login.php');
}

?>
<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>
        <!-- Heading Row -->
        <div class='row'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header bg-danger text-white'>
                        <h1 class='mb-0'><i class='fas fa-tools'></i> Administrative Documentation</h1>
                        <p class='text-muted mb-0'>Technical guides and administrative procedures for registry management</p>
                    </div>
                    <div class='card-body'>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> <strong>Administrator Access Required</strong><br>
                            This documentation is intended for registry administrators and technical staff only.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Administrative Documentation -->
        <div class='row mt-4'>
            <div class='col-12'>
                <h2><i class='fas fa-user-shield text-primary'></i> Car Transfer Administration</h2>
            </div>
        </div>

        <div class='row'>
            <!-- Admin Guide -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-primary text-white'>
                        <h5 class='mb-0'><i class='fas fa-book'></i> Administrator Guide</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Comprehensive guide for managing car ownership transfer requests, handling disputes, and system administration.</p>
                        <p class='small text-muted'>8,000+ words • Complete procedures</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=CAR_TRANSFER_ADMIN_GUIDE.md' class='btn btn-primary btn-sm'><i class='fas fa-book-open'></i> Read Guide</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Reference -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-success text-white'>
                        <h5 class='mb-0'><i class='fas fa-tachometer-alt'></i> Quick Reference</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Daily admin tasks, decision trees, emergency procedures, and quick fixes for common transfer issues.</p>
                        <p class='small text-muted'>Print-friendly • Mobile-accessible</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md' class='btn btn-success btn-sm'><i class='fas fa-lightning-bolt'></i> Quick Access</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Troubleshooting -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-warning text-dark'>
                        <h5 class='mb-0'><i class='fas fa-wrench'></i> Troubleshooting</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Systematic diagnostic procedures, problem classification, and resolution strategies for transfer system issues.</p>
                        <p class='small text-muted'>4-level classification • Step-by-step fixes</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=CAR_TRANSFER_TROUBLESHOOTING.md' class='btn btn-warning btn-sm'><i class='fas fa-tools'></i> Troubleshoot</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Technical Documentation -->
        <div class='row mt-4'>
            <div class='col-12'>
                <h2><i class='fas fa-code text-info'></i> Technical Documentation</h2>
            </div>
        </div>

        <div class='row'>
            <!-- Database Documentation -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-info text-white'>
                        <h5 class='mb-0'><i class='fas fa-database'></i> Database Schema</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Complete database documentation including table relationships, indexes, and data integrity constraints.</p>
                        <p class='small text-muted'>Schema diagrams • Relationship mapping</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=development/DATABASE.md' class='btn btn-info btn-sm'><i class='fas fa-table'></i> View Schema</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Requirements -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-dark text-white'>
                        <h5 class='mb-0'><i class='fas fa-file-contract'></i> Product Requirements</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Product Requirements Document (PRD) with feature specifications, business requirements, and system architecture.</p>
                        <p class='small text-muted'>Business logic • Feature specs</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=PRD.md' class='btn btn-dark btn-sm'><i class='fas fa-clipboard-list'></i> View PRD</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Email Guidelines -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-secondary text-white'>
                        <h5 class='mb-0'><i class='fas fa-envelope'></i> Email Guidelines</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Email template styling standards, guidelines for notifications, and best practices for user communication.</p>
                        <p class='small text-muted'>Template standards • Communication guidelines</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=EMAIL_STYLING_GUIDELINES.md' class='btn btn-secondary btn-sm'><i class='fas fa-palette'></i> View Guidelines</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Administration -->
        <div class='row mt-4'>
            <div class='col-12'>
                <h2><i class='fas fa-server text-danger'></i> System Administration</h2>
            </div>
        </div>

        <div class='row'>
            <!-- Spam Cleanup -->
            <div class='col-lg-6 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-danger text-white'>
                        <h5 class='mb-0'><i class='fas fa-broom'></i> Spam Cleanup System</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Automated user cleanup system documentation including criteria, processes, and safety measures for spam account removal.</p>
                        <p class='small text-muted'>Automated cleanup • Safety protocols</p>
                        <div class='mt-auto'>
                            <a href='../../view.php?doc=SPAM_CLEANUP_SYSTEM.md' class='btn btn-danger btn-sm'><i class='fas fa-shield-alt'></i> View System</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Admin Tools -->
            <div class='col-lg-6 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-primary text-white'>
                        <h5 class='mb-0'><i class='fas fa-cogs'></i> Administrative Tools</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Access to the main administrative interface for managing cars, users, and system operations.</p>
                        <ul class='list-unstyled small'>
                            <li><i class='fas fa-check text-success'></i> Car/Owner Management</li>
                            <li><i class='fas fa-check text-success'></i> Transfer Administration</li>
                            <li><i class='fas fa-check text-success'></i> Data Quality Monitoring</li>
                            <li><i class='fas fa-check text-success'></i> System Reports</li>
                        </ul>
                        <div class='mt-auto'>
                            <a href='<?= $us_url_root ?>app/admin/manage-consolidated.php' class='btn btn-primary btn-sm'><i class='fas fa-external-link-alt'></i> Open Admin Panel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Links -->
        <div class='row mt-4 mb-4'>
            <div class='col-12 text-center'>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-primary mr-2'><i class='fas fa-home'></i> Registry Home</a>
                <a href='../index.php' class='btn btn-outline-info mr-2'><i class='fas fa-question-circle'></i> User FAQ</a>
                <a href='<?= $us_url_root ?>app/admin/' class='btn btn-outline-success'><i class='fas fa-tools'></i> Admin Dashboard</a>
            </div>
        </div>

    </div> <!-- /.container -->
</div><!-- .page-wrapper -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>