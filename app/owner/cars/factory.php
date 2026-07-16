<?php

declare(strict_types=1);

/**
 * list_factory.php
 * Displays factory information for Lotus Elan cars.
 * 
 * Shows a searchable, sortable table of factory records with warnings about data verification.
 * Uses DataTables for client-side features and AJAX for server-side data loading.
 * 
 * @author Elan Registry Team
 * @copyright 2025
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
  die();
}
?>

<div class="page-wrapper">
  <div class="container-fluid">
    <div class="page-container">
      <div class="row">
        <div class="col-12">
          <div class="card registry-card">
            <div class="card-header card-header-er-primary">
              <h2 class="mb-0 card-header-er-primary-text">Elan Factory Information</h2>
            </div>
            <div class="card-body">
              <p class="mb-3">These records are transcribed from original Lotus factory production logs that document each Elan as it left the factory, capturing its configuration at the time of manufacture — body type, engine, gearbox, and color. Where a matching car exists in the Elan Registry, a link is provided; factory data may differ from current registry records due to subsequent modifications, registration changes, or transcription variations in the original handwritten logs.</p>
              <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>WARNING:</strong> This information has not been verified against the Lotus archives.
              </div>
              <table id="cartable" class="table table-striped table-bordered table-sm w-100 registry-table" aria-describedby="card-header">
                <thead>
                  <tr>
                    <th scope="column">Record #</th>
                    <th scope="column">Year</th>
                    <th scope="column">Month</th>
                    <th scope="column">Batch</th>
                    <th scope="column">Type</th>
                    <th scope="column">Serial</th>
                    <th scope="column">Suffix</th>
                    <th scope="column">Engine Letter</th>
                    <th scope="column">Engine Number</th>
                    <th scope="column">Gearbox</th>
                    <th scope="column">Color</th>
                    <th scope="column">Built / Invoiced / 1ST Registered </th>
                    <th scope="column">Note</th>
                    <th scope="column">Registry Link</th>
                  </tr>
                </thead>
              </table>
            </div> <!-- card-body -->
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- End of main content section -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">

<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
window.factoryListConfig = {
    csrf: <?= json_encode(Token::generate()) ?>,
    urlRoot: <?= json_encode((string)$us_url_root, JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
</script>
<script src='<?= $us_url_root ?>app/assets/js/factory-list.min.js?v=<?= ASSET_VERSION ?>'></script>