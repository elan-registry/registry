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
            <!-- Add Car Guide -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-success text-white'>
                        <h5 class='mb-0'><i class='fas fa-car'></i> How to Add Your Car</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Step-by-step guide to registering your Lotus Elan or +2. Learn about chassis validation, photo uploads, and completing your car's profile.</p>
                        <div class='mt-auto'>
                            <a href='../view.php?doc=ADD_CAR_GUIDE.md' class='btn btn-success btn-sm'><i class='fas fa-book-open'></i> Read Guide</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Identification Guide -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-danger text-white'>
                        <h5 class='mb-0'><i class='fas fa-search'></i> Identification Guide</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Complete guide to identifying different Lotus Elan models and variants. Includes detailed descriptions and photos of Roadster, Coupe, Racing, and Plus 2 models.</p>
                        <div class='mt-auto'>
                            <a href='../view.php?doc=IDENTIFICATION_GUIDE.md' class='btn btn-danger btn-sm'><i class='fas fa-book-open'></i> View Guide</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chassis Validation Rules -->
            <div class='col-lg-4 mb-4'>
                <div class='card registry-card h-100'>
                    <div class='card-header bg-warning text-dark'>
                        <h5 class='mb-0'><i class='fas fa-barcode'></i> Chassis Validation Rules</h5>
                    </div>
                    <div class='card-body d-flex flex-column'>
                        <p class='card-text'>Comprehensive guide to Lotus Elan chassis numbering formats and validation requirements. Detailed specifications for pre-1970, 1970 transition, and post-1970 formats.</p>
                        <div class='mt-auto'>
                            <a href='../chassis-validation.php' class='btn btn-warning btn-sm'><i class='fas fa-book-open'></i> View Rules</a>
                        </div>
                    </div>
                </div>
            </div>

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