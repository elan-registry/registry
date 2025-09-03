/**
 * Car Edit Form JavaScript
 * 
 * Handles dropzone file uploads, form validation, and multi-step form navigation
 * for the car registration/editing interface.
 * 
 * @author Elan Registry Team
 * @version 2.0
 */

// Configuration constants
const CONFIG = {
    DROPZONE: {
        RESIZE_WIDTH: 2048,
        RESIZE_MIME_TYPE: 'image/jpeg',
        MAX_FILESIZE: 2, // MB
        PARALLEL_UPLOADS: 10,
        ACCEPTED_FILES: 'image/*'
    },
    ANIMATION: {
        DURATION: 500
    },
    VALIDATION: {
        CHASSIS_SUFFIXES: ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'J', 'K', 'L', 'M', 'N']
    }
};

// Global variables
let thisDropzone = null;
let validYear = '';
let validModel = '';
let validChassis = '';
let current = 1;

/**
 * Initialize dropzone for file uploads
 */
function initializeDropzone() {
    Dropzone.autoDiscover = false;
    const csrf = $('#csrf').val();
    const carid = $('#carid').val();
    const maximages = window.maximages || 10;

    // Make sortable
    $("#myDrop").sortable({
        items: '.dz-preview',
        cursor: 'move',
        opacity: 0.5,
        containment: '#myDrop',
        distance: 20,
        tolerance: 'pointer',
    }).disableSelection();

    const myDropzone = new Dropzone("div#myDrop", {
        url: "actions/edit.php",
        autoProcessQueue: false,
        clickable: true,
        uploadMultiple: true,
        maxFiles: maximages,
        maxFilesize: CONFIG.DROPZONE.MAX_FILESIZE,
        parallelUploads: CONFIG.DROPZONE.PARALLEL_UPLOADS,
        acceptedFiles: CONFIG.DROPZONE.ACCEPTED_FILES,
        addRemoveLinks: true,
        resizeWidth: CONFIG.DROPZONE.RESIZE_WIDTH,
        resizeMimeType: CONFIG.DROPZONE.RESIZE_MIME_TYPE,
        dictRemoveFile: 'Remove photo',
        dictDefaultMessage: "Drop photos here to upload",
        dictMaxFilesExceeded: `Only ${maximages} photos are allowed`,
        dictFileTooBig: "Photo is too big ({{filesize}}mb). Max allowed photo size is {{maxFilesize}}mb",
        dictInvalidFileType: "Invalid File Type - Only images are allowed",
        paramName: "file",
        init: function() {
            thisDropzone = this;
            loadExistingImages(csrf, carid);
            setupSubmitHandler();
            setupFileAddedHandler();
        }
    });

    setupDropzoneEvents(myDropzone);
    return myDropzone;
}

/**
 * Load existing images for editing
 */
function loadExistingImages(csrf, carid) {
    $.post('actions/edit.php', {
        'carID': carid,
        'csrf': csrf,
        'action': 'fetchImages'
    }, function(response) {
        try {
            const data = JSON.parse(response);
            if (!data || data.status !== 'success') {
                return;
            }
            
            data.images.forEach(function(value) {
                const mockFile = {
                    path: value.path,
                    name: value.basename,
                    accepted: true,
                    status: 'success',
                };

                thisDropzone.emit("addedfile", mockFile);
                thisDropzone.emit("thumbnail", mockFile, value.path);
                thisDropzone.emit("complete", mockFile);
                thisDropzone.files.push(mockFile);
            });

            // Style thumbnails
            $('[data-dz-thumbnail]').css({
                'height': '120px',
                'width': '120px',
                'object-fit': 'cover'
            });
        } catch (e) {
            console.error('Error loading existing images:', e);
        }
    }).fail(function() {
        console.error('Failed to load existing images');
    });
}

/**
 * Setup form submission handler
 */
