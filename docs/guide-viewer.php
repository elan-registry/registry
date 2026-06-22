<?php

declare(strict_types=1);

/**
 * Universal Document Viewer - Documentation System
 *
 * Displays markdown documents in a formatted view with proper permissions.
 * Handles both user-facing and administrative documentation.
 *
 * @package ElanRegistry
 * @version 2.9.0
 * @author Jim Boone
 */

require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/DocumentConfig.php';

// Hint for the nav active-state matcher (see usersc/templates/customizer/file_nav_custom.php).
// Must be set before elanregistry_prep.php, which triggers the nav render. Falls back to
// 'guides' if the doc is unknown — the request will fail validation below regardless.
$nav_section = (\ElanRegistry\Documentation\DocumentConfig::validateDocument($_GET['doc'] ?? '')['category'] ?? 'guides') === 'admin'
    ? 'admin'
    : 'guides';

require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;
use ElanRegistry\Documentation\MarkdownRenderer;
use ElanRegistry\Documentation\DocumentConfig;

// Security check - ensure page access is authorized
if (!securePage($php_self)) {
    logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Unauthorized documentation access attempt: ' . $php_self);
    Redirect::to($us_url_root . '403.php');
}

// Get and validate the document parameter with strict validation
$doc = $_GET['doc'] ?? '';

// Security: Only allow alphanumeric, hyphens, underscores, and .md extension
if (!preg_match('/^[a-zA-Z0-9_-]+\.md$/', $doc)) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Invalid document format attempted: ' . $doc);
    Redirect::to($us_url_root . '404.php');
}

// Validate document and get configuration
$documentData = DocumentConfig::validateDocument($doc);
if (!$documentData) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Documentation not found: ' . $doc);
    Redirect::to($us_url_root . '404.php');
}

// Check user permissions
if (!DocumentConfig::hasAccess($documentData, $user)) {
    Redirect::to($us_url_root . 'users/login.php');
}

// Verify file exists and is readable
if (!file_exists($documentData['path']) || !is_readable($documentData['path'])) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Documentation file not accessible: ' . $documentData['path']);
    Redirect::to($us_url_root . '404.php');
}

// Read the markdown content with error handling
$markdownContent = '';
try {
    $markdownContent = file_get_contents($documentData['path']);
    if ($markdownContent === false) {
        throw new DocumentationException('Failed to read document file');
    }
} catch (DocumentationException $e) {
    logger($user->data()->id ?? 0, $e->getLogCategory(), 'Documentation read error: ' . $e->getMessage());
    Redirect::to($us_url_root . '404.php');
}

// Convert markdown to HTML using the utility class
try {
    $htmlContent = MarkdownRenderer::convert($markdownContent, $us_url_root);
} catch (\RuntimeException $e) {
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'Markdown rendering failed for "' . $doc . '": ' . $e->getMessage());
    Redirect::to($us_url_root . '500.php');
}

// Get document information and navigation
$info = $documentData['info'];
$isAdmin    = ($documentData['category'] === 'admin');
$accentColor = $isAdmin ? 'var(--er-danger)' : 'var(--er-primary)';
$breadcrumb = DocumentConfig::getBreadcrumb($documentData, $us_url_root);

?>
<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>
        <?= DocumentPortalTemplate::renderBreadcrumbFromItems($breadcrumb) ?>

        <?php if ($isAdmin) { ?>
        <!-- Admin Warning -->
        <div class='row mb-3'>
            <div class='col-12'>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Administrator Documentation</strong><br>
                    This content is intended for registry administrators and technical staff only.
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- Document Header -->
        <div class='row'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-header <?= $isAdmin ? 'card-header-er-dark' : 'card-header-er-primary' ?>'>
                        <h1 class='mb-0 card-header-er-primary-text'><i class='<?= $info['icon'] ?>'></i> <?= htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class='card-header-er-primary-text mb-0'><?= htmlspecialchars($info['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Document Content -->
        <div class='row mt-4'>
            <div class='col-12'>
                <div class='card registry-card'>
                    <div class='card-body'>
                        <div class="document-content">
                            <?= $htmlContent ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Navigation Footer -->
        <div class='row mt-4 mb-4'>
            <div class='col-12 text-center'>
                <?php if ($isAdmin) { ?>
                    <a href='admin/index.php' class='btn btn-outline-primary me-2'><i class='fas fa-arrow-left'></i> Back to Admin Docs</a>
                    <a href='<?= $us_url_root ?>app/admin/manage-consolidated.php' class='btn btn-outline-primary me-2'><i class='fas fa-tools'></i> Admin Panel</a>
                <?php } else { ?>
                    <a href='guides/index.php' class='btn btn-outline-primary me-2'><i class='fas fa-arrow-left'></i> Back to Owner Guides</a>
                <?php } ?>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-secondary'><i class='fas fa-home'></i> Registry Home</a>
            </div>
        </div>

    </div> <!-- /.container -->
</div><!-- .page-wrapper -->

<style>
.document-content {
    /* Typography inherits from Bootswatch Simplex theme (1rem font-size, 1.5 line-height) */
    /* This ensures consistency with the rest of the site */
}

.document-content img {
    max-width: 100%;
    height: auto;
    margin: 1.5rem 0;
    border: 1px solid #dee2e6;
    border-radius: 0.25rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.document-content h1 {
    color: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>;
    border-bottom: 2px solid <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>;
    padding-bottom: 0.5rem;
    margin-top: 2rem;
    margin-bottom: 1rem;
}

.document-content h2 {
    color: var(--er-neutral-dark);
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 0.3rem;
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}

.document-content h3 {
    color: var(--er-neutral);
    margin-top: 1.25rem;
    margin-bottom: 0.75rem;
}

.document-content h4, .document-content h5 {
    color: var(--er-neutral);
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.document-content ul, .document-content ol {
    margin-bottom: 1rem;
    padding-left: 2rem;
}

.document-content li {
    margin-bottom: 0.25rem;
}

.document-content pre {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    padding: 1rem;
    margin: 1rem 0;
    overflow-x: auto;
}

.document-content code {
    background-color: #f8f9fa;
    color: #e83e8c;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.875em;
}

.document-content pre code {
    background-color: transparent;
    color: inherit;
    padding: 0;
}

.document-content blockquote {
    border-left: 4px solid <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>;
    padding-left: 1rem;
    margin: 1rem 0;
    font-style: italic;
    color: #6c757d;
}

.document-content table {
    width: 100%;
    margin: 1rem 0;
    border-collapse: collapse;
}

.document-content table th,
.document-content table td {
    border: 1px solid #dee2e6;
    padding: 0.75rem;
    text-align: left;
}

.document-content table th {
    background-color: #f8f9fa;
    font-weight: bold;
}

.breadcrumb-item a {
    color: var(--er-primary);
    text-decoration: none;
}

.breadcrumb-item a:hover {
    color: var(--er-primary);
    text-decoration: underline;
}

.breadcrumb-item.active {
    color: var(--er-neutral-dark);
    font-weight: 500;
}

.breadcrumb-item + .breadcrumb-item::before {
    color: #6c757d;
}
</style>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>