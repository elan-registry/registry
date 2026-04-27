<?php

/**
 * list_cars.php
 * Displays a searchable, sortable table of all cars in the registry.
 *
 * Uses DataTables for client-side features and AJAX for server-side data loading.
 * Includes site template header and footer for consistent layout.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

// Security: Only allow access to authorized users
if (!securePage($php_self)) {
  die();
}
?>

<div class="page-wrapper">
  <div class="container-fluid">
    <div class="page-container">
    <div class="row">
      <div class="col-12">
        <div class="card registry-card mb-4">
          <div class="card-header">
            <h2 class="mb-0"><i class="fa fa-car" aria-hidden="true"></i> Registry Cars</h2>
            <p class="text-muted mb-0 small">All Lotus Elan and Plus 2 cars registered in the registry</p>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table id="cartable" class="table table-striped table-bordered table-hover table-sm w-100" aria-describedby="card-header">
                <thead>
                  <tr>
                    <th scope="col">ID</th>
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
<script src='<?= $us_url_root ?>app/assets/js/imagedisplay.js'></script>

<script>
  const table = $('#cartable').DataTable({
    fixedHeader: true,
    responsive: true,
    pageLength: 15,
    scrollX: true,
    'aLengthMenu': [
      [10, 25, 50, 100, -1],
      [10, 25, 50, 100, 'All']
    ],
    caseInsensitive: true,
    'aaSorting': [
      [1, 'asc'],
      [2, 'asc'],
      [3, 'asc']
    ],
    'language': {
      'emptyTable': 'No Cars'
    },
    'processing': true,
    'serverSide': true,
    'serverMethod': 'post',
    'ajax': {
      'url': '../action/getDataTables.php',
      'dataSrc': 'data',
      data: function(d) {
        d.csrf = csrf;
        d.table = 'cars';
      }
    },
    'columns': [{
      data: 'id',
      'searchable': false,
      'orderable': false,
      'render': function(data, type, row, meta) {
        return '<a class="btn btn-success btn-sm" href="' + us_url_root + 'app/cars/details.php?car_id=' + data + '">Details';
      }
    }, {
      data: 'year',
    }, {
      data: 'type'
    }, {
      data: 'chassis'
    }, {
      data: 'series'
    }, {
      data: 'variant'
    }, {
      data: 'color'
    }, {
      data: 'image',
      'searchable': false,
      'render': function(data, type, row) {
        if (data) {
          return carousel(row);
        } else {
          return '';
        }
      }
    }, {
      data: 'fname'
    }, {
      data: 'city'
    }, {
      data: 'state'
    }, {
      data: 'country'
    }, {
      data: 'ctime',
      'searchable': true,
    }]
  });
</script>
    </div> <!-- page-container -->
  </div> <!-- container-fluid -->
</div> <!-- page-wrapper -->
