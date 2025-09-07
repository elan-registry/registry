<?php

/**
 * privacy.php
 * Displays the privacy policy for the Lotus Elan Registry project.
 *
 * Loads PRIVACY.md, converts markdown to HTML, and renders it using the site template.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

$mdFile = __DIR__ . '/../docs/elanregistry/PRIVACY.md';
$policy = '';
if (file_exists($mdFile)) {
    $policy = file_get_contents($mdFile);
    // Convert markdown to HTML (simple conversion)
    $policy = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $policy);
    $policy = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $policy);
    $policy = preg_replace('/^\-\-? (.*)$/m', '<li>$1</li>', $policy);
    $policy = preg_replace('/^\- (.*)$/m', '<li>$1</li>', $policy);
    $policy = str_replace("\n\n", '<br><br>', $policy);
    $policy = str_replace("\n", '<br>', $policy);
    $policy = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $policy);
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
