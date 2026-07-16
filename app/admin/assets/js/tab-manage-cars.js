/* exported openCarDetails, switchToCarManagementTab */
(function() {
    'use strict';

    // Invalid-chassis filter: hide rows whose override is set
    (function() {
        const cb    = document.getElementById('hide_overridden_chassis');
        const tbody = document.getElementById('invalid-chassis-tbody');
        const empty = document.getElementById('invalid-chassis-no-results');
        if (!cb || !tbody || !empty) return;
        cb.addEventListener('change', function() {
            const hide = cb.checked;
            let visible = 0;
            tbody.querySelectorAll('tr[data-override]').forEach(function(tr) {
                const shouldHide = hide && tr.getAttribute('data-override') === '1';
                tr.classList.toggle('d-none', shouldHide);
                if (!shouldHide) visible++;
            });
            empty.classList.toggle('d-none', visible > 0);
        });
    })();

    // Auto-refresh data every 5 minutes for live monitoring
    setTimeout(function() {
        location.reload();
    }, 300000);

    // Function to open car details page for editing
    function openCarDetails(carId) {
        // Open car details page in new tab for editing
        window.open('../../app/owner/cars/details.php?car_id=' + carId, '_blank');
    }

    // Function to switch to car management tab with specific car pre-loaded
    function switchToCarManagementTab(carId) {
        // Switch to car management tab and pass car ID as parameter
        window.location.href = '?tab=car-mgmt&car_id=' + carId;
    }

    // Expose tab-specific functions globally (called from onclick attributes in PHP)
    window.openCarDetails = openCarDetails;
    window.switchToCarManagementTab = switchToCarManagementTab;

    // Smooth scrolling to report sections
    document.querySelectorAll('a[href^="#report-"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
                // Auto-expand the clicked report
                const collapseElement = target.querySelector('.collapse');
                if (collapseElement && !collapseElement.classList.contains('show')) {
                    new bootstrap.Collapse(collapseElement, {toggle: false}).show();
                }
            }
        });
    });

    $(document).ready(function() {
        // Add hover effect for collapsible headers
        $('.card-header[data-bs-toggle="collapse"]').hover(
            function() {
                $(this).css('background-color', '#495057');
            },
            function() {
                $(this).css('background-color', '');
            }
        );

        // Duplicate detection JavaScript functionality
        // Enhanced merge functionality
        $('.merge-form').each(function() {
            const $form = $(this);
            const $carCheckboxes = $form.find('.car-select');
            const $reasonRadios = $form.find('input[name="reason[]"]');
            const $mergeBtn = $form.find('.merge-btn');

            // Enable/disable merge button based on selections
            function updateMergeButton() {
                const selectedCars = $carCheckboxes.filter(':checked').length;
                const selectedReason = $reasonRadios.filter(':checked').length;

                const canMerge = selectedCars === 2 && selectedReason === 1;
                $mergeBtn.prop('disabled', !canMerge);

                if (selectedCars > 2) {
                    $mergeBtn.text('Select exactly 2 cars to merge');
                    $mergeBtn.removeClass('btn-danger').addClass('btn-warning');
                } else if (selectedCars === 2 && selectedReason === 1) {
                    $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                    $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
                } else {
                    $mergeBtn.html('<i class="fas fa-compress-arrows-alt"></i> Merge Selected');
                    $mergeBtn.removeClass('btn-warning').addClass('btn-danger');
                }
            }

            // Visual feedback for selected cars
            $carCheckboxes.on('change', function() {
                const $card = $(this).closest('.car-comparison-card');
                if ($(this).is(':checked')) {
                    $card.addClass('selected');
                } else {
                    $card.removeClass('selected');
                }
                updateMergeButton();
            });

            $reasonRadios.on('change', updateMergeButton);

            // Confirmation dialog for merge operations
            $form.on('submit', function(e) {
                const selectedCars = $carCheckboxes.filter(':checked');
                const selectedReason = $reasonRadios.filter(':checked');

                if (selectedCars.length !== 2 || selectedReason.length !== 1) {
                    e.preventDefault();
                    showNotification('Please select exactly 2 cars and 1 merge reason.', 'danger');
                    return false;
                }

                const car1Id = $(selectedCars[0]).val();
                const car2Id = $(selectedCars[1]).val();
                const reason = selectedReason.val();

                let reasonText = '';
                switch(reason) {
                    case 'duplicate':
                        reasonText = 'These are duplicate entries of the same car';
                        break;
                    case 'newownerNewToOld':
                        reasonText = 'Keep the newer record (current owner information)';
                        break;
                    case 'newownerOldToNew':
                        reasonText = 'Keep the older record (original registration)';
                        break;
                }

                e.preventDefault();

                showConfirmDialog(
                    'Confirm Car Merge',
                    `Are you sure you want to merge cars #${car1Id} and #${car2Id}?\n\nReason: ${reasonText}\n\nThis action cannot be undone. The history will be preserved, but one car record will be permanently deleted.`,
                    function() { $form[0].submit(); }
                );
            });

            // Initialize button state
            updateMergeButton();
        });

        // Collapsible group management
        $('.duplicate-group [data-bs-toggle="collapse"]').on('click', function() {
            const $icon = $(this).find('i');
            const isExpanded = $(this).attr('aria-expanded') === 'true';

            setTimeout(function() {
                if (isExpanded) {
                    $icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
                } else {
                    $icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
                }
            }, 100);
        });

        // Enhanced tooltips and popovers (if Bootstrap supports them)
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) { bootstrap.Tooltip.getOrCreateInstance(el); });
    });

})();
