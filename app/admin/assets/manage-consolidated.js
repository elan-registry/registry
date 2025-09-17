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
            console.error('AJAX Error:', {
                url: settings.url,
                status: xhr.status,
                error: thrownError
            });

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
            console.log('Consolidated Interface - Active Tab:', activeTab);

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

            // Mark initialization complete
            $('body').addClass('consolidated-interface-ready');

            console.log('Consolidated Management Interface initialized successfully');
        } catch (error) {
            console.error('Error initializing Consolidated Management Interface:', error);
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