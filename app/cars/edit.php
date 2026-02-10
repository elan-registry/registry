<?php
declare(strict_types=1);

/**
 * edit_car.php
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
require_once '../../users/init.php';
require_once $abs_us_root . $us_url_root . 'users/includes/template/prep.php';

if (!securePage($php_self)) {
    die();
}

// Ensure settings have default values for image configuration
if (!isset($settings->elan_image_upload_max_size)) {
    $settings->elan_image_upload_max_size = 2;
}
if (!isset($settings->elan_image_display_max_size)) {
    $settings->elan_image_display_max_size = 2048;
}
if (!isset($settings->elan_image_thumbnail_sizes)) {
    $settings->elan_image_thumbnail_sizes = '100,300,768,1024,2048';
}

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
$carprompt['purchasedate']  = 'YYYY-MM-DD';
$carprompt['solddate']      = 'YYYY-MM-DD';
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
<link rel="stylesheet" href="<?= $us_url_root ?>app/assets/css/edit_car.css">

<div class="page-wrapper">
    <div class="container-fluid">
        <div class="page-container">
            <div class="row justify-content-center">
                <div class="col-xl-10 col-lg-11 col-md-12">
            <h2 id="heading" class="mt-4 mb-3 text-center">Fill all form fields to go to next step</h2>

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
                <!-- progressbar -->
                <ul id="progressbar" class="mb-4">
                    <li class="active" id="cardetails"><strong>Car Details</strong></li>
                    <li id="addInfo"><strong>Additional Information</strong></li>
                    <li id="image"><strong>Images</strong></li>
                    <li id="confirm"><strong>Results</strong></li>
                </ul>
                <div class="mb-4">
                    <progress id="carProgress" value="0" max="100" class="w-100 car-progress"></progress>
                </div>
                <div id="message" class="d-none"></div>
                <fieldset>
                    <!-- fieldsets page 1 -->
                    <div class="card registry-card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-md-7 text-left">
                                    <legend class="fs-title mb-0">Car Details:</legend>
                                </div>
                                <div class="col-5">
                                    <h2 class="steps mb-0">Step 1 - 4</h2>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php include_once $abs_us_root . $us_url_root . 'app/views/_edit_car_1.php'; ?>
                        </div>
                    </div>
                    <input type="button" name="next" class="next btn btn-info" value="Next" />
                </fieldset>
                <fieldset>
                    <!-- fieldsets page 2 -->
                    <div class="card registry-card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-md-7">
                                    <legend class="fs-title mb-0">Additional Information:</legend>
                                </div>
                                <div class="col-5">
                                    <h2 class="steps mb-0">Step 2 - 4</h2>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php include_once $abs_us_root . $us_url_root . 'app/views/_edit_car_2.php'; ?>
                        </div>
                    </div>
                    <input type="button" name="next" class="next btn btn-info" value="Next" />
                    <input type="button" name="previous" class="previous btn btn-danger" value="Previous" />
                </fieldset>
                <fieldset>
                    <!-- fieldsets page 3 -->
                    <div class="card registry-card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-md-7">
                                    <legend class="fs-title mb-0">Image Upload:</legend>
                                </div>
                                <div class="col-5">
                                    <h2 class="steps mb-0">Step 3 - 4</h2>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php include_once $abs_us_root . $us_url_root . 'app/views/_edit_car_3.php'; ?>
                        </div>
                    </div>
                    <!-- End Image panel -->
                    <input type="submit" name="submit" id="submit" class="btn btn-success" value="Add Car" />
                    <input type="button" name="previous" class="previous btn btn-danger" value="Previous" />
                </fieldset>
                <fieldset>
                    <div class="card registry-card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-6 d-flex text-left">
                                    <legend class="fs-title mb-0">Results</legend>
                                </div>
                                <div class="col-6">
                                    <h2 class="steps mb-0">Step 4 - 4</h2>
                                </div>
                            </div>
                        </div>
                        <div class="card-body" id="results">
                        </div>
                    </div>
                </fieldset>
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
                <h5 class="modal-title" id="chassisValidationModalLabel">
                    <i class="fas fa-barcode"></i> Chassis Validation Rules - Quick Reference
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Format Overview -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="text-center p-3 border rounded bg-light">
                            <h6 class="text-primary mb-2">Pre-1970 (1963-1969)</h6>
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
                            <h6 class="text-success mb-2">Post-1970 (1971-1974)</h6>
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
                                <h6 class="mb-0"><i class="fas fa-car-side"></i> Elan Models</h6>
                            </div>
                            <div class="card-body">
                                <p><strong>Valid codes:</strong> A, B, C, D, E, F, G, H, J, K</p>
                                <p class="text-danger mb-0"><strong>Invalid:</strong> I (never used)</p>
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

                <div class="alert alert-info mb-0">
                    <h6 class="alert-heading"><i class="fas fa-info-circle"></i> Override Option</h6>
                    <p class="mb-0">If your chassis number doesn't validate but you have historical documentation supporting it, you can use the validation override checkbox with caution.</p>
                </div>
            </div>
            <div class="modal-footer">
                <a href="<?= $us_url_root ?>docs/chassis-validation.php" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-external-link-alt"></i> View Full Documentation
                </a>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
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
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Please ensure year, model, and chassis are selected before requesting transfer.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal">OK</button>
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
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to request ownership transfer for this chassis number?</p>

                <div class="alert alert-info mb-3">
                    <small><i class="fas fa-info-circle"></i> The current owner and Registry Administrators will be notified of your request.</small>
                </div>

                <!-- Transfer Comment Field -->
                <div class="form-group">
                    <label for="transfer_comments" class="font-weight-bold">
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
                        <span class="ml-2"><i class="fas fa-info-circle"></i> Providing details helps expedite the review process.</span>
                    </small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
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
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
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
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <strong>Error:</strong> <span id="transferErrorMessage">There was an error processing your request.</span>
                </div>
                <p>Please try again or contact support if the problem persists.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!--footers-->
<?php
require_once $abs_us_root . $us_url_root . 'users/includes/html_footer.php'; //custom template footer
?>

<!-- Dropzone  jqueryui required for sortable dropzone -->
<?php echo html_entity_decode($settings->elan_jquery_ui_cdn); ?>
<?php echo html_entity_decode($settings->elan_dropzone_js_cdn); ?>
<?php echo html_entity_decode($settings->elan_dropzone_css_cdn); ?>

<!-- Include datapicker -->
<?php echo html_entity_decode($settings->elan_datepicker_js_cdn); ?>
<?php echo html_entity_decode($settings->elan_datepicker_css_cdn); ?>

<!-- Dynamic model loading from database -->
<script src='<?= $us_url_root ?>app/assets/js/model-loader.js'></script>

<script>
    Dropzone.autoDiscover = false;
    const csrf = $('#csrf').val();
    const car_id = $('#car_id').val();

    $(document).ready(function() {
        // BEGIN DROPZONE

        $(function() {
            $("#myDrop").sortable({
                items: '.dz-preview',
                cursor: 'move',
                opacity: 0.5,
                containment: '#myDrop',
                distance: 20,
                tolerance: 'pointer',
            });

            $("#myDrop").disableSelection();
        });

        var myDropzone = new Dropzone("div#myDrop", {
            url: "actions/edit.php",
            autoProcessQueue: false,
            clickable: true,

            uploadMultiple: true,
            maxFiles: <?= $maximages ?>,
            maxFilesize: <?= isset($settings->elan_image_upload_max_size) ? $settings->elan_image_upload_max_size : 2 ?>, // MB
            parallelUploads: 10,

            acceptedFiles: 'image/*',
            addRemoveLinks: true,

            resizeWidth: <?= isset($settings->elan_image_display_max_size) ? $settings->elan_image_display_max_size : 2048 ?>,
            resizeMimeType: 'image/jpeg',

            dictRemoveFile: 'Remove photo',
            dictDefaultMessage: "Drop photos here to upload",
            dictMaxFilesExceeded: "Only {{maxFiles}} photos are allowed",
            dictFileTooBig: "Photo is to big ({{filesize}}mb). Max allowed photo size is {{maxFilesize}}mb",
            dictInvalidFileType: "Invalid File Type - Only images are allowed",

            paramName: "file", // The name that will be used to transfer the file

            init: function() {
                thisDropzone = this;
                
                // Only load existing images if we have a valid car ID (editing mode)
                if (car_id && car_id !== '') {
                    new ElanRegistryAPI().post('<?= $us_url_root ?>app/cars/actions/edit.php', {
                        carID: car_id,
                        action: 'fetchImages'
                    }).then(function(data) {
                        if (data == null || data.success !== true) {
                            return;
                        }
                        $.each(data.images, function(key, value) {
                            var mockFile = {
                                path: value.path,
                                name: value.basename,
                                accepted: true,
                                status: 'success',
                            };

                            thisDropzone.emit("addedfile", mockFile);
                            thisDropzone.emit("thumbnail", mockFile, value.path);
                            $('[data-dz-thumbnail]').css('height', '120');
                            $('[data-dz-thumbnail]').css('width', '120');
                            $('[data-dz-thumbnail]').css('object-fit', 'cover');

                            // Make sure that there is no progress bar, etc...

                            // thisDropzone.emit("success", mockFile);
                            thisDropzone.emit("complete", mockFile);

                            thisDropzone.files.push(mockFile);
                        });
                    }).catch(function() {
                        // Failed to fetch images - handle silently
                    });
                }

                // Grab the submit button.  Make sure it's error free and process the queue
                document.getElementById("submit").addEventListener("click", function(e) {
                    current_fs = $(this).parent();
                    next_fs = $(this).parent().next();

                    // Check to see if any of the form fields are invalid
                    var form_data = $('#addCar').serializeArray();
                    var error_free = true;

                    for (var input in form_data) {
                        var element = $('#' + form_data[input]['name'] + '_icon');
                        var invalid = element.hasClass('fa-thumbs-down');
                        if (invalid) {
                            error_free = false;
                        }
                    }

                    // check to see if there are any errors on the images
                    //  See if data-dz-errormessage is empty for all images.
                    $('.dropzone .dz-error-message span').each(function() {
                        if ($(this).text()) {
                            error_free = false;
                        }
                    });

                    if (!error_free) {
                        $('#message').show().append('<div class="alert alert-primary">Error: There are one or more errors on the page.<br>Please update and submit</div>');

                        e.preventDefault();
                    } else {
                        // Now process the queue
                        if (thisDropzone.getQueuedFiles().length > 0) {
                            e.stopPropagation();
                            e.preventDefault();
                            thisDropzone.processQueue();
                        } else {
                            e.stopPropagation();
                            e.preventDefault();

                            // https://stackoverflow.com/questions/20910571/dropzonejs-submit-form-without-files
                            var blob = new Blob();
                            blob.upload = {};
                            thisDropzone.uploadFile(blob);
                        }
                    }
                });

                thisDropzone.on("addedfile", function(file) {
                    $("#message").hide();
                });

            }
        });

        //send all the form data along with the files:
        myDropzone.on("sendingmultiple", function(data, xhr, formData) {
            var filenames = [];
            $('.dz-preview .dz-filename').each(function() {
                filenames.push($(this).find('span').text());
            });

            formData.append('action', $('#action').val());
            formData.append('csrf', $('#csrf').val());
            formData.append('filenames', filenames);
            formData.append('car_id', $('#car_id').val());
            formData.append('year', $('#year').val());
            formData.append('model', $('#model').val());
            formData.append('series', $('#series').val());
            formData.append('variant', $('#variant').val());
            formData.append('type', $('#type').val());
            formData.append('chassis', $('#chassis').val());
            formData.append('chassis_override', $('#chassis_override').is(':checked') ? '1' : '0');
            formData.append('color', $('#color').val());
            formData.append('engine', $('#engine').val());
            formData.append('purchasedate', $('#purchasedate').val());
            formData.append('solddate', $('#solddate').val());
            formData.append('website', $('#website').val());
            formData.append('comments', $('#comments').val());
        });

        /**
         * Display validation errors in the results fieldset and repopulate form fields.
         * Used by both successmultiple (200 with success:false) and error (422) handlers.
         */
        function displayValidationErrors(data) {
            // Advance the page progress indicator
            $('#message').hide();
            $('#progressbar li').eq($('fieldset').index(next_fs)).addClass('active');

            //show the next fieldset
            next_fs.show();
            //hide the current fieldset with style
            current_fs.animate({
                opacity: 0
            }, {
                step: function(now) {
                    // for making fielset appear animation
                    opacity = 1 - now;

                    current_fs.css({
                        'display': 'none',
                        'position': 'relative'
                    });
                    next_fs.css({
                        'opacity': opacity
                    });
                },
                duration: 500
            });
            setProgressBar(++current);

            // Build error display table
            var html = "<table id='resultstable' class='table table-striped table-bordered table-sm text-wrap'>";
            html += '<tr><td>Status</td><td><strong class="text-danger">ERROR</strong></td></tr>';
            html += '<tr><td>Message</td><td>' + (data.message || 'An error occurred') + '</td></tr>';

            // Display validation errors if present
            if (data.errors) {
                html += '<tr><td>Validation Errors</td><td><ul>';
                if (Array.isArray(data.errors.general)) {
                    data.errors.general.forEach(function(error) {
                        html += '<li>' + error + '</li>';
                    });
                } else {
                    for (var field in data.errors) {
                        html += '<li><strong>' + field + ':</strong> ' + data.errors[field] + '</li>';
                    }
                }
                html += '</ul></td></tr>';
            }

            html += '</table>';
            $("#results").html(html);

            // Repopulate form fields with submitted values to prevent data loss
            if (data.cardetails) {
                // Repopulate Year dropdown
                if (data.cardetails.year) {
                    $('#year option[value=' + data.cardetails.year + ']').prop('selected', true);
                    $('#year').trigger('change');
                }

                // Repopulate Model dropdown
                if (data.cardetails.model) {
                    var model = data.cardetails.model.replace(/\|/g, "\\\|")
                                                     .replace(/ /g, "\\\ ")
                                                     .replace(/\//g, "\\\/")
                                                     .replace(/\+/g, "\\\+");
                    $('#model option[value=' + model + ']').prop('selected', true);
                    $('#model').trigger('change');
                }

                // Repopulate other fields as needed
                if (data.cardetails.chassis) $('#chassis').val(data.cardetails.chassis);
                if (data.cardetails.color) $('#color').val(data.cardetails.color);
                if (data.cardetails.engine) $('#engine').val(data.cardetails.engine);
                if (data.cardetails.comments) $('#comments').val(data.cardetails.comments);
                if (data.cardetails.website) $('#website').val(data.cardetails.website);
                if (data.cardetails.purchasedate) $('#purchasedate').val(data.cardetails.purchasedate);
                if (data.cardetails.solddate) $('#solddate').val(data.cardetails.solddate);
            }
        }

        myDropzone.on("successmultiple", function(file, message) {
            // Message may already be parsed by Dropzone due to Content-Type header
            const data = (typeof message === 'string') ? JSON.parse(message) : message;

            // Pattern A: Check success boolean
            if (data.success === true) {
                window.location = '<?= $us_url_root ?>app/cars/details.php?car_id=' + data.cardetails.id;
            } else {
                displayValidationErrors(data);
            }
        });

        myDropzone.on("error", function(data, msg, xhr) {
            if (xhr && xhr.responseText) {
                try {
                    var jsonData = JSON.parse(xhr.responseText);
                    if (jsonData.success === false) {
                        displayValidationErrors(jsonData);
                        return;
                    }
                } catch (e) {
                    // Not JSON, fall through to generic display
                }
            }
            $("#message").show().html('<div class="alert alert-primary">' + msg + '</div>');
        });

        // END DROPZONE

        // Tabbed interface

        var current_fs, next_fs, previous_fs; //fieldsets
        var opacity;
        var current = 1;
        var steps = $('fieldset').length;

        setProgressBar(current);

        $('.next').click(function() {
            current_fs = $(this).parent();
            next_fs = $(this).parent().next();

            // Check to see if the page is error free
            var form_data = current_fs.serializeArray();
            var error_free = true;
            var chassis_override = $('#chassis_override').is(':checked');

            for (var input in form_data) {
                var element = $('#' + form_data[input]['name'] + '_icon');
                var invalid = element.hasClass('fa-thumbs-down');
                
                // Allow proceeding if chassis validation is overridden
                if (invalid && form_data[input]['name'] === 'chassis' && chassis_override) {
                    continue; // Skip chassis validation error if override is enabled
                }
                
                if (invalid) {
                    error_free = false;
                }
            }

            if (!error_free) {
                var errorMessage = 'Error: There are one or more errors on the page.<br>Please update and submit.';
                if (chassis_override) {
                    errorMessage += '<br><strong>Note:</strong> Chassis validation has been overridden - please verify the chassis number is correct.';
                }
                $('#message').show().html('<div class="alert alert-primary">' + errorMessage + '<div>');
            } else {
                $('#message').hide();

                //Add Class Active
                $('#progressbar li').eq($('fieldset').index(next_fs)).addClass('active');

                //show the next fieldset
                next_fs.show();
                //hide the current fieldset with style
                current_fs.animate({
                    opacity: 0
                }, {
                    step: function(now) {
                        // for making fielset appear animation
                        opacity = 1 - now;

                        current_fs.css({
                            'display': 'none',
                            'position': 'relative'
                        });
                        next_fs.css({
                            'opacity': opacity
                        });
                    },
                    duration: 500
                });
                setProgressBar(++current);
            }
        });

        $(".previous").click(function() {
            current_fs = $(this).parent();
            previous_fs = $(this).parent().prev();

            //Remove class active
            $("#progressbar li").eq($("fieldset").index(current_fs)).removeClass("active");

            //show the previous fieldset
            previous_fs.show();

            //hide the current fieldset with style
            current_fs.animate({
                opacity: 0
            }, {
                step: function(now) {
                    // for making fielset appear animation
                    opacity = 1 - now;

                    current_fs.css({
                        'display': 'none',
                        'position': 'relative'
                    });
                    previous_fs.css({
                        'opacity': opacity
                    });
                },
                duration: 500
            });
            setProgressBar(--current);
        });

        function setProgressBar(curStep) {
            var percent = parseFloat(100 / steps) * curStep;
            percent = percent.toFixed();
            $(".progress-bar")
                .css("width", percent + "%")
        }
    });

    // End Tabbed Form

    // Car Validation
    var validYear = '';
    var validModel = '';
    var validChassis = '';

    $(document).ready(function() {
        $('#message').hide();

        // // Pop-up Calendar for date fields
        // Avoid conflict with jquery datepicker - https://stackoverflow.com/questions/18507908/bootstrap-datepicker-noconflict#18512888
        $(function() {
            var datepicker = $.fn.datepicker.noConflict();
            $.fn.bootstrapDP = datepicker;
            $('#purchasedate').bootstrapDP({
                format: 'yyyy-mm-dd',
                todayHighlight: false,
                autoclose: true,
            });
            $('#solddate').bootstrapDP({
                format: 'yyyy-mm-dd',
                todayHighlight: false,
                autoclose: true,
            });
        });

        // Pre-populate dropdown menus if we are updating a car
<?php if ($action === 'updateCar'): ?>
        if ($('#action').val() === 'updateCar') {
            const year = <?= $cardetails['year'] ?>;
            const modelValue = "<?= $cardetails['model'] ?>";

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
            $('#submit').attr('value', 'Update Car');
            $('#car_id').html($('#car_id').val());
            $('#carHeader').html('<h2><strong>Update car</strong><h2>');
        }
<?php endif; ?>
    });

    /* *
     *  Validate car form during data entry
     *
     * Set fields that are valid as green and invalid as red
     */

    // Initialize ModelLoader with API endpoint
    ModelLoader.init('<?= $us_url_root ?>app/cars/actions/get-models.php');

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
        new ElanRegistryAPI().post('<?= $us_url_root ?>app/cars/actions/validateChassis.php', {
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

        // Show/hide validation error
        const overrideEnabled = $('#chassis_override').is(':checked');
        if (!isValid && errorReason && !overrideEnabled) {
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
        new ElanRegistryAPI().post('<?= $us_url_root ?>app/cars/actions/check-chassis.php', {
            csrf: $('#csrf').val(),
            command: 'chassis_check',
            year: validYear,
            model: validModel,
            chassis: validChassis
        }).then(function(response) {
            if (response.taken) {
                validChassis = '';
                updateChassisUI(false, 'This chassis number is already registered');
                $('#chassis_taken').show();
            } else {
                $('#chassis_taken').hide();
                $('#color').prop('disabled', false);
                $('#engine').prop('disabled', false);
            }
        }).catch(function() {
            // Chassis availability check failed - handle silently
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
            $('#transferValidationModal').modal('show');
            return;
        }

        // Show confirmation modal
        $('#transferConfirmModal').modal('show');
    });

    // Handle transfer confirmation
    $('#confirmTransferBtn').click(function() {
        $('#transferConfirmModal').modal('hide');

        // Disable button to prevent double-submission
        $('#request_transfer_btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Requesting...');

        // Validate transfer comments length
        const transferComments = $('#transfer_comments').val();
        if (transferComments.length > 1000) {
            $('#transferErrorMessage').text('Transfer explanation must be 1000 characters or less.');
            $('#transferErrorModal').modal('show');
            $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
            return;
        }

        const chassis = $('#chassis').val();
        const year = validYear;
        const model = validModel;

        new ElanRegistryAPI().post('<?= $us_url_root ?>app/cars/actions/request-transfer.php', {
            csrf: $('#csrf').val(),
            chassis: chassis,
            year: year,
            model: model,
            color: $('#color').val() || '',
            engine: $('#engine').val() || '',
            comments: $('#transfer_comments').val() || ''
        }).then(function(response) {
            if (response.success) {
                $('#transferSuccessModal').modal('show');
            } else {
                $('#transferErrorMessage').text(response.message);
                $('#transferErrorModal').modal('show');
                $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
            }
        }).catch(function() {
            $('#transferErrorMessage').text('There was an error processing your request. Please try again.');
            $('#transferErrorModal').modal('show');
            $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
        });
    });

    // Handle success modal OK button
    $('#transferSuccessOkBtn').click(function() {
        window.location.href = '<?= $us_url_root ?>app/cars/';
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
