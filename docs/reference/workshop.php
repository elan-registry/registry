<?php

declare(strict_types=1);

/**
 * Workshop & Parts Reference - Lotus Elan Registry
 *
 * Workshop manuals, parts lists, and engine reference documents.
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
        'title'           => 'Workshop Manual',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'bg-info text-white',
        'cardImage'       => $us_url_root . 'docs/reference/assets/Elan_26_36_Workshop_Manual.png',
        'cardImageAlt'    => 'Elan Workshop Manual',
        'description'     => 'Elan Workshop Manual',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('Elan_26_36_Workshop_Manual.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-info btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('Elan_26_36_Workshop_Manual.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-success btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'Parts List',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'bg-info text-white',
        'cardImage'       => $us_url_root . 'docs/reference/assets/Elan_S1_S2_Coupe_Masterpartslist.png',
        'cardImageAlt'    => 'Elan Parts List',
        'description'     => '1966 Parts list for Series 1, Series 2 and Coupe',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('Elan_S1_S2_Coupe_Masterpartslist.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-info btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('Elan_S1_S2_Coupe_Masterpartslist.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-success btn-sm',
            'download' => true,
        ],
    ],
    [
        'title'           => 'Engine Types',
        'icon'            => 'fa-file-pdf',
        'headerClass'     => 'bg-info text-white',
        'cardImage'       => $us_url_root . 'docs/reference/assets/' . rawurlencode('2016 Jan Elan Engine Types.png'),
        'cardImageAlt'    => 'Elan Engine Types',
        'description'     => 'CLUB LOTUS ELAN — Elan & +2 Engine Types',
        'url'             => $us_url_root . 'docs/pdf-viewer.php?subdir=reference&doc=' . rawurlencode('2016 Jan Elan Engine Types.pdf'),
        'buttonText'      => 'Read Online',
        'buttonIcon'      => 'fa-eye',
        'buttonClass'     => 'btn-outline-info btn-sm',
        'buttonTarget'    => '_blank',
        'secondaryButton' => [
            'url'      => $us_url_root . 'docs/reference/assets/' . rawurlencode('2016 Jan Elan Engine Types.pdf'),
            'text'     => 'Download PDF',
            'icon'     => 'fa-download',
            'class'    => 'btn-success btn-sm',
            'download' => true,
        ],
    ],
];

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Workshop & Parts',
            'titleIcon'   => 'fa-wrench',
            'description' => 'Maintenance manuals and parts references for Lotus Elan owners',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
