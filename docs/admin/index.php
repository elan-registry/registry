<?php

declare(strict_types=1);

/**
 * Administrative Documentation - Lotus Elan Registry
 *
 * Requires administrator privileges to access.
 *
 * @package ElanRegistry
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

// Additional admin check (will be enforced by UserSpice page management in production)
if (!$user->isLoggedIn() || !hasPerm([2], $user->data()->id)) {
    Redirect::to($us_url_root . 'users/login.php');
}

$carTransferCards = [
    [
        'title'       => 'Administrator Guide',
        'icon'        => 'fa-book',
        'url'         => '../guide-viewer.php?doc=CAR_TRANSFER_ADMIN_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'description' => 'Comprehensive guide for managing car ownership transfer requests, handling disputes, and system administration.',
        // 'metadata'    => '8,000+ words • Complete procedures',
    ],
    [
        'title'       => 'Quick Reference',
        'icon'        => 'fa-tachometer-alt',
        'url'         => '../guide-viewer.php?doc=CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md',
        'buttonText'  => 'Quick Access',
        'buttonIcon'  => 'fa-lightning-bolt',
        'description' => 'Daily admin tasks, decision trees, emergency procedures, and quick fixes for common transfer issues.',
        // 'metadata'    => 'Print-friendly • Mobile-accessible',
    ],
    [
        'title'       => 'Troubleshooting',
        'icon'        => 'fa-wrench',
        'url'         => '../guide-viewer.php?doc=CAR_TRANSFER_TROUBLESHOOTING.md',
        'buttonText'  => 'Troubleshoot',
        'buttonIcon'  => 'fa-tools',
        'description' => 'Systematic diagnostic procedures, problem classification, and resolution strategies for transfer system issues.',
        // 'metadata'    => '4-level classification • Step-by-step fixes',
    ],
];

$technicalCards = [
    [
        'title'       => 'Email Guidelines',
        'icon'        => 'fa-envelope',
        'url'         => '../guide-viewer.php?doc=EMAIL_STYLING_GUIDELINES.md',
        'buttonText'  => 'View Guidelines',
        'buttonIcon'  => 'fa-palette',
        'description' => 'Email template styling standards, guidelines for notifications, and best practices for user communication.',
        // 'metadata'    => 'Template standards • Communication guidelines',
    ],
];

$sysAdminCards = [
    [
        'title'       => 'Spam Cleanup System',
        'icon'        => 'fa-broom',
        'url'         => '../guide-viewer.php?doc=SPAM_CLEANUP_SYSTEM.md',
        'buttonText'  => 'View System',
        'buttonIcon'  => 'fa-shield-alt',
        'description' => 'Automated user cleanup system documentation including criteria, processes, and safety measures for spam account removal.',
        // 'metadata'    => 'Automated cleanup • Safety protocols',
        'colClass'    => 'col-lg-6',
    ],
    [
        'title'       => 'Administrative Tools',
        'icon'        => 'fa-cogs',
        'url'         => $us_url_root . 'app/admin/manage-consolidated.php',
        'buttonText'  => 'Open Admin Panel',
        'buttonIcon'  => 'fa-external-link-alt',
        'description' => 'Access to the main administrative interface for managing cars, users, and system operations.',
        'colClass'    => 'col-lg-6',
        'listItems'   => [
            ['icon' => 'fa-check text-primary', 'text' => 'Car/Owner Management'],
            ['icon' => 'fa-check text-primary', 'text' => 'Transfer Administration'],
            ['icon' => 'fa-check text-primary', 'text' => 'Data Quality Monitoring'],
            ['icon' => 'fa-check text-primary', 'text' => 'System Reports'],
        ],
    ],
];

$navLinks = [
    ['label' => 'Registry Home',  'url' => $us_url_root,                    'icon' => 'fa-home',            'btnClass' => 'btn-outline-primary'],
    ['label' => 'User FAQ',       'url' => '../guides/index.php',            'icon' => 'fa-question-circle', 'btnClass' => 'btn-outline-primary'],
    ['label' => 'Admin Dashboard', 'url' => $us_url_root . 'app/admin/',     'icon' => 'fa-tools',           'btnClass' => 'btn-outline-primary'],
];

?>
<div class="page-wrapper">
    <div class='container'>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Administrative Documentation',
            'titleIcon'   => 'fa-tools',
            'description' => 'Technical guides and administrative procedures for registry management',
            'headerClass' => 'card-header-er-dark',
            'isAdmin'     => true,
        ]) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-user-shield', 'Car Transfer Administration', 'text-primary') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($carTransferCards) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-code', 'Technical Documentation', 'text-primary') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($technicalCards) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-server', 'System Administration', 'text-danger') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($sysAdminCards) ?>

        <?= DocumentPortalTemplate::renderNavFooter($navLinks) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
