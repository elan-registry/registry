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
    
    if (historyTableInitialized) {
        return;
    }
    
    // Check if DataTable is already initialized on this element
    if ($.fn.DataTable.isDataTable('#historytable')) {
        historyTableInitialized = true;
        return;
    }
    
    
    // Get car ID from multiple sources
    let carId = null;
    
    
    // Try global carDetailsConfig first
    if (typeof carDetailsConfig !== 'undefined' && carDetailsConfig && carDetailsConfig.carId) {
        carId = carDetailsConfig.carId;
    }
    // Fallback to window property
    else if (typeof window.carDetailsConfig !== 'undefined' && window.carDetailsConfig && window.carDetailsConfig.carId) {
        carId = window.carDetailsConfig.carId;
    }
    // Try DOM elements
    else if ($('#car_id').length && $('#car_id').val()) {
        carId = $('#car_id').val();
    }
    else if ($('input[name="car_id"]').length && $('input[name="car_id"]').first().val()) {
        carId = $('input[name="car_id"]').first().val();
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
    initializeHistoryTable();
});

// Backup initialization for DOMContentLoaded (in case jQuery ready doesn't fire)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if not already done
        if (!historyTableInitialized) {
            initializeHistoryTable();
        }
    });
} else {
    initializeHistoryTable();
}