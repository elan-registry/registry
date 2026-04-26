<?php

declare(strict_types=1);

/**
 * Technical Reference Index - Lotus Elan Registry
 *
 * Landing page for workshop manuals, parts lists, and technical articles.
 *
 * @package ElanRegistry
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$cards = [
    [
        'title'       => 'Workshop & Parts',
        'icon'        => 'fa-wrench',
        'url'         => 'workshop.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'bg-primary text-white',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Workshop manual, parts lists, and engine reference documents for Elan maintenance and restoration.',
    ],
    [
        'title'       => 'Technical Articles',
        'icon'        => 'fa-file-alt',
        'url'         => 'technical-articles.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'bg-success text-white',
        'buttonClass' => 'btn-success btn-sm',
        'description' => 'Club Lotus technical articles covering gearknobs, steering wheels, engine types, and more.',
    ],
];

$navLinks = [
    ['label' => 'Registry Home',  'url' => $us_url_root,                              'icon' => 'fa-home',      'btnClass' => 'btn-outline-primary'],
    ['label' => 'Car Stories',    'url' => $us_url_root . 'docs/car-stories.php',      'icon' => 'fa-book-open', 'btnClass' => 'btn-outline-success'],
    ['label' => 'Owner Guides',   'url' => $us_url_root . 'docs/guides/index.php',     'icon' => 'fa-user',      'btnClass' => 'btn-outline-info'],
];

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Technical Reference',
            'description' => 'Manuals, technical articles, and identification resources for Lotus Elan owners',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards, 'col-md-6') ?>
        <?= DocumentPortalTemplate::renderNavFooter($navLinks) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
