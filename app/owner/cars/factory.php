<?php
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

<script>
  const img_root = '<?= $us_url_root . $settings->elan_image_dir ?>';
  const csrf = '<?= Token::generate(); ?>';
  const us_url_root = '<?= $us_url_root ?>';

  const table = $('#cartable').DataTable({
    fixedHeader: true,
    responsive: true,
    pageLength: 25,
    scrollX: true,
    "lengthMenu": [
      [25, 50, 100, -1],
      [25, 50, 100, "All"]
    ],
    "order": [
      [0, "asc"]
    ],
    "language": {
      "emptyTable": "No Data"
    },
    'processing': true,
    'serverSide': true,
    'serverMethod': 'post',

    "ajax": {
      "url": "../../api/cars/factory-list.php",
      "dataSrc": "data",
      data: function(d) {
        d.csrf = csrf;
      },
      error: function(xhr, error, thrown) {
        console.error('Factory table load failed', xhr.status, thrown);
        $('#cartable').closest('.dataTables_wrapper').prepend(
          '<div class="alert alert-danger mt-2">Could not load factory data. Please refresh the page.</div>'
        );
      }
    },
    'columns': [{
        data: "id",
        'searchable': false,
        'orderable': false,
        visible: false,
      },
      {
        data: "year",
      },
      {
        data: "month"
      },
      {
        data: "batch"
      },
      {
        data: "type"
      },
      {
        data: "serial"
      },
      {
        data: "suffix"
      },
      {
        data: "engineletter"
      },
      {
        data: "enginenumber"
      },
      {
        data: "gearbox"
      },
      {
        data: "color"
      },
      {
        data: "builddate",
      }, {
        data: "note",
      }, {
        // car_id is not a table column — injected as a correlated subquery alias by CarDataTablesService
        data: "car_id",
        render: function(data, type, row) {
          if (type !== 'display') {
            return data || '';
          }
          const carId = parseInt(data, 10);
          const inner = (Number.isFinite(carId) && carId > 0)
            ? '<a href="' + us_url_root + 'app/owner/cars/details.php?car_id=' + carId + '" class="btn btn-sm btn-primary" target="_blank"><i class="fas fa-car"></i> View Car #' + carId + '</a>'
            : '<span class="text-muted small"><i class="fas fa-times-circle"></i> Not in registry</span>';
          return '<div class="registry-link-container">' + inner + '</div>';
        },
        orderable: false,
        searchable: false
      }
    ]
  });
</script>