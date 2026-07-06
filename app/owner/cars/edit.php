<?php
declare(strict_types=1);

/**
 * edit.php
 * Allows users to add or edit car records in the registry.
 *
 * Handles form input, validation, image uploads, and updates to car data.
 * Uses the site template for layout and security checks for access.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 * @package ElanRegistry
 * @version 2.0
 */
require_once '../../../users/init.php';
require_once $abs_us_root . $us_url_root . 'usersc/includes/elanregistry_prep.php';

use ElanRegistry\Documentation\DocumentPortalTemplate;

if (!securePage($php_self)) {
    die();
}

// Ensure settings have default values for image configuration
$settings->elan_image_upload_max_size ??= 2;
$settings->elan_image_display_max_size ??= 2048;
$settings->elan_image_thumbnail_sizes ??= '100,300,768,1024,2048';

$maximages = $settings->elan_image_max;

$cardetails = [];
// Initialize car details array with default null values
$cardetails['id']           = null;
$cardetails['year']         = null;
$cardetails['model']        = null;
$cardetails['series']       = null;
$cardetails['variant']      = null;
$cardetails['type']         = null;
$cardetails['chassis']      = null;
$cardetails['color']        = null;
$cardetails['engine']       = null;
$cardetails['purchasedate'] = null;
$cardetails['solddate']     = null;
$cardetails['website']      = null;
$cardetails['comments']     = null;
$cardetails['image']        = null;

// Placeholder text for form input fields
$carprompt['chassis']       = 'Enter Chassis Number';
$carprompt['color']         = 'Enter the current color of the car';
$carprompt['engine']        = 'Enter Engine number - LPAxxxxx';
$carprompt['comments']      = 'Please give a brief history of your car and anything special';
$carprompt['website']       = 'Website URL';

// Default action when no form submission
$action = 'addCar';

// Initialize message arrays
$errors = [];
$successes = [];

if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {

        $action = Input::get('action');
        $cardetails['id']  = Input::get('car_id') ? (int)Input::get('car_id') : null;

        if ($action === 'updateCar') {
            updateCarDetails($cardetails);
        } else {
            $errors[] = 'No valid action';
        }
    } // End Post with data
    
    // Convert error/success arrays to UserSpice session messages (Issue #237)
    if (!empty($errors)) {
        foreach ($errors as $error) {
            usError($error);
        }
    }
    if (!empty($successes)) {
        foreach ($successes as $success) {
            usSuccess($success);
        }
    }
    
    // Messages will be displayed by UserSpice session system in template
}

/**
 * Update car details from database for editing
 *
 * @param array &$car Reference to car details array
 * @return void
 * @throws Exception If user doesn't own the car
 */
