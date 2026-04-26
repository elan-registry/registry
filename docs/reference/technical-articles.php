<?php

declare(strict_types=1);

/**
 * Technical Articles - Lotus Elan Registry
 *
 * Club Lotus technical articles and historical references.
 *
 * @package ElanRegistry
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

?>
<div class="page-wrapper">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="card registry-card">
                    <div class="card-header">
                        <h2><i class="fas fa-file-alt"></i> <strong>Technical Articles</strong></h2>
                        <p class="text-muted mb-0">Club Lotus technical articles and historical references</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Engine Number Breakdown</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">Identifying engine types from number sequences</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?doc=<?= rawurlencode('Engine number breakdown (Miles Wilkins).pdf') ?>" target="_blank" class="btn btn-outline-primary btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/assets/<?= rawurlencode('Engine number breakdown (Miles Wilkins).pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Elan Gearknobs</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">A description of the various types of gear knobs available on the Elan and Elan +2</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?doc=<?= rawurlencode('2014 Jul Elan Gearknobs.pdf') ?>" target="_blank" class="btn btn-outline-primary btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/assets/<?= rawurlencode('2014 Jul Elan Gearknobs.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Steering Wheels</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">A description of the various types of steering wheels available on the Elan and Elan +2</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?doc=<?= rawurlencode('2014 Oct Elan and Plus 2 Steering Wheels.pdf') ?>" target="_blank" class="btn btn-outline-primary btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/assets/<?= rawurlencode('2014 Oct Elan and Plus 2 Steering Wheels.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> The Elan Super Safety</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">A description of the Elan Super Safety</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?doc=<?= rawurlencode('2019_Jan_The_Elan_Super_Safety.pdf') ?>" target="_blank" class="btn btn-outline-primary btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/assets/<?= rawurlencode('2019_Jan_The_Elan_Super_Safety.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Plus 2 Serial Numbers</h5>
                    </div>
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">Serial number sequences for the Lotus Elan Plus 2</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?doc=<?= rawurlencode('Lotus Elan Plus 2 serial numbers.pdf') ?>" target="_blank" class="btn btn-outline-primary btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/assets/<?= rawurlencode('Lotus Elan Plus 2 serial numbers.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4 mb-4">
            <div class="col-12 text-center">
                <a href="index.php" class="btn btn-outline-primary mr-2">
                    <i class="fas fa-book"></i> Reference Library
                </a>
                <a href="workshop.php" class="btn btn-outline-success mr-2">
                    <i class="fas fa-wrench"></i> Workshop & Parts
                </a>
                <a href="<?= $us_url_root ?>" class="btn btn-outline-info">
                    <i class="fas fa-home"></i> Registry Home
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
