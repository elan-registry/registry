/**
 * car_details.js
 * JavaScript functionality for the car details page
 * Handles DataTables initialization for car history and Google Maps display
 * 
 * @author Elan Registry Admin
 * @copyright 2025
 */

// Global variables (will be injected by PHP)
// carDetailsConfig is defined in the PHP page

// Prevent multiple initializations
let historyTableInitialized = false;
let initializationAttempts = 0;

// Initialize DataTables for car history
function initializeHistoryTable() {
    initializationAttempts++;
    // console.log(`initializeHistoryTable() called - Attempt #${initializationAttempts}`);
    
    if (historyTableInitialized) {
        // console.log('History table already initialized, skipping...');
        return;
    }
    
    // Check if DataTable is already initialized on this element
    if ($.fn.DataTable.isDataTable('#historytable')) {
        // console.log('DataTable already exists on #historytable, skipping...');
        historyTableInitialized = true;
        return;
    }
    
    // Debug: Log available config
    // console.log('Initializing history table...');
    // console.log('carDetailsConfig:', window.carDetailsConfig);
    // console.log('DOM car_id elements:', $('input[name="car_id"]'));
    
    // Get car ID from multiple sources
    let carId = null;
    
    // Debug all possible sources
    // console.log('Checking car ID sources:');
    // console.log('  typeof carDetailsConfig:', typeof carDetailsConfig);
    // console.log('  carDetailsConfig:', typeof carDetailsConfig !== 'undefined' ? carDetailsConfig : 'undefined');
    // console.log('  window.carDetailsConfig:', window.carDetailsConfig);
    // console.log('  DOM #car_id:', $('#car_id').length, $('#car_id').val());
    // console.log('  DOM input[name="car_id"]:', $('input[name="car_id"]').length, $('input[name="car_id"]').map(function() { return $(this).val(); }).get());
    
    // Try global carDetailsConfig first
    if (typeof carDetailsConfig !== 'undefined' && carDetailsConfig && carDetailsConfig.carId) {
        carId = carDetailsConfig.carId;
        // console.log('✓ Got carId from global carDetailsConfig:', carId);
    }
    // Fallback to window property
    else if (typeof window.carDetailsConfig !== 'undefined' && window.carDetailsConfig && window.carDetailsConfig.carId) {
        carId = window.carDetailsConfig.carId;
        // console.log('✓ Got carId from window.carDetailsConfig:', carId);
    }
    // Try DOM elements
    else if ($('#car_id').length && $('#car_id').val()) {
        carId = $('#car_id').val();
        // console.log('✓ Got carId from #car_id:', carId);
    }
    else if ($('input[name="car_id"]').length && $('input[name="car_id"]').first().val()) {
        carId = $('input[name="car_id"]').first().val();
        // console.log('✓ Got carId from input[name="car_id"]:', carId);
    }
    else {
        // console.log('✗ No carId found in any source');
    }
    
    if (!carId) {
        console.error('Car ID not provided - Config:', window.carDetailsConfig, 'DOM elements:', $('input[name="car_id"]').length);
        return;
    }
    
    historyTableInitialized = true;

    try {
        const table = $('#historytable').DataTable({
        scrollX: true,
        responsive: true,
        order: [
            [1, 'desc']
        ],
        language: {
            'emptyTable': 'No history'
        },
        ajax: {
            url: 'actions/history.php',
            dataSrc: 'history',
            type: 'POST',
            data: function(d) {
                d.csrf = window.carDetailsConfig?.csrf || carDetailsConfig.csrf;
                d.car_id = carId;
            }
        },
        columns: [{
                data: "operation"
            },
            {
                data: "mtime"
            },
            {
                data: "year"
            },
            {
                data: "type"
            },
            {
                data: "chassis"
            },
            {
                data: "series"
            },
            {
                data: "variant"
            },
            {
                data: "color"
            },
            {
                data: "engine"
            },
            {
                data: "purchasedate"
            },
            {
                data: "solddate"
            },
            {
                data: "comments"
            },
            {
                data: "image",
                searchable: false,
                'render': function(data, type, row) {
                    if (data) {
                        return carousel(row, row.car_id);
                    } else {
                        return '';
                    }
                }
            },
            {
                data: "fname"
            }, {
                data: "city"
            }, {
                data: "state"
            }, {
                data: 'country'
            }
        ]
        });
        
        // console.log('DataTable initialized successfully');
        
    } catch (error) {
        console.error('Failed to initialize DataTable:', error);
        historyTableInitialized = false; // Reset flag so initialization can be retried
        throw error;
    }
}

// Initialize Google Maps
function initMap() {
    if (!carDetailsConfig.hasLocation) {
        return; // No location data available
    }

    // Car location coordinates
    const carLocation = {
        lat: carDetailsConfig.latitude,
        lng: carDetailsConfig.longitude
    };

    const mapElement = document.getElementById("map");

    if (mapElement) {
        // The map, centered at car location
        const map = new google.maps.Map(mapElement, {
            zoom: 8,
            center: carLocation,
            streetViewControl: false
        });

        // Use classic marker (reliable and widely supported)
        const marker = new google.maps.Marker({
            position: carLocation,
            map: map,
            title: "Car Location"
        });
    }
}

// Make initMap available globally for Google Maps callback
window.initMap = initMap;

// Initialize when DOM is ready - Use both jQuery ready and native DOMContentLoaded with protection
$(document).ready(function() {
    // console.log('jQuery ready - attempting to initialize history table');
    initializeHistoryTable();
});

// Backup initialization for DOMContentLoaded (in case jQuery ready doesn't fire)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        // console.log('DOMContentLoaded - checking if history table needs initialization');
        // Only initialize if not already done
        if (!historyTableInitialized) {
            // console.log('DOMContentLoaded - initializing history table as backup');
            initializeHistoryTable();
        }
    });
} else {
    // console.log('Document already ready - attempting immediate initialization');
    initializeHistoryTable();
}