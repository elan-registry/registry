<?php

declare(strict_types=1);

/**
 * privacy.php
 * Displays the privacy policy for the Lotus Elan Registry project.
 *
 * Loads PRIVACY.md, converts markdown to HTML using MarkdownRenderer, and renders it using the site template.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\MarkdownRenderer;

$mdFile = __DIR__ . '/../docs/guides/PRIVACY.md';
$policy = '';
if (!file_exists($mdFile)) {
    logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'privacy.php: PRIVACY.md not found at ' . $mdFile);
} else {
    $markdownContent = file_get_contents($mdFile);
    if ($markdownContent === false) {
        logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'privacy.php: failed to read PRIVACY.md at ' . $mdFile);
    } else {
        try {
            $policy = MarkdownRenderer::convert($markdownContent, $us_url_root);
        } catch (\RuntimeException $e) {
            logger(0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, 'privacy.php: MarkdownRenderer::convert() failed: ' . $e->getMessage());
        }
    }
}
?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header card-header-er-primary">
                            <h2 class="mb-0 card-header-er-primary-text">Privacy Policy</h2>
                        </div>
                        <div class="card-body">
                            <div class="content-wrapper">
                                <?php echo $policy; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php

require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php';
?>
