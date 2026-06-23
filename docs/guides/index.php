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
        'title'       => 'Transfer FAQ',
        'icon'        => 'fa-question',
        'url'         => 'car-transfer-faq.php',
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
        <?= DocumentPortalTemplate::renderBreadcrumb('guides', $us_url_root) ?>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Owner Guides',
            'titleIcon'   => 'fa-question-circle',
            'description' => 'Documentation and guides for using the Lotus Elan Registry',
        ]) ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($cards) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