function setupSubmitHandler() {
    document.getElementById("submit").addEventListener("click", function(e) {
        const current_fs = $(this).parent();
        const next_fs = $(this).parent().next();

        if (!validateForm()) {
            $('#message').show().html('<div class="alert alert-primary">Error: There are one or more errors on the page.<br>Please update and submit</div>');
            e.preventDefault();
            return;
        }

        // Process queue or upload empty blob
        if (thisDropzone.getQueuedFiles().length > 0) {
            e.stopPropagation();
            e.preventDefault();
            thisDropzone.processQueue();
        } else {
            e.stopPropagation();
            e.preventDefault();
            const blob = new Blob();
            blob.upload = {};
            thisDropzone.uploadFile(blob);
        }
    });
}

/**
 * Validate entire form
 */
function validateForm() {
    // Check form field validation
    const form_data = $('#addCar').serializeArray();
    let error_free = true;

    form_data.forEach(function(input) {
        const element = $('#' + input.name + '_icon');
        if (element.hasClass('fa-thumbs-down')) {
            error_free = false;
        }
    });

    // Check dropzone errors
    $('.dropzone .dz-error-message span').each(function() {
        if ($(this).text()) {
            error_free = false;
        }
    });

    return error_free;
}

/**
 * Setup file added handler
 */
function setupFileAddedHandler() {
    thisDropzone.on("addedfile", function() {
        $("#message").hide();
    });
}

/**
 * Setup dropzone event handlers
 */
function setupDropzoneEvents(myDropzone) {
    // Send form data with files
    myDropzone.on("sendingmultiple", function(data, xhr, formData) {
        const filenames = [];
        $('.dz-preview .dz-filename').each(function() {
            filenames.push($(this).find('span').text());
        });

        // Append all form data
        const formFields = [
            'action', 'csrf', 'carid', 'year', 'model', 'series', 
            'variant', 'type', 'chassis', 'color', 'engine', 
            'purchasedate', 'solddate', 'website', 'comments'
        ];

        formFields.forEach(function(field) {
            formData.append(field, $('#' + field).val());
        });
        formData.append('filenames', filenames);
    });

    // Handle successful upload
    myDropzone.on("successmultiple", function(file, message) {
        try {
            const data = JSON.parse(message);
            if (data.status === 'success') {
                window.location = window.us_url_root + 'app/cars/details.php?car_id=' + data.cardetails.id;
            } else {
                showResults(data);
            }
        } catch (e) {
            console.error('Error parsing response:', e);
            showError('Invalid response from server');
        }
    });

    // Handle upload errors
    myDropzone.on("error", function(data, msg) {
        showError(msg);
    });
}

/**
 * Show upload results
 */
function showResults(data) {
    const next_fs = $('#submit').parent().next();
    const current_fs = $('#submit').parent();

    $('#message').hide();
    $('#progressbar li').eq($('fieldset').index(next_fs)).addClass('active');

    animateFieldsetTransition(current_fs, next_fs);
    setProgressBar(++current);

    let html = "<table id='resultstable' class='table table-striped table-bordered table-sm text-wrap'>";
    html += '<tr><td>Status</td><td>' + escapeHtml(data.status) + '</td></tr>';
    html += '<tr><td>Info</td><td><ul>';
    
    if (data.info && Array.isArray(data.info)) {
        data.info.forEach(function(element) {
            html += '<li>' + escapeHtml(element) + '</li>';
        });
    }
    
    html += '</ul></td></tr></table>';
    $("#results").html(html);
}

/**
 * Show error message
 */
function showError(message) {
    $("#message").show().html('<div class="alert alert-danger">' + escapeHtml(message) + '</div>');
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
}

/**
 * Initialize tabbed interface
 */
function initializeTabbedInterface() {
    const steps = $('fieldset').length;
    setProgressBar(current);

    $('.next').click(function() {
        const current_fs = $(this).parent();
        const next_fs = $(this).parent().next();

        if (validateCurrentPage(current_fs)) {
            navigateToNext(current_fs, next_fs);
        } else {
            showError('There are one or more errors on the page.<br>Please update and submit.');
        }
    });

    $(".previous").click(function() {
        const current_fs = $(this).parent();
        const previous_fs = $(this).parent().prev();
        navigateToPrevious(current_fs, previous_fs);
    });
}

/**
 * Validate current page
 */
