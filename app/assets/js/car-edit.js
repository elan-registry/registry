/* global FilePond, FilePondPluginImageExifOrientation, FilePondPluginFileValidateType, FilePondPluginFileValidateSize, FilePondPluginImagePreview, FilePondPluginImageResize, FilePondPluginImageTransform, ModelLoader */
(function() {
    'use strict';

    var el = document.getElementById('website');
    if (el) {
        var validateWebsite = function() {
            el.classList.toggle('is-invalid', el.value !== '' && !el.validity.valid);
        };
        el.addEventListener('input', validateWebsite);
        el.addEventListener('blur', validateWebsite);
    }

    var cfg = window.editCarConfig;
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
            maxFiles: cfg.maxFiles,
            acceptedFileTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            maxFileSize: cfg.maxFileSize,
            instantUpload: false,
            allowProcess: false,
            imageResizeTargetWidth: cfg.imageResizeWidth,
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
            new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/save.php', {
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
                new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/save.php', {
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
            submitBtn.textContent = 'Saving…';

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
                const response = await fetch(cfg.urlRoot + 'app/api/cars/save.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData
                });
                const data = await response.json();

                if (data.success === true) {
                    window.location = cfg.urlRoot + 'app/owner/cars/details.php?car_id=' + data.cardetails.id;
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
                    Object.entries(data.errors).forEach(function([field, messages]) {
                        const msgs = Array.isArray(messages) ? messages : [messages];
                        msgs.forEach(function(msg) {
                            html += '<li><strong>' + NotificationHelper.escapeHtml(field) + ':</strong> '
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
        if (cfg.isUpdate) {
            var year = cfg.year;
            var modelValue = cfg.model;

            // Set year and trigger change to load models
            $('#year').val(year).trigger('change');

            // After models load, set the model value
            setTimeout(function() {
                $('#model').val(modelValue).trigger('change');
                $('#chassis').trigger('blur');
            }, 500);

            // Show all fields
            $('#color').prop('disabled', false);
            $('#engine').prop('disabled', false);
            $('#purchasedate').prop('disabled', false);
            $('#solddate').prop('disabled', false);
            $('#website').prop('disabled', false);
            $('#comments').prop('disabled', false);

            // Set the form text for Update
            $('#submit').text('Update Car').attr('data-label', 'Update Car');
            $('#car_id').html($('#car_id').val());
            $('#carHeader').html('<h2><strong>Update car</strong><h2>');
        }
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
    ModelLoader.init(cfg.urlRoot + 'app/api/cars/models.php');

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
        new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/chassis-validate.php', {
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
        new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/chassis-availability.php', {
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

        new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/transfer-request.php', {
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
        window.location.href = cfg.urlRoot + 'app/owner/cars/';
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
}());
