<?php

/**
 * Documents Index - Redirect Page
 *
 * The documents section has been reorganized into focused pages.
 * This page provides navigation to the new sections.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

?>
<div class="page-wrapper">
    <div class="container">
        <div class="row">
            <div class="col-sm-12">
                <div class="card registry-card">
                        <div class="card-header">
                            <h2><strong>Documentation Center</strong></h2>
                            <p class="text-muted">Our documentation has been reorganized for better navigation</p>
                        </div>
                        <div class="card-body">
                            
                            <div class="alert alert-info mb-4">
                                <i class="fas fa-info-circle"></i>
                                <strong>Updated Organization:</strong> We've split our documentation into focused sections 
                                to make it easier to find what you're looking for.
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-4">
                                    <div class="card border-primary">
                                        <div class="card-header bg-primary text-white">
                                            <h5 class="mb-0">
                                                <i class="fas fa-book"></i> Reference Library
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <p class="card-text">
                                                Technical documentation, workshop manuals, parts lists, and owner guides. 
                                                Everything you need for maintenance and restoration.
                                            </p>
                                            <ul class="list-unstyled">
                                                <li><i class="fas fa-wrench text-primary"></i> Workshop Manuals</li>
                                                <li><i class="fas fa-cogs text-primary"></i> Parts Lists</li>
                                                <li><i class="fas fa-tools text-primary"></i> Technical Guides</li>
                                                <li><i class="fas fa-barcode text-primary"></i> Chassis Validation</li>
                                            </ul>
                                            <a href="reference-library.php" class="btn btn-primary">
                                                <i class="fas fa-arrow-right"></i> View Reference Library
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 mb-4">
                                    <div class="card border-success">
                                        <div class="card-header bg-success text-white">
                                            <h5 class="mb-0">
                                                <i class="fas fa-book-open"></i> Car Stories
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <p class="card-text">
                                                Individual car histories, owner stories, and community articles. 
                                                Discover the unique tales behind registry vehicles.
                                            </p>
                                            <ul class="list-unstyled">
                                                <li><i class="fas fa-history text-success"></i> Individual Car Histories</li>
                                                <li><i class="fas fa-users text-success"></i> Owner Stories</li>
                                                <li><i class="fas fa-newspaper text-success"></i> Magazine Articles</li>
                                                <li><i class="fas fa-archive text-success"></i> Historical Archives</li>
                                            </ul>
                                            <a href="car-stories.php" class="btn btn-success">
                                                <i class="fas fa-arrow-right"></i> Read Car Stories
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Quick Access Links -->
                            <div class="mt-4">
                                <h5>Quick Access</h5>
                                <div class="btn-group-vertical btn-group-sm w-100" role="group">
                                    <a href="chassis-validation.php" class="btn btn-outline-secondary text-left">
                                        <i class="fas fa-barcode"></i> Chassis Validation Rules
                                    </a>
                                    <a href="reference-library.php" class="btn btn-outline-secondary text-left">
                                        <i class="fas fa-file-pdf"></i> Workshop Manual (Elan 26/36)
                                    </a>
                                    <a href="car-stories.php" class="btn btn-outline-secondary text-left">
                                        <i class="fas fa-external-link-alt"></i> SGO 2F Story
                                    </a>
                                </div>
                            </div>

                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->
            </div> <!-- row -->
    </div><!-- Container -->
</div><!-- page -->

<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; ?>
