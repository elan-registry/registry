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
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/MarkdownParser.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/DocumentConfig.php';

use ElanRegistry\Documentation\MarkdownParser;
use ElanRegistry\Documentation\DocumentConfig;

// Security check - ensure page access is authorized
if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get and validate the document parameter with strict validation
$doc = $_GET['doc'] ?? '';

// Security: Only allow alphanumeric, hyphens, underscores, and .md extension
if (!preg_match('/^[a-zA-Z0-9_-]+\.md$/', $doc)) {
    header('HTTP/1.0 400 Bad Request');
    die('Invalid document format.');
}

// Validate document and get configuration
$documentData = DocumentConfig::validateDocument($doc);
if (!$documentData) {
    header('HTTP/1.0 404 Not Found');
    die('Document not found.');
}

// Check user permissions
if (!DocumentConfig::hasAccess($documentData, $user)) {
    Redirect::to($us_url_root . 'users/login.php');
}

// Verify file exists and is readable
if (!file_exists($documentData['path']) || !is_readable($documentData['path'])) {
    header('HTTP/1.0 404 Not Found');
    die('Document file not found.');
}

// Read the markdown content with error handling
try {
    $markdownContent = file_get_contents($documentData['path']);
    if ($markdownContent === false) {
        throw new RuntimeException('Failed to read document file');
    }
} catch (Exception $e) {
    logger($user->data()->id ?? 0, 'DocumentError',
        "Document read error: " . $e->getMessage() . " | Path: " . ($documentData['path'] ?? 'unknown'));
    header('HTTP/1.0 500 Internal Server Error');
    die('Error reading document file.');
}

// Convert markdown to HTML using the utility class
$htmlContent = MarkdownParser::toHtml($markdownContent);
$htmlContent = MarkdownParser::sanitizeHtml($htmlContent);

// Get document information and navigation
$info = $documentData['info'];
$isAdmin = ($documentData['category'] === 'admin');
$breadcrumb = DocumentConfig::getBreadcrumb($documentData, $us_url_root);

?>
<div class="page-wrapper">
    <!-- Page Content -->
    <div class='container'>
        <!-- Navigation Breadcrumb -->
        <div class='row mb-3'>
            <div class='col-12'>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <?php foreach ($breadcrumb as $item) { ?>
                            <?php if (isset($item['active']) && $item['active']) { ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8') ?></li>
                            <?php } else { ?>
                                <li class="breadcrumb-item">
                                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if (isset($item['icon'])) { ?><i class="<?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>"></i> <?php } ?>
                                        <?= htmlspecialchars($item['text'], ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                </li>
                            <?php } ?>
                        <?php } ?>
                    </ol>
                </nav>
            </div>
        </div>

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
                    <div class='card-header <?= $isAdmin ? 'bg-danger' : 'bg-primary' ?> text-white'>
                        <h1 class='mb-0'><i class='<?= $info['icon'] ?>'></i> <?= htmlspecialchars($info['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class='text-light mb-0'><?= htmlspecialchars($info['description'], ENT_QUOTES, 'UTF-8') ?></p>
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
                    <a href='faq/admin/index.php' class='btn btn-outline-primary mr-2'><i class='fas fa-arrow-left'></i> Back to Admin Docs</a>
                    <a href='<?= $us_url_root ?>app/admin/manage-consolidated.php' class='btn btn-outline-success mr-2'><i class='fas fa-tools'></i> Admin Panel</a>
                <?php } else { ?>
                    <a href='faq/index.php' class='btn btn-outline-primary mr-2'><i class='fas fa-arrow-left'></i> Back to FAQ</a>
                <?php } ?>
                <a href='<?= $us_url_root ?>' class='btn btn-outline-secondary'><i class='fas fa-home'></i> Registry Home</a>
            </div>
        </div>

    </div> <!-- /.container -->
</div><!-- .page-wrapper -->

<style>
.document-content {
    line-height: 1.6;
    font-size: 1.1rem;
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
    color: <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
    border-bottom: 2px solid <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
    padding-bottom: 0.5rem;
    margin-top: 2rem;
    margin-bottom: 1rem;
}

.document-content h2 {
    color: #495057;
    border-bottom: 1px solid #dee2e6;
    padding-bottom: 0.3rem;
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}

.document-content h3 {
    color: #6c757d;
    margin-top: 1.25rem;
    margin-bottom: 0.75rem;
}

.document-content h4, .document-content h5 {
    color: #6c757d;
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
    border-left: 4px solid <?= $isAdmin ? '#dc3545' : '#007bff' ?>;
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

.breadcrumb {
    background-color: #f8f9fa;
    border-radius: 0.25rem;
    padding: 0.75rem 1rem;
}

.breadcrumb-item a {
    color: #007bff;
    text-decoration: none;
}

.breadcrumb-item a:hover {
    color: #0056b3;
    text-decoration: underline;
}

.breadcrumb-item.active {
    color: #495057;
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