<?php

declare(strict_types=1);

/**
 * Document Embed Page
 *
 * Embeds a selected document (PDF) in an iframe for viewing.
 * Requires authentication and uses Bootstrap for layout.
 */
require_once '../users/init.php';

// Hint for the nav active-state matcher (see usersc/templates/customizer/file_nav_custom.php).
// Must be set before elanregistry_prep.php, which triggers the nav render.
$nav_section = (($_GET['subdir'] ?? '') === 'stories') ? 'stories' : 'reference';

require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

// Validate and sanitize document parameter
$document    = '';
$path_parts  = [];
$error_message = '';
$asset_base  = 'docs/assets/';

if (!empty($_GET['doc'])) {
    $requested_doc = $_GET['doc'];

    // Security: Prevent directory traversal attacks
    if (strpos($requested_doc, '..') !== false ||
        strpos($requested_doc, '/') !== false ||
        strpos($requested_doc, '\\') !== false ||
        strpos($requested_doc, 'http') === 0) {
        logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid document path attempted: ' . $requested_doc);
        $error_message = 'Invalid document path.';
    } else {
        // Sanitize the document name
        $document = basename($requested_doc);

        // Additional validation: only allow certain file extensions
        $allowed_extensions = ['pdf', 'PDF'];
        $path_parts = pathinfo($document);

        if (!isset($path_parts['extension']) || !in_array($path_parts['extension'], $allowed_extensions)) {
            logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid document type attempted: ' . $requested_doc);
            $error_message = 'Invalid document type. Only PDF files are allowed.';
            $document = '';
        }

        // Validate optional subdir parameter (allowlist only)
        $allowed_subdirs = ['reference', 'stories'];
        $asset_subdir = '';
        if (!empty($_GET['subdir'])) {
            $requested_subdir = $_GET['subdir'];
            if (!in_array($requested_subdir, $allowed_subdirs, true)) {
                logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Invalid subdir attempted: ' . $requested_subdir);
                $error_message = 'Invalid document path.';
                $document = '';
            } else {
                $asset_subdir = $requested_subdir;
            }
        }

        $asset_base = $asset_subdir !== '' ? 'docs/' . $asset_subdir . '/assets/' : 'docs/assets/';

        // Check if file actually exists
        $file_path = $abs_us_root . $us_url_root . $asset_base . $document;
        if (empty($error_message) && !empty($document) && !file_exists($file_path)) {
            logger(0, LogCategories::LOG_CATEGORY_SECURITY, 'Non-existent document requested: ' . $document);
            $error_message = 'Document not found.';
            $document = '';
        }
    }
}

?>
<div id='page-wrapper'>
    <!-- Page Content -->
    <div class='container'>
        <div class='card card-default'>
            <div class='card-header'>
                <h1><?= !empty($path_parts['filename']) ? htmlspecialchars($path_parts['filename'], ENT_QUOTES, 'UTF-8') : 'Document Viewer' ?></h1>
                <a href="javascript:history.go(-1)">Back ...</a>
            </div>
            <div class='card-body'>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fa fa-exclamation-triangle"></i>
                        <?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <p>Please <a href="javascript:history.go(-1)">go back</a> and try again.</p>
                <?php elseif (!empty($document)): ?>
                    <iframe style='width:100%; height:100vw;'
                            src='<?= htmlspecialchars($us_url_root . $asset_base . $document, ENT_QUOTES, 'UTF-8') ?>'
                            title='<?= htmlspecialchars($document, ENT_QUOTES, 'UTF-8') ?>'
                            allowfullscreen></iframe>
                <?php else: ?>
                    <div class="alert alert-warning" role="alert">
                        <i class="fa fa-info-circle"></i>
                        No document specified.
                    </div>
                    <p>Please <a href="javascript:history.go(-1)">go back</a> and select a document.</p>
                <?php endif; ?>
            </div>
        </div>
    </div> <!-- /.container -->
</div><!-- .page-wrapper -->
<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>
