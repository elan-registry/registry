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
            
            <!-- Breadcrumb Navigation -->
            <div class="row">
                <div class="col-12">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= $us_url_root ?>" class="text-primary"><i class="fas fa-home"></i> Home</a></li>
                            <li class="breadcrumb-item"><a href="<?= $us_url_root ?>app/cars/index.php" class="text-primary"><i class="fas fa-list"></i> Cars</a></li>
                            <li class="breadcrumb-item active text-muted" aria-current="page">
                                <i class="fas fa-car"></i> <?= $car->data()->year ?> <?= $car->data()->series ?> 
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- Quick Facts Summary Card -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card bg-info text-white">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h1 class="mb-0">
                                        <i class="fas fa-car me-2"></i>
                                        <?= $car->data()->year ?> Lotus Elan <?= $car->data()->series ?>
                                        <?= !empty($car->data()->variant) ? ' (' . $car->data()->variant . ')' : '' ?>
                                    </h1>
                                    <div class="row mt-4">
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-hashtag me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Registry ID</div>
                                                    <div class="fw-bold fs-5"><?= $car->data()->id ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-barcode me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Chassis</div>
                                                    <div class="fw-bold fs-5"><?= $car->data()->chassis ?: 'Not specified' ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-palette me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Color</div>
                                                    <div class="fw-bold fs-5"><?= $car->data()->color ?: 'Not specified' ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-cog me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Engine</div>
                                                    <div class="fw-bold fs-5"><?= $car->data()->engine ?: 'Not specified' ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <?php
                                    if (isset($user) && $user->isLoggedIn()) {
                                        if ($user->data()->id === $car->data()->user_id) { ?>
                                            <form method="POST" action="<?= $us_url_root ?>app/cars/edit.php" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="updateCar" />
                                                <input type="hidden" name="carid" id="carid" value="<?= $car->data()->id ?>" />
                                                <button class="btn btn-light btn-lg" type="submit">
                                                    <i class="fas fa-edit"></i> Update Car
                                                </button>
                                            </form>
                                        <?php
                                        } else {
                                        ?>
                                            <form method="POST" action="<?= $us_url_root ?>app/contact/owner.php" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="contact_owner" />
                                                <input type="hidden" name="carid" id="carid" value="<?= $car->data()->id ?>" />
                                                <button class="btn btn-light btn-lg" type="submit">
                                                    <i class="fas fa-envelope"></i> Contact Owner
                                                </button>
                                            </form>
                                    <?php
                                        }
                                    } else {
                                        echo "<div class='text-white-50'><i class='fas fa-sign-in-alt'></i> Log in to contact owner</div>";
                                        echo "<input type='hidden' name='carid' id='carid' value='" . $car->data()->id . "' />";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6 mb-4">
                    <!-- Vehicle Information Card -->
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-car text-primary"></i> Vehicle Information</h3>
                        </div>
                        <div class="card-body">
                            <!-- Basic Vehicle Details -->
                            <dl class="row mb-4">
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-calendar text-primary"></i> Model Year
                                </dt>
                                <dd class="col-sm-8">
                                    <?= $car->data()->year ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-tag text-primary"></i> Series
                                </dt>
                                <dd class="col-sm-8">
                                    <?= $car->data()->series ?>
                                    <?= !empty($car->data()->variant) ? ' - ' . $car->data()->variant : '' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-car text-primary"></i> Type
                                </dt>
                                <dd class="col-sm-8">
                                    <?= $car->data()->type ?: '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-barcode text-primary"></i> Chassis
                                </dt>
                                <dd class="col-sm-8">
                                    <strong><?= $car->data()->chassis ?: '<em class="text-muted">Not specified</em>' ?></strong>
                                </dd>
                            </dl>

                            <hr>

                            <!-- Appearance & Technical -->
                            <h6 class="text-muted mb-3">
                                <i class="fas fa-palette text-secondary"></i> Appearance & Technical
                            </h6>
                            <dl class="row mb-4">
                                <dt class="col-sm-4 text-muted">Color</dt>
                                <dd class="col-sm-8">
                                    <?= $car->data()->color ?: '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">Engine</dt>
                                <dd class="col-sm-8">
                                    <?= $car->data()->engine ?: '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                            </dl>

                            <hr>

                            <!-- Ownership & History -->
                            <h6 class="text-muted mb-3">
                                <i class="fas fa-history text-secondary"></i> Ownership & History
                            </h6>
                            <dl class="row mb-4">
                                <?php if (!empty($car->data()->purchasedate)) { ?>
                                <dt class="col-sm-4 text-muted">Purchase Date</dt>
                                <dd class="col-sm-8">
                                    <?php 
                                    $purchaseDate = new DateTime($car->data()->purchasedate);
                                    echo $purchaseDate->format('F j, Y');
                                    ?>
                                </dd>
                                <?php } ?>
                                
                                <?php if (!empty($car->data()->solddate)) { ?>
                                <dt class="col-sm-4 text-muted">Sold Date</dt>
                                <dd class="col-sm-8">
                                    <?php 
                                    $soldDate = new DateTime($car->data()->solddate);
                                    echo $soldDate->format('F j, Y');
                                    ?>
                                </dd>
                                <?php } ?>
                                
                                <?php if (!empty($car->data()->website)) { ?>
                                <dt class="col-sm-4 text-muted">Website</dt>
                                <dd class="col-sm-8">
                                    <a href="<?= $car->data()->website ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-external-link-alt"></i> Visit Website
                                    </a>
                                </dd>
                                <?php } ?>
                            </dl>

                            <?php if (!empty($car->data()->comments)) { ?>
                            <hr>
                            <h6 class="text-muted mb-3">
                                <i class="fas fa-comment text-secondary"></i> Owner Comments
                            </h6>
                            <div class="bg-light p-3 rounded">
                                <?= nl2br(htmlspecialchars($car->data()->comments)) ?>
                            </div>
                            <?php } ?>

                            <!-- Registry Information -->
                            <hr>
                            <div class="row text-center">
                                <div class="col-6">
                                    <i class="fas fa-plus-circle text-success d-block mb-1"></i>
                                    <small class="text-muted d-block">Added to Registry</small>
                                    <strong>
                                        <?php 
                                        $createdDate = new DateTime($car->data()->ctime);
                                        echo $createdDate->format('M j, Y');
                                        ?>
                                    </strong>
                                </div>
                                <div class="col-6">
                                    <i class="fas fa-edit text-info d-block mb-1"></i>
                                    <small class="text-muted d-block">Last Updated</small>
                                    <strong>
                                        <?php 
                                        $modifiedDate = new DateTime($car->data()->mtime);
                                        echo $modifiedDate->format('M j, Y');
                                        ?>
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Owner Information Card -->
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-user text-primary"></i> Owner Information</h3>
                        </div>
                        <div class="card-body">
                            <dl class="row">
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-user text-primary"></i> Owner Name
                                </dt>
                                <dd class="col-sm-8">
                                    <?= ucfirst($car->data()->fname) ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-map-marker-alt text-primary"></i> Location
                                </dt>
                                <dd class="col-sm-8">
                                    <?php 
                                    $location = [];
                                    if (!empty($car->data()->city)) $location[] = html_entity_decode($car->data()->city);
                                    if (!empty($car->data()->state)) $location[] = html_entity_decode($car->data()->state);
                                    if (!empty($car->data()->country)) $location[] = html_entity_decode($car->data()->country);
                                    echo !empty($location) ? implode(', ', $location) : '<em class="text-muted">Location not specified</em>';
                                    ?>
                                </dd>
                            </dl>
                            
                            <hr>
                            
                            <div class="text-center">
                                <small class="text-muted">
                                    <i class="fas fa-id-card"></i> Registry Owner ID: <?= $car->data()->user_id ?>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Location Map -->
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-map-marked-alt text-primary"></i> Location</h3>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($car->data()->lat) && !empty($car->data()->lon) && 
                                        $car->data()->lat !== null && $car->data()->lon !== null &&
                                        is_numeric($car->data()->lat) && is_numeric($car->data()->lon)) { ?>
                                <div class="map-container map-container-small" style="height: 100%;">
                                    <div id="map" style="height: 400px; width: 100%;"></div>
                                </div>
                                <div class="mt-2 text-center">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle"></i> 
                                        Approximate location
                                    </small>
                                </div>
                            <?php } else { ?>
                                <div class="text-center text-muted py-5">
                                    <i class="fas fa-map-marker-alt fa-3x mb-3"></i>
                                    <p>Location information not available for this car.</p>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6 mb-4">
                    <!-- Car Images -->
                    <div class="card registry-card mb-4">
                        <div class="card-header">
                            <h3 class="mb-0"><i class="fas fa-images text-primary"></i> Photos</h3>
                        </div>
                        <div class="card-body">
                            <?php echo displayCarousel($car); ?>
                        </div>
                    </div>

                    <!-- Factory Data Card -->
                    <?php if (!is_null($car->factory())) { ?>
                    <div class="card registry-card">
                        <div class="card-header">
                            <h3 class="mb-0">
                                <i class="fas fa-industry text-primary"></i> Factory Data
                                <span class="badge bg-warning ms-2">Unverified</span>
                            </h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Note:</strong> This information has not been verified against the official Lotus archives.
                            </div>
                            
                            <dl class="row">
                                <dt class="col-sm-5 text-muted">Production Year</dt>
                                <dd class="col-sm-7"><?= $car->factory()->year ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Production Month</dt>
                                <dd class="col-sm-7"><?= $car->factory()->month ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Production Batch</dt>
                                <dd class="col-sm-7"><?= $car->factory()->batch ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Type</dt>
                                <dd class="col-sm-7"><?= $car->factory()->type ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Chassis</dt>
                                <dd class="col-sm-7">
                                    <strong><?= $car->factory()->serial ?: '<em class="text-muted">Unknown</em>' ?></strong>
                                </dd>
                                
                                <dt class="col-sm-5 text-muted">Chassis Suffix</dt>
                                <dd class="col-sm-7"><?= $car->factory()->suffix ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Engine</dt>
                                <dd class="col-sm-7">
                                    <?= $car->factory()->engineletter ?><?= $car->factory()->enginenumber ?>
                                </dd>
                                
                                <dt class="col-sm-5 text-muted">Gearbox</dt>
                                <dd class="col-sm-7"><?= $car->factory()->gearbox ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Color</dt>
                                <dd class="col-sm-7"><?= $car->factory()->color ?: '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <?php if (!empty($car->factory()->builddate)) { ?>
                                <dt class="col-sm-5 text-muted">Build Date</dt>
                                <dd class="col-sm-7">
                                    <?php 
                                    try {
                                        $buildDate = new DateTime($car->factory()->builddate);
                                        echo $buildDate->format('F j, Y');
                                    } catch (Exception $e) {
                                        echo $car->factory()->builddate;
                                    }
                                    ?>
                                </dd>
                                <?php } ?>
                            </dl>
                            
                            <?php if (!empty($car->factory()->note)) { ?>
                            <hr>
                            <h6 class="text-muted mb-2">Factory Notes</h6>
                            <div class="bg-light p-3 rounded">
                                <small><?= htmlspecialchars($car->factory()->note) ?></small>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>

            <!-- Car History Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card registry-card" id="historyCard">
                        <div class="card-header">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h3 class="mb-0">
                                        <i class="fas fa-history text-primary"></i> Car Update History
                                    </h3>
                                    <small class="text-muted">Track all changes and updates made to this car's registry information</small>
                                </div>
                                <div class="col-md-4 text-md-end">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="historyToggleBtn" data-toggle="collapse" data-target="#historyDetails" aria-expanded="false" aria-controls="historyDetails">
                                        <i class="fas fa-eye"></i> <span id="historyToggleText">Show Details</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="collapse" id="historyDetails">
                                <div class="alert alert-info mb-3">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>About History:</strong> This table shows all changes made to the car's information over time. 
                                    Use horizontal scrolling on mobile devices to view all columns.
                                </div>
                                
                                <div class="table-responsive">
                                    <table id="historytable" class="table table-striped table-bordered table-hover table-sm w-100" aria-describedby="History of car updates">
                                        <thead class="thead-dark">
                                            <tr>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-cog"></i> Operation
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-calendar"></i> Date Modified
                                                </th>
                                                <th scope="col" class="text-nowrap">Year</th>
                                                <th scope="col" class="text-nowrap">Type</th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-barcode"></i> Chassis
                                                </th>
                                                <th scope="col" class="text-nowrap">Series</th>
                                                <th scope="col" class="text-nowrap">Variant</th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-palette"></i> Color
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-cog"></i> Engine
                                                </th>
                                                <th scope="col" class="text-nowrap">Purchase Date</th>
                                                <th scope="col" class="text-nowrap">Sold Date</th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-comment"></i> Comments
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-image"></i> Image
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-user"></i> Owner
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-city"></i> City
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-map"></i> State
                                                </th>
                                                <th scope="col" class="text-nowrap">
                                                    <i class="fas fa-globe"></i> Country
                                                </th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-lightbulb"></i>
                                        <strong>Tip:</strong> You can sort, search, and filter the history data using the controls above the table.
                                        Changes are automatically tracked whenever the car's information is updated.
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Summary Stats when collapsed -->
                            <div class="show" id="historySummary">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-edit text-primary fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0" id="totalUpdates">Loading...</div>
                                                <small class="text-muted">Total Updates</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-calendar-alt text-success fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0" id="lastUpdate">Loading...</div>
                                                <small class="text-muted">Last Updated</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-plus-circle text-info fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0" id="firstAdded">Loading...</div>
                                                <small class="text-muted">Added to Registry</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
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

<!-- Enhanced Car Details JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle history toggle - Bootstrap 4/5 compatible
    const historyDetails = document.getElementById('historyDetails');
    const historyToggleText = document.getElementById('historyToggleText');
    const historySummary = document.getElementById('historySummary');
    const historyToggleBtn = document.getElementById('historyToggleBtn');
    
    if (historyDetails && historyToggleText && historyToggleBtn) {
        // Manual toggle handling for better compatibility
        historyToggleBtn.addEventListener('click', function() {
            const isExpanded = historyDetails.classList.contains('show');
            
            if (isExpanded) {
                // Hide details
                $(historyDetails).collapse('hide');
                historyToggleText.textContent = 'Show Details';
                historyToggleText.previousElementSibling.className = 'fas fa-eye';
                if (historySummary) historySummary.style.display = 'block';
                historyToggleBtn.setAttribute('aria-expanded', 'false');
            } else {
                // Show details
                $(historyDetails).collapse('show');
                historyToggleText.textContent = 'Hide Details';
                historyToggleText.previousElementSibling.className = 'fas fa-eye-slash';
                if (historySummary) historySummary.style.display = 'none';
                historyToggleBtn.setAttribute('aria-expanded', 'true');
            }
        });
        
        // Also listen for Bootstrap collapse events as backup
        $(historyDetails).on('show.bs.collapse shown.bs.collapse', function () {
            historyToggleText.textContent = 'Hide Details';
            historyToggleText.previousElementSibling.className = 'fas fa-eye-slash';
            if (historySummary) historySummary.style.display = 'none';
            historyToggleBtn.setAttribute('aria-expanded', 'true');
        });
        
        $(historyDetails).on('hide.bs.collapse hidden.bs.collapse', function () {
            historyToggleText.textContent = 'Show Details';
            historyToggleText.previousElementSibling.className = 'fas fa-eye';
            if (historySummary) historySummary.style.display = 'block';
            historyToggleBtn.setAttribute('aria-expanded', 'false');
        });
    }
    
    // Update history summary with actual data when DataTable loads
    $(document).on('init.dt', '#historytable', function() {
        const table = $(this).DataTable();
        
        // Wait a bit for data to be fully loaded
        setTimeout(function() {
            const rowCount = table.rows().count();
            document.getElementById('totalUpdates').textContent = rowCount;
            
            if (rowCount > 0) {
                try {
                    // Get all row data
                    const allData = table.rows().data().toArray();
                    const validDates = [];
                    
                    // Process each row to extract dates
                    allData.forEach(function(rowData, index) {
                        // The date is in column index 1 (Date Modified)
                        const dateStr = rowData[1];
                        if (dateStr && typeof dateStr === 'string' && dateStr.trim() !== '') {
                            // Try multiple date parsing approaches
                            let date = new Date(dateStr);
                            
                            // If that fails, try parsing common date formats
                            if (isNaN(date.getTime())) {
                                // Try parsing formats like "2023-12-01 10:30:00"
                                const cleanDateStr = dateStr.replace(/[^\d\-\s:]/g, '').trim();
                                date = new Date(cleanDateStr);
                            }
                            
                            if (!isNaN(date.getTime())) {
                                validDates.push(date);
                            }
                        }
                    });
                    
                    console.log('Found valid dates:', validDates.length, validDates);
                    
                    if (validDates.length > 0) {
                        // Sort dates to find newest and oldest
                        validDates.sort((a, b) => b.getTime() - a.getTime());
                        
                        // Most recent update
                        document.getElementById('lastUpdate').textContent = validDates[0].toLocaleDateString('en-US', {
                            month: 'short', 
                            day: 'numeric', 
                            year: 'numeric'
                        });
                        
                        // First added (oldest date)
                        document.getElementById('firstAdded').textContent = validDates[validDates.length - 1].toLocaleDateString('en-US', {
                            month: 'short', 
                            day: 'numeric', 
                            year: 'numeric'
                        });
                    } else {
                        // No valid dates found, use fallback from car data
                        const createdDate = '<?= $car->data()->ctime ?>';
                        const modifiedDate = '<?= $car->data()->mtime ?>';
                        
                        if (modifiedDate) {
                            const mDate = new Date(modifiedDate);
                            if (!isNaN(mDate.getTime())) {
                                document.getElementById('lastUpdate').textContent = mDate.toLocaleDateString('en-US', {
                                    month: 'short', 
                                    day: 'numeric', 
                                    year: 'numeric'
                                });
                            } else {
                                document.getElementById('lastUpdate').textContent = 'Unknown';
                            }
                        }
                        
                        if (createdDate) {
                            const cDate = new Date(createdDate);
                            if (!isNaN(cDate.getTime())) {
                                document.getElementById('firstAdded').textContent = cDate.toLocaleDateString('en-US', {
                                    month: 'short', 
                                    day: 'numeric', 
                                    year: 'numeric'
                                });
                            } else {
                                document.getElementById('firstAdded').textContent = 'Unknown';
                            }
                        }
                    }
                } catch (error) {
                    console.error('Error parsing history dates:', error);
                    
                    // Fallback to car creation/modification dates
                    try {
                        const createdDate = new Date('<?= $car->data()->ctime ?>');
                        const modifiedDate = new Date('<?= $car->data()->mtime ?>');
                        
                        document.getElementById('lastUpdate').textContent = !isNaN(modifiedDate.getTime()) ? 
                            modifiedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown';
                        document.getElementById('firstAdded').textContent = !isNaN(createdDate.getTime()) ? 
                            createdDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Unknown';
                    } catch (fallbackError) {
                        document.getElementById('lastUpdate').textContent = 'Unknown';
                        document.getElementById('firstAdded').textContent = 'Unknown';
                    }
                }
            } else {
                document.getElementById('lastUpdate').textContent = 'No history';
                document.getElementById('firstAdded').textContent = 'No history';
            }
        }, 500); // Wait 500ms for DataTable to fully initialize
    });
    
});
</script>

<!-- Print Styles -->
<style>
@media print {
    .btn, .breadcrumb, .card-header .row .col-md-4 { display: none !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; }
    .bg-primary { background-color: #0056b3 !important; -webkit-print-color-adjust: exact; }
    .text-white { color: #fff !important; -webkit-print-color-adjust: exact; }
    #historyDetails { display: block !important; }
    #historySummary { display: none !important; }
    .collapse { display: block !important; }
    body { font-size: 12px; }
    h1 { font-size: 18px; }
    h3 { font-size: 14px; }
}
</style>
