<?php

declare(strict_types=1);

/**
 * Workshop & Parts Reference - Lotus Elan Registry
 *
 * Workshop manuals, parts lists, and engine reference documents.
 *
 * @package ElanRegistry
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

?>
<div class="page-wrapper">
    <div class="container">
        <?= DocumentPortalTemplate::renderPortalHeader([
            'title'       => 'Workshop & Parts',
            'titleIcon'   => 'fa-wrench',
            'description' => 'Maintenance manuals and parts references for Lotus Elan owners',
        ]) ?>

        <div class="row mt-4">
            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Elan Workshop Manual</h5>
                    </div>
                    <img src="<?= $us_url_root ?>docs/reference/assets/Elan_26_36_Workshop_Manual.png" class="card-img-top" alt="Elan Workshop Manual" style="max-height: 200px; object-fit: contain; padding: 10px;">
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">Elan Workshop Manual</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?subdir=reference&doc=<?= rawurlencode('Elan_26_36_Workshop_Manual.pdf') ?>" target="_blank" class="btn btn-outline-info btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/reference/assets/<?= rawurlencode('Elan_26_36_Workshop_Manual.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Elan Parts List</h5>
                    </div>
                    <img src="<?= $us_url_root ?>docs/reference/assets/Elan_S1_S2_Coupe_Masterpartslist.png" class="card-img-top" alt="Elan Parts List" style="max-height: 200px; object-fit: contain; padding: 10px;">
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">1966 Parts list for Series 1, Series 2 and Coupe</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?subdir=reference&doc=<?= rawurlencode('Elan_S1_S2_Coupe_Masterpartslist.pdf') ?>" target="_blank" class="btn btn-outline-info btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/reference/assets/<?= rawurlencode('Elan_S1_S2_Coupe_Masterpartslist.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card registry-card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-file-pdf"></i> Elan Engine Types</h5>
                    </div>
                    <img src="<?= $us_url_root ?>docs/reference/assets/<?= rawurlencode('2016 Jan Elan Engine Types.png') ?>" class="card-img-top" alt="Elan Engine Types" style="max-height: 200px; object-fit: contain; padding: 10px;">
                    <div class="card-body d-flex flex-column">
                        <p class="card-text flex-grow-1">CLUB LOTUS ELAN — Elan & +2 Engine Types</p>
                        <div class="mt-auto">
                            <a href="<?= $us_url_root ?>docs/embed.php?subdir=reference&doc=<?= rawurlencode('2016 Jan Elan Engine Types.pdf') ?>" target="_blank" class="btn btn-outline-info btn-sm mr-2">
                                <i class="fas fa-eye"></i> Read Online
                            </a>
                            <a href="<?= $us_url_root ?>docs/reference/assets/<?= rawurlencode('2016 Jan Elan Engine Types.pdf') ?>" download class="btn btn-success btn-sm">
                                <i class="fas fa-download"></i> Download PDF
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; ?>
