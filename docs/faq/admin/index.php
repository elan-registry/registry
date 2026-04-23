<?php
/**
 * Administrative Documentation - Lotus Elan Registry
 *
 * Requires administrator privileges to access.
 *
 * @package ElanRegistry
 */

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

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
        'url'         => '../../view.php?doc=CAR_TRANSFER_ADMIN_GUIDE.md',
        'buttonText'  => 'Read Guide',
        'buttonIcon'  => 'fa-book-open',
        'headerClass' => 'bg-primary text-white',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Comprehensive guide for managing car ownership transfer requests, handling disputes, and system administration.',
        'metadata'    => '8,000+ words • Complete procedures',
    ],
    [
        'title'       => 'Quick Reference',
        'icon'        => 'fa-tachometer-alt',
        'url'         => '../../view.php?doc=CAR_TRANSFER_ADMIN_QUICK_REFERENCE.md',
        'buttonText'  => 'Quick Access',
        'buttonIcon'  => 'fa-lightning-bolt',
        'headerClass' => 'bg-success text-white',
        'buttonClass' => 'btn-success btn-sm',
        'description' => 'Daily admin tasks, decision trees, emergency procedures, and quick fixes for common transfer issues.',
        'metadata'    => 'Print-friendly • Mobile-accessible',
    ],
    [
        'title'       => 'Troubleshooting',
        'icon'        => 'fa-wrench',
        'url'         => '../../view.php?doc=CAR_TRANSFER_TROUBLESHOOTING.md',
        'buttonText'  => 'Troubleshoot',
        'buttonIcon'  => 'fa-tools',
        'headerClass' => 'bg-warning text-dark',
        'buttonClass' => 'btn-warning btn-sm',
        'description' => 'Systematic diagnostic procedures, problem classification, and resolution strategies for transfer system issues.',
        'metadata'    => '4-level classification • Step-by-step fixes',
    ],
];

$technicalCards = [
    [
        'title'       => 'Database Schema',
        'icon'        => 'fa-database',
        'url'         => '../../view.php?doc=development/DATABASE.md',
        'buttonText'  => 'View Schema',
        'buttonIcon'  => 'fa-table',
        'headerClass' => 'bg-info text-white',
        'buttonClass' => 'btn-info btn-sm',
        'description' => 'Complete database documentation including table relationships, indexes, and data integrity constraints.',
        'metadata'    => 'Schema diagrams • Relationship mapping',
    ],
    [
        'title'       => 'Product Requirements',
        'icon'        => 'fa-file-contract',
        'url'         => '../../view.php?doc=PRD.md',
        'buttonText'  => 'View PRD',
        'buttonIcon'  => 'fa-clipboard-list',
        'headerClass' => 'bg-dark text-white',
        'buttonClass' => 'btn-dark btn-sm',
        'description' => 'Product Requirements Document (PRD) with feature specifications, business requirements, and system architecture.',
        'metadata'    => 'Business logic • Feature specs',
    ],
    [
        'title'       => 'Email Guidelines',
        'icon'        => 'fa-envelope',
        'url'         => '../../view.php?doc=EMAIL_STYLING_GUIDELINES.md',
        'buttonText'  => 'View Guidelines',
        'buttonIcon'  => 'fa-palette',
        'headerClass' => 'bg-secondary text-white',
        'buttonClass' => 'btn-secondary btn-sm',
        'description' => 'Email template styling standards, guidelines for notifications, and best practices for user communication.',
        'metadata'    => 'Template standards • Communication guidelines',
    ],
];

$sysAdminCards = [
    [
        'title'       => 'Spam Cleanup System',
        'icon'        => 'fa-broom',
        'url'         => '../../view.php?doc=SPAM_CLEANUP_SYSTEM.md',
        'buttonText'  => 'View System',
        'buttonIcon'  => 'fa-shield-alt',
        'headerClass' => 'bg-danger text-white',
        'buttonClass' => 'btn-danger btn-sm',
        'description' => 'Automated user cleanup system documentation including criteria, processes, and safety measures for spam account removal.',
        'metadata'    => 'Automated cleanup • Safety protocols',
        'colClass'    => 'col-lg-6',
    ],
    [
        'title'       => 'Administrative Tools',
        'icon'        => 'fa-cogs',
        'url'         => $us_url_root . 'app/admin/manage-consolidated.php',
        'buttonText'  => 'Open Admin Panel',
        'buttonIcon'  => 'fa-external-link-alt',
        'headerClass' => 'bg-primary text-white',
        'buttonClass' => 'btn-primary btn-sm',
        'description' => 'Access to the main administrative interface for managing cars, users, and system operations.',
        'colClass'    => 'col-lg-6',
        'listItems'   => [
            ['icon' => 'fa-check text-success', 'text' => 'Car/Owner Management'],
            ['icon' => 'fa-check text-success', 'text' => 'Transfer Administration'],
            ['icon' => 'fa-check text-success', 'text' => 'Data Quality Monitoring'],
            ['icon' => 'fa-check text-success', 'text' => 'System Reports'],
        ],
    ],
];

$navLinks = [
    ['label' => 'Registry Home',  'url' => $us_url_root,                    'icon' => 'fa-home',            'btnClass' => 'btn-outline-primary'],
    ['label' => 'User FAQ',       'url' => '../index.php',                   'icon' => 'fa-question-circle', 'btnClass' => 'btn-outline-info'],
    ['label' => 'Admin Dashboard', 'url' => $us_url_root . 'app/admin/',     'icon' => 'fa-tools',           'btnClass' => 'btn-outline-success'],
];

?>
<div class="page-wrapper">
    <div class='container'>
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Administrative Documentation',
            'titleIcon'   => 'fa-tools',
            'description' => 'Technical guides and administrative procedures for registry management',
            'headerClass' => 'bg-danger text-white',
            'isAdmin'     => true,
        ]) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-user-shield', 'Car Transfer Administration', 'text-primary') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($carTransferCards) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-code', 'Technical Documentation', 'text-info') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($technicalCards) ?>

        <?= DocumentPortalTemplate::renderSectionHeading('fa-server', 'System Administration', 'text-danger') ?>
        <?= DocumentPortalTemplate::renderDocumentCardGrid($sysAdminCards) ?>

        <?= DocumentPortalTemplate::renderNavFooter($navLinks) ?>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
