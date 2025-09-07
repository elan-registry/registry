<?php

/**
 * Type 26 Register Archive Viewer
 *
 * Displays an iframe of the archived type26register.com site.
 * Requires authentication and uses Bootstrap for layout.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

$type26index = $us_url_root . "stories/type26register.com/index.html";
?>
<div id="page-wrapper">
    <div class="container">
        <div class="well">
            <div class="row">
                <div class="col-sm-12">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>An incomplete achive of type26register.com</strong></h2> <a href="javascript:history.go(-1)">Back ...</a>
                        </div>
                        <div class="card-body">
                            <iframe width="100%" onload="this.height=screen.height;" src="<?= $type26index ?>" title="type26register.com"></iframe>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->
            </div> <!-- row -->
        </div> <!-- well -->
    </div><!-- Container -->
</div><!-- page -->


<!-- Javascript -->

<!-- footers -->
<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>
