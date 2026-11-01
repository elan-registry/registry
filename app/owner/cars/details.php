<?php
declare(strict_types=1);

/**
 * details.php
 * Displays detailed information about a specific car in the registry.
 *
 * Shows car data, owner info, factory info, images, location map, and update history.
 * Uses the site template for layout and security checks for access.
 *
 * @author Elan Registry Team
 * @copyright 2025
 */

$pageTitle = 'Car Details';
$pageDescription = 'Detailed registry record for a Lotus Elan or Elan Plus 2, including chassis number, ownership history, and factory build data.';

require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Car\Car;
use ElanRegistry\Car\CarBadges;
use ElanRegistry\Car\CarRepository;
use ElanRegistry\CarView;
use ElanRegistry\Exceptions\CarDatabaseException;
use ElanRegistry\LogCategories;

if (!securePage($php_self)) {
    die();
}

// The page differs per viewer and carries members-only history (#2144), so no
// shared cache may keep it. Set explicitly rather than rely on PHP's session
// cache_limiter default.
header('Cache-Control: private, no-store');

// Get car information from URL parameter
if (!empty($_GET)) {
    $carID = Input::get('car_id');

    // Get the car information - cast to int for type safety
    $car = new Car((int)$carID);

    // Validate that car exists
    if (!$car->exists()) {
        // Log car not found error
        logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, "Car details requested for non-existent car ID: $carID");
        // Redirect to list if car not found
        Redirect::to($us_url_root . 'app/owner/cars/index.php');
        exit;
    }
    
    // Cache car data objects to eliminate repeated method calls (Performance Optimization)
    $carData = $car->data();
    $factoryData = $car->factory();
    // History rows carry past owners' first name and location, so they are for
    // members only (#2144). No try/catch: Car::find() already catches a failed
    // history query, logs it, and leaves an empty history, so history() does
    // not throw.
    $carHistory = [];
    $historyCount = 0;
    if ($user->isLoggedIn()) {
        $carHistory = $car->history();
        $historyCount = count($carHistory);
    }

    // Pre-process common dates to avoid redundant DateTime creation
    $purchaseDate = null;

    if (!empty($carData->purchasedate)) {
        try {
            $purchaseDate = new DateTime($carData->purchasedate);
        } catch (Exception $e) {
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Invalid purchase date format for car ID $carID: " . $carData->purchasedate);
            $purchaseDate = null;
        }
    }
    
    $soldDate = CarBadges::soldDate($carData->solddate ?? null, (int) $carID);

} else {
    // Shouldn't be here unless someone is mangling the url
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car details page accessed without car_id parameter');
    Redirect::to($us_url_root . 'app/owner/cars/index.php');
    exit;
}

// $current_url is 'https:///…' when the request's host is not trusted
// (server_globals.php), so build the schema URL from getBaseUrl() in that case.
$schemaUrl = !empty($host)
    ? ($current_url ?? '')
    : getBaseUrl() . '/app/owner/cars/details.php?car_id=' . (int) $carID;
$carSchema = CarView::buildCarSchema($carData, $schemaUrl);
$carSchemaJson = json_encode(
    $carSchema,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);
