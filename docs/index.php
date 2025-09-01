<?php

/**
 * Document Index Page
 *
 * Lists available PDF documents in the docs/assets directory and displays them in a table.
 * Requires authentication and uses Bootstrap for layout.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get list of PDF files in the directory
$directory    = $abs_us_root . $us_url_root . 'docs/assets/';
$files = preg_grep('~\.(pdf)$~', scandir($directory));
$stories = $abs_us_root . $us_url_root . 'stories/stories.php';

?>
<div id="page-wrapper">
    <div class="container">
        <div class="well">
            <div class="row">
                <div class="col-sm-6">
                    <div class="card card-default">
                        <div class="card-header">
                            <h2><strong>Documents</strong></h2>
                        </div>
                        <div class="card-body">
                            
                            <!-- Web-based Documentation -->
                            <h5 class="mb-3"><i class="fas fa-globe"></i> Online Documentation</h5>
                            <table class="table table-striped table-bordered table-sm mb-4">
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Document</th>
                                        <th scope="col">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <a href="../app/help/chassis-validation.php" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-barcode"></i> Chassis Validation Rules
                                            </a>
                                        </td>
                                        <td>
                                            Complete guide to Lotus Elan chassis numbering formats, validation standards, and override procedures
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <!-- PDF Documents -->
                            <h5 class="mb-3"><i class="fas fa-file-pdf"></i> PDF Documents</h5>
                            <table class="table table-striped table-bordered table-sm" aria-describedby="legend">
                                <colgroup>
                                    <col span="1" style="width: 50%;">
                                    <col span="1" style="width: 50%;">
                                </colgroup>
                                <tr>
                                    <th scope=column>Document</th>
                                    <th scope=column>Description </th>
                                </tr>

                                <?php
                                foreach ($files as $file) {
                                    $path_parts = pathinfo($file);
                                    $img = $path_parts['filename'] . '.png';
                                    $description = $path_parts['filename'] . '.txt';

                                ?>
                                    <tr>
                                        <td>
                                            <?php

                                            if (file_exists($directory . $img)) {
                                            ?>
                                                <a href='<?= $us_url_root ?>docs/embed.php?doc=<?= $path_parts['basename'] ?>' target='_blank'>
                                                    <img src='<?= $us_url_root ?>docs/assets/<?= $img ?>' height='225' alt='<?= $file ?>' /><br>
                                                </a>
                                            <?php
                                            } else {
                                            ?>
                                                <a href='<?= $us_url_root ?>docs/embed.php?doc=<?= $path_parts['basename'] ?>' target='_blank'><?= $path_parts['filename'] ?></a>
                                            <?php
                                            }
                                            ?>
                                            <br><br><a href='<?= $us_url_root ?>docs/assets/<?= $file ?>' download><u><small>Direct Download</small></u></a>

                                        <td>
                                            <?php
                                            if (file_exists($directory . $description)) {
                                                require_once $directory . $description;
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                <?php
                                }
                                ?>
                            </table>
                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->

                <div class="col-sm-6">
                    <div class="card card-default">
                        <?php
                        include_once $stories
                        ?>
                    </div>
                </div> <!-- col -->
            </div> <!-- row -->
        </div> <!-- well -->
    </div><!-- Container -->
</div><!-- page -->


<!-- Javascript -->



<!-- footers -->


<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; //custom template footer
?>
