<?php

/**
 * car_details.php
 * Displays detailed information about a specific car in the registry.
 *
 * Shows car data, owner info, factory info, images, location map, and update history.
 * Uses the site template for layout and security checks for access.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
}

// Get car information from URL parameter
if (!empty($_GET)) {
    $carID = Input::get('car_id');

    // Get the car information
    $car = new Car($carID);

    // Validate that car exists
    if (!$car->exists()) {
        // Redirect to list if car not found
        Redirect::to($us_url_root . '/app/cars/index.php');
    }
} else {
    // Shouldn't be here unless someone is mangling the url
    Redirect::to($us_url_root . '/app/cars/index.php');
}
?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <div class="row">
                <div class="col-lg-6 mb-4">
                    <div class="card registry-card">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col-md-7">
                                    <h2 class="mb-0">Car Information</h2>
                                </div>
                                <div class="col-md-5 text-end">
                                    <?php
                                    if (isset($user) && $user->isLoggedIn()) {
                                        if ($user->data()->id === $car->data()->user_id) { ?>
                                            <form method="POST" action=<?= $us_url_root . 'app/cars/edit.php' ?>>
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="updateCar" />
                                                <input type="hidden" name="carid" id="carid" value="<?= $car->data()->id ?>" />
                                                <button class="btn btn-success" type="submit">Update</button>
                                            </form>
                                        <?php
                                        } else {
                                        ?>
                                            <form method="POST" action=<?= $us_url_root . 'app/contact/owner.php' ?>>
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="contact_owner" />
                                                <input type="hidden" name="carid" id="carid" value="<?= $car->data()->id ?>" />
                                                <button class="btn btn-success" type="submit">Contact Owner</button>
                                            </form>
                                    <?php
                                        }
                                    } else {
                                        echo "<input type='hidden' name='carid' id='carid' value='" . $car->data()->id . "' />";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="cartable" class="table table-striped table-bordered table-hover table-sm w-100">
                                    <tr class='table-success'>
                                        <th scope="row"><strong>Car ID</strong></th>
                                        <td><?= $car->data()->id ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Series</th>
                                        <td><?= $car->data()->series ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Variant</th>
                                        <td><?= $car->data()->variant ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Model</th>
                                        <td><?= $car->data()->model ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Year</th>
                                        <td><?= $car->data()->year ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Type</th>
                                        <td><?= $car->data()->type ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Chassis</th>
                                        <td><?= $car->data()->chassis ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Color</th>
                                        <td><?= $car->data()->color ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Engine</th>
                                        <td><?= $car->data()->engine ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Purchased</th>
                                        <td><?= $car->data()->purchasedate ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Sold</th>
                                        <td><?= $car->data()->solddate ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Comments</th>
                                        <td><?= $car->data()->comments ?></td>
                                    </tr>
                                    <tr class='table-success'>
                                        <th scope="row">Owner ID</th>
                                        <td><?= $car->data()->user_id ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">First name</th>
                                        <td><?= ucfirst($car->data()->fname) ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">City</th>
                                        <td><?= html_entity_decode($car->data()->city); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">State</th>
                                        <td><?= html_entity_decode($car->data()->state); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Country</th>
                                        <td><?= html_entity_decode($car->data()->country); ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Created</th>
                                        <td><?= $car->data()->ctime ?></td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Modified</th>
                                        <td><?= $car->data()->mtime ?></td>
                                    </tr>
                                    <?php if (!empty($car->data()->website)) { ?>
                                        <tr>
                                            <th scope="row">Website</th>
                                            <td><a target="_blank" href="<?= $car->data()->website ?>">Website</a></td>
                                        </tr>
                                    <?php } ?>
                                    <?php if (!is_null($car->factory())) { ?>
                                        <tr class='table-info'>
                                            <th colspan="2"><strong>Factory Data - <small>This information has not been verified against the Lotus archives.</small></strong></th>
                                        </tr>
                                        <tr>
                                            <th scope="row">Year</th>
                                            <td><?= $car->factory()->year ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Month</th>
                                            <td><?= $car->factory()->month ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Production Batch</th>
                                            <td><?= $car->factory()->batch ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Type</th>
                                            <td><?= $car->factory()->type ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Chassis</th>
                                            <td><?= $car->factory()->serial ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Suffix</th>
                                            <td><?= $car->factory()->suffix ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Engine</th>
                                            <td><?= $car->factory()->engineletter ?><?= $car->factory()->enginenumber ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Gearbox</th>
                                            <td><?= $car->factory()->gearbox ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Color</th>
                                            <td><?= $car->factory()->color ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Build Date</th>
                                            <td><?= $car->factory()->builddate ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row">Notes</th>
                                            <td><?= $car->factory()->note ?></td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 mb-4">
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h2 class="mb-0">The Car</h2>
                        </div>
                        <div class="card-body">
                            <?php echo displayCarousel($car); ?>
                        </div>
                    </div>

                    <div class="card registry-card">
                        <div class="card-header">
                            <h2 class="mb-0">Location</h2>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($car->data()->lat) && !empty($car->data()->lon) && 
                                        $car->data()->lat !== null && $car->data()->lon !== null &&
                                        is_numeric($car->data()->lat) && is_numeric($car->data()->lon)) { ?>
                                <div class="map-container map-container-small" style="height: 100%;">
                                    <div id="map" style="height: 450px; width: 100%;"></div>
                                </div>
                            <?php } else { ?>
                                <p class="text-muted">Location information not available for this car.</p>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Car History full width -->
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card" id="historyCard">
                        <div class="card-header mb-3">
                            <h2 class="mb-0">Car Update History</h2>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="historytable" class="table table-striped table-bordered table-hover table-sm w-100" aria-describedby="card-header">
                                    <thead>
                                        <tr>
                                            <th scope="col">Operation</th>
                                            <th scope="col">Date Modified</th>
                                            <th scope="col">Year</th>
                                            <th scope="col">Type</th>
                                            <th scope="col">Chassis</th>
                                            <th scope="col">Series</th>
                                            <th scope="col">Variant</th>
                                            <th scope="col">Color</th>
                                            <th scope="col">Engine</th>
                                            <th scope="col">Purchase Date</th>
                                            <th scope="col">Sold Date</th>
                                            <th scope="col">Comments</th>
                                            <th scope="col">Image</th>
                                            <th scope="col">Owner</th>
                                            <th scope="col">City</th>
                                            <th scope="col">State</th>
                                            <th scope="col">Country</th>
                                        </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer

// Table Sorting and Such
echo html_entity_decode($settings->elan_datatables_js_cdn);
echo html_entity_decode($settings->elan_datatables_css_cdn);
?>

<!-- Configuration for external JavaScript -->
<script>
    // Global configuration for car details page
    const carDetailsConfig = {
        csrf: '<?= Token::generate(); ?>',
        usUrlRoot: '<?= $us_url_root ?>',
        imgRoot: '<?= $us_url_root . $settings->elan_image_dir ?>',
        hasLocation: <?php
            $hasValidLocation = (!empty($car->data()->lat) && !empty($car->data()->lon) && 
                               $car->data()->lat !== null && $car->data()->lon !== null &&
                               is_numeric($car->data()->lat) && is_numeric($car->data()->lon));
            echo $hasValidLocation ? 'true' : 'false';
        ?>
        <?php if ($hasValidLocation) { ?>
        ,latitude: <?= (float)$car->data()->lat ?>
        ,longitude: <?= (float)$car->data()->lon ?>
        <?php } ?>
    };
    
    // Legacy constants for backward compatibility
    const csrf = carDetailsConfig.csrf;
    const us_url_root = carDetailsConfig.usUrlRoot;
    const img_root = carDetailsConfig.imgRoot;
</script>

<!-- Load external JavaScript files -->
<script src='<?= $us_url_root ?>app/assets/js/imagedisplay.js'></script>
<script src='<?= $us_url_root ?>app/assets/js/car_details.js'></script>
<?php if ($hasValidLocation) { ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?= $settings->elan_google_maps_key ?>&callback=initMap"></script>
<?php } ?>
