<?php

declare(strict_types=1);

/**
 * Car Stories Page
 *
 * Individual car histories, owner stories, and registry articles about specific vehicles.
 * Features stories from the community and historical documentation.
 *
 * @package ElanRegistry
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$storiesBase = $us_url_root . 'docs/stories/';

$storyCards = [
    [
        'title'        => 'The Story of SGO 2F',
        'icon'         => 'fa-car',
        'url'          => $storiesBase . 'SGO_2F/index.php',
        'buttonText'   => 'Read Story',
        'buttonIcon'   => 'fa-book-open',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => 'A detailed history of this remarkable Elan.',
        'metadata'     => 'Registry ID: 50/0164',
        'cardImage'    => $storiesBase . 'SGO_2F/photos/SGO_2F_Nepal_Orange.jpg',
        'cardImageAlt' => 'SGO 2F in Nepal Orange',
    ],
    [
        'title'        => 'Elan Experimental Rally Car',
        'icon'         => 'fa-flag-checkered',
        'url'          => $storiesBase . 'brian_walton/index.php',
        'buttonText'   => 'Read Story',
        'buttonIcon'   => 'fa-book-open',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => "The fascinating story of Brian Walton's rally-prepared Elan.",
        'metadata'     => 'Registry ID: 36/6086',
        'cardImage'    => $storiesBase . 'brian_walton/brian_walton_s3rally_1.jpg',
        'cardImageAlt' => "Brian Walton's Elan S3 rally car",
    ],
    [
        'title'        => 'Shapecraft Elan Story',
        'icon'         => 'fa-file-pdf',
        'url'          => $us_url_root . 'docs/pdf-viewer.php?subdir=stories&doc=' . rawurlencode('Mag _issue_50_p12-15_Barry-Shapecraft.pdf'),
        'buttonText'   => 'Read Article',
        'buttonIcon'   => 'fa-book-open',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => 'Featured in Historic Lotus Racing Magazine, No. 50, Spring 2022.',
        'metadata'     => 'Registry ID: 26/4992',
        'cardImage'    => $us_url_root . 'docs/stories/assets/' . rawurlencode('Mag _issue_50_p12-15_Barry-Shapecraft.png'),
        'cardImageAlt' => 'Shapecraft Elan Story — Historic Lotus Racing Magazine',
    ],
];

$archiveCards = [
    [
        'title'       => 'Type26Register.com Archive',
        'icon'        => 'fa-history',
        'url'         => $storiesBase . 'type26register.php',
        'buttonText'  => 'Browse Archive',
        'buttonIcon'  => 'fa-folder-open',
        'headerClass' => 'card-header-er-dark',
        'buttonClass' => 'btn-secondary btn-sm',
        'description' => 'An incomplete archive of type26register.com retrieved from the Wayback Machine, preserving valuable historical information about Type 26 Elans as of July 2010.',
    ],
];

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Car Stories',
            'titleIcon'   => 'fa-book-open',
            'description' => 'Individual car histories, owner stories, and community articles',
        ]) ?>

        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-success">
                    <i class="fas fa-pen-alt"></i>
                    <strong>Share Your Story:</strong>
                    Have a story about your Elan? We'd love to feature it here!
                    <a href="<?= $us_url_root ?>app/contact/" class="alert-link">Contact us</a>
                    to share your car's unique history.
                </div>
            </div>
        </div>

        <?= DocumentPortalTemplate::renderDocumentCardGrid($storyCards) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-archive', 'Historical Archives') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($archiveCards, 'col-lg-6') ?>

    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
