<?php

/**
 * Documents Index - Redirect Page
 *
 * The documents section has been reorganized into focused pages.
 * This page provides navigation to the new sections.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$portalCards = [
    [
        'title'       => 'Reference Library',
        'icon'        => 'fa-book',
        'url'         => 'reference-library.php',
        'buttonText'  => 'View Reference Library',
        'buttonIcon'  => 'fa-arrow-right',
        'headerClass' => 'bg-primary text-white',
        'buttonClass' => 'btn-primary',
        'cardClass'   => 'border-primary',
        'description' => 'Technical documentation, workshop manuals, parts lists, and owner guides. Everything you need for maintenance and restoration.',
        'listItems'   => [
            ['icon' => 'fa-wrench text-primary',  'text' => 'Workshop Manuals'],
            ['icon' => 'fa-cogs text-primary',    'text' => 'Parts Lists'],
            ['icon' => 'fa-tools text-primary',   'text' => 'Technical Guides'],
            ['icon' => 'fa-barcode text-primary', 'text' => 'Chassis Validation'],
        ],
    ],
    [
        'title'       => 'Car Stories',
        'icon'        => 'fa-book-open',
        'url'         => 'car-stories.php',
        'buttonText'  => 'Read Car Stories',
        'buttonIcon'  => 'fa-arrow-right',
        'headerClass' => 'bg-success text-white',
        'buttonClass' => 'btn-success',
        'cardClass'   => 'border-success',
        'description' => 'Individual car histories, owner stories, and community articles. Discover the unique tales behind registry vehicles.',
        'listItems'   => [
            ['icon' => 'fa-history text-success',   'text' => 'Individual Car Histories'],
            ['icon' => 'fa-users text-success',     'text' => 'Owner Stories'],
            ['icon' => 'fa-newspaper text-success', 'text' => 'Magazine Articles'],
            ['icon' => 'fa-archive text-success',   'text' => 'Historical Archives'],
        ],
    ],
];

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

                            <?= DocumentPortalTemplate::renderDocumentCardGrid($portalCards, 'col-md-6') ?>

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