function validateCurrentPage(current_fs) {
    const form_data = current_fs.serializeArray();
    let error_free = true;

    form_data.forEach(function(input) {
        const element = $('#' + input.name + '_icon');
        if (element.hasClass('fa-thumbs-down')) {
            error_free = false;
        }
    });

    return error_free;
}

/**
 * Navigate to next page
 */
function navigateToNext(current_fs, next_fs) {
    $('#message').hide();
    $('#progressbar li').eq($('fieldset').index(next_fs)).addClass('active');
    animateFieldsetTransition(current_fs, next_fs);
    setProgressBar(++current);
}

/**
 * Navigate to previous page
 */
function navigateToPrevious(current_fs, previous_fs) {
    $("#progressbar li").eq($("fieldset").index(current_fs)).removeClass("active");
    animateFieldsetTransition(current_fs, previous_fs);
    setProgressBar(--current);
}

/**
 * Animate fieldset transition
 */
function animateFieldsetTransition(from_fs, to_fs) {
    to_fs.show();
    from_fs.animate({ opacity: 0 }, {
        step: function(now) {
            const opacity = 1 - now;
            from_fs.css({
                'display': 'none',
                'position': 'relative'
            });
            to_fs.css({ 'opacity': opacity });
        },
        duration: CONFIG.ANIMATION.DURATION
    });
}

/**
 * Set progress bar
 */
function setProgressBar(curStep) {
    const steps = $('fieldset').length;
    const percent = parseFloat((100 / steps) * curStep).toFixed();
    $(".progress-bar").css("width", percent + "%");
}

/**
 * Initialize date pickers
 */
function initializeDatePickers() {
    const datepicker = $.fn.datepicker.noConflict();
    $.fn.bootstrapDP = datepicker;
    
    const dateConfig = {
        format: 'yyyy-mm-dd',
        todayHighlight: false,
        autoclose: true,
    };
    
    $('#purchasedate').bootstrapDP(dateConfig);
    $('#solddate').bootstrapDP(dateConfig);
}

/**
 * Initialize car validation
 */
function initializeCarValidation() {
    setupYearValidation();
    setupModelValidation();
    setupChassisValidation();
}

/**
 * Setup year validation
 */
function setupYearValidation() {
    $('#year').change(function() {
        validYear = $('#year option:selected').val();
        updateFieldValidation('#year', validYear);

        // Reset dependent fields when year changes
        if ($('#action').val() === 'addCar') {
            resetDependentFields();
        } else {
            $('#model').prop('disabled', false);
        }

        if (validYear) {
            showChassisHelp(validYear);
            populateSub($('#year').get(0), $('#model').get(0));
        }
    });
}

/**
 * Setup model validation
 */
function setupModelValidation() {
    $('#model').change(function() {
        validModel = $('#model option:selected').val();
        updateFieldValidation('#model', validModel);
        $('#chassis').prop('disabled', false);
    });
}

/**
 * Setup chassis validation
 */
function setupChassisValidation() {
    $('#chassis').blur(function() {
        const chassis = $('#chassis').val();
        validChassis = validateChassisNumber(chassis, validYear, validModel);
        updateFieldValidation('#chassis', validChassis);

        if ($('#action').val() === 'addCar' && validChassis) {
            checkChassisAvailability();
        }
    });
}

/**
 * Validate chassis number based on year and model
 */
function validateChassisNumber(chassis, year, model) {
    // Race models have flexible chassis validation
    if (model && model.indexOf('Race') >= 0) {
        return chassis;
    }

    const suffixes = CONFIG.VALIDATION.CHASSIS_SUFFIXES;
    let base, suffix;

    switch (year) {
        case '1963': case '1964': case '1965': case '1966':
        case '1967': case '1968': case '1969':
            return ($.isNumeric(chassis) && chassis.length === 4) ? chassis : '';

        case '1970':
            if (chassis.length === 5) {
                base = chassis.slice(0, 4);
                suffix = chassis.slice(4, 5).toUpperCase();
            } else if (chassis.length === 11) {
                base = chassis.slice(0, 10);
                suffix = chassis.slice(10, 11).toUpperCase();
            }
            return ($.isNumeric(base) && suffixes.includes(suffix)) ? chassis : '';

        case '1971':
            if (chassis.length === 11) {
                base = chassis.slice(0, 10);
                suffix = chassis.slice(10, 11).toUpperCase();
            }
            return ($.isNumeric(base) && suffixes.includes(suffix)) ? chassis : '';

        case '1972': case '1973': case '1974':
            if (chassis.length === 9) {
                base = chassis.slice(0, 8);
                suffix = chassis.slice(8, 9).toUpperCase();
            }
            return ($.isNumeric(base) && suffixes.includes(suffix)) ? chassis : '';

        default:
            return '';
    }
}

