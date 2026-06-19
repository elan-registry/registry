<?php

declare(strict_types=1);

/**
 * Car list — searchable, sortable table of all registered cars.
 *
 * @package ElanRegistry
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

// Security: Only allow access to authorized users
if (!securePage($php_self)) {
  die();
}

$filterSeries   = $db->query("SELECT DISTINCT series_normalized AS series  FROM car_models WHERE series_normalized  IS NOT NULL AND series_normalized  != '' ORDER BY series_normalized")->results();
$filterTypes    = $db->query("SELECT DISTINCT type_code         AS type   FROM car_models WHERE type_code          IS NOT NULL AND type_code          != '' ORDER BY type_code")->results();
$filterVariants = $db->query("SELECT DISTINCT variant                      FROM car_models WHERE variant            IS NOT NULL AND variant            != '' ORDER BY variant")->results();

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

<!-- Constants needed by the scripts -->
<script>
  const csrf = '<?= Token::generate(); ?>';
  const us_url_root = '<?= $us_url_root ?>';
  const img_root = '<?= $us_url_root . $settings->elan_image_dir ?>';
</script>

<!-- Configure thumbnail sizes from settings -->
<script>
window.ELAN_CONFIG = {
    THUMBNAIL_SIZE: <?php 
        $thumbnailSize = 100; // Default
        $responsiveSize = 300; // Default
        
        // Try to get settings if the field exists
        if (isset($settings->elan_image_thumbnail_sizes) && !empty($settings->elan_image_thumbnail_sizes)) {
            $thumbnailSizes = explode(',', $settings->elan_image_thumbnail_sizes);
            if (count($thumbnailSizes) >= 1) {
                $thumbnailSize = intval(trim($thumbnailSizes[0]));
            }
            if (count($thumbnailSizes) >= 2) {
                $responsiveSize = intval(trim($thumbnailSizes[1]));
            }
        }
        echo $thumbnailSize;
    ?>,
    RESPONSIVE_SIZE: <?php echo $responsiveSize; ?>
};
</script>
<script src='<?= $us_url_root ?>app/assets/js/imagedisplay.min.js'></script>

<script>
  const table = $('#cartable').DataTable({
    fixedHeader: true,
    responsive: true,
    pageLength: 15,
    lengthMenu: [
      [10, 25, 50, 100, -1],
      [10, 25, 50, 100, 'All']
    ],
    order: [
      [1, 'asc'],
      [2, 'asc'],
      [3, 'asc']
    ],
    language: {
      emptyTable: 'No Cars'
    },
    processing: true,
    serverSide: true,
    serverMethod: 'post',
    ajax: {
      url: '../action/getDataTables.php',
      dataSrc: 'data',
      data: function(d) {
        d.csrf = csrf;
        d.table = 'cars';
      }
    },
    columnDefs: [
      { visible: false, targets: [12] }
    ],
    columns: [{
      data: 'id',
      searchable: false,
      orderable: false,
      responsivePriority: 1,
      render: function(data, type, row) {
        return '<a class="btn btn-primary btn-sm" href="' + us_url_root + 'app/cars/details.php?car_id=' + data + '"><i class="fas fa-eye"></i> Details</a>';
      }
    }, {
      data: 'year',
      responsivePriority: 1
    }, {
      data: 'type',
      responsivePriority: 1
    }, {
      data: 'chassis',
      responsivePriority: 1
    }, {
      data: 'series',
      responsivePriority: 2
    }, {
      data: 'variant',
      responsivePriority: 2
    }, {
      data: 'color',
      responsivePriority: 2
    }, {
      data: 'image',
      searchable: false,
      orderable: false,
      responsivePriority: 3,
      render: function(data, type, row) {
        if (data) {
          return carousel(row);
        } else {
          return '<img src="' + us_url_root + 'app/assets/img/elan-placeholder.svg" alt="No photo" style="height:50px;opacity:0.5;" title="No photo available">';
        }
      }
    }, {
      data: 'fname',
      responsivePriority: 3
    }, {
      data: 'city',
      responsivePriority: 3
    }, {
      data: 'state',
      responsivePriority: 3
    }, {
      data: 'country',
      responsivePriority: 3
    }, {
      data: 'ctime',
      searchable: true,
      responsivePriority: 3
    }]
  });

  document.querySelectorAll('.filter-pill').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var col = this.dataset.col;
      var val = this.dataset.value;
      document.querySelectorAll('.filter-pill[data-col="' + col + '"]').forEach(function(b) {
        b.classList.remove('active', 'btn-primary');
        b.classList.add('btn-outline-secondary');
      });
      this.classList.add('active', 'btn-primary');
      this.classList.remove('btn-outline-secondary');
      table.column(parseInt(col)).search(val).draw();
    });
  });

  document.getElementById('toggle-date-added').addEventListener('click', function() {
    var col = table.column(12);
    col.visible(!col.visible());
    this.innerHTML = col.visible()
      ? '<i class="fas fa-calendar-alt"></i> Hide Date Added'
      : '<i class="fas fa-calendar-alt"></i> Show Date Added';
  });
</script>
