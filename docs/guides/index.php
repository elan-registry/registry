<?php

declare(strict_types=1);

/**
 * Owner Guides - Lotus Elan Registry
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
        'title'       => 'How to Add Your Car',
        'icon'        => 'fa-car',
        'url'         => '../view.php?doc=ADD_CAR_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'bg-success text-white',
        'buttonClass' => 'btn-success btn-sm',
        'description' => "Step-by-step guide to registering your Lotus Elan or +2. Learn about chassis validation, photo uploads, and completing your car's profile.",
    ],
    [
        'title'       => 'Car Transfer Guide',
        'icon'        => 'fa-exchange-alt',
        'url'         => '../view.php?doc=CAR_TRANSFER_USER_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'bg-primary text-white',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Complete guide for requesting ownership transfers of cars in the registry. Learn the step-by-step process and what to expect.',
    ],
    [
        'title'       => 'Transfer FAQ',
        'icon'        => 'fa-question',
        'url'         => '../view.php?doc=CAR_TRANSFER_FAQ.md',
        'buttonText'  => 'View FAQ',
        'buttonIcon'  => 'fa-question-circle',
        'headerClass' => 'bg-info text-white',
        'buttonClass' => 'btn-info btn-sm',
        'description' => 'Frequently asked questions about car ownership transfers, common issues, and quick solutions.',
    ],
    [
        'title'       => 'Paint Colors Guide',
        'icon'        => 'fa-palette',
        'url'         => '../reference/paint-colors.php',
        'buttonText'  => 'View Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'bg-danger text-white',
        'headerStyle' => 'background-color: #8e44ad !important;',
        'buttonClass' => 'btn-sm',
        'buttonStyle' => 'background-color: #8e44ad; color: white;',
        'description' => 'Complete reference to Lotus Elan and Plus 2 factory paint colors, codes, date ranges, and supplier cross-references for restoration.',
    ],
    [
        'title'       => 'Privacy Policy',
        'icon'        => 'fa-shield-alt',
        'url'         => '../view.php?doc=PRIVACY.md',
        'buttonText'  => 'Read Policy',
        'buttonIcon'  => 'fa-file-alt',
        'headerClass' => 'bg-dark text-white',
        'buttonClass' => 'btn-dark btn-sm',
        'description' => 'Our privacy policy explaining how we collect, use, and protect your personal information in the registry.',
    ],
];

// Build nav links — conditional on login state
$navLinks = [
    ['label' => 'Registry Home', 'url' => $us_url_root,               'icon' => 'fa-home', 'btnClass' => 'btn-outline-primary'],
    ['label' => 'Browse Cars',   'url' => $us_url_root . 'app/cars/', 'icon' => 'fa-car',  'btnClass' => 'btn-outline-success'],
];

if ($user->isLoggedIn()) {
    $navLinks[] = ['label' => 'My Account', 'url' => $us_url_root . 'users/account.php', 'icon' => 'fa-user',        'btnClass' => 'btn-outline-info'];
} else {
    $navLinks[] = ['label' => 'Login',      'url' => $us_url_root . 'users/login.php',   'icon' => 'fa-sign-in-alt', 'btnClass' => 'btn-outline-warning'];
}

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
        <?= DocumentPortalTemplate::renderNavFooter($navLinks) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
