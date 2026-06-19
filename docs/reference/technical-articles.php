<?php

declare(strict_types=1);

/**
 * Technical Articles - Lotus Elan Registry
 *
 * Club Lotus technical articles and historical references.
 *
 * @package ElanRegistry
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

$cards = [
    [
        'title'           => 'Engine Number Breakdown',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'card-header-er-primary',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('Engine number breakdown (Miles Wilkins).png'),
        'cardImageAlt'    => 'Engine Number Breakdown',
        'description'     => 'Identifying engine types from number sequences',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('Engine number breakdown (Miles Wilkins).pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-primary btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('Engine number breakdown (Miles Wilkins).pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-secondary btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'Elan Gearknobs',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'card-header-er-primary',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('2014 Jul Elan Gearknobs.png'),
        'cardImageAlt'    => 'Elan Gearknobs',
        'description'     => 'A description of the various types of gear knobs available on the Elan and Elan +2',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('2014 Jul Elan Gearknobs.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-primary btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('2014 Jul Elan Gearknobs.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-secondary btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'Steering Wheels',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'card-header-er-primary',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('2014 Oct Elan and Plus 2 Steering Wheels.png'),
        'cardImageAlt'    => 'Elan and Plus 2 Steering Wheels',
        'description'     => 'A description of the various types of steering wheels available on the Elan and Elan +2',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('2014 Oct Elan and Plus 2 Steering Wheels.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-primary btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('2014 Oct Elan and Plus 2 Steering Wheels.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-secondary btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'The Elan Super Safety',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'card-header-er-primary',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('2019_Jan_The_Elan_Super_Safety.png'),
        'cardImageAlt'    => 'The Elan Super Safety',
        'description'     => 'A description of the Elan Super Safety',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('2019_Jan_The_Elan_Super_Safety.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-primary btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('2019_Jan_The_Elan_Super_Safety.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-secondary btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'Plus 2 Serial Numbers',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'card-header-er-primary',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('Lotus Elan Plus 2 serial numbers.png'),
        'cardImageAlt'    => 'Plus 2 Serial Numbers',
        'description'     => 'Serial number sequences for the Lotus Elan Plus 2',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('Lotus Elan Plus 2 serial numbers.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-primary btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('Lotus Elan Plus 2 serial numbers.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-secondary btn-sm',
            'download' => true,
        ],
    ],
];

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderBreadcrumb('reference', $us_url_root, 'Technical Articles', 'fa-file-alt') ?>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Technical Articles',
            'titleIcon'   => 'fa-file-alt',
            'description' => 'Club Lotus technical articles and historical references',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
