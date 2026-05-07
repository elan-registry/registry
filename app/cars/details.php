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

require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

if (!securePage($php_self)) {
    die();
}

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
        Redirect::to($us_url_root . '/app/cars/index.php');
    }
    
    // Cache car data objects to eliminate repeated method calls (Performance Optimization)
    $carData = $car->data();
    $factoryData = $car->factory();
    $carHistory = $car->history();
    $historyCount = $carHistory ? count($carHistory) : 0;
    
    // Pre-process common dates to avoid redundant DateTime creation
    $purchaseDate = null;
    $soldDate = null;
    $buildDate = null;
    
    if (!empty($carData->purchasedate)) {
        try {
            $purchaseDate = new DateTime($carData->purchasedate);
        } catch (Exception $e) {
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Invalid purchase date format for car ID $carID: " . $carData->purchasedate);
            $purchaseDate = null;
        }
    }
    
    if (!empty($carData->solddate)) {
        try {
            $soldDate = new DateTime($carData->solddate);
        } catch (Exception $e) {
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Invalid sold date format for car ID $carID: " . $carData->solddate);
            $soldDate = null;
        }
    }
    
    if ($factoryData && !empty($factoryData->builddate)) {
        try {
            $buildDate = new DateTime($factoryData->builddate);
        } catch (Exception $e) {
            logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_SYSTEM_ERROR, "Invalid build date format for car ID $carID: " . $factoryData->builddate);
            $buildDate = null;
        }
    }
    
} else {
    // Shouldn't be here unless someone is mangling the url
    logger($user->data()->id ?? 0, LogCategories::LOG_CATEGORY_VALIDATION_ERROR, 'Car details page accessed without car_id parameter');
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
                                <i class="fas fa-car"></i> <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?> 
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
                                        <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?> Lotus Elan <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        <?= !empty($carData->variant) ? ' (' . htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') . ')' : '' ?>
                                    </h1>
                                    <div class="row mt-4">
                                        <div class="col-sm-6 col-lg-3 mb-3">
                                            <div class="d-flex align-items-center">
                                                <i class="fas fa-hashtag me-3 fa-lg"></i>
                                                <div>
                                                    <div class="text-white-75 fw-medium mb-1">Registry ID</div>
                                                    <div class="fw-bold fs-5"><?= $carData->id ?></div>
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
                                    if (isset($user) && $user->isLoggedIn()) {
                                        $isOwner = ($user->data()->id === $car->data()->user_id);
                                        $isAdmin = isRegistryAdmin($user->data()->id); // Administrator (2) or Editor (3)

                                        if ($isOwner) { ?>
                                            <form method="POST" action="<?= $us_url_root ?>app/cars/form.php" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="updateCar" />
                                                <input type="hidden" name="car_id" id="car_id" value="<?= $carData->id ?>" />
                                                <button class="btn btn-light btn-lg" type="submit">
                                                    <i class="fas fa-edit"></i> Update Car
                                                </button>
                                            </form>
                                        <?php } elseif ($isAdmin) { ?>
                                            <div class="alert alert-warning mb-2">
                                                <i class="fas fa-shield-alt"></i> <strong>Administrative Override:</strong>
                                                You are editing a car that you do not own using Administrator/Editor privileges.
                                            </div>
                                            <form method="POST" action="<?= $us_url_root ?>app/cars/form.php" class="d-inline me-2">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="updateCar" />
                                                <input type="hidden" name="car_id" id="car_id" value="<?= $carData->id ?>" />
                                                <input type="hidden" name="admin_override" value="1" />
                                                <button class="btn btn-warning btn-lg" type="submit">
                                                    <i class="fas fa-edit"></i> Admin Edit Car
                                                </button>
                                            </form>
                                            <form method="POST" action="<?= $us_url_root ?>app/contact/owner.php" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="contact_owner" />
                                                <input type="hidden" name="car_id" id="car_id" value="<?= $carData->id ?>" />
                                                <button class="btn btn-light btn-lg" type="submit">
                                                    <i class="fas fa-envelope"></i> Contact Owner
                                                </button>
                                            </form>
                                        <?php
                                        } else {
                                        ?>
                                            <form method="POST" action="<?= $us_url_root ?>app/contact/owner.php" class="d-inline">
                                                <input type="hidden" name="csrf" value="<?= Token::generate(); ?>" />
                                                <input type="hidden" name="action" value="contact_owner" />
                                                <input type="hidden" name="car_id" id="car_id" value="<?= $carData->id ?>" />
                                                <button class="btn btn-light btn-lg" type="submit">
                                                    <i class="fas fa-envelope"></i> Contact Owner
                                                </button>
                                            </form>
                                    <?php
                                        }
                                    } else {
                                        echo "<div class='text-white-50'><i class='fas fa-sign-in-alt'></i> Log in to contact owner</div>";
                                        echo "<input type='hidden' name='car_id' id='car_id' value='" . htmlspecialchars((string)$carData->id, ENT_QUOTES, 'UTF-8') . "' />";
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
                                    <?= htmlspecialchars((string)($carData->year ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-tag text-primary"></i> Series
                                </dt>
                                <dd class="col-sm-8">
                                    <?= htmlspecialchars($carData->series ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    <?= !empty($carData->variant) ? ' - ' . htmlspecialchars($carData->variant, ENT_QUOTES, 'UTF-8') : '' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-car text-primary"></i> Type
                                </dt>
                                <dd class="col-sm-8">
                                    <?= $carData->type ? htmlspecialchars($carData->type, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-barcode text-primary"></i> Chassis
                                </dt>
                                <dd class="col-sm-8">
                                    <strong><?= $carData->chassis ? htmlspecialchars($carData->chassis, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?></strong>
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
                                    <?= $carData->color ? htmlspecialchars($carData->color, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">Engine</dt>
                                <dd class="col-sm-8">
                                    <?= $carData->engine ? htmlspecialchars($carData->engine, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                            </dl>

                            <hr>

                            <!-- Ownership & History -->
                            <h6 class="text-muted mb-3">
                                <i class="fas fa-history text-secondary"></i> Ownership & History
                            </h6>
                            <dl class="row mb-4">
                                <?php if ($purchaseDate) { ?>
                                <dt class="col-sm-4 text-muted">Purchase Date</dt>
                                <dd class="col-sm-8">
                                    <?= $purchaseDate->format('F j, Y') ?>
                                </dd>
                                <?php } ?>
                                
                                <?php if ($soldDate) { ?>
                                <dt class="col-sm-4 text-muted">Sold Date</dt>
                                <dd class="col-sm-8">
                                    <?= $soldDate->format('F j, Y') ?>
                                </dd>
                                <?php } ?>
                                
                                <?php if (!empty($carData->website) && in_array(strtolower((string)parse_url($carData->website, PHP_URL_SCHEME)), ['http', 'https'], true)) { ?>
                                <dt class="col-sm-4 text-muted">Website</dt>
                                <dd class="col-sm-8">
                                    <a href="<?= htmlspecialchars($carData->website, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-external-link-alt"></i> Visit Website
                                    </a>
                                </dd>
                                <?php } ?>
                            </dl>

                            <?php if (!empty($carData->comments)) { ?>
                            <hr>
                            <h6 class="text-muted mb-3">
                                <i class="fas fa-comment text-secondary"></i> Owner Comments
                            </h6>
                            <div class="bg-light p-3 rounded">
                                <?= nl2br(htmlspecialchars($carData->comments, ENT_QUOTES, 'UTF-8')) ?>
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
                                    <?= !empty($carData->fname) ? htmlspecialchars(ucfirst($carData->fname), ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Not specified</em>' ?>
                                </dd>
                                
                                <dt class="col-sm-4 text-muted">
                                    <i class="fas fa-map-marker-alt text-primary"></i> Location
                                </dt>
                                <dd class="col-sm-8">
                                    <?php
                                    $location = [];
                                    if (!empty($carData->city)) $location[] = html_entity_decode($carData->city);
                                    if (!empty($carData->state)) $location[] = html_entity_decode($carData->state);
                                    if (!empty($carData->country)) $location[] = html_entity_decode($carData->country);
                                    echo !empty($location) ? htmlspecialchars(implode(', ', $location), ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Location not specified</em>';
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
                            <?php if (!empty($carData->lat) && !empty($carData->lon) &&
                                        $carData->lat !== null && $carData->lon !== null &&
                                        is_numeric($carData->lat) && is_numeric($carData->lon)) { ?>
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
                            <?php echo CarView::displayCarousel($car); ?>
                        </div>
                    </div>
                    
                    <?php
                    // $carHistory and $historyCount already loaded at top of file
                    ?>

                    <!-- Factory Data Card -->
                    <?php if (!is_null($factoryData)) { ?>
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
                                <dd class="col-sm-7"><?= $factoryData->year ? htmlspecialchars((string)$factoryData->year, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Production Month</dt>
                                <dd class="col-sm-7"><?= $factoryData->month ? htmlspecialchars((string)$factoryData->month, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Production Batch</dt>
                                <dd class="col-sm-7"><?= $factoryData->batch ? htmlspecialchars((string)$factoryData->batch, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Type</dt>
                                <dd class="col-sm-7"><?= $factoryData->type ? htmlspecialchars((string)$factoryData->type, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Chassis</dt>
                                <dd class="col-sm-7">
                                    <strong><?= $factoryData->serial ? htmlspecialchars((string)$factoryData->serial, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></strong>
                                </dd>
                                
                                <dt class="col-sm-5 text-muted">Chassis Suffix</dt>
                                <dd class="col-sm-7"><?= $factoryData->suffix ? htmlspecialchars((string)$factoryData->suffix, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Engine</dt>
                                <dd class="col-sm-7">
                                    <?= htmlspecialchars((string)($factoryData->engineletter ?? ''), ENT_QUOTES, 'UTF-8') ?><?= htmlspecialchars((string)($factoryData->enginenumber ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </dd>
                                
                                <dt class="col-sm-5 text-muted">Gearbox</dt>
                                <dd class="col-sm-7"><?= $factoryData->gearbox ? htmlspecialchars((string)$factoryData->gearbox, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <dt class="col-sm-5 text-muted">Factory Color</dt>
                                <dd class="col-sm-7"><?= $factoryData->color ? htmlspecialchars((string)$factoryData->color, ENT_QUOTES, 'UTF-8') : '<em class="text-muted">Unknown</em>' ?></dd>
                                
                                <?php if ($buildDate) { ?>
                                <dt class="col-sm-5 text-muted">Build Date</dt>
                                <dd class="col-sm-7">
                                    <?= $buildDate->format('F j, Y') ?>
                                </dd>
                                <?php } ?>
                            </dl>
                            
                            <?php if (!empty($factoryData->note)) { ?>
                            <hr>
                            <h6 class="text-muted mb-2">Factory Notes</h6>
                            <div class="bg-light p-3 rounded">
                                <small><?= htmlspecialchars($factoryData->note, ENT_QUOTES, 'UTF-8') ?></small>
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
                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="historyToggleBtn" data-bs-toggle="collapse" data-bs-target="#historyDetails" aria-expanded="false" aria-controls="historyDetails">
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
                            
                            <?php
                            // $carHistory and $historyCount are loaded above
                            ?>
                            
                            <!-- Summary Stats when collapsed -->
                            <div class="show" id="historySummary">
                                <?php
                                
                                // Find first and last dates
                                $firstDate = $lastDate = null;
                                if ($historyCount > 0) {
                                    $dates = array_map(function($h) { return $h->timestamp; }, $carHistory);
                                    $dates = array_filter($dates);
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

<script src="<?=$us_url_root?>usersc/js/datatables.min.js"></script>
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/datatables.min.css">

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
<script src='<?= $us_url_root ?>app/assets/js/highlightDifferences.min.js'></script>
<script>
const img_root = '<?= $us_url_root . $settings->elan_image_dir ?>';
window.carDetailsConfig = {
    carId: <?= (int)$carData->id ?>,
    csrf: '<?= Token::generate() ?>'
};
</script>
<script src='<?= $us_url_root ?>app/assets/js/car_details.min.js'></script>



<?php if (!empty($carData->lat) && $carData->lat != 0 && !empty($carData->lon) && $carData->lon != 0): ?>
<link rel="stylesheet" href="<?= $us_url_root ?>usersc/css/maplibre-gl.css">
<script src="<?= $us_url_root ?>usersc/js/maplibre-gl.min.js"></script>
<script>
(function () {
    if (typeof maplibregl === 'undefined') {
        var mapEl = document.getElementById('map');
        if (mapEl) {
            var wrap = document.createElement('div');
            wrap.className = 'd-flex flex-column align-items-center justify-content-center h-100 text-muted';
            var msg = document.createElement('p');
            msg.className = 'mb-2';
            msg.textContent = 'Map unavailable. Please try refreshing.';
            var btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-secondary';
            btn.type = 'button';
            btn.textContent = 'Retry';
            btn.addEventListener('click', function () { location.reload(); });
            wrap.appendChild(msg);
            wrap.appendChild(btn);
            mapEl.appendChild(wrap);
        }
        return;
    }

    const lat = <?= (float)$carData->lat ?>;
    const lon = <?= (float)$carData->lon ?>;
    const series = <?= json_encode((string)($carData->series ?? '')) ?>;

    const seriesClass = (function(s) {
        s = s.toLowerCase();
        if (s.includes('sprint')) return 'sprint';
        if (s.includes('+2'))     return 'plus2';
        if (s.includes('s1'))     return 's1';
        if (s.includes('s2'))     return 's2';
        if (s.includes('s3'))     return 's3';
        if (s.includes('s4'))     return 's4';
        return 'unknown';
    })(series);

    const map = new maplibregl.Map({
        container: 'map',
        style: '<?= $us_url_root ?>usersc/js/versatiles-colorful.json',
        center: [lon, lat],
        zoom: 8,
        scrollZoom: false,
        attributionControl: false
    });

    map.addControl(new maplibregl.AttributionControl({ compact: true }), 'bottom-right');
    map.once('idle', function () {
        var attrEl = document.querySelector('#map .maplibregl-ctrl-attrib');
        if (attrEl) attrEl.classList.remove('maplibregl-compact-show');
    });
    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(new maplibregl.ScaleControl({ unit: 'metric' }), 'bottom-left');

    map.on('load', function () {
        const el = document.createElement('div');
        el.className = 'elan-marker-wrapper';
        const dot = document.createElement('div');
        dot.className = 'elan-marker ' + seriesClass;
        el.appendChild(dot);

        new maplibregl.Marker({ element: el, anchor: 'bottom' })
            .setLngLat([lon, lat])
            .addTo(map);
    });

    map.on('error', function (e) {
        // Tile and source load errors are transient — let MapLibre retry
        if (e.sourceId !== undefined || (e.error && typeof e.error.status === 'number')) {
            console.warn('[ElanRegistry] Map tile/source error (non-fatal):', e.error);
            return;
        }
        console.error('[ElanRegistry] Fatal map error on car details page:', e.error);
        const mapEl = document.getElementById('map');
        if (!mapEl) return;
        while (mapEl.firstChild) {
            mapEl.removeChild(mapEl.firstChild);
        }
        const wrap = document.createElement('div');
        wrap.className = 'd-flex flex-column align-items-center justify-content-center h-100 text-muted';
        const msg = document.createElement('p');
        msg.className = 'mb-2';
        msg.textContent = 'Map unavailable. Please try refreshing.';
        const btn = document.createElement('button');
        btn.className = 'btn btn-sm btn-outline-secondary';
        btn.type = 'button';
        btn.textContent = 'Retry';
        btn.addEventListener('click', function () { location.reload(); });
        wrap.appendChild(msg);
        wrap.appendChild(btn);
        mapEl.appendChild(wrap);
    });
}());
</script>
<?php endif; ?>

<!-- Simple History Toggle JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle history toggle
    const historyDetails = document.getElementById('historyDetails');
    const historyToggleText = document.getElementById('historyToggleText');
    const historySummary = document.getElementById('historySummary');
    const historyToggleBtn = document.getElementById('historyToggleBtn');
    
    if (historyDetails && historyToggleText && historyToggleBtn) {
        const historyToggleIcon = historyToggleBtn.querySelector('.fas');
        // The button has data-bs-toggle="collapse" so Bootstrap 5 handles show/hide
        // automatically. Listen to collapse events to update label, icon, and summary.
        historyDetails.addEventListener('shown.bs.collapse', function() {
            historyToggleText.textContent = 'Hide Details';
            if (historyToggleIcon) historyToggleIcon.className = 'fas fa-eye-slash';
            if (historySummary) historySummary.style.display = 'none';
        });

        historyDetails.addEventListener('hidden.bs.collapse', function() {
            historyToggleText.textContent = 'Show Details';
            if (historyToggleIcon) historyToggleIcon.className = 'fas fa-eye';
            if (historySummary) historySummary.style.display = 'block';
        });
    }
});
</script>

<!-- Print Styles -->
<style>
@media print {
    .btn, .breadcrumb, .card-header .row .col-md-4 { display: none !important; }
    .card { border: 1px solid #000 !important; box-shadow: none !important; }
    .bg-primary { background-color: #0056b3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .text-white { color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    #historyDetails { display: block !important; }
    #historySummary { display: none !important; }
    .collapse { display: block !important; }
    body { font-size: 12px; }
    h1 { font-size: 18px; }
    h3 { font-size: 14px; }
}
@media (max-width: 575.98px) {
    #map { height: 220px !important; }
}
.elan-marker {
    width: 18px; height: 18px;
    border-radius: 50% 50% 50% 0;
    border: 2px solid rgba(0,0,0,0.4);
    transform: rotate(-45deg);
}
.elan-marker-wrapper {
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    transform: rotate(45deg);
}
.elan-marker.s1     { background: #e53e3e; }
.elan-marker.s2     { background: #3182ce; }
.elan-marker.s3     { background: #d69e2e; }
.elan-marker.s4     { background: #e2e8f0; border-color: rgba(0,0,0,0.5); }
.elan-marker.sprint { background: #805ad5; }
.elan-marker.plus2  { background: #38a169; }
.elan-marker.unknown{ background: #718096; }
</style>
