<?php

declare(strict_types=1);

/**
 * Documentation Hub - Lotus Elan Registry
 *
 * Top-level documentation landing page organised by user intent.
 *
 * @package ElanRegistry
 */

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$cards = [
    [
        'title'       => 'Technical Reference',
        'icon'        => 'fa-book',
        'url'         => 'reference/index.php',
        'buttonText'  => 'Browse',
        'buttonIcon'  => 'fa-arrow-right',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Workshop manuals, technical articles, identification guides, and paint reference for Lotus Elan owners.',
        'colClass'    => 'col-md-4',
    ],
    [
        'title'       => 'Car Stories',
        'icon'        => 'fa-history',
        'url'         => 'car-stories.php',
        'buttonText'  => 'Browse',
        'buttonIcon'  => 'fa-arrow-right',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Individual car histories, owner stories, and historical archives from the Elan community.',
        'colClass'    => 'col-md-4',
    ],
    [
        'title'       => 'Owner Guides',
        'icon'        => 'fa-question-circle',
        'url'         => 'guides/index.php',
        'buttonText'  => 'Browse',
        'buttonIcon'  => 'fa-arrow-right',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'How to add your car, transfer ownership, and use the registry effectively.',
        'colClass'    => 'col-md-4',
    ],
];

$navLinks = [
    ['label' => 'Registry Home', 'url' => $us_url_root, 'icon' => 'fa-home', 'btnClass' => 'btn-outline-primary'],
    ['label' => 'Browse Cars',   'url' => $us_url_root . 'app/cars/', 'icon' => 'fa-car', 'btnClass' => 'btn-outline-primary'],
];

?>
<div class="page-wrapper">
    <div class='container'>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Documentation',
            'titleIcon'   => 'fa-book-open',
            'description' => 'Lotus Elan knowledge organised by what you need',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards, 'col-md-4') ?>
        <?= DocumentPortalTemplate::renderNavFooter($navLinks) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
