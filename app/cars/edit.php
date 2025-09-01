<?php

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

if (!securePage($_SERVER['PHP_SELF'])) {
    die();
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

if (Input::exists('post')) {
    $token = Input::get('csrf');
    if (!Token::check($token)) {
        include_once $abs_us_root . $us_url_root . 'usersc/scripts/token_error.php';
    } else {

        $action = Input::get('action');
        $cardetails['id']  = Input::get('carid');

        if ($action === 'updateCar') {
            updateCarDetails($cardetails);
        } else {
            $errors[] = 'No valid action';
        }
    } // End Post with data
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
        logger($user->data()->id, 'ElanRegistry', 'Empty carid field in GET');
        return;
    }

    $carQ = new Car($car['id']);

    // Security: Verify user ownership or admin/editor permissions
    $isOwner = ($user->data()->id == $carQ->data()->user_id);
    $hasAdminAccess = hasPerm([2, 3]); // Permission 2 = Administrator, 3 = Editor
    
    if (!$isOwner && !$hasAdminAccess) {
        // Security violation: User attempting to access car they don't own and don't have admin/editor access
        logger($user->data()->id, 'ElanRegistry', 'Access denied for car edit - USER ' . $user->data()->id . ' CAR ' . $car['id'] . ' (not owner, no admin/editor perms)');
        $user->logout();
        exit();
    }
    
    // Log admin/editor access for audit trail
    if (!$isOwner && $hasAdminAccess) {
        logger($user->data()->id, 'ElanRegistry', 'Admin/Editor accessing car edit - USER ' . $user->data()->id . ' CAR ' . $car['id']);
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
            <form id="editCar" name="editCar" method="post" enctype="multipart/form-data" novalidate>
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
                    <input type="hidden" name="csrf" id="csrf" value="<?= htmlspecialchars(Token::generate(), ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="action" id="action" value="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8'); ?>" />
                    <input type="hidden" name="carid" id="carid" value="<?= htmlspecialchars($cardetails['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" />
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

<!-- Year/Model definitions -->
<script src='<?= $us_url_root ?>app/assets/js/cardefinition.js'></script>

<script>
    Dropzone.autoDiscover = false;
    const csrf = $('#csrf').val();
    const carid = $('#carid').val();

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
            maxFilesize: 2, // MB
            parallelUploads: 10,

            acceptedFiles: 'image/*',
            addRemoveLinks: true,

            resizeWidth: 2048,
            resizeMimeType: 'image/jpeg',

            dictRemoveFile: 'Remove photo',
            dictDefaultMessage: "Drop photos here to upload",
            dictMaxFilesExceeded: "Only {{maxFiles}} photos are allowed",
            dictFileTooBig: "Photo is to big ({{filesize}}mb). Max allowed photo size is {{maxFilesize}}mb",
            dictInvalidFileType: "Invalid File Type - Only images are allowed",

            paramName: "file", // The name that will be used to transfer the file

            init: function() {
                thisDropzone = this;
                // Load any existing images
                $.post('actions/edit.php', {
                        'carID': carid,
                        'csrf': csrf,
                        'action': 'fetchImages'
                    },
                    function(response) {
                        let data = JSON.parse(response);
                        if (data == null || data.status != 'success') {
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

                    });

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
            formData.append('carid', $('#carid').val());
            formData.append('year', $('#year').val());
            formData.append('model', $('#model').val());
            formData.append('series', $('#series').val());
            formData.append('variant', $('#variant').val());
            formData.append('type', $('#type').val());
            formData.append('chassis', $('#chassis').val());
            formData.append('color', $('#color').val());
            formData.append('engine', $('#engine').val());
            formData.append('purchasedate', $('#purchasedate').val());
            formData.append('solddate', $('#solddate').val());
            formData.append('website', $('#website').val());
            formData.append('comments', $('#comments').val());
        });

        myDropzone.on("successmultiple", function(file, message) {
            const data = JSON.parse(message);

            if (data.status === 'success') {
                window.location = '<?= $us_url_root ?>app/cars/details.php?car_id=' + data.cardetails.id;
            } else {

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
                var html = "<table id='resultstable' class='table table-striped table-bordered table-sm text-wrap'>";
                html += '<tr><td>Status</td><td>' + data.status + '</td></tr>';
                html += '<tr><td>Info</td><td><ul>';
                data.info.forEach(function(element, index, names) {
                    html += '<li>' + element + '</li>';
                });
                html += '<ul></td></tr>';
                html += '</table>'

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
        });

        myDropzone.on("error", function(data, msg, xhr) {
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
        if ($('#action').val() === 'updateCar') {
            $('#year option[value=<?= $cardetails['year'] ?>]').prop('selected', true);
            $('#year').trigger('change'); // Trigger the change event to populate and validate
            // Need to escape all the special characters in the MODEL field in order for this to work
            var model = "<?= $cardetails['model'] ?>";

            // Escape the special characters in the model string
            var model = model.replace(/\|/g, "\\\|");
            var model = model.replace(/ /g, "\\\ ");
            var model = model.replace(/\//g, "\\\/");
            var model = model.replace(/\+/g, "\\\+");

            $('#model option[value=' + model + ']').prop('selected', true);

            $('#model').trigger('change'); // Trigger the change event to populate and validate
            $('#chassis').trigger('blur'); // Trigger the change event to populate and validate

            // Show all fields
            $('#color').prop('disabled', false)
            $('#engine').prop('disabled', false)
            $('#purchasedate').prop('disabled', false)
            $('#solddate').prop('disabled', false)
            $('#website').prop('disabled', false)
            $('#comments').prop('disabled', false)

            // Set the form text for Update
            $('#submit').attr('value', 'Update Car');
            $('#carid').html($('#carid').val());
            $('#carHeader').html('<h2><strong>Update car</strong><h2>');
        }
    });

    /* *
     *  Validate car form during data entry
     *
     * Set fields that are valid as green and invalid as red
     */

    /*
     * When year changes, update the model list and show the appropriate chassis help text
     */
    $('#year').change(function() {
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

        if (validYear) {
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
            populateSub($('#year').get(0), $('#model').get(0));
        }
    });
    // Validate Model
    $('#model').change(function() {
        validModel = $('#model option:selected').val();

        $('#model_icon').toggleClass('fa-thumbs-up', Boolean(validModel)).toggleClass('fa-thumbs-down', !Boolean(validModel)).toggleClass('is-valid', Boolean(validModel)).toggleClass('is-invalid', !Boolean(validModel));
        $('#model').toggleClass('is-valid', Boolean(validModel)).toggleClass('is-invalid', !Boolean(validModel));
        $('#chassis').prop('disabled', false);
    });

    // Validate Chassis
    // Chassis Validation Rules:
    // Race cars: 26-R-xx (1963) or 26-R-xx/26-S2-xx (1964) or 26-S2-xx (1965-1966)
    // Pre-1970: 4 digits only (all production models)
    // Post-Jan 1970: YYMMBBXXXXC format (11 characters) where:
    //   - YY = Year, MM = Month, BB = Batch, XXXX = Sequential, C = Letter code
    //   - Elan models: Letter codes A-K only
    //   - +2 models: Letter codes L, M, N only
    $('#chassis').blur(function() {
        // Letter codes are now validated based on model type in the validation logic below
        const _chassis = $('#chassis').val();
        var _base;
        var _suffix;
        var errorReason = '';

        // Check if override is enabled
        const overrideEnabled = $('#chassis_override').is(':checked');

        // Race cars: year-specific format validation
        if (validModel.indexOf('Race') >= 0) {
            const racePatternR = /^26-R-\d{2}$/;   // 26-R-xx format
            const racePatternS2 = /^26-S2-\d{2}$/; // 26-S2-xx format
            
            if (validYear === '1963') {
                // 1963: 26-R-xx only
                if (racePatternR.test(_chassis)) {
                    validChassis = _chassis;
                } else {
                    validChassis = overrideEnabled ? _chassis : '';
                    errorReason = '1963 race cars must use format 26-R-xx (e.g., 26-R-01)';
                }
            } else if (validYear === '1964') {
                // 1964: 26-R-xx OR 26-S2-xx
                if (racePatternR.test(_chassis) || racePatternS2.test(_chassis)) {
                    validChassis = _chassis;
                } else {
                    validChassis = overrideEnabled ? _chassis : '';
                    errorReason = '1964 race cars must use format 26-R-xx or 26-S2-xx (e.g., 26-R-01 or 26-S2-01)';
                }
            } else if (validYear === '1965' || validYear === '1966') {
                // 1965-1966: 26-S2-xx only
                if (racePatternS2.test(_chassis)) {
                    validChassis = _chassis;
                } else {
                    validChassis = overrideEnabled ? _chassis : '';
                    errorReason = validYear + ' race cars must use format 26-S2-xx (e.g., 26-S2-01)';
                }
            } else {
                // Other years with race models - use 26-R-xx format as default
                if (racePatternR.test(_chassis)) {
                    validChassis = _chassis;
                } else {
                    validChassis = overrideEnabled ? _chassis : '';
                    errorReason = validYear + ' race cars must use format 26-R-xx (e.g., 26-R-01)';
                }
            }
        } else {
            // Production cars validation
            switch (validYear) {
                case '1963':
                case '1964':
                case '1965':
                case '1966':
                case '1967':
                case '1968':
                case '1969':
                    // Pre-1970: 4 digits only
                    if ($.isNumeric(_chassis) && (_chassis.length === 4)) {
                        validChassis = _chassis;
                    } else {
                        validChassis = overrideEnabled ? _chassis : '';
                        if (!$.isNumeric(_chassis)) {
                            errorReason = 'Pre-1970 chassis must be numeric (4 digits only, e.g., 1234)';
                        } else {
                            errorReason = 'Pre-1970 chassis must be exactly 4 digits (e.g., 1234)';
                        }
                    }
                    break;
                case '1970':
                case '1971':
                case '1972':
                case '1973':
                case '1974':
                    // Post-Jan 1970: All models use YYMMBBXXXXC format (11 characters)
                    if (_chassis.length === 11) {
                        _base = _chassis.slice(0, 10);
                        _suffix = _chassis.slice(10, 11).toUpperCase();
                        
                        // Model-specific letter validation
                        let validSuffixes;
                        if (validModel.indexOf('+2') >= 0) {
                            // +2 models can only use L, M, N
                            validSuffixes = ['L', 'M', 'N'];
                        } else {
                            // Elan models can only use A-K
                            validSuffixes = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'];
                        }
                        
                        if ($.isNumeric(_base) && ($.inArray(_suffix, validSuffixes) !== -1)) {
                            validChassis = _chassis;
                        } else {
                            validChassis = overrideEnabled ? _chassis : '';
                            if (!$.isNumeric(_base)) {
                                errorReason = 'First 10 characters must be numeric in YYMMBBXXXXC format (e.g., 7301019999B)';
                            } else {
                                const modelType = validModel.indexOf('+2') >= 0 ? '+2' : 'Elan';
                                const allowedCodes = validModel.indexOf('+2') >= 0 ? 'L, M, N' : 'A-K (excluding I)';
                                errorReason = modelType + ' models require letter codes: ' + allowedCodes + ' (current: "' + _suffix + '")';
                            }
                        }
                    } else if (validYear === '1970' && _chassis.length === 5) {
                        // 1970 transition year: also allow legacy 5-character format
                        _base = _chassis.slice(0, 4);
                        _suffix = _chassis.slice(4, 5).toUpperCase();
                        
                        let validSuffixes = (validModel.indexOf('+2') >= 0) ? ['L', 'M', 'N'] : ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K'];
                        
                        if ($.isNumeric(_base) && ($.inArray(_suffix, validSuffixes) !== -1)) {
                            validChassis = _chassis;
                        } else {
                            validChassis = overrideEnabled ? _chassis : '';
                            if (!$.isNumeric(_base)) {
                                errorReason = '1970 transition format: First 4 characters must be numeric plus letter (e.g., 1234A)';
                            } else {
                                const modelType = validModel.indexOf('+2') >= 0 ? '+2' : 'Elan';
                                const allowedCodes = validModel.indexOf('+2') >= 0 ? 'L, M, N' : 'A-K (excluding I)';
                                errorReason = '1970 ' + modelType + ' models require letter codes: ' + allowedCodes + ' (current: "' + _suffix + '")';
                            }
                        }
                    } else {
                        validChassis = overrideEnabled ? _chassis : '';
                        if (validYear === '1970') {
                            errorReason = '1970 chassis must be 5 characters (legacy format) or 11 characters (new YYMMBBXXXXC format)';
                        } else {
                            errorReason = 'Post-1970 chassis must be 11 characters in YYMMBBXXXXC format (e.g., 7301019999B)';
                        }
                    }
                    break;
                default:
                    validChassis = overrideEnabled ? _chassis : '';
                    errorReason = 'Invalid year selected';
                    break;
            }
        }

        // Display or hide validation error
        if (!validChassis && _chassis && !overrideEnabled) {
            $('#chassis_validation_error').removeClass('hidden').show();
            $('#chassis_error_reason').text(errorReason);
        } else {
            $('#chassis_validation_error').addClass('hidden').hide();
        }

        $('#chassis_icon').toggleClass('fa-thumbs-up', Boolean(validChassis)).toggleClass('fa-thumbs-down', !Boolean(validChassis)).toggleClass('is-valid', Boolean(validChassis)).toggleClass('is-invalid', !Boolean(validChassis));
        $('#chassis').toggleClass('is-valid', Boolean(validChassis)).toggleClass('is-invalid', !Boolean(validChassis));

        if ($('#action').val() === 'addCar' && (validChassis)) {
            // addCar
            if (validChassis) {
                // Now see if the chassis is taken
                // const csrf = $('#csrf').val();
                $.ajax({
                    url: 'action/checkChassis.php',
                    type: 'post',
                    data: {
                        'command': 'chassis_check',
                        'year': validYear,
                        'model': validModel,
                        'chassis': validChassis,
                        'csrf': csrf,
                    },
                    success: function(response) {
                        if (response === 'taken') {
                            validChassis = '';
                            $('#chassis_icon').toggleClass('fa-thumbs-up', Boolean(validChassis)).toggleClass('fa-thumbs-down', !Boolean(validChassis)).toggleClass('is-valid', Boolean(validChassis)).toggleClass('is-invalid', !Boolean(validChassis));
                            $('#chassis').toggleClass('is-valid', Boolean(validChassis)).toggleClass('is-invalid', !Boolean(validChassis));
                            $('#chassis_taken').show();
                        } else if (response === 'not_taken') {
                            $('#chassis_taken').hide();
                            $('#color').prop('disabled', false)
                            $('#engine').prop('disabled', false)
                        }
                    },
                    error: function(response) {},
                });
            }
        }
    });

    // Override checkbox event handler - re-validate when toggled
    $('#chassis_override').change(function() {
        if ($('#chassis').val()) {
            $('#chassis').trigger('blur');
        }
    });

    // End Car Validation
</script>
