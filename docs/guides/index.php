<?php

declare(strict_types=1);

/**
 * Owner Guides - Lotus Elan Registry
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
        'title'       => 'How to Add Your Car',
        'icon'        => 'fa-car',
        'url'         => '../guide-viewer.php?doc=ADD_CAR_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => "Step-by-step guide to registering your Lotus Elan or +2. Learn about chassis validation, photo uploads, and completing your car's profile.",
    ],
    [
        'title'       => 'Car Transfer Guide',
        'icon'        => 'fa-exchange-alt',
        'url'         => '../guide-viewer.php?doc=CAR_TRANSFER_USER_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Complete guide for requesting ownership transfers of cars in the registry. Learn the step-by-step process and what to expect.',
    ],
    [
        'title'       => 'Transfer FAQ',
        'icon'        => 'fa-question',
        'url'         => '../guide-viewer.php?doc=CAR_TRANSFER_FAQ.md',
        'buttonText'  => 'View FAQ',
        'buttonIcon'  => 'fa-question-circle',
        'headerClass' => 'card-header-er-primary',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Frequently asked questions about car ownership transfers, common issues, and quick solutions.',
    ],
];

?>
<div class="page-wrapper">
    <div class='container'>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Owner Guides',
            'titleIcon'   => 'fa-question-circle',
            'description' => 'Documentation and guides for using the Lotus Elan Registry',
            'leadText'    => 'How-to guides and policies for using the Lotus Elan Registry.',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