function updateCarDetails(array &$car): void
{
    global $user;

    if (empty($car['id'])) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Empty car_id field in GET');
        return;
    }

    $carQ = new Car((int) $car['id']);

    // Security: Verify user ownership or admin/editor permissions
    $isOwner = ($user->data()->id == $carQ->data()->user_id);
    $hasAdminAccess = hasPerm([2, 3]); // Permission 2 = Administrator, 3 = Editor
    
    if (!$isOwner && !$hasAdminAccess) {
        // Security violation: User attempting to access car they don't own and don't have admin/editor access
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Access denied for car edit - USER ' . $user->data()->id . ' CAR ' . $car['id'] . ' (not owner, no admin/editor perms)');
        $user->logout();
        exit();
    }
    
    // Log admin/editor access for audit trail
    if (!$isOwner && $hasAdminAccess) {
        logger($user->data()->id, LogCategories::LOG_CATEGORY_CAR_ACTIONS, 'Admin/Editor accessing car edit - USER ' . $user->data()->id . ' CAR ' . $car['id']);
    }

    foreach ($carQ->data() as $key => $value) {
        // Copy data into the $car
        $car[$key] = $value;
    }
}
?>
<link rel="stylesheet" href="<?= $us_url_root ?>app/assets/css/edit_car.min.css?v=<?= ASSET_VERSION ?>">

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <?= DocumentPortalTemplate::renderBreadcrumb('add_car', $us_url_root, $action === 'addCar' ? '' : 'Edit Car', $action === 'addCar' ? '' : 'fa-edit') ?>
            <div class="row justify-content-center">
                <div class="col-xl-10 col-lg-11 col-md-12">
            <?php
            // Show admin override warning if applicable
            if (isset($cardetails['id']) && isset($user) && $user->isLoggedIn()) {
                $editCarObj = new Car((int)$cardetails['id']);
                $isEditOwner = ($user->data()->id == $editCarObj->data()->user_id);
                $hasEditAdminAccess = hasPerm([2, 3]); // Permission 2 = Administrator, 3 = Editor

                if (!$isEditOwner && $hasEditAdminAccess) { ?>
                    <div class="alert alert-warning text-center mb-4">
                        <h5><i class="fas fa-shield-alt"></i> Administrative Override Active</h5>
                        <p class="mb-0">You are editing a car that you do not own using Administrator/Editor privileges. All changes will be logged for audit purposes.</p>
                    </div>
            <?php }
            } ?>

            <form id="editCar" name="editCar" method="post" enctype="multipart/form-data" novalidate>
                <!-- CSRF Token (must be early for AJAX validation) -->
                <input type="hidden" name="csrf" id="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="action" id="action" value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" />
                <input type="hidden" name="car_id" id="car_id" value="<?= htmlspecialchars((string)($cardetails['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" />
                <div id="message" class="d-none"></div>

                <h5 class="form-section-heading"><i class="fas fa-car me-2"></i>Car Details</h5>
                <!-- Car Info -->
                <?php
                if (isset($cardetails['id'])) {
                ?>

                    <div class="mb-3 row">
                        <label for="car_id_display" class="col-md-3 col-12 col-form-label">Car ID</label>
                        <div class="col-12 col-sm-9">
                            <input type="text" id="car_id_display" class="form-control-plaintext" value="<?= htmlspecialchars((string)($cardetails['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        </div>
                    </div>
                <?php
                }
                ?>
                <!-- Year -->
                <div class="mb-3 row">
                    <label for="year" class="col-md-3 col-12 col-form-label">Year *</label>
                    <div class="col-12 col-sm-9">
                        <div class="input-group">
                            <span class="input-group-text"><i aria-hidden="true" class="fas fa-calendar-check"></i></span>
                            <select name='year' id='year' class='form-select'>
                                <option value="">--Choose Year--</option>
                                <option value="1963">1963</option>
                                <option value="1964">1964</option>
                                <option value="1965">1965</option>
                                <option value="1966">1966</option>
                                <option value="1967">1967</option>
                                <option value="1968">1968</option>
                                <option value="1969">1969</option>
                                <option value="1970">1970</option>
                                <option value="1971">1971</option>
                                <option value="1972">1972</option>
                                <option value="1973">1973</option>
                                <option value="1974">1974</option>
                            </select>
                            <span class='input-group-text'><i id="year_icon" aria-hidden='true' class="fas"></i></span>
                        </div>
                    </div>
                </div>

                <!-- Model -->
                <div class="mb-3 row">
                    <label for="model" class="col-md-3 col-12 col-form-label">Model *</label>
                    <div class="col-12 col-sm-9">
                        <div class="input-group">
                            <span class="input-group-text"><i aria-hidden="true" class="fas fa-car-side"></i></span>
                            <select disabled class="form-select" name="model" id="model">
                                <option value="">--Please Select Model--</option>
                            </select>
                            <span class='input-group-text'><i id="model_icon" aria-hidden='true' class="fas"></i></span>
                        </div>
                    </div>
                </div>


                <!-- Chassis -->
                <div class="mb-3 row">
                    <label for="chassis" class="col-md-3 col-12 col-form-label">Chassis *</label>
                    <div class="col-12 col-sm-9">
                        <div class="input-group">
                            <span class="input-group-text"><i aria-hidden="true" class="fas fa-barcode"></i></span>
                            <input data-lpignore="true" disabled class="form-control" type="text" name="chassis" id="chassis" placeholder="<?= htmlspecialchars($carprompt['chassis'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)($cardetails['chassis'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                            <span class='input-group-text'><i id="chassis_icon" aria-hidden='true' class="fas"></i></span>
                        </div>
                        <div id="chassis_check_error" class="alert alert-warning d-none mt-2" role="alert">
                            <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                            Could not check chassis availability. You may still submit — the registry will verify it.
                        </div>

                        <div id="chassis_taken" class="text-danger hidden">
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-triangle"></i> Chassis Already Registered</h6>
                                <p>This chassis number is already in the registry by another owner.</p>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary btn-sm" id="request_transfer_btn">
                                        <i class="fas fa-exchange-alt"></i> Request Ownership Transfer
                                    </button>
                                    <small class="text-muted d-block mt-2">This will notify the current owner and Registry Administrators of your transfer request.</small>
                                </div>
                            </div>
                        </div>
                        <div id="chassis_pre1970" class="hidden">
                            <strong>Before 1970</strong><br>The chassis number should be 4 digits. Do not enter the type (i.e. 26/0001 enter 0001)<br>
                        </div>
                        <div id="chassis_1970" class="hidden">
                            <strong>1970</strong><br>The chassis can have two forms<br>
                            <ul>
                                <li>4 Digits plus letter - Do not enter the type (i.e. 26/0001x enter 0001x)</li>
                                <li>11 digits starting with the Year (i.e. YYmmbbssssT)</li>
                                <ul>
                                    <li>YY = 2 digit year</li>
                                    <li>mm = month</li>
                                    <li>bb = batch numner</li>
                                    <li>uuuu = unit number</li>
                                    <li>T = Type Letter</li>
                                </ul>
                            </ul>
                        </div>
                        <div id="chassis_post1970" class="hidden">
                            <strong>After 1970</strong><br>The Chassis number is 11 digits starting with the Year (i.e. YYmmbbssssT)<br>
                            <ul>
                                <li>YY = 2 digit year</li>
                                <li>mm = month</li>
                                <li>bb = batch numner</li>
                                <li>uuuu = unit number</li>
                                <li>T = Type Letter</li>
                            </ul>
                        </div>

                        <div id="chassis_validation_error" class="text-danger hidden">
                            <strong>Chassis Validation Failed:</strong><br>
                            <span id="chassis_error_reason"></span>
                        </div>

                        <?php $chassisOverrideActive = !empty($cardetails['chassis_override']); ?>
                        <div id="chassis_override_section" class="<?= $chassisOverrideActive ? '' : 'd-none' ?>">
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="chassis_override" name="chassis_override" value="1"
                                       <?= $chassisOverrideActive ? 'checked' : '' ?>>
                                <label class="form-check-label text-warning" for="chassis_override">
                                    <strong>⚠️ Override chassis validation</strong><br>
                                    <small>Use only if this chassis number is correct but fails validation.</small>
                                </label>
                            </div>

                            <div class="mt-2">
                                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#chassisValidationModal">
                                    <i class="fas fa-info-circle"></i> Chassis Validation Rules
                                </button>
                                <a href="<?= $us_url_root ?>docs/chassis-validation.php" target="_blank" class="btn btn-sm btn-outline-secondary ms-1">
                                    <i class="fas fa-external-link-alt"></i> Full Documentation
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Color -->
                <div class="mb-3 row">
                    <label for="color" class="col-md-3 col-12 col-form-label">Color</label>
                    <div class="col-12 col-sm-9">
                        <div class="input-group">
                            <span class="input-group-text"><i aria-hidden="true" class="fas fa-palette"></i></span>
                            <input class="form-control" type="text" name="color" id="color" placeholder="<?= htmlspecialchars($carprompt['color'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)($cardetails['color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                        </div>
                    </div>
                </div>

                <!-- Engine Number -->
                <div class="mb-3 row">
                    <label for="engine" class="col-md-3 col-12 col-form-label">Engine Number</label>
                    <div class="col-12 col-sm-9">
                        <div class="input-group">
                            <span class="input-group-text"><i aria-hidden="true" class="fas fa-car"></i></span>
                            <input class="form-control" type="text" name="engine" id="engine" placeholder="<?= htmlspecialchars($carprompt['engine'], ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)($cardetails['engine'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                        </div>
                    </div>
                </div>
                <!-- Comments -->
                <div class='mb-3 row'>
                    <label for='comments' class='col-md-3 col-12 col-form-label'>Comments</label>
                    <div class='col-12 col-sm-9'>
                        <div class='input-group'>
                            <span class='input-group-text'><i aria-hidden='true' class='fas fa-comment-alt'></i></span>
                            <textarea class='form-control' name='comments' id='comments' rows='4' wrap='soft' maxlength='2000' placeholder='<?= htmlspecialchars($carprompt['comments'], ENT_QUOTES, 'UTF-8') ?>'><?= htmlspecialchars((string)($cardetails['comments'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <small class='form-text text-muted text-end d-block'><span id='comments-count'>0</span> / 2000 characters</small>
                    </div>
                </div>

                <!-- Purchase Date  -->
                <div class='mb-3 row'>
                    <label for='purchasedate' class='col-md-3 col-12 col-form-label'>Purchase Date</label>
                    <div class='col-12 col-sm-9'>
                        <div class='input-group'>
                            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
                            <input class='form-control' name='purchasedate' id='purchasedate' value='<?= htmlspecialchars((string)($cardetails['purchasedate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' type='date' min='1957-01-01' max='<?= date('Y-m-d') ?>' aria-describedby='purchasedateHelp' />
                        </div>
                        <small id='purchasedateHelp' class='form-text text-muted'>Approximate date you purchased the car.</small>
                    </div>
                </div>

                <!-- Sold Date toggle -->
                <div class='mb-3 mt-3 row'>
                    <div class='col-md-3 col-12'></div>
                    <div class='col-12 col-sm-9'>
                        <div class='form-check'>
                            <input class='form-check-input' type='checkbox' id='sold-toggle' <?= !empty($cardetails['solddate']) ? 'checked' : '' ?> />
                            <label class='form-check-label' for='sold-toggle'>I no longer own this car</label>
                        </div>
                    </div>
                </div>

                <!-- Sold Date (revealed by toggle) -->
                <div id='solddate-row' class='mb-3 row <?= empty($cardetails['solddate']) ? 'd-none' : '' ?>'>
                    <label for='solddate' class='col-md-3 col-12 col-form-label'>Sold Date</label>
                    <div class='col-12 col-sm-9'>
                        <div class='input-group'>
                            <span class='input-group-text'><i aria-hidden='true' class='fas fa-calendar'></i></span>
                            <input class='form-control' name='solddate' id='solddate' value='<?= htmlspecialchars((string)($cardetails['solddate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' type='date' min='1957-01-01' max='<?= date('Y-m-d') ?>' aria-describedby='solddateHelp' />
                        </div>
                        <small id='solddateHelp' class='form-text text-muted'>Approximate date you sold the car.</small>
                    </div>
                </div>

                <!-- Website -->
                <div class='mb-3 row'>
                    <label for='website' class='col-md-3 col-12 col-form-label'>Website</label>
                    <div class='col-12 col-sm-9'>
                        <div class='input-group'>
                            <span class='input-group-text'><i aria-hidden='true' class='fas fa-globe'></i></span>
                            <input class='form-control' type='url' name='website' id='website' placeholder='<?= htmlspecialchars($carprompt['website'], ENT_QUOTES, 'UTF-8') ?>' value='<?= htmlspecialchars((string)($cardetails['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?>' pattern="https?://.+" />
                        </div>
                        <div class='invalid-feedback'>URL must start with http:// or https:// (e.g. https://example.com)</div>
                    </div>
                </div>
                <script>
                (function () {
                    var el = document.getElementById('website');
                    function validate() {
                        el.classList.toggle('is-invalid', el.value !== '' && !el.validity.valid);
                    }
                    el.addEventListener('input', validate);
                    el.addEventListener('blur', validate);
                }());
                </script>

                <hr class="my-4">

                <h5 class="form-section-heading"><i class="fas fa-camera me-2"></i>Photos <small class="text-muted fw-normal" style="font-size:0.7rem;letter-spacing:0">optional</small></h5>
                <div class='row'>
                    <div class='col-12'>
                        <input type="file" id="myPond" multiple accept="image/*">
                        <details class="mt-2">
                            <summary class="text-muted small" style="cursor:pointer">Photo requirements</summary>
                            <ul class="small text-muted mt-1 mb-0">
                                <li>Maximum of <?= $settings->elan_image_max ?> photos</li>
                                <li>Maximum size <?= $settings->elan_image_upload_max_size ?> MB each</li>
                                <li>Photos only</li>
                                <li>Tap and hold to reorder &bull; Drag on desktop</li>
                            </ul>
                        </details>
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-3 mb-4">
                  <button type="button" id="submit" data-label="Add Car"
                          class="btn btn-success btn-lg">Add Car</button>
                </div>
            </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chassis Validation Rules Modal -->
<div class="modal fade" id="chassisValidationModal" tabindex="-1" role="dialog" aria-labelledby="chassisValidationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title card-header-er-primary-text" id="chassisValidationModalLabel">
                    <i class="fas fa-barcode"></i> Chassis Validation Rules - Quick Reference
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Format Overview -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-primary mb-2">1963–1969</h6>
                            <code class="d-block mb-2">1234</code>
                            <small class="text-muted">4 digits only</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-warning mb-2">1970 Transition</h6>
                            <code class="d-block mb-1">1234A</code>
                            <code class="d-block mb-2">7001019999B</code>
                            <small class="text-muted">5 or 11 characters</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-success mb-2">1971–1974</h6>
                            <code class="d-block mb-2">7301019999B</code>
                            <small class="text-muted">11 characters YYMMBBXXXXC</small>
                        </div>
                    </div>
                </div>

                <!-- Letter Codes -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0 card-header-er-primary-text"><i class="fas fa-car-side"></i> Elan Models</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Valid codes:</strong> A, B, C, D, E, F, G, H, J, K</p>
                                <p class="text-danger mb-0"><strong>Invalid:</strong> I — not used</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="fas fa-plus"></i> +2 Models</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Valid codes:</strong> L, M, N only</p>
                                <p class="text-danger mb-0"><strong>Invalid:</strong> A-K (Elan codes)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Examples -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <h6 class="text-success"><i class="fas fa-check-circle"></i> Valid Examples</h6>
                        <ul class="list-unstyled">
                            <li><code class="text-success">1234</code> - Pre-1970</li>
                            <li><code class="text-success">5678A</code> - 1970 Elan</li>
                            <li><code class="text-success">7012345678M</code> - 1970 +2</li>
                            <li><code class="text-success">7301019999B</code> - 1973 Elan</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-danger"><i class="fas fa-times-circle"></i> Invalid Examples</h6>
                        <ul class="list-unstyled">
                            <li><code class="text-danger">123</code> - Too short</li>
                            <li><code class="text-danger">7301019999I</code> - Invalid letter I</li>
                            <li><code class="text-danger">7301019999L</code> - Wrong letter for Elan</li>
                            <li><code class="text-danger">36/1234</code> - Includes type prefix</li>
                        </ul>
                    </div>
                </div>

                <div class="alert alert-primary mb-0">
                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Override Option</h6>
                    <p class="mb-0">If your chassis number doesn't validate but you have historical documentation supporting it, you can use the validation override checkbox with caution.</p>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= $us_url_root ?>docs/chassis-validation.php" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt"></i> View Full Documentation
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Request Modals -->

<!-- Transfer Validation Error Modal -->
<div class="modal fade" id="transferValidationModal" tabindex="-1" role="dialog" aria-labelledby="transferValidationModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="transferValidationModalLabel">
                    <i class="fas fa-exclamation-triangle"></i> Missing Information
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Please ensure year, model, and chassis are selected before requesting transfer.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Confirmation Modal -->
<div class="modal fade" id="transferConfirmModal" tabindex="-1" role="dialog" aria-labelledby="transferConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="transferConfirmModalLabel">
                    <i class="fas fa-exchange-alt"></i> Confirm Transfer Request
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to request ownership transfer for this chassis number?</p>

                <div class="alert alert-primary mb-3">
                    <small><i class="fas fa-info-circle"></i> The current owner and Registry Administrators will be notified of your request.</small>
                </div>

                <!-- Transfer Comment Field -->
                <div class="mb-3">
                    <label for="transfer_comments" class="fw-bold">
                        <i class="fas fa-comment-alt"></i> Explanation for Transfer Request (Optional but Recommended)
                    </label>
                    <textarea
                        class="form-control"
                        id="transfer_comments"
                        name="transfer_comments"
                        rows="4"
                        maxlength="1000"
                        placeholder="Please explain why you believe you are the rightful owner. Include details such as:
- When and from whom you purchased the car
- Documentation you have (sales receipt, title, registration)
- Current location of the vehicle
- Any other relevant ownership information"
                        style="resize: vertical;"
                    ></textarea>
                    <small class="form-text text-muted">
                        <span id="transfer_comments_counter">0 / 1000 characters</span>
                        <span class="ms-2"><i class="fas fa-info-circle"></i> Providing details helps expedite the review process.</span>
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmTransferBtn">
                    <i class="fas fa-check"></i> Request Transfer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Success Modal -->
<div class="modal fade" id="transferSuccessModal" tabindex="-1" role="dialog" aria-labelledby="transferSuccessModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="transferSuccessModalLabel">
                    <i class="fas fa-check-circle"></i> Transfer Request Submitted
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Transfer request submitted successfully!</p>
                <div class="alert alert-success">
                    <small><i class="fas fa-envelope"></i> You will be notified when the current owner responds.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-success" id="transferSuccessOkBtn">
                    <i class="fas fa-arrow-left"></i> Return to Car Listings
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Transfer Error Modal -->
<div class="modal fade" id="transferErrorModal" tabindex="-1" role="dialog" aria-labelledby="transferErrorModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="transferErrorModalLabel">
                    <i class="fas fa-exclamation-circle"></i> Transfer Request Failed
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <strong>Error:</strong> <span id="transferErrorMessage">There was an error processing your request.</span>
                </div>
                <p>Please try again or contact support if the problem persists.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!--footers-->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<script src="<?=$us_url_root?>usersc/js/filepond.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-image-exif-orientation.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-file-validate-type.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-file-validate-size.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-image-preview.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-image-resize.min.js"></script>
<script src="<?=$us_url_root?>usersc/js/filepond-plugin-image-transform.min.js"></script>
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/filepond.min.css">
<link rel="stylesheet" href="<?=$us_url_root?>usersc/css/filepond-plugin-image-preview.min.css">

<!-- Dynamic model loading from database -->
<script src='<?= $us_url_root ?>app/assets/js/model-loader.min.js?v=<?= ASSET_VERSION ?>'></script>

<script>
    const csrf = $('#csrf').val();
    const car_id = $('#car_id').val();

    $(document).ready(function() {
        $('#message').addClass('d-none');

        const solddateRow = document.getElementById('solddate-row');
        const solddateInput = document.getElementById('solddate');

        document.getElementById('sold-toggle').addEventListener('change', function() {
            if (this.checked) {
                solddateRow.classList.remove('d-none');
                if (!solddateInput.value) {
                    solddateInput.value = new Date().toISOString().split('T')[0];
                }
            } else {
                solddateRow.classList.add('d-none');
                solddateInput.value = '';
            }
        });

        // BEGIN FILEPOND

        FilePond.registerPlugin(
            FilePondPluginImageExifOrientation,
            FilePondPluginFileValidateType,
            FilePondPluginFileValidateSize,
            FilePondPluginImagePreview,
            FilePondPluginImageResize,
            FilePondPluginImageTransform
        );

        const processedFiles = new Map();

        const pond = FilePond.create(document.querySelector('#myPond'), {
            allowMultiple: true,
            allowReorder: true,
            maxFiles: <?= $maximages ?>,
            acceptedFileTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            maxFileSize: <?= (int)((float)$settings->elan_image_upload_max_size * 1024 * 1024) ?>,
            instantUpload: false,
            allowProcess: false,
            imageResizeTargetWidth: <?= $settings->elan_image_display_max_size ?>,
            imageResizeMode: 'downscale',
            credits: false,
            labelIdle: 'Drop photos here or <span class="filepond--label-action">Browse</span>',
            labelFileTypeNotAllowed: 'Invalid file type. Only images are allowed.',
            fileValidateTypeLabelExpectedTypes: 'Expects {allTypes}',
            labelMaxFileSizeExceeded: 'Photo is too large',
            labelMaxFileSize: 'Maximum size is {filesize}',
            labelMaxTotalFileSizeExceeded: 'Maximum total size exceeded',
            server: {
                process: (fieldName, file, metadata, load, error, progress, abort) => {
                    const serverId = 'fp_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
                    processedFiles.set(serverId, { blob: file, name: file.name });
                    load(serverId);
                    return {
                        abort: () => {
                            processedFiles.delete(serverId);
                            abort();
                        }
                    };
                },
                load: (source, load, error) => {
                    const expectedOrigin = window.location.origin;
                    // Normalise to absolute URL so the origin check is reliable
                    const absSource = source.startsWith('http') ? source : expectedOrigin + '/' + source.replace(/^\//, '');
                    if (!absSource.startsWith(expectedOrigin)) {
                        error('Blocked load from unexpected origin');
                        return;
                    }
                    fetch(absSource)
                        .then(r => r.blob())
                        .then(blob => load(blob))
                        .catch(error);
                },
                revert: (serverId, load, error) => {
                    processedFiles.delete(serverId);
                    load();
                }
            }
        });

        // Hydrate existing images in edit mode
        if (car_id && car_id !== '') {
            new ElanRegistryAPI().post('<?= $us_url_root ?>app/api/cars/save.php', {
                carID: car_id,
                action: 'fetchImages'
            }).then(function(data) {
                if (data == null || data.success !== true) {
                    $('#message').removeClass('d-none').html(
                        '<div class="alert alert-warning">Existing photos could not be loaded. ' +
                        'Please refresh the page before making changes to avoid losing photo data.</div>'
                    );
                    document.getElementById('submit').disabled = true;
                    return;
                }
                if (!Array.isArray(data.images)) {
                    console.error('[edit.php] fetchImages: unexpected response shape — data.images is not an array', data);
                    $('#message').removeClass('d-none').html(
                        '<div class="alert alert-warning">Existing photos could not be loaded. ' +
                        'Please refresh the page before making changes to avoid losing photo data.</div>'
                    );
                    document.getElementById('submit').disabled = true;
                    return;
                }
                // Chain serially so FilePond receives files in server-defined order.
                // Per-item .catch() absorbs individual load failures; imageLoadErrors is
                // tallied and a summary banner is shown after all addFile() calls settle.
                var imageLoadErrors = 0;
                data.images.reduce(function(chain, img) {
                    return chain.then(function() {
                        return pond.addFile(img.path, {
                            type: 'local',
                            metadata: { serverFilename: img.basename }
                        }).catch(function(err) {
                            console.error('[edit.php] Photo could not be loaded:', img.basename, err);
                            imageLoadErrors++;
                        });
                    });
                }, Promise.resolve()).then(function() {
                    if (imageLoadErrors > 0) {
                        var isSingular = imageLoadErrors === 1;
                        var noun       = isSingular ? 'photo' : 'photos';
                        var itemStr    = isSingular ? 'the failed item' : 'failed items';
                        $('#message').removeClass('d-none').html(
                            '<div class="alert alert-warning"><i class="fas fa-exclamation-circle me-1" aria-hidden="true"></i>' +
                            imageLoadErrors + ' ' + noun + ' could not be loaded. ' +
                            'You can remove ' + itemStr + ' and continue editing, ' +
                            'or submit to save your other changes.</div>'
                        );
                    }
                }).catch(function(summaryErr) {
                    console.error('[edit.php] Error showing image-load summary:', summaryErr);
                });
            }).catch(function(err) {
                console.error('[edit.php] fetchImages API failure:', err);
                $('#message').removeClass('d-none').html(
                    '<div class="alert alert-warning">Existing photos could not be loaded. ' +
                    'Please refresh the page before making changes to avoid losing photo data.</div>'
                );
                document.getElementById('submit').disabled = true;
            });
        }

        // Remove existing images in edit mode
        pond.on('removefile', function(error, fileItem) {
            if (error) {
                $('#message').removeClass('d-none').html(
                    '<div class="alert alert-warning">A photo could not be removed. Please try again.</div>'
                );
                return;
            }
            if (car_id && car_id !== '' && fileItem.origin === FilePond.FileOrigin.LOCAL) {
                const filename = fileItem.getMetadata('serverFilename');
                new ElanRegistryAPI().post('<?= $us_url_root ?>app/api/cars/save.php', {
                    action: 'removeImages',
                    carID: car_id,
                    file: filename
                }).catch(function() {
                    $('#message').removeClass('d-none').html(
                        '<div class="alert alert-warning">A photo could not be removed from the server. ' +
                        'Please refresh the page and try again before submitting.</div>'
                    );
                });
            }
        });

        // Clear file-level errors when a new file is added successfully
        pond.on('addfile', function(error) {
            if (!error) {
                $('#message').addClass('d-none');
            }
        });

        const submitBtn = document.getElementById('submit');
        submitBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const pondHasProcessingErrors = pond.getFiles().some(function(item) {
                return item.status === FilePond.FileStatus.PROCESSING_ERROR;
            });

            if (pondHasProcessingErrors) {
                $('#message').removeClass('d-none').html('<div class="alert alert-danger">Error: One or more photos failed to upload. Please remove them and try again.</div>');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving\u2026';

            // Only process new files — LOCAL files (already on server) don't need
            // client-side transform before submit; processing them caused the slow-save regression.
            const newFileIds = pond.getFiles()
                .filter(function(item) { return item.origin !== FilePond.FileOrigin.LOCAL; })
                .map(function(item) { return item.id; });

            const handleProcessError = function(err) {
                console.error('[edit.php] Photo processing error:', err);
                $('#message').removeClass('d-none').html('<div class="alert alert-danger">An error occurred processing the photos. Please try again.</div>');
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.label;
            };

            const submission = newFileIds.length > 0
                ? pond.processFiles(newFileIds).then(submitCarForm)
                : submitCarForm();
            submission.catch(handleProcessError);
        });

        async function submitCarForm() {
            const formData = new FormData();
            formData.append('action', $('#action').val());
            formData.append('csrf', $('#csrf').val());
            formData.append('car_id', car_id || '');
            formData.append('year', $('#year').val());
            formData.append('model', $('#model').val());
            formData.append('chassis', $('#chassis').val());
            formData.append('chassis_override', $('#chassis_override').is(':checked') ? '1' : '0');
            formData.append('color', $('#color').val());
            formData.append('engine', $('#engine').val());
            formData.append('purchasedate', $('#purchasedate').val());
            formData.append('solddate', $('#solddate').val());
            formData.append('website', $('#website').val());
            formData.append('comments', $('#comments').val());

            // Build ordered filenames and append new files in pond order
            const allItems = pond.getFiles();
            const orderedFilenames = [];
            let hasNewFiles = false;

            allItems.forEach(function(item) {
                if (item.origin === FilePond.FileOrigin.LOCAL) {
                    orderedFilenames.push(item.getMetadata('serverFilename'));
                } else if (item.serverId) {
                    const processed = processedFiles.get(item.serverId);
                    if (processed) {
                        formData.append('file[]', processed.blob, processed.name);
                        orderedFilenames.push(processed.name);
                        hasNewFiles = true;
                    }
                }
            });

            // Server parses filenames via explode(',', Input::get('filenames'))
            formData.append('filenames', orderedFilenames.join(','));

            // Empty blob named 'blob' signals server that no new files were uploaded
            if (!hasNewFiles) {
                formData.append('file[]', new Blob([]), 'blob');
            }

            try {
                const response = await fetch('<?= $us_url_root ?>app/api/cars/save.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await response.json();

                if (data.success === true) {
                    window.location = '<?= $us_url_root ?>app/owner/cars/details.php?car_id=' + data.cardetails.id;
                } else {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.label;
                    displayValidationErrors(data);
                }
            } catch (err) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitBtn.dataset.label;
                $('#message').removeClass('d-none').html('<div class="alert alert-danger">An error occurred. Please try again.</div>');
            }
        }

        function displayValidationErrors(data) {
            let html = '<div class="alert alert-danger mb-0">';
            html += '<strong>Submission failed.</strong>';
            if (data.message) {
                html += ' ' + NotificationHelper.escapeHtml(data.message);
            }
            if (data.errors) {
                html += '<ul class="mb-0 mt-2">';
                if (Array.isArray(data.errors.general)) {
                    data.errors.general.forEach(function(e) {
                        html += '<li>' + NotificationHelper.escapeHtml(String(e)) + '</li>';
                    });
                } else {
                    Object.entries(data.errors).forEach(function(entry) {
                        const msgs = Array.isArray(entry[1]) ? entry[1] : [entry[1]];
                        msgs.forEach(function(msg) {
                            html += '<li><strong>' + NotificationHelper.escapeHtml(entry[0]) + ':</strong> '
                                  + NotificationHelper.escapeHtml(String(msg)) + '</li>';
                        });
                    });
                }
                html += '</ul>';
            }
            html += '</div>';
            $('#message').removeClass('d-none').html(html);

            // Scroll to message
            const msgEl = document.getElementById('message');
            if (msgEl) {
                msgEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // Repopulate form fields from submitted data
            if (data.cardetails) {
                if (data.cardetails.year) {
                    $('#year').val(data.cardetails.year);
                }
                if (data.cardetails.model) {
                    $('#model').val(data.cardetails.model).trigger('change');
                }
                if (data.cardetails.chassis) $('#chassis').val(data.cardetails.chassis);
                if (data.cardetails.color) $('#color').val(data.cardetails.color);
                if (data.cardetails.engine) $('#engine').val(data.cardetails.engine);
                if (data.cardetails.comments) $('#comments').val(data.cardetails.comments);
                if (data.cardetails.website) $('#website').val(data.cardetails.website);
                if (data.cardetails.purchasedate) $('#purchasedate').val(data.cardetails.purchasedate);
                if (data.cardetails.solddate) {
                    solddateInput.value = data.cardetails.solddate;
                    document.getElementById('sold-toggle').checked = true;
                    solddateRow.classList.remove('d-none');
                }
            }
        }
        // Pre-populate dropdown menus if we are updating a car
<?php if ($action === 'updateCar'): ?>
        if ($('#action').val() === 'updateCar') {
            const year = <?= (int)($cardetails['year'] ?? 0) ?>;
            const modelValue = <?= json_encode((string)($cardetails['model'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

            // Set year and trigger change to load models
            $('#year').val(year).trigger('change');

            // After models load, set the model value
            setTimeout(function() {
                $('#model').val(modelValue).trigger('change');
                $('#chassis').trigger('blur');
            }, 500);

            // Show all fields
            $('#color').prop('disabled', false)
            $('#engine').prop('disabled', false)
            $('#purchasedate').prop('disabled', false)
            $('#solddate').prop('disabled', false)
            $('#website').prop('disabled', false)
            $('#comments').prop('disabled', false)

            // Set the form text for Update
            $('#submit').text('Update Car').attr('data-label', 'Update Car');
            $('#car_id').html($('#car_id').val());
            $('#carHeader').html('<h2><strong>Update car</strong><h2>');
        }
<?php endif; ?>
    });

    // Car Validation
    var validYear = '';
    var validModel = '';
    var validChassis = '';

    /* *
     *  Validate car form during data entry
     *
     * Set fields that are valid as green and invalid as red
     */

    // Initialize ModelLoader with API endpoint
    ModelLoader.init('<?= $us_url_root ?>app/api/cars/models.php');

    // Comments character counter
    const commentsEl = document.getElementById('comments');
    const commentsCount = document.getElementById('comments-count');
    function updateCommentsCount() {
        commentsCount.textContent = commentsEl.value.length;
    }
    updateCommentsCount();
    commentsEl.addEventListener('input', updateCommentsCount);

    /*
     * When year changes, update the model list and show the appropriate chassis help text
     */
    $('#year').change(async function() {
        validYear = $('#year option:selected').val();
        $('#year_icon').toggleClass('fa-thumbs-up', Boolean(validYear)).toggleClass('fa-thumbs-down', !Boolean(validYear)).toggleClass('is-valid', Boolean(validYear)).toggleClass('is-invalid', !Boolean(validYear));
        $('#year').toggleClass('is-valid', Boolean(validYear)).toggleClass('is-invalid', !Boolean(validYear));

        // Year changed so reset model and chassis
        if ($('#action').val() === 'addCar') {
            validModel = '';
            validChassis = '';
            $('#model_icon').toggleClass('fa-thumbs-up', Boolean(validModel)).toggleClass('fa-thumbs-down', !Boolean(validModel)).toggleClass('is-valid', false).toggleClass('is-invalid', false);
            $('#model').toggleClass('is-valid', false).toggleClass('is-invalid', false);
            $('#model').prop('disabled', false).val('');

            $('#chassis_icon').toggleClass('fa-thumbs-up', Boolean(validChassis)).toggleClass('fa-thumbs-down', !Boolean(validChassis)).toggleClass('is-valid', false).toggleClass('is-invalid', false);
            $('#chassis').toggleClass('is-valid', false).toggleClass('is-invalid', false);

            $('#chassis').val('');
        } else {
            $('#model').prop('disabled', false);
        }

        // Load model dropdown for selected year
        if (validYear) {
            await ModelLoader.populateModelDropdown(validYear, $('#model'));

            //Display appropriate chassis text
            if (validYear < 1970) {
                $('#chassis_pre1970').show();
                $('#chassis_1970').hide();
                $('#chassis_post1970').hide();
                $('#chassis_taken').hide();
            } else if (validYear === '1970') {
                $('#chassis_pre1970').hide();
                $('#chassis_1970').show();
                $('#chassis_post1970').hide();
                $('#chassis_taken').hide();
            } else {
                $('#chassis_pre1970').hide();
                $('#chassis_1970').hide();
                $('#chassis_post1970').show();
                $('#chassis_taken').hide();
            }

            // Re-validate chassis when year changes (format requirements change)
            if ($('#chassis').val() && $('#action').val() === 'updateCar') {
                $('#chassis').trigger('blur');
            }
        }
    });
    // Validate Model
    $('#model').change(function() {
        validModel = $('#model option:selected').val();

        $('#model_icon').toggleClass('fa-thumbs-up', Boolean(validModel)).toggleClass('fa-thumbs-down', !Boolean(validModel)).toggleClass('is-valid', Boolean(validModel)).toggleClass('is-invalid', !Boolean(validModel));
        $('#model').toggleClass('is-valid', Boolean(validModel)).toggleClass('is-invalid', !Boolean(validModel));
        $('#chassis').prop('disabled', false);

        // Re-validate chassis when model changes (requirements change based on year/model)
        if ($('#chassis').val()) {
            $('#chassis').trigger('blur');
        }
    });

    // Validate Chassis - Centralized AJAX Validation
    // Uses centralized ChassisValidator class for consistent frontend/backend validation
    $('#chassis').blur(function() {
        const _chassis = $('#chassis').val();
        const overrideEnabled = $('#chassis_override').is(':checked');

        // Skip validation if empty chassis
        if (!_chassis || !validYear || !validModel) {
            validChassis = '';
            updateChassisUI(false, '');
            return;
        }

        // Call centralized validation via AJAX
        new ElanRegistryAPI().post('<?= $us_url_root ?>app/api/cars/chassis-validate.php', {
            csrf: $('#csrf').val(),
            chassis: _chassis,
            year: validYear,
            model: validModel,
            allow_override: overrideEnabled ? 'true' : 'false'
        }).then(function(result) {
            validChassis = result.valid ? _chassis : '';
            updateChassisUI(result.valid, result.error_reason || '');
        }).catch(function() {
            validChassis = overrideEnabled ? _chassis : '';
            updateChassisUI(validChassis !== '', 'Validation service temporarily unavailable');
        });
    });

    /**
     * Update chassis UI based on validation result
     * @param {boolean} isValid - Whether chassis is valid
     * @param {string} errorReason - Error message if invalid
     */
    function updateChassisUI(isValid, errorReason) {
        // Update visual indicators
        $('#chassis_icon').toggleClass('fa-thumbs-up', isValid)
                          .toggleClass('fa-thumbs-down', !isValid)
                          .toggleClass('is-valid', isValid)
                          .toggleClass('is-invalid', !isValid);
        
        $('#chassis').toggleClass('is-valid', isValid)
                     .toggleClass('is-invalid', !isValid);

        // Show/hide override section and validation error
        const overrideEnabled = $('#chassis_override').is(':checked');
        // Only hide section when chassis is genuinely valid (without override)
        $('#chassis_override_section').toggleClass('d-none', isValid && !overrideEnabled);
        if (isValid && !overrideEnabled) {
            $('#chassis_override').prop('checked', false);
            $('#chassis_validation_error').addClass('hidden').hide();
        } else if (!isValid && errorReason && !overrideEnabled) {
            $('#chassis_validation_error').removeClass('hidden').show();
            $('#chassis_error_reason').text(errorReason);
        } else {
            $('#chassis_validation_error').addClass('hidden').hide();
        }

        // Check chassis availability for new cars only
        if ($('#action').val() === 'addCar' && isValid && validChassis) {
            checkChassisAvailability();
        }
    }

    /**
     * Check if chassis number is already taken (for new cars)
     */
    function checkChassisAvailability() {
        new ElanRegistryAPI().post('<?= $us_url_root ?>app/api/cars/chassis-availability.php', {
            csrf: $('#csrf').val(),
            command: 'chassis_check',
            year: validYear,
            model: validModel,
            chassis: validChassis
        }).then(function(response) {
            $('#chassis_check_error').addClass('d-none');
            if (response.taken) {
                validChassis = '';
                updateChassisUI(false, 'This chassis number is already registered');
                $('#chassis_taken').show();
            } else {
                $('#chassis_taken').hide();
                $('#color').prop('disabled', false);
                $('#engine').prop('disabled', false);
            }
        }).catch(function(err) {
            console.error('[checkChassisAvailability] Availability check failed:', err);
            $('#chassis_check_error').removeClass('d-none');
            $('#color').prop('disabled', false);
            $('#engine').prop('disabled', false);
        });
    }

    // Override checkbox event handler - re-validate when toggled
    $('#chassis_override').change(function() {
        if ($('#chassis').val()) {
            $('#chassis').trigger('blur');
        }
    });

    // Transfer Request Handler
    $('#request_transfer_btn').click(function() {
        const chassis = $('#chassis').val();
        const year = validYear;
        const model = validModel;

        if (!chassis || !year || !model) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('transferValidationModal')).show();
            return;
        }

        // Show confirmation modal
        bootstrap.Modal.getOrCreateInstance(document.getElementById('transferConfirmModal')).show();
    });

    // Handle transfer confirmation
    $('#confirmTransferBtn').click(function() {
        bootstrap.Modal.getInstance(document.getElementById('transferConfirmModal'))?.hide();

        // Disable button to prevent double-submission
        $('#request_transfer_btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Requesting...');

        // Validate transfer comments length
        const transferComments = $('#transfer_comments').val();
        if (transferComments.length > 1000) {
            $('#transferErrorMessage').text('Transfer explanation must be 1000 characters or less.');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('transferErrorModal')).show();
            $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
            return;
        }

        const chassis = $('#chassis').val();
        const year = validYear;
        const model = validModel;

        new ElanRegistryAPI().post('<?= $us_url_root ?>app/api/cars/transfer-request.php', {
            csrf: $('#csrf').val(),
            chassis: chassis,
            year: year,
            model: model,
            color: $('#color').val() || '',
            engine: $('#engine').val() || '',
            comments: $('#transfer_comments').val() || ''
        }).then(function(response) {
            if (response.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('transferSuccessModal')).show();
            } else {
                $('#transferErrorMessage').text(response.message);
                bootstrap.Modal.getOrCreateInstance(document.getElementById('transferErrorModal')).show();
                $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
            }
        }).catch(function() {
            $('#transferErrorMessage').text('There was an error processing your request. Please try again.');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('transferErrorModal')).show();
            $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
        });
    });

    // Handle success modal OK button
    $('#transferSuccessOkBtn').click(function() {
        window.location.href = '<?= $us_url_root ?>app/owner/cars/';
    });

    // Character counter for transfer comments
    $('#transfer_comments').on('input', function() {
        const currentLength = $(this).val().length;
        const maxLength = 1000;
        const remaining = maxLength - currentLength;

        $('#transfer_comments_counter').text(currentLength + ' / ' + maxLength + ' characters');

        // Visual feedback when approaching limit
        if (remaining <= 100) {
            $('#transfer_comments_counter').addClass('text-warning');
        } else {
            $('#transfer_comments_counter').removeClass('text-warning');
        }

        if (remaining <= 0) {
            $('#transfer_comments_counter').addClass('text-danger').removeClass('text-warning');
        } else {
            $('#transfer_comments_counter').removeClass('text-danger');
        }
    });

    // Reset transfer comment field when modal is closed
    $('#transferConfirmModal').on('hidden.bs.modal', function() {
        $('#transfer_comments').val('');
        $('#transfer_comments_counter').text('0 / 1000 characters').removeClass('text-warning text-danger');
    });

    // End Car Validation
</script>
