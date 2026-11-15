/* global FilePond, FilePondPluginImageExifOrientation, FilePondPluginFileValidateType, FilePondPluginFileValidateSize, FilePondPluginImagePreview, FilePondPluginImageResize, FilePondPluginImageTransform, ModelLoader */
(function() {
    'use strict';

    var cfg = window.editCarConfig;

    // Several async paths (photo hydration, model repopulation, save) can each
    // need the button off; it re-enables only when none of them still does.
    const submitBtn = document.getElementById('submit');
    const submitBlockers = new Set();
    function blockSubmit(reason) {
        submitBlockers.add(reason);
        submitBtn.disabled = true;
    }
    function unblockSubmit(reason) {
        submitBlockers.delete(reason);
        if (submitBlockers.size === 0) {
            submitBtn.disabled = false;
        }
    }

    // Field values when the page became ready; null until then. The
    // unsaved-changes guard compares against it.
    let savedSnapshot = null;
    function formSnapshot() {
        return JSON.stringify(
            ['#year', '#model', '#chassis', '#color', '#engine', '#purchasedate', '#solddate', '#comments']
                .map(function(selector) {
                    // Until the list loads, #model is empty; the saved model is
                    // the value the owner sees as unchanged.
                    return selector === '#model' && savedModelPending ? cfg.model : $(selector).val();
                })
        );
    }
    function takeSnapshot() {
        savedSnapshot = formSnapshot();
    }
    // Set just before a redirect that follows a completed request, so the
    // unsaved-changes guard does not ask about input the server already has.
    let leaveAllowed = false;

    // Edit mode only: true until the saved model is selected in the loaded list.
    let savedModelPending = Boolean(cfg.isUpdate);

    $(document).ready(function() {
        const car_id = $('#car_id').val();
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
            // The default 'before' puts each added file first, so the serial
            // load of stored photos reversed them and each save stored that order.
            itemInsertLocation: 'after',
            maxFiles: cfg.maxFiles,
            acceptedFileTypes: ['image/jpeg', 'image/png', 'image/gif'],
            maxFileSize: cfg.maxFileSize,
            minFileSize: cfg.minFileSize,
            // maxFileSize is the config MiB value in bytes; base 1024 makes the label show the
            // config number (20 MB), not the larger decimal value (21 MB).
            fileSizeBase: 1024,
            instantUpload: false,
            allowProcess: false,
            imageResizeTargetWidth: cfg.imageResizeWidth,
            // FilePond knows only force/cover/contain; any other mode skips the
            // resize. 'contain' bounds the longest side, and no upscale keeps small photos.
            imageResizeMode: 'contain',
            imageResizeUpscale: false,
            credits: false,
            labelIdle: 'Drop photos here or <span class="filepond--label-action">Browse</span>',
            labelFileTypeNotAllowed: 'Invalid file type. Only images are allowed.',
            fileValidateTypeLabelExpectedTypes: 'Expects {allTypes}',
            labelMaxFileSizeExceeded: 'Photo is too large',
            labelMaxFileSize: 'Maximum size is {filesize}',
            labelMinFileSizeExceeded: 'Photo is empty or damaged',
            labelMinFileSize: 'Minimum size is {filesize}',
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
        if (car_id) {
            new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/save.php', {
                carID: car_id,
                action: 'fetchImages'
            }).then(function(data) {
                if (data == null || data.success !== true) {
                    $('#message').removeClass('d-none').html(
                        '<div class="alert alert-warning">Existing photos could not be loaded. ' +
                        'Please refresh the page before making changes to avoid losing photo data.</div>'
                    );
                    blockSubmit('images');
                    return;
                }
                if (!Array.isArray(data.images)) {
                    console.error('[edit.php] fetchImages: unexpected response shape — data.images is not an array', data);
                    $('#message').removeClass('d-none').html(
                        '<div class="alert alert-warning">Existing photos could not be loaded. ' +
                        'Please refresh the page before making changes to avoid losing photo data.</div>'
                    );
                    blockSubmit('images');
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
                blockSubmit('images');
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
            if (car_id && fileItem.origin === FilePond.FileOrigin.LOCAL) {
                const filename = fileItem.getMetadata('serverFilename');
                new ElanRegistryAPI().post(cfg.urlRoot + 'app/api/cars/save.php', {
                    action: 'removeImages',
                    carID: car_id,
                    file: filename
                }).catch(function(err) {
                    console.error('[car-edit] removeImages call failed:', err);
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

        // Removal of a stored photo posts to save.php at once, so only new
        // (non-LOCAL) photos are unsaved changes.
        function isDirty() {
            const fieldsChanged = savedSnapshot !== null && formSnapshot() !== savedSnapshot;
            const hasNewPhotos = pond.getFiles().some(function(item) {
                return item.origin !== FilePond.FileOrigin.LOCAL;
            });
            return fieldsChanged || hasNewPhotos;
        }

        // The browser shows its own text; a custom message is ignored.
        function warnBeforeUnload(event) {
            if (!leaveAllowed && isDirty()) {
                event.preventDefault();
                event.returnValue = '';
            }
        }
        window.addEventListener('beforeunload', warnBeforeUnload);

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

            // FilePond 4 has no separate invalid status: a validation plugin
            // rejection sets LOAD_ERROR. A LOCAL item with LOAD_ERROR is a stored
            // photo that failed to load, which must not block the save.
            const invalidNames = pond.getFiles()
                .filter(function(item) {
                    return item.origin !== FilePond.FileOrigin.LOCAL
                        && item.status === FilePond.FileStatus.LOAD_ERROR;
                })
                .map(function(item) { return NotificationHelper.escapeHtml(item.filename); });

            if (invalidNames.length > 0) {
                $('#message').removeClass('d-none').html('<div class="alert alert-danger">Remove these photos, then save again: ' + invalidNames.join(', ') + '</div>');
                return;
            }

            blockSubmit('saving');
            submitBtn.textContent = 'Saving…';

            // Only process new files — LOCAL files (already on server) don't need
            // client-side transform before submit; processing them caused the slow-save regression.
            const newFileIds = pond.getFiles()
                .filter(function(item) { return item.origin !== FilePond.FileOrigin.LOCAL; })
                .map(function(item) { return item.id; });

            const handleProcessError = function(err) {
                console.error('[edit.php] Photo processing error:', err);
                $('#message').removeClass('d-none').html('<div class="alert alert-danger">An error occurred processing the photos. Please try again.</div>');
                unblockSubmit('saving');
                submitBtn.textContent = submitBtn.dataset.label;
            };

            const submission = newFileIds.length > 0
                ? pond.processFiles(newFileIds).then(submitCarForm)
                : submitCarForm();
            submission.catch(handleProcessError);
        });

        // The button says "Add Car" or "Update Car", not "Save".
        function loggedOutMessage() {
            return 'You are logged out. Log in again in a new tab, then select ' + submitBtn.dataset.label + ' again.';
        }
        function stalePageMessage() {
            return 'This page is out of date. Your changes are still on this page. Select ' + submitBtn.dataset.label +
                ' again. If this message shows again, copy your comments, then reload the page.';
        }

        // save.php also sends 403 when the owner may not change the car. Only a
        // token rejection can succeed on a retry.
        async function isTokenRejection(response) {
            if (response.status !== 403) {
                return false;
            }
            const body = await response.clone().json().catch(function() { return {}; });
            return body.message === 'Invalid CSRF token';
        }

        function buildCarFormData() {
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

            return formData;
        }

        // The form data is built again on each send, so a retry carries the new token.
        function postCarForm() {
            return fetch(cfg.urlRoot + 'app/api/cars/save.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: buildCarFormData()
            });
        }

        /**
         * Get the session's current token and write it to #csrf. Each new
         * ElanRegistryAPI reads #csrf, so the photo calls use it too.
         * @returns {Promise<void>}
         * @throws {ApiError} When the request fails; status 401 when the session has ended
         * @throws {Error} When the response has no token
         */
        async function refreshCsrfToken() {
            const data = await new ElanRegistryAPI().get(cfg.urlRoot + 'app/api/shared/csrf-token.php');
            if (!data.csrf) {
                throw new Error('Token response has no token');
            }
            $('#csrf').val(data.csrf);
        }

        // Fields and photos stay on the page, so the owner can save again.
        function showSaveProblem(message) {
            unblockSubmit('saving');
            submitBtn.textContent = submitBtn.dataset.label;
            $('#message').removeClass('d-none').html('<div class="alert alert-danger" role="alert">' + message + '</div>');
            document.getElementById('message').scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        async function submitCarForm() {
            try {
                let response = await postCarForm();

                // A token rejection means the session changed after the page
                // loaded, for example a new login in another tab. Send once more
                // with the current token; a second rejection is final.
                if (await isTokenRejection(response)) {
                    try {
                        await refreshCsrfToken();
                    } catch (err) {
                        console.error('[car-edit] Token refresh failed:', err);
                        showSaveProblem(err.status === 401 ? loggedOutMessage() : stalePageMessage());
                        return;
                    }
                    response = await postCarForm();
                    if (await isTokenRejection(response)) {
                        showSaveProblem(stalePageMessage());
                        return;
                    }
                }

                if (response.status === 401) {
                    showSaveProblem(loggedOutMessage());
                    return;
                }

                const data = await response.json();

                if (data.success === true) {
                    const carId = data.cardetails.id;
                    leaveAllowed = true;
                    window.location = cfg.urlRoot + 'app/owner/cars/details.php?car_id=' + carId;
                } else {
                    unblockSubmit('saving');
                    submitBtn.textContent = submitBtn.dataset.label;
                    displayValidationErrors(data);
                }
            } catch (err) {
                console.error('[car-edit] submitCarForm failed:', err);
                unblockSubmit('saving');
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
            // Await the model list instead of guessing its load time: a slow
            // models.php left #model empty and save.php rejected the form (#2295).
            // The 'models' blocker stays until applySavedModel() runs, so a
            // failed load cannot save an empty model.
            blockSubmit('models');
            $('#year').val(cfg.year);
            onYearChange();

            // Show all fields
            $('#color, #engine, #purchasedate, #solddate, #comments').prop('disabled', false);

            // Set the form text for Update
            $('#submit').text('Update Car').attr('data-label', 'Update Car');
            $('#car_id').text($('#car_id').val());
            $('#carHeader').html('<h2><strong>Update car</strong><h2>');
        }
        // Taken now, not after the model list loads: input typed while the
        // list loads must count as a change.
        takeSnapshot();
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
    $('#year').change(onYearChange);

    $('#model-load-retry').on('click', function() {
        onYearChange();
    });

    /**
     * Show the model-list error with its Retry button and block Save, because
     * a save without a model is rejected by save.php.
     */
    function showModelLoadError() {
        $('#model').prop('disabled', true);
        $('#model-load-error').removeClass('d-none');
        blockSubmit('models');
    }

    /**
     * Select the car's saved model in the loaded list (Edit mode) and unblock Save
     */
    function applySavedModel() {
        const year = cfg.year;
        const modelValue = cfg.model;
        // The code below can clear an invalid saved model. That is not an owner
        // change, so a form that was clean stays clean.
        const wasClean = formSnapshot() === savedSnapshot;
        savedModelPending = false;

        $('#model').val(modelValue).trigger('change');
        if ($('#model').val() !== modelValue) {
            console.warn('[edit.php] Saved model "' + modelValue + '" is not valid for year ' + year);
            // .val(modelValue) with no matching option leaves selectedIndex
            // -1, so .val() reads back null and a later save would send the
            // string "null" instead of an empty selection. Clear it explicitly
            // so the owner sees an unselected dropdown, not a stuck one.
            $('#model').val('').trigger('change');
            $('#message').removeClass('d-none').html(
                '<div class="alert alert-warning">The saved model is not valid for this year. ' +
                'Please select a model.</div>'
            );
        }
        $('#chassis').trigger('blur');

        if (wasClean) {
            takeSnapshot();
        }
        unblockSubmit('models');
    }

    async function onYearChange() {
        $('#model-load-error').addClass('d-none');
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
            try {
                await ModelLoader.populateModelDropdown(validYear, $('#model'));
            } catch (err) {
                console.error('[car-edit] Model list could not be loaded:', err);
                showModelLoadError();
                return;
            }

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

            if (savedModelPending) {
                applySavedModel();
            } else {
                unblockSubmit('models');
            }
        } else if (!savedModelPending) {
            unblockSubmit('models');
        }
    }
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
        }).catch(function(err) {
            console.error('[car-edit] chassis validation failed:', err);
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
        }).catch(function(err) {
            console.error('[car-edit] Transfer request failed:', err);
            $('#transferErrorMessage').text('There was an error processing your request. Please try again.');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('transferErrorModal')).show();
            $('#request_transfer_btn').prop('disabled', false).html('<i class="fas fa-exchange-alt"></i> Request Ownership Transfer');
        });
    });

    // Handle success modal OK button
    $('#transferSuccessOkBtn').click(function() {
        leaveAllowed = true;
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
