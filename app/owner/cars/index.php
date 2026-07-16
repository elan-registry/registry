<?php

declare(strict_types=1);

/**
 * Car list — searchable, sortable table of all registered cars.
 *
 * @package ElanRegistry
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Car\CarRepository;
use ElanRegistry\Car\CarShowcaseService;
use ElanRegistry\Documentation\DocumentPortalTemplate;

// Security: Only allow access to authorized users
if (!securePage($php_self)) {
  die();
}

['series' => $filterSeries, 'types' => $filterTypes, 'variants' => $filterVariants]
    = (new CarRepository($db))->getFilterOptions();

/**
 * Render a row of filter pills for a single DataTables column.
 *
 * @param string   $label  Visible label (e.g. "Series")
 * @param int      $col    DataTables column index
 * @param string   $prop   Object property name on each result row
 * @param object[] $rows   Query results from $db->query()->results()
 */
function renderFilterRow(string $label, int $col, string $prop, array $rows): void
{
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    echo '<div class="d-flex flex-wrap gap-2 align-items-center mb-2">';
    echo "<span class=\"text-muted small\" style=\"min-width:4rem\">{$safeLabel}:</span>";
    echo "<button class=\"btn btn-primary btn-sm filter-pill active\" data-col=\"{$col}\" data-value=\"\">All</button>";
    foreach ($rows as $row) {
        $val = htmlspecialchars((string) $row->$prop, ENT_QUOTES, 'UTF-8');
        echo "<button class=\"btn btn-outline-secondary btn-sm filter-pill\" data-col=\"{$col}\" data-value=\"{$val}\">{$val}</button>";
    }
    echo '</div>';
}
?>

<div class="page-wrapper">
  <div class="container-fluid">
    <div class="page-container">
    <?= DocumentPortalTemplate::renderBreadcrumb('list_cars', $us_url_root) ?>
    <div class="row">
      <div class="col-12">
        <div class="card registry-card mb-4">
          <div class="card-header card-header-er-primary">
            <h2 class="mb-0 card-header-er-primary-text"><i class="fa fa-car" aria-hidden="true"></i> Registry Cars</h2>
            <p class="card-header-er-primary-text mb-0 small">All Lotus Elan and Plus 2 cars registered in the registry</p>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <?php renderFilterRow('Series',  4, 'series',  $filterSeries); ?>
              <?php renderFilterRow('Type',    2, 'type',    $filterTypes); ?>
              <?php renderFilterRow('Variant', 5, 'variant', $filterVariants); ?>
              <div class="d-flex justify-content-end">
                <button id="toggle-date-added" class="btn btn-outline-secondary btn-sm">
                  <i class="fas fa-calendar-alt"></i> Show Date Added
                </button>
              </div>
            </div>
            <div class="table-responsive">
              <table id="cartable" class="table table-striped table-bordered table-hover table-sm w-100" aria-describedby="card-header">
                <thead>
                  <tr>
                    <th scope="col">Details</th>
                    <th scope="col">Year</th>
                    <th scope="col">Type</th>
                    <th scope="col">Chassis</th>
                    <th scope="col">Series</th>
                    <th scope="col">Variant</th>
                    <th scope="col">Color</th>
                    <th scope="col">Image</th>
                    <th scope="col">First Name</th>
                    <th scope="col">City</th>
                    <th scope="col">State</th>
                    <th scope="col">Country</th>
                    <th scope="col">Date Added</th>
                  </tr>
                </thead>
              </table>
            </div>
          </div> <!-- card-body -->
        </div> <!-- card -->
      </div> <!-- col-12 -->
    </div> <!-- row -->
    </div> <!-- page-container -->
  </div> <!-- container-fluid -->
</div><!-- page-wrapper -->
<!-- End of main content section -->

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">

<?php include 'includes/elan-config-island.php'; ?>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
window.carListConfig = {
    csrf: <?= json_encode(Token::generate()) ?>,
    urlRoot: <?= json_encode((string)$us_url_root, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    newCarIds: <?= json_encode(CarShowcaseService::getNewCarIds($db), JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
window.img_root = <?= json_encode((string)($us_url_root . ($settings->elan_image_dir ?? '')), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src='<?= $us_url_root ?>app/assets/js/imagedisplay.min.js?v=<?= ASSET_VERSION ?>'></script>
<script src='<?= $us_url_root ?>app/assets/js/car-list.min.js?v=<?= ASSET_VERSION ?>'></script>
