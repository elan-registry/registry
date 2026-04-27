<?php

declare(strict_types=1);

/**
 * privacy.php
 * Displays the privacy policy for the Lotus Elan Registry project.
 *
 * Loads PRIVACY.md, converts markdown to HTML using MarkdownParser, and renders it using the site template.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';
require_once $abs_us_root . $us_url_root . 'usersc/classes/MarkdownParser.php';

use ElanRegistry\Documentation\MarkdownParser;

$mdFile = __DIR__ . '/../docs/elanregistry/PRIVACY.md';
$policy = '';
if (file_exists($mdFile)) {
    $markdownContent = file_get_contents($mdFile);
    if ($markdownContent !== false) {
        // Convert markdown to HTML using centralized parser with application root path
        $policy = MarkdownParser::toHtml($markdownContent, $us_url_root);
        $policy = MarkdownParser::sanitizeHtml($policy);
    }
}
?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">Privacy Policy</h2>
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
