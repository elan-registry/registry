<?php
/**
 * FAQ and User Documentation - Lotus Elan Registry
 *
 * This page displays user-facing documentation including guides, FAQ, and policies.
 *
 * @package ElanRegistry
 * @version 2.9.0
 * @author Jim Boone
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

?>
<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>
        <!-- Heading Row -->
        <div class='row'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header'>
                        <h1 class='mb-0'><i class='fas fa-question-circle'></i> FAQ & User Guides</h1>
                        <p class='text-muted'>Documentation and guides for using the Lotus Elan Registry</p>
                    </div>
                    <div class='card-body'>
                        <p class="lead">Find answers to common questions and learn how to use the registry effectively.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentation Cards -->
        <div class='row mt-4'>
            <!-- Car Transfer User Guide -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-primary text-white'>
                        <h5 class='mb-0'><i class='fas fa-exchange-alt'></i> Car Transfer Guide</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Complete guide for requesting ownership transfers of cars in the registry. Learn the step-by-step process and what to expect.</p>
                        <div class='mt-auto'>
                            <a href='../view.php?doc=CAR_TRANSFER_USER_GUIDE.md' class='btn btn-primary btn-sm'><i class='fas fa-book-open'></i> Read Guide</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transfer FAQ -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-info text-white'>
                        <h5 class='mb-0'><i class='fas fa-question'></i> Transfer FAQ</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Frequently asked questions about car ownership transfers, common issues, and quick solutions.</p>
                        <div class='mt-auto'>
                            <a href='../view.php?doc=CAR_TRANSFER_FAQ.md' class='btn btn-info btn-sm'><i class='fas fa-question-circle'></i> View FAQ</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Privacy Policy -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-dark text-white'>
                        <h5 class='mb-0'><i class='fas fa-shield-alt'></i> Privacy Policy</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Our privacy policy explaining how we collect, use, and protect your personal information in the registry.</p>
                        <div class='mt-auto'>
                            <a href='../view.php?doc=PRIVACY.md' class='btn btn-dark btn-sm'><i class='fas fa-file-alt'></i> Read Policy</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Getting Started Section -->
        <div class='row mt-4'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header bg-success text-white'>
                        <h3 class='mb-0'><i class='fas fa-rocket'></i> Getting Started</h3>
                    </div>
                    <div class='card-body'>
                        <div class='row'>
                            <div class='col-md-6'>
                                <h5><i class='fas fa-user-plus text-primary'></i> New Users</h5>
                                <ul class='list-unstyled'>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> <a href='<?= $us_url_root ?>users/join.php'>Create an account</a></li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> <a href='<?= $us_url_root ?>app/cars/add.php'>Add your car to the registry</a></li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> Upload photos of your Elan</li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> Connect with other owners</li>
                                </ul>
                            </div>
                            <div class='col-md-6'>
                                <h5><i class='fas fa-search text-primary'></i> Finding Cars</h5>
                                <ul class='list-unstyled'>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> <a href='<?= $us_url_root ?>app/cars/'>Browse all registered cars</a></li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> Search by chassis number or series</li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> View detailed car histories</li>
                                    <li class='mb-2'><i class='fas fa-check text-success'></i> Contact current owners</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div class='row mt-4'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header bg-warning text-dark'>
                        <h3 class='mb-0'><i class='fas fa-headset'></i> Need More Help?</h3>
                    </div>
                    <div class='card-body'>
                        <div class='row'>
                            <div class='col-md-6'>
                                <h5>Contact Registry Administrators</h5>
                                <p>If you have questions that aren't answered in our documentation, please contact the registry administrators.</p>
                                <p><strong>Email Subject Format:</strong> "Registry Help - [Your Issue]"<br>
                                <strong>Include:</strong> Your account information, specific details about your issue, and any error messages you're seeing.</p>
                            </div>
                            <div class='col-md-6'>
                                <h5>Community Resources</h5>
                                <ul class='list-unstyled'>
                                    <li class='mb-2'><i class='fas fa-external-link-alt'></i> <a href='http://www.lotuselan.net/' target='_blank'>LotusElan.Net Community</a></li>
                                    <li class='mb-2'><i class='fas fa-external-link-alt'></i> <a href='http://www.lotuselansprint.com/' target='_blank'>Lotus Elan Sprint Site</a></li>
                                    <li class='mb-2'><i class='fas fa-code-branch'></i> <a href='https://github.com/unibrain1/elanregistry' target='_blank'>GitHub Project</a></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Links -->
        <div class='row mt-4 mb-4'>
            <div class='col-12 text-center'>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-primary mr-2'><i class='fas fa-home'></i> Registry Home</a>
                <a href='<?= $us_url_root ?>app/cars/' class='btn btn-outline-success mr-2'><i class='fas fa-car'></i> Browse Cars</a>
                <?php if ($user->isLoggedIn()) { ?>
                    <a href='<?= $us_url_root ?>users/account.php' class='btn btn-outline-info'><i class='fas fa-user'></i> My Account</a>
                <?php } else { ?>
                    <a href='<?= $us_url_root ?>users/login.php' class='btn btn-outline-warning'><i class='fas fa-sign-in-alt'></i> Login</a>
                <?php } ?>
            </div>
        </div>

    </div> <!-- /.container -->
</div><!-- .page-wrapper -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>