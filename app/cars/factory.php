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
require_once '../../users/init.php';
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
      "url": "../action/getDataTables.php",
      "dataSrc": "data",
      data: function(d) {
        d.csrf = csrf;
        d.table = 'factory';
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
        data: "serial",
        render: function(data, type, row, meta) {
          if (type === 'display' && data) {
            var div = document.createElement('div');
            div.className = 'registry-link-container';
            div.dataset.chassis = data;
            div.innerHTML = '<span class="text-muted small"><i class="fas fa-spinner fa-spin"></i> Checking...</span>';
            return div.outerHTML;
          }
          if (type === 'sort' || type === 'type') {
            return data || '';
          }
          return '';
        },
        orderable: true,
        searchable: false
      }
    ]
  });

  // Function to check for registry matches and populate links
  function checkRegistryLinks() {
    $('.registry-link-container').each(function() {
      const container = $(this);
      const chassis = container.data('chassis');
      
      if (!chassis) {
        container.html('<span class="text-muted small">No chassis data</span>');
        return;
      }

      // Make AJAX request to find car by chassis
      new ElanRegistryAPI()
        .post(us_url_root + 'app/action/getDataTables.php', {
          table: 'findCarByChassis',
          chassis: chassis,
          csrf: csrf
        })
        .then(function(response) {
          if (response.car_id) {
            // Car exists - create link to car details
            const detailsUrl = us_url_root + 'app/cars/details.php?car_id=' + response.car_id;
            container.html(
              '<a href="' + detailsUrl + '" class="btn btn-sm btn-primary" target="_blank">' +
              '<i class="fas fa-car"></i> View Car #' + response.car_id +
              '</a>'
            );
          } else {
            // Car not found - show not registered message
            container.html(
              '<span class="text-muted small">' +
              '<i class="fas fa-times-circle"></i> Not in registry' +
              '</span>'
            );
          }
        })
        .catch(function(error) {
          if (error && error.name !== 'ApiCancelledError') {
            console.error('Registry link check failed for chassis', chassis, error);
          }
          container.html(
            '<span class="text-danger small">' +
            '<i class="fas fa-exclamation-triangle"></i> Check failed' +
            '</span>'
          );
        });
    });
  }

  // Check registry links after table is drawn
  table.on('draw.dt', function() {
    checkRegistryLinks();
  });

  // Initial check after table loads
  table.on('init.dt', function() {
    checkRegistryLinks();
  });
</script>