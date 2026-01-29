<?php

/**
 * Reference Library Page
 *
 * Technical documents, manuals, and guides for Lotus Elan owners and enthusiasts.
 * Lists PDF documents and online technical resources.
 */
require_once '../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Get list of PDF files in the directory
$directory = $abs_us_root . $us_url_root . 'docs/assets/';
$files = preg_grep('~\.(pdf)$~', scandir($directory));

?>
<div class="page-wrapper">
    <div class="container">
        <div class="row">
            <div class="col-sm-12">
                <div class="card registry-card">
                        <div class="card-header">
                            <h2><i class="fas fa-book"></i> <strong>Reference Library</strong></h2>
                            <p class="text-muted">Technical documentation, workshop manuals, and owner guides</p>
                        </div>
                        <div class="card-body">
                            
                            <!-- Web-based Documentation -->
                            <h5 class="mb-3"><i class="fas fa-globe"></i> Online Technical Resources</h5>
                            <table class="table table-striped table-bordered table-sm mb-4">
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="col">Resource</th>
                                        <th scope="col">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <a href="chassis-validation.php" target="_blank" class="btn btn-sm btn-outline-primary">
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
                            <h5 class="mb-3"><i class="fas fa-file-pdf"></i> Technical Documents & Manuals</h5>
                            <table class="table table-striped table-bordered table-sm" aria-describedby="Technical documents and manuals table">
                                <colgroup>
                                    <col span="1" style="width: 50%;">
                                    <col span="1" style="width: 50%;">
                                </colgroup>
                                <thead class="thead-light">
                                    <tr>
                                        <th scope="column">Document</th>
                                        <th scope="column">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
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
                                                <br><br><a href='<?= $us_url_root ?>docs/assets/<?= $file ?>' download class="btn btn-sm btn-success">
                                                    <i class="fas fa-download"></i> Download PDF
                                                </a>
                                            </td>
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
                                </tbody>
                            </table>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> All documents open in a new window. Right-click any document link to save directly to your computer.
                            </div>

                        </div> <!-- card-body -->
                    </div> <!-- card -->
                </div> <!-- col -->
            </div> <!-- row -->
    </div><!-- Container -->
</div><!-- page -->

<?php require_once $abs_us_root . $us_url_root . 'usersc/templates/' . $settings->template . '/footer.php'; ?>