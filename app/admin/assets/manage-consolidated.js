/**
 * manage-consolidated.js
 * Consolidated Management Interface JavaScript
 *
 * Provides enhanced interactivity for the unified administrative interface
 * including tab navigation, form validation, and user experience improvements
 */

$(document).ready(function() {
    'use strict';

    // ==========================================================================
    // Tab Management and Navigation
    // ==========================================================================

    /**
     * Initialize tab navigation and URL handling
     */
    function initializeTabNavigation() {
        // Handle tab clicks for proper URL updating
        $('.nav-tabs a[href^="?tab="]').on('click', function(e) {
            // Let the browser handle the navigation naturally
            // This ensures proper URL updates and back button support
        });

        // Handle browser back/forward buttons
        window.addEventListener('popstate', function(e) {
            // Reload the page to show the correct tab
            // This maintains consistency with server-side routing
            window.location.reload();
        });

        // Add visual feedback for active tab
        updateActiveTabState();
    }

    /**
     * Update visual state of active tab
     */
    function updateActiveTabState() {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab') || 'car-mgmt';

        // Update tab appearance
        $('.nav-tabs .nav-link').removeClass('active');
        $('.nav-tabs .nav-link[href*="tab=' + activeTab + '"]').addClass('active');
    }

    // ==========================================================================
    // Enhanced Loading States
    // ==========================================================================

    /**
     * Show loading state for buttons and links
     */
    function showLoadingState($element, originalText) {
        const loadingHtml = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        $element.data('original-text', originalText || $element.html());
        $element.prop('disabled', true);
        $element.html(loadingHtml);
    }

    /**
     * Restore button from loading state
     */
    function hideLoadingState($element) {
        const originalText = $element.data('original-text');
        if (originalText) {
            $element.html(originalText);
            $element.prop('disabled', false);
            $element.removeData('original-text');
        }
    }

    // Add loading states to external links
    $('a[href*="manage.php"], a[href*="data-quality.php"], a[href*="/FIX/"]').on('click', function() {
        const $link = $(this);
        if (!$link.hasClass('btn-sm')) {
            showLoadingState($link);
        }
    });

    // ==========================================================================
    // Form Enhancements
    // ==========================================================================

    /**
     * Enhanced form validation
     */
    function initializeFormValidation() {
        // Add Bootstrap validation classes
        $('.needs-validation').on('submit', function(e) {
            const form = this;
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();

                // Focus on first invalid field
                $(form).find(':invalid').first().focus();
            }
            $(form).addClass('was-validated');
        });

        // Real-time validation feedback
        $('.form-control').on('blur input', function() {
            const $field = $(this);
            const $form = $field.closest('form');

            if ($form.hasClass('was-validated')) {
                if (this.checkValidity()) {
                    $field.removeClass('is-invalid').addClass('is-valid');
                } else {
                    $field.removeClass('is-valid').addClass('is-invalid');
                }
            }
        });
    }

    /**
     * Auto-resize textareas
     */
    function initializeAutoResize() {
        $('textarea').on('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
    }

    // ==========================================================================
    // Mobile Navigation Enhancement
    // ==========================================================================

    /**
     * Improve tab navigation on mobile devices
     */
    function initializeMobileNavigation() {
        const $tabContainer = $('.nav-tabs');

        // Add swipe support for mobile tab navigation
        if ('ontouchstart' in window) {
            let startX = 0;
            let endX = 0;

            $tabContainer.on('touchstart', function(e) {
                startX = e.originalEvent.touches[0].clientX;
            });

            $tabContainer.on('touchend', function(e) {
                endX = e.originalEvent.changedTouches[0].clientX;
                handleSwipe();
            });

            function handleSwipe() {
                const swipeThreshold = 50;
                const diff = startX - endX;

                if (Math.abs(diff) > swipeThreshold) {
                    const $activeTab = $('.nav-tabs .nav-link.active');
                    const $tabs = $('.nav-tabs .nav-link');
                    const currentIndex = $tabs.index($activeTab);

                    if (diff > 0 && currentIndex < $tabs.length - 1) {
                        // Swipe left - next tab
                        $tabs.eq(currentIndex + 1)[0].click();
                    } else if (diff < 0 && currentIndex > 0) {
                        // Swipe right - previous tab
                        $tabs.eq(currentIndex - 1)[0].click();
                    }
                }
            }
        }

        // Scroll active tab into view on mobile
        function scrollTabIntoView() {
            const $activeTab = $('.nav-tabs .nav-link.active');
            if ($activeTab.length && $(window).width() <= 768) {
                const tabContainer = $tabContainer[0];
                const activeTab = $activeTab[0];

                if (tabContainer.scrollLeft !== undefined) {
                    const tabLeft = activeTab.offsetLeft;
                    const tabWidth = activeTab.offsetWidth;
                    const containerWidth = tabContainer.offsetWidth;
                    const scrollLeft = tabContainer.scrollLeft;

                    if (tabLeft < scrollLeft) {
                        tabContainer.scrollLeft = tabLeft - 20;
                    } else if (tabLeft + tabWidth > scrollLeft + containerWidth) {
                        tabContainer.scrollLeft = tabLeft + tabWidth - containerWidth + 20;
                    }
                }
            }
        }

        // Call on load and resize
        scrollTabIntoView();
        $(window).on('resize', scrollTabIntoView);
    }

    // ==========================================================================
    // Accessibility Enhancements
    // ==========================================================================

    /**
     * Improve keyboard navigation
     */
    function initializeKeyboardNavigation() {
        // Tab navigation with arrow keys
        $('.nav-tabs .nav-link').on('keydown', function(e) {
            const $tabs = $('.nav-tabs .nav-link');
            const currentIndex = $tabs.index(this);

            switch(e.which) {
                case 37: // Left arrow
                    e.preventDefault();
                    if (currentIndex > 0) {
                        $tabs.eq(currentIndex - 1).focus().click();
                    }
                    break;
                case 39: // Right arrow
                    e.preventDefault();
                    if (currentIndex < $tabs.length - 1) {
                        $tabs.eq(currentIndex + 1).focus().click();
                    }
                    break;
                case 36: // Home
                    e.preventDefault();
                    $tabs.first().focus().click();
                    break;
                case 35: // End
                    e.preventDefault();
                    $tabs.last().focus().click();
                    break;
            }
        });

        // Add ARIA labels for better screen reader support
        $('.nav-tabs .nav-link').each(function(index) {
            $(this).attr('aria-posinset', index + 1);
            $(this).attr('aria-setsize', $('.nav-tabs .nav-link').length);
        });
    }

    /**
     * Announce tab changes to screen readers
     */
    function announceTabChange(tabName) {
        const announcement = `Now viewing ${tabName} tab`;

        // Create or update aria-live region
        let $announcement = $('#tab-announcement');
        if ($announcement.length === 0) {
            $announcement = $('<div id="tab-announcement" aria-live="polite" class="sr-only"></div>');
            $('body').append($announcement);
        }

        $announcement.text(announcement);
    }

    // ==========================================================================
    // Performance Optimizations
    // ==========================================================================

    /**
     * Lazy load content for better performance
     */
    function initializeLazyLoading() {
        // Defer loading of non-critical content
        const $heavyContent = $('.card-body .fa-cog.fa-spin').closest('.card');

        $heavyContent.each(function() {
            const $card = $(this);
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        // Content is visible, mark for potential future loading
                        $card.addClass('visible');
                        observer.unobserve(entry.target);
                    }
                });
            });

            observer.observe(this);
        });
    }

    /**
     * Debounce function for performance
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // ==========================================================================
    // Error Handling and User Feedback
    // ==========================================================================

    /**
     * Enhanced error handling for AJAX requests
     */
    function initializeErrorHandling() {
        // Global AJAX error handler
        $(document).ajaxError(function(event, xhr, settings, thrownError) {

            // Show user-friendly error message
            showNotification('An error occurred. Please try again.', 'error');
        });

        // Handle offline status
        window.addEventListener('online', function() {
            showNotification('Connection restored', 'success');
        });

        window.addEventListener('offline', function() {
            showNotification('Connection lost. Some features may not work.', 'warning');
        });
    }

    /**
     * Show notifications to user
     */
    function showNotification(message, type = 'info') {
        const alertClass = `alert-${type === 'error' ? 'danger' : type}`;
        const iconClass = type === 'error' ? 'fa-exclamation-triangle' :
                         type === 'success' ? 'fa-check-circle' :
                         type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';

        const $notification = $(`
            <div class="alert ${alertClass} alert-dismissible fade show position-fixed"
                 style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                <i class="fas ${iconClass}"></i> ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `);

        $('body').append($notification);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notification.alert('close');
        }, 5000);
    }

    // ==========================================================================
    // Development Mode Features
    // ==========================================================================

    /**
     * Add development mode indicators and features
     */
    function initializeDevelopmentMode() {
        // Check if we're in development mode (Phase 1A)
        if ($('.alert:contains("Phase 1A")').length > 0) {
            // Add development indicator
            $('body').addClass('development-mode');

            // Log current tab for debugging
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = urlParams.get('tab') || 'car-mgmt';

            // Add keyboard shortcut for quick tab switching (Ctrl/Cmd + number)
            $(document).on('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.which >= 49 && e.which <= 55) {
                    e.preventDefault();
                    const tabIndex = e.which - 49;
                    const $tabs = $('.nav-tabs .nav-link');
                    if ($tabs.eq(tabIndex).length) {
                        $tabs.eq(tabIndex)[0].click();
                    }
                }
            });
        }
    }

    // ==========================================================================
    // Initialization
    // ==========================================================================

    /**
     * Initialize all features
     */
    function initialize() {
        try {
            initializeTabNavigation();
            initializeFormValidation();
            initializeAutoResize();
            initializeMobileNavigation();
            initializeKeyboardNavigation();
            initializeLazyLoading();
            initializeErrorHandling();
            initializeDevelopmentMode();
            initializeCarManagement();

            // Mark initialization complete
            $('body').addClass('consolidated-interface-ready');
        } catch (error) {
        }
    }

    // Start initialization
    initialize();

    // ==========================================================================
    // Public API (for future use)
    // ==========================================================================

    // Expose utilities for other scripts
    window.ConsolidatedInterface = {
        showNotification: showNotification,
        showLoadingState: showLoadingState,
        hideLoadingState: hideLoadingState,
        announceTabChange: announceTabChange,
        debounce: debounce
    };
});

// ==========================================================================
// Utility Functions (Available globally)
// ==========================================================================

/**
 * Format numbers with thousands separators
 */
function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

/**
 * Format dates consistently
 */
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Check if user prefers reduced motion
 */
function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Initialize Car Management functionality for the car-mgmt tab
 */
function initializeCarManagement() {
    // Initialize car management functionality for all tabs that might need it

    // Car management state
    let selectedCar = null;
    let selectedUser = null;
    let selectedDeleteCar = null;

    // Car lookup functionality
    $('#lookupCarBtn').on('click', function() {
        const carId = $('#reassign_car_id').val();
        if (!carId) {
            showMessage('Please enter a Car ID first', 'warning');
            return;
        }

        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                command: 'getCarDetails',
                car_id: carId,
                csrf: $('.reassign-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);

                if (!response.success) {
                    showMessage('Error: ' + response.error, 'danger');
                    $('#carDetails').hide();
                    selectedCar = null;
                    updateReassignButton();
                    return;
                }

                selectedCar = response.car;
                const car = response.car;
                const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';

                $('#carInfo').html(
                    `<strong>${car.year || 'Unknown'} ${car.type || 'Unknown'}</strong><br>` +
                    `Chassis: ${car.chassis || 'Unknown'} | Color: ${car.color || 'Unknown'}`
                );
                $('#currentOwner').text(`${ownerName} (${car.email || 'No email'})`);
                $('#carDetails').show();
                updateReassignButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                showMessage('Error fetching car details: ' + error, 'danger');
                $('#carDetails').hide();
                selectedCar = null;
                updateReassignButton();
            }
        });
    });

    // User lookup functionality
    $('#lookupUserBtn').on('click', function() {
        const userId = $('#reassign_user_id').val();
        if (!userId) {
            showMessage('Please enter a User ID first', 'warning');
            return;
        }

        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: {
                command: 'getUserDetails',
                user_id: userId,
                csrf: $('.reassign-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);

                if (!response.success) {
                    showMessage('Error: ' + response.error, 'danger');
                    $('#userDetails').hide();
                    selectedUser = null;
                    updateReassignButton();
                    return;
                }

                selectedUser = response.user;
                const user = response.user;
                const userName = user.fname && user.lname ? `${user.fname} ${user.lname}` : 'Unknown Name';
                const location = user.city && user.state ? `${user.city}, ${user.state} ${user.country}` : 'Unknown Location';
                const joinDate = new Date(user.join_date).toLocaleDateString();

                $('#userInfo').html(
                    `<strong>${userName}</strong><br>` +
                    `Email: ${user.email}<br>` +
                    `Location: ${location}<br>` +
                    `Member since: ${joinDate}`
                );
                $('#userDetails').show();
                updateReassignButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                showMessage('Error fetching user details: ' + error, 'danger');
                $('#userDetails').hide();
                selectedUser = null;
                updateReassignButton();
            }
        });
    });

    // Delete car lookup functionality
    $('#lookupDeleteCarBtn').on('click', function() {
        const carId = $('#delete_car_id').val();
        if (!carId) {
            showMessage('Please enter a Car ID first', 'warning');
            return;
        }

        const $btn = $(this);
        const originalHtml = $btn.html();
        $btn.prop('disabled', true);
        $btn.html('<i class="fas fa-spinner fa-spin"></i>');

        $.ajax({
            url: window.location.pathname + window.location.search,
            type: 'POST',
            data: {
                command: 'getCarDetails',
                car_id: carId,
                csrf: $('.delete-form input[name="csrf"]').val()
            },
            dataType: 'json',
            success: function(response) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);

                if (!response.success) {
                    showMessage('Error: ' + response.error, 'danger');
                    $('#deleteCarDetails').hide();
                    selectedDeleteCar = null;
                    updateDeleteButton();
                    return;
                }

                selectedDeleteCar = response.car;
                const car = response.car;
                const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';

                $('#deleteCarInfo').html(
                    `<strong>${car.year || 'Unknown'} ${car.type || 'Unknown'}</strong><br>` +
                    `Chassis: ${car.chassis || 'Unknown'} | Color: ${car.color || 'Unknown'} | Series: ${car.series || 'Unknown'}`
                );
                $('#deleteCurrentOwner').text(`${ownerName} (${car.email || 'No email'})`);
                $('#deleteCarDetails').show();
                updateDeleteButton();
            },
            error: function(xhr, status, error) {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
                showMessage('Error fetching car details: ' + error, 'danger');
                $('#deleteCarDetails').hide();
                selectedDeleteCar = null;
                updateDeleteButton();
            }
        });
    });

    // No Owner checkbox functionality
    $('#noOwnerCheckbox').on('change', function() {
        const isChecked = $(this).is(':checked');
        const $userIdField = $('#reassign_user_id');
        const $lookupBtn = $('#lookupUserBtn');

        if (isChecked) {
            // Set No Owner data
            selectedUser = {
                id: 83,
                fname: 'No',
                lname: 'Owner',
                email: 'noowner@example.com',
                city: null,
                state: null,
                country: null,
                join_date: '2023-01-01'
            };

            // Update UI
            $userIdField.val('83').prop('disabled', true);
            $lookupBtn.prop('disabled', true);
            $('#userDetails').hide();
            $('#noOwnerDetails').show();

        } else {
            // Clear No Owner data
            selectedUser = null;
            $userIdField.val('').prop('disabled', false).focus();
            $lookupBtn.prop('disabled', false);
            $('#userDetails').hide();
            $('#noOwnerDetails').hide();
        }

        updateReassignButton();
    });

    // Input change handlers
    $('#reassign_car_id').on('input', function() {
        const value = $(this).val();

        // Clear previous car details when typing
        if (!value || (selectedCar && value != selectedCar.id)) {
            $('#carDetails').hide();
            selectedCar = null;
        }
        updateReassignButton();
    }).on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupCarBtn').click();
        }
    });

    $('#reassign_user_id').on('input', function() {
        const value = $(this).val();

        // If field is cleared or different from 83, uncheck "No Owner"
        if (!value || value !== '83') {
            $('#noOwnerCheckbox').prop('checked', false);
            $('#noOwnerDetails').hide();
            selectedUser = null;
        }

        // Clear previous user details when typing
        $('#userDetails').hide();
        updateReassignButton();
    }).on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupUserBtn').click();
        }
    });

    $('#delete_car_id').on('input', function() {
        const value = $(this).val();

        // Clear previous car details when typing
        if (!value || (selectedDeleteCar && value != selectedDeleteCar.id)) {
            $('#deleteCarDetails').hide();
            selectedDeleteCar = null;
        }
        updateDeleteButton();
    }).on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#lookupDeleteCarBtn').click();
        }
    });

    $('#delete_confirmation').on('input', updateDeleteButton);

    // Form submission handlers
    $('.reassign-form').on('submit', function(e) {
        e.preventDefault();

        if (!selectedCar || !selectedUser) {
            showMessage('Please lookup both car and user details before reassigning.', 'warning');
            return false;
        }

        const carName = `${selectedCar.year || 'Unknown'} ${selectedCar.type || 'Unknown'} (${selectedCar.chassis || 'Unknown'})`;
        const currentOwner = selectedCar.fname && selectedCar.lname ? `${selectedCar.fname} ${selectedCar.lname}` : 'Unknown Owner';
        const newOwner = selectedUser.fname && selectedUser.lname ? `${selectedUser.fname} ${selectedUser.lname}` : 'Unknown Name';
        const isNoOwner = selectedUser.id === 83;

        // Populate modal with car details
        $('#modal-car-details').html(
            `<strong>${carName}</strong><br>` +
            `<small class="text-muted">Current Owner: ${currentOwner}</small><br>` +
            `<small class="text-muted">Email: ${selectedCar.email || 'No email'}</small>`
        );

        // Populate modal with user details
        if (isNoOwner) {
            $('#modal-user-details').html(
                `<strong class="text-warning">No Owner</strong><br>` +
                `<small class="text-muted">Registry placeholder account</small><br>` +
                `<small class="text-muted">For cars without current owner information</small>`
            );
        } else {
            const userLocation = selectedUser.city && selectedUser.state ?
                `${selectedUser.city}, ${selectedUser.state} ${selectedUser.country}` : 'Unknown Location';
            $('#modal-user-details').html(
                `<strong>${newOwner}</strong><br>` +
                `<small class="text-muted">Email: ${selectedUser.email}</small><br>` +
                `<small class="text-muted">Location: ${userLocation}</small>`
            );
        }

        // Store the form reference for later submission
        reassignFormToSubmit = this;

        // Show the modal
        $('#reassignConfirmModal').modal('show');

        return false;
    });

    $('.delete-form').on('submit', function(e) {
        e.preventDefault();

        const carId = $('#delete_car_id').val();
        const confirmation = $('#delete_confirmation').val();

        if (!selectedDeleteCar || !carId || confirmation !== 'DELETE') {
            showMessage('Please lookup the car details first and type DELETE to confirm.', 'warning');
            return false;
        }

        if (selectedDeleteCar.id != carId) {
            showMessage('Please lookup the current car ID before proceeding.', 'warning');
            return false;
        }

        const car = selectedDeleteCar;
        const ownerName = car.fname && car.lname ? `${car.fname} ${car.lname}` : 'Unknown Owner';
        const location = car.city && car.state ? `${car.city}, ${car.state} ${car.country}` : 'Unknown Location';
        const createdDate = new Date(car.ctime).toLocaleDateString();
        const modifiedDate = new Date(car.mtime).toLocaleDateString();

        // Populate modal with car details
        $('#modal-delete-car-details').html(
            `<div class="row">` +
                `<div class="col-md-6">` +
                    `<h6 class="text-danger">Car Information</h6>` +
                    `<p><strong>ID:</strong> ${car.id}</p>` +
                    `<p><strong>Year:</strong> ${car.year || 'Unknown'}</p>` +
                    `<p><strong>Type:</strong> ${car.type || 'Unknown'}</p>` +
                    `<p><strong>Chassis:</strong> ${car.chassis || 'Unknown'}</p>` +
                    `<p><strong>Color:</strong> ${car.color || 'Unknown'}</p>` +
                    `<p><strong>Series:</strong> ${car.series || 'Unknown'}</p>` +
                `</div>` +
                `<div class="col-md-6">` +
                    `<h6 class="text-danger">Owner Information</h6>` +
                    `<p><strong>Owner:</strong> ${ownerName}</p>` +
                    `<p><strong>Email:</strong> ${car.email || 'Unknown'}</p>` +
                    `<p><strong>Location:</strong> ${location}</p>` +
                    `<p><strong>Created:</strong> ${createdDate}</p>` +
                    `<p><strong>Modified:</strong> ${modifiedDate}</p>` +
                `</div>` +
            `</div>`
        );

        // Store the form reference for later submission
        deleteFormToSubmit = this;

        // Clear confirmation field and disable button
        $('#modal-delete-confirmation').val('');
        $('#confirmDeleteBtn').prop('disabled', true);

        // Show the modal
        $('#deleteConfirmModal').modal('show');

        return false;
    });

    // Transfer request action handlers
    let transferFormToSubmit = null;

    $('.transfer-action-form').on('submit', function(e) {
        e.preventDefault();

        const command = $(this).find('input[name="command"]').val();
        const transferId = $(this).find('input[name="transfer_id"]').val();

        // Store form for submission
        transferFormToSubmit = this;

        // Set up modal content based on action
        if (command === 'approve_transfer') {
            $('#transferActionModalHeader').removeClass('bg-danger').addClass('bg-success text-white');
            $('#transferActionTitle').text('Approve Transfer Request');
            $('#transferActionMessage').html(
                `<div class="alert alert-success">` +
                    `<i class="fas fa-check-circle"></i> <strong>Approve Transfer Request #${transferId}</strong>` +
                `</div>`
            );
            $('#transferActionDetails').html(
                `<p>This action will:</p>` +
                `<ul>` +
                    `<li><i class="fas fa-check text-success"></i> Transfer car ownership to the requesting user</li>` +
                    `<li><i class="fas fa-check text-success"></i> Send approval notification emails</li>` +
                    `<li><i class="fas fa-check text-success"></i> Update car ownership records</li>` +
                    `<li><i class="fas fa-check text-success"></i> Log the transfer in car history</li>` +
                `</ul>`
            );
            $('#confirmTransferActionBtn').removeClass('btn-danger').addClass('btn-success');
            $('#confirmTransferActionText').text('Approve Transfer');

        } else if (command === 'deny_transfer') {
            $('#transferActionModalHeader').removeClass('bg-success').addClass('bg-danger text-white');
            $('#transferActionTitle').text('Deny Transfer Request');
            $('#transferActionMessage').html(
                `<div class="alert alert-danger">` +
                    `<i class="fas fa-times-circle"></i> <strong>Deny Transfer Request #${transferId}</strong>` +
                `</div>`
            );
            $('#transferActionDetails').html(
                `<p>This action will:</p>` +
                `<ul>` +
                    `<li><i class="fas fa-times text-danger"></i> Reject the transfer request</li>` +
                    `<li><i class="fas fa-times text-danger"></i> Send denial notification emails</li>` +
                    `<li><i class="fas fa-times text-danger"></i> Keep current car ownership unchanged</li>` +
                    `<li><i class="fas fa-times text-danger"></i> Log the denial for record keeping</li>` +
                `</ul>`
            );
            $('#confirmTransferActionBtn').removeClass('btn-success').addClass('btn-danger');
            $('#confirmTransferActionText').text('Deny Transfer');
        }

        // Show the modal
        $('#transferActionModal').modal('show');

        return false;
    });

    // Helper functions
    function updateReassignButton() {
        const $btn = $('#reassignBtn');
        const canReassign = selectedCar && selectedUser;
        $btn.prop('disabled', !canReassign);

        if (canReassign) {
            $btn.removeClass('btn-secondary').addClass('btn-warning');
        } else {
            $btn.removeClass('btn-warning').addClass('btn-secondary');
        }
    }

    function updateDeleteButton() {
        const carId = $('#delete_car_id').val();
        const confirmation = $('#delete_confirmation').val();
        const $deleteBtn = $('#deleteBtn');

        // Enable button only when car is looked up, confirmation matches, and IDs match
        const carLookedUp = selectedDeleteCar && selectedDeleteCar.id == carId;
        const confirmationValid = confirmation === 'DELETE';
        const canDelete = carLookedUp && confirmationValid;

        $deleteBtn.prop('disabled', !canDelete);

        if (canDelete) {
            $deleteBtn.removeClass('btn-secondary').addClass('btn-danger');
        } else {
            $deleteBtn.removeClass('btn-danger').addClass('btn-secondary');
        }
    }

    function showMessage(message, type = 'info') {
        const $messageContainer = $('#messageContainer');
        if (!$messageContainer.length) return;

        const alertClass = `alert alert-${type} alert-dismissible fade show`;
        const alertHtml = `
            <div class="${alertClass}" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;

        $messageContainer.html(alertHtml);

        // Auto-dismiss after 5 seconds for non-error messages
        if (type !== 'danger') {
            setTimeout(() => {
                const $alert = $messageContainer.find('.alert');
                if ($alert.length) {
                    $alert.removeClass('show');
                    setTimeout(() => {
                        $messageContainer.html('');
                    }, 150);
                }
            }, 5000);
        }
    }

    // Initialize form states
    selectedCar = null;
    selectedUser = null;
    selectedDeleteCar = null;
    updateReassignButton();
    updateDeleteButton();

    // Hide all detail boxes initially
    $('#carDetails, #userDetails, #noOwnerDetails, #deleteCarDetails').hide();

    // Modal handlers
    let reassignFormToSubmit = null;
    let deleteFormToSubmit = null;

    // Reassignment modal confirm button
    $('#confirmReassignBtn').on('click', function() {
        if (reassignFormToSubmit) {
            // Show loading state
            const $btn = $('#reassignBtn');
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Reassigning...');

            // Hide modal and submit form
            $('#reassignConfirmModal').modal('hide');
            reassignFormToSubmit.submit();
        }
    });

    // Delete modal confirmation input handler
    $('#modal-delete-confirmation').on('input', function() {
        const $btn = $('#confirmDeleteBtn');
        const confirmationText = $(this).val();

        if (confirmationText === 'DELETE PERMANENTLY') {
            $btn.prop('disabled', false);
        } else {
            $btn.prop('disabled', true);
        }
    });

    // Delete modal confirm button
    $('#confirmDeleteBtn').on('click', function() {
        if (deleteFormToSubmit) {
            // Show loading state
            const $btn = $('#deleteBtn');
            $btn.prop('disabled', true);
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Deleting...');

            // Hide modal and submit form
            $('#deleteConfirmModal').modal('hide');
            deleteFormToSubmit.submit();
        }
    });

    // Clear modal data when closed
    $('#reassignConfirmModal').on('hidden.bs.modal', function() {
        reassignFormToSubmit = null;
    });

    $('#deleteConfirmModal').on('hidden.bs.modal', function() {
        deleteFormToSubmit = null;
        $('#modal-delete-confirmation').val('');
        $('#confirmDeleteBtn').prop('disabled', true);
    });

    // Transfer decision modal handlers
    let transferDecisionData = null;

    // Handle transfer approve button click
    $(document).on('click', '.transfer-approve-btn', function() {
        transferDecisionData = {
            action: 'approve',
            transferId: $(this).data('transfer-id'),
            carYear: $(this).data('car-year'),
            carType: $(this).data('car-type'),
            carSeries: $(this).data('car-series'),
            carChassis: $(this).data('car-chassis'),
            carColor: $(this).data('car-color'),
            currentOwner: $(this).data('current-owner'),
            currentEmail: $(this).data('current-email'),
            requesterName: $(this).data('requester-name'),
            requesterEmail: $(this).data('requester-email'),
            requestDate: $(this).data('request-date'),
            expiresDate: $(this).data('expires-date'),
            comments: $(this).data('comments')
        };
        showTransferDecisionModal(true);
    });

    // Handle transfer deny button click
    $(document).on('click', '.transfer-deny-btn', function() {
        transferDecisionData = {
            action: 'deny',
            transferId: $(this).data('transfer-id'),
            carYear: $(this).data('car-year'),
            carType: $(this).data('car-type'),
            carSeries: $(this).data('car-series'),
            carChassis: $(this).data('car-chassis'),
            carColor: $(this).data('car-color'),
            currentOwner: $(this).data('current-owner'),
            currentEmail: $(this).data('current-email'),
            requesterName: $(this).data('requester-name'),
            requesterEmail: $(this).data('requester-email'),
            requestDate: $(this).data('request-date'),
            expiresDate: $(this).data('expires-date'),
            comments: $(this).data('comments')
        };
        showTransferDecisionModal(false);
    });

    // Function to show transfer decision modal
    function showTransferDecisionModal(isApprove) {
        const data = transferDecisionData;

        // Update modal header and colors based on action
        if (isApprove) {
            $('#transferDecisionModalHeader').removeClass('bg-danger').addClass('bg-success');
            $('#transferDecisionTitle').text('Approve Transfer Request');
            $('#transferDecisionMessage').removeClass('alert-danger').addClass('alert-success');
            $('#transferDecisionMessageText').text('You are about to APPROVE this transfer request.');
            $('#confirmTransferDecisionBtn').removeClass('btn-danger').addClass('btn-success');
            $('#confirmTransferDecisionText').text('Approve Transfer');
        } else {
            $('#transferDecisionModalHeader').removeClass('bg-success').addClass('bg-danger');
            $('#transferDecisionTitle').text('Deny Transfer Request');
            $('#transferDecisionMessage').removeClass('alert-success').addClass('alert-danger');
            $('#transferDecisionMessageText').text('You are about to DENY this transfer request.');
            $('#confirmTransferDecisionBtn').removeClass('btn-success').addClass('btn-danger');
            $('#confirmTransferDecisionText').text('Deny Transfer');
        }

        // Populate car details
        const carDetails = `
            <strong>${data.carYear} ${data.carType}</strong>
            ${data.carSeries ? `<span class="badge badge-secondary badge-sm ml-1">${data.carSeries}</span>` : ''}
            <br><small class="text-muted">
                <i class="fas fa-barcode"></i> Chassis: ${data.carChassis}
                ${data.carColor ? ` • Color: ${data.carColor}` : ''}
            </small>
        `;
        $('#modal-transfer-car-details').html(carDetails);

        // Populate current owner details
        const currentOwnerDetails = `
            <strong>${data.currentOwner}</strong><br>
            <small class="text-muted"><i class="fas fa-envelope"></i> ${data.currentEmail}</small>
        `;
        $('#modal-current-owner-details').html(currentOwnerDetails);

        // Populate requester details
        const requesterDetails = `
            <strong>${data.requesterName}</strong><br>
            <small class="text-muted"><i class="fas fa-envelope"></i> ${data.requesterEmail}</small>
        `;
        $('#modal-requester-details').html(requesterDetails);

        // Populate request information
        const requestDetails = `
            <strong>Request Date:</strong> ${new Date(data.requestDate).toLocaleDateString()}<br>
            <strong>Expires:</strong> ${new Date(data.expiresDate).toLocaleDateString()}<br>
            ${data.comments ? `<strong>Comments:</strong><br><em>"${data.comments}"</em>` : '<em>No comments provided</em>'}
        `;
        $('#modal-transfer-request-details').html(requestDetails);

        // Update consequences based on action
        const effects = isApprove ? `
            <li><i class="fas fa-check text-success"></i> Transfer car ownership to requester</li>
            <li><i class="fas fa-check text-success"></i> Send confirmation emails to both parties</li>
            <li><i class="fas fa-check text-success"></i> Log the transfer in car history</li>
            <li><i class="fas fa-check text-success"></i> Mark request as completed</li>
            <li><i class="fas fa-exclamation-triangle text-warning"></i> This action cannot be undone easily</li>
        ` : `
            <li><i class="fas fa-times text-danger"></i> Reject the transfer request</li>
            <li><i class="fas fa-times text-danger"></i> Send denial notification to requester</li>
            <li><i class="fas fa-times text-danger"></i> Notify current owner of decision</li>
            <li><i class="fas fa-check text-info"></i> Car ownership remains unchanged</li>
            <li><i class="fas fa-info-circle text-info"></i> Request will be marked as denied</li>
        `;
        $('#transferDecisionEffects').html(effects);

        // Show the modal
        $('#transferDecisionModal').modal('show');
    }

    // Transfer decision modal confirm button
    $('#confirmTransferDecisionBtn').on('click', function() {
        if (transferDecisionData) {
            // Show loading state
            const $btn = $(this);
            $btn.prop('disabled', true);
            const originalHtml = $btn.html();
            $btn.html('<i class="fas fa-spinner fa-spin"></i> Processing...');

            // Create form and submit
            const form = $('<form>', {
                method: 'POST',
                action: window.location.href
            });

            // Get CSRF token from an existing form on the page
            const csrfToken = $('.reassign-form input[name="csrf"]').val() || $('input[name="csrf"]').first().val() || '';
            form.append($('<input>', { type: 'hidden', name: 'csrf', value: csrfToken }));
            form.append($('<input>', { type: 'hidden', name: 'command', value: transferDecisionData.action + '_transfer' }));
            form.append($('<input>', { type: 'hidden', name: 'transfer_id', value: transferDecisionData.transferId }));

            // Hide modal and submit form
            $('#transferDecisionModal').modal('hide');
            $('body').append(form);
            form.submit();

            // Re-enable after a timeout in case of network issues
            setTimeout(() => {
                $btn.prop('disabled', false);
                $btn.html(originalHtml);
            }, 10000);
        }
    });

    // Clear transfer decision modal data when closed
    $('#transferDecisionModal').on('hidden.bs.modal', function() {
        transferDecisionData = null;
    });
}

// ==========================================================================
// Global Admin Functions (Available across all tabs)
// ==========================================================================

/**
 * Function to switch to owner management tab with specific user pre-loaded
 */
function switchToOwnerManagementTab(userId) {
    // Switch to owner management tab and pass user ID as parameter
    window.location.href = '?tab=owner-mgmt&owner_id=' + userId;
}

/**
 * Function to open admin contact modal for owner communication
 */
function openAdminContactModal(carData, ownerData, qualityIssue = '', targetEmail = '') {
    // Populate car information
    document.getElementById('contactCarInfo').innerHTML = `
        <div><strong>Car ID:</strong> ${carData.id}</div>
        <div><strong>Year/Model:</strong> ${carData.year || 'N/A'} ${carData.model || 'N/A'}</div>
        <div><strong>Chassis:</strong> ${carData.chassis || 'Missing'}</div>
        <div><strong>Series:</strong> ${carData.series || 'Missing'}</div>
    `;

    // Populate owner information
    document.getElementById('contactOwnerInfo').innerHTML = `
        <div><strong>Name:</strong> ${ownerData.name || 'Unknown'}</div>
        <div><strong>Email:</strong> ${ownerData.email || 'Unknown'}</div>
        <div><strong>User ID:</strong> ${ownerData.id || 'Unknown'}</div>
    `;

    // Set hidden field values
    document.getElementById('contactCarId').value = carData.id;
    document.getElementById('contactOwnerId').value = ownerData.id;
    document.getElementById('contactTargetEmail').value = targetEmail || ownerData.email || '';

    // Pre-populate quality issue if provided
    if (qualityIssue) {
        document.getElementById('qualityIssue').value = qualityIssue;
    } else {
        document.getElementById('qualityIssue').value = '';
    }

    // Show the modal
    $('#adminContactModal').modal('show');
}