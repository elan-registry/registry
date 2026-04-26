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
        'headerClass' => 'bg-info text-white',
        'buttonClass' => 'btn-info btn-sm',
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
    [
        'title'       => 'Chassis Validation Rules',
        'icon'        => 'fa-barcode',
        'url'         => '../chassis-validation.php',
        'buttonText'  => 'View Rules',
        'headerClass' => 'bg-info text-white',
        'buttonClass' => 'btn-info btn-sm',
        'description' => 'Complete chassis number validation rules for all Elan and Plus 2 models, including pre-1970 and post-1970 formats.',
    ],
    [
        'title'       => 'Paint Colors',
        'icon'        => 'fa-palette',
        'url'         => 'paint-colors.php',
        'buttonText'  => 'Browse',
        'headerClass' => 'bg-info text-white',
        'buttonClass' => 'btn-info btn-sm',
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
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