/**
 * Update field validation visual indicators
 */
function updateFieldValidation(fieldSelector, isValid) {
    const field = $(fieldSelector);
    const icon = $(fieldSelector + '_icon');
    
    icon.toggleClass('fa-thumbs-up', Boolean(isValid))
        .toggleClass('fa-thumbs-down', !Boolean(isValid))
        .toggleClass('is-valid', Boolean(isValid))
        .toggleClass('is-invalid', !Boolean(isValid));
        
    field.toggleClass('is-valid', Boolean(isValid))
         .toggleClass('is-invalid', !Boolean(isValid));
}

/**
 * Reset dependent fields
 */
function resetDependentFields() {
    validModel = '';
    validChassis = '';
    
    updateFieldValidation('#model', false);
    updateFieldValidation('#chassis', false);
    
    $('#model').prop('disabled', false).val('');
    $('#chassis').val('');
}

/**
 * Show appropriate chassis help text
 */
function showChassisHelp(year) {
    const helpElements = ['#chassis_pre1970', '#chassis_1970', '#chassis_post1970', '#chassis_taken'];
    helpElements.forEach(el => $(el).hide());

    if (year < 1970) {
        $('#chassis_pre1970').show();
    } else if (year === '1970') {
        $('#chassis_1970').show();
    } else {
        $('#chassis_post1970').show();
    }
}

/**
 * Check chassis availability via AJAX
 */
function checkChassisAvailability() {
    const csrf = $('#csrf').val();
    
    $.ajax({
        url: 'actions/check-chassis.php',
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
                updateFieldValidation('#chassis', false);
                $('#chassis_taken').show();
            } else if (response === 'not_taken') {
                $('#chassis_taken').hide();
                enableAdditionalFields();
            }
        },
        error: function() {
            console.error('Failed to check chassis availability');
        }
    });
}

/**
 * Enable additional form fields
 */
function enableAdditionalFields() {
    const fields = ['#color', '#engine'];
    fields.forEach(field => $(field).prop('disabled', false));
}

/**
 * Pre-populate form for editing
 */
function prepopulateForEditing() {
    if ($('#action').val() !== 'updateCar') {
        return;
    }

    // Pre-populate year and trigger change
    const yearValue = window.carYear;
    if (yearValue) {
        $('#year option[value=' + yearValue + ']').prop('selected', true);
        $('#year').trigger('change');
    }

    // Pre-populate model with escaped special characters
    const modelValue = window.carModel;
    if (modelValue) {
        const escapedModel = modelValue
            .replace(/\\/g, "\\\\")
            .replace(/\|/g, "\\|")
            .replace(/ /g, "\\ ")
            .replace(/\//g, "\\/")
            .replace(/\+/g, "\\+");
            
        $('#model option[value=' + escapedModel + ']').prop('selected', true);
        $('#model').trigger('change');
        $('#chassis').trigger('blur');
    }

    // Enable all fields and update UI
    const fields = ['color', 'engine', 'purchasedate', 'solddate', 'website', 'comments'];
    fields.forEach(field => $('#' + field).prop('disabled', false));

    $('#submit').attr('value', 'Update Car');
    $('#carid').text($('#carid').val());
    $('#carHeader').html('<h2><strong>Update car</strong></h2>');
}

/**
 * Main initialization function
 */
function initializeEditCar() {
    $('#message').hide();
    
    initializeDropzone();
    initializeTabbedInterface();
    initializeDatePickers();
    initializeCarValidation();
    prepopulateForEditing();
}

// Initialize when document is ready
$(document).ready(function() {
    initializeEditCar();
});