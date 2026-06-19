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
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$externalCards = [
    [
        'title'        => 'The Lotus Elan Sprint',
        'icon'         => 'fa-globe',
        'url'          => 'https://www.lotuselansprint.com/',
        'buttonText'   => 'Visit',
        'buttonTarget' => '_blank',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => 'This site is dedicated to the Lotus Elan Sprint, the final iteration of the Lotus Elan.',
    ],
    [
        'title'        => 'LotusElan.Net',
        'icon'         => 'fa-users',
        'url'          => 'https://www.lotuselan.net/',
        'buttonText'   => 'Visit',
        'buttonTarget' => '_blank',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => 'A great online community for the Lotus Elan.',
    ],
    [
        'title'       => 'Type 26 Registry',
        'icon'        => 'fa-history',
        'url'         => '../stories/type26register.php',
        'buttonText'  => 'Browse Archive',
        'headerClass' => 'card-header-er-dark',
        'buttonClass' => 'btn-secondary btn-sm',
        'description' => 'An incomplete archive of type26register.com retrieved from the Wayback Machine, preserving historical information about Type 26 Elans as of July 2010.',
    ],
    [
        'title'        => 'Elan Registry on GitHub',
        'icon'         => 'fa-code-branch',
        'url'          => 'https://github.com/unibrain1/elanregistry',
        'buttonText'   => 'View',
        'buttonTarget' => '_blank',
        'headerClass'  => 'card-header-er-primary',
        'buttonClass'  => 'btn-primary btn-sm',
        'description'  => 'The open source code behind this registry. Contributions and bug reports welcome.',
    ],
];

$cards = [
    [
        'title'       => 'Workshop & Parts',
        'icon'        => 'fa-wrench',
        'url'         => 'workshop.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Workshop manual, parts lists, and engine reference documents for Elan maintenance and restoration.',
    ],
    [
        'title'       => 'Technical Articles',
        'icon'        => 'fa-file-alt',
        'url'         => 'technical-articles.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Club Lotus technical articles covering gearknobs, steering wheels, engine types, and more.',
    ],
    [
        'title'       => 'Chassis Validation Rules',
        'icon'        => 'fa-barcode',
        'url'         => 'chassis-validation.php',
        'buttonText'  => 'View Rules',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Complete chassis number validation rules for all Elan and Plus 2 models, including pre-1970 and post-1970 formats.',
    ],
    [
        'title'       => 'Paint Colors',
        'icon'        => 'fa-palette',
        'url'         => 'paint-colors.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Factory paint colors, codes, date ranges, and supplier cross-references for all Elan and Plus 2 models.',
    ],
];

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Technical Reference',
            'description' => 'Manuals, technical articles, and identification resources for Lotus Elan owners',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards) ?>
        <?= DocumentPortalTemplate::renderSectionHeading('fa-external-link-alt', 'External Resources') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($externalCards, 'col-lg-3 col-md-6') ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