if ($carSchemaJson === false) {
    // Legacy chassis/color/series data predates the current input-sanitization
    // pipeline (see CarView::buildCarSchema() docblock) and could in principle
    // contain malformed UTF-8, which json_encode() rejects. Log and skip the
    // block rather than emitting an empty, invalid <script type="application/ld+json">.
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "JSON-LD encoding failed for car ID $carID: " . json_last_error_msg());
}
?>
<?php if ($carSchemaJson !== false): ?>
<script type="application/ld+json"><?= $carSchemaJson ?></script>
<?php endif; ?>
<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            
            <!-- Breadcrumb Navigation -->
            <div class="row">
                <div class="col-12">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= $us_url_root ?>" class="text-primary"><i class="fas fa-home"></i> Home</a></li>
                            <li class="breadcrumb-item"><a href="<?= $us_url_root ?>app/owner/cars/index.php" class="text-primary"><i class="fas fa-list"></i> Cars</a></li>
                            <li class="breadcrumb-item active text-muted" aria-current="page">
                                <i class="fas fa-car"></i> <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?> 
                            </li>
                        </ol>
                    </nav>
                </div>
            </div>

            <!-- Quick Facts Summary Card -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card registry-card bg-primary text-white" style="border-top: 5px solid var(--er-accent);">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h1 class="mb-0 card-header-er-primary-text">
                                        <i class="fas fa-car me-2"></i>
                                        <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?> Lotus Elan <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        <?= !empty($carData->variant) ? ' (' . htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') . ')' : '' ?>
                                    </h1>
                                    <div class="row mt-4">
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-hashtag me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Registry ID</div>
                                                    <div class="fw-bold fs-5"><?= (int)$carData->id ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-barcode me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Chassis</div>
                                                    <div class="fw-bold fs-5"><?= htmlspecialchars($carData->chassis ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-palette me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Color</div>
                                                    <div class="fw-bold fs-5"><?= htmlspecialchars($carData->color ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-cog me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Engine</div>
                                                    <div class="fw-bold fs-5"><?= htmlspecialchars($carData->engine ?: 'Not specified', ENT_QUOTES, 'UTF-8') ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 text-md-end">
                                        <?php
                                    if (!$user->isLoggedIn()) {
                                        $context = 'guest_detail';
                                    } elseif ($user->data()->id === $carData->user_id) {
                                        $context = 'owner_detail';
                                    } elseif (isRegistryAdmin($user->data()->id)) {
                                        $context = 'admin_detail';
                                    } else {
                                        $context = 'visitor_detail';
                                    }
                                    $heroCarId = (int)$carData->id;
                                    include $abs_us_root . $us_url_root . 'app/views/cars/_car_hero_actions.php';
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6 mb-4 order-last order-lg-first">
                    <?php
                    $headingTag = 'h3';
                    $viewerIsRegistryAdmin = $user->isLoggedIn() && isRegistryAdmin($user->data()->id);
                    // The deliverability flags live on the owner's profile, not the car
                    // row, and only registry admins see the Email on file row.
                    $ownerEmailState = null;
                    if ($viewerIsRegistryAdmin && !empty($carData->user_id)) {
                        try {
                            $ownerEmailState = (new CarRepository(dbi()))->effectiveEmailFlags((int) $carData->user_id);
                        } catch (CarDatabaseException $e) {
                            logger($user->data()->id, LogCategories::LOG_CATEGORY_DATABASE_ERROR, "Owner email state lookup failed for car ID $carID: " . $e->getMessage());
                        }
                    }
                    include $abs_us_root . $us_url_root . 'app/views/cars/_vehicle_info_card.php';
                    ?>

                    <?php
                    $showOwnerId = true;
                    include $abs_us_root . $us_url_root . 'app/views/cars/_owner_info_card.php';
                    include $abs_us_root . $us_url_root . 'app/views/cars/_location_map_card.php';
                    ?>
                </div>
                
                <div class="col-lg-6 mb-4 order-first order-lg-last">
                    <?php include $abs_us_root . $us_url_root . 'app/views/cars/_photos_card.php'; ?>

                    <!-- Factory Data Card -->
                    <?php if (!is_null($factoryData)) {
                        $headingTag = 'h3';
                        $carId = (int)$carID;
                        include $abs_us_root . $us_url_root . 'app/views/cars/_factory_data_card.php';
                    } ?>
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
                                <?php if ($user->isLoggedIn()) { ?>
                                <div class="col-md-4 text-md-end">
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="historyToggleBtn" data-bs-toggle="collapse" data-bs-target="#historyDetails" aria-expanded="false" aria-controls="historyDetails">
                                        <i class="fas fa-eye"></i> <span id="historyToggleText">Show Details</span>
                                    </button>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                        <?php if (!$user->isLoggedIn()) { ?>
                        <div class="card-body">
                            <div class="alert alert-primary mb-0">
                                <i class="fas fa-info-circle" aria-hidden="true"></i>
                                <a href="<?= htmlspecialchars($us_url_root, ENT_QUOTES, 'UTF-8') ?>users/login.php">Log in</a> to see this car's update history.
                            </div>
                        </div>
                        <?php } else { ?>
                        <div class="card-body">
                            <div class="collapse" id="historyDetails">
                                <div class="alert alert-primary mb-3">
                                    <i class="fas fa-info-circle"></i>
                                    <strong>About History:</strong> This table shows all changes made to the car's information over time.
                                    Tap any row to expand hidden columns on mobile devices.
                                </div>
                                
                                <div class="table-responsive">
                                    <table id="carHistoryTable" class="table table-striped table-bordered table-hover table-sm w-100" aria-describedby="History of car updates">
                                        <thead class="table-dark">
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
                                        <tbody>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-lightbulb"></i>
                                        <strong>Tip:</strong> You can sort, search, and filter the history data using the controls above the table.
                                        Changes are automatically tracked whenever the car's information is updated.
                                    </small>
                                    <table><tr><td>Cell Colour Coding Information:</td><td class="table-success">Newly Inserted value</td><td class="table-danger">Deleted Value</td><td class="table-info">Changed value</td></tr></table>
                                </div>
                            </div>
                            
                            <!-- Summary Stats when collapsed -->
                            <div class="show" id="historySummary">
                                <?php
                                
                                // Find first and last dates
                                $firstDate = $lastDate = null;
                                if ($historyCount > 0) {
                                    $dates = array_filter(array_column($carHistory, 'timestamp'));
                                    if (!empty($dates)) {
                                        sort($dates);
                                        $firstDate = reset($dates);
                                        $lastDate = end($dates);
                                    }
                                }
                                
                                // Fallback to car creation/modification dates
                                if (!$firstDate) $firstDate = $car->data()->ctime;
                                if (!$lastDate) $lastDate = $car->data()->mtime;
                                ?>
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-edit text-primary fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0"><?= $historyCount ?></div>
                                                <small class="text-muted">Total Updates</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-calendar-alt text-success fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0">
                                                    <?php
                                                    if ($lastDate) {
                                                        try {
                                                            $date = new DateTime($lastDate);
                                                            echo $date->format('M j, Y');
                                                        } catch (Exception $e) {
                                                            echo 'Unknown';
                                                        }
                                                    } else {
                                                        echo 'Unknown';
                                                    }
                                                    ?>
                                                </div>
                                                <small class="text-muted">Last Updated</small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="d-flex align-items-center justify-content-center">
                                            <i class="fas fa-plus-circle text-info fa-2x me-3"></i>
                                            <div>
                                                <div class="h5 mb-0">
                                                    <?php
                                                    if ($firstDate) {
                                                        try {
                                                            $date = new DateTime($firstDate);
                                                            echo $date->format('M j, Y');
                                                        } catch (Exception $e) {
                                                            echo 'Unknown';
                                                        }
                                                    } else {
                                                        echo 'Unknown';
                                                    }
                                                    ?>
                                                </div>
                                                <small class="text-muted">Added to Registry</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- footers -->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<?php if ($user->isLoggedIn()): ?>
<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/datatables-fixedheader.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/datatables-responsive.min.js"></script>
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">
<?php endif; ?>

<?php include 'includes/elan-config-island.php'; ?>
<script nonce="<?= htmlspecialchars($userspice_nonce ?? '', ENT_QUOTES, 'UTF-8') ?>">
window.carDetailsConfig = {
    carId: <?= (int)$carData->id ?>,
    urlRoot: <?= json_encode((string)$us_url_root, JSON_HEX_TAG | JSON_HEX_AMP) ?>
};
window.img_root = <?= json_encode((string)($us_url_root . ELAN_IMAGE_DIR), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src='<?= $us_url_root ?>app/assets/js/imagedisplay.min.js?v=<?= ASSET_VERSION ?>'></script>
<?php if ($user->isLoggedIn()): ?>
<script src='<?= $us_url_root ?>app/assets/js/highlightDifferences.min.js?v=<?= ASSET_VERSION ?>'></script>
<script src='<?= $us_url_root ?>app/assets/js/car_details.min.js?v=<?= ASSET_VERSION ?>'></script>
<?php endif; ?>

<?php include $abs_us_root . $us_url_root . 'app/views/cars/_location_map_scripts.php'; ?>

<!-- Print Styles -->
<style>
@media print {
    .btn, .breadcrumb, .card-header .row .col-md-4 { display: none !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; }
    .bg-primary { background-color: #00563F !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .text-white { color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    #historyDetails { display: block !important; }
    #historySummary { display: none !important; }
    .collapse { display: block !important; }
    body { font-size: 12px; }
    h1 { font-size: 18px; }
    h3 { font-size: 14px; }
}
</style>
