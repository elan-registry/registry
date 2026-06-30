/* exported initializeHistoryTable */
/* global highlightDifferences, carousel */
/**
 * car_details.js
 * JavaScript functionality for the car details page
 * Handles DataTables initialization for car history with AJAX loading
 * and difference highlighting on draw events.
 *
 * @author Elan Registry Admin
 * @copyright 2025
 */

// Prevent multiple initializations
let historyTableInitialized = false;

// Initialize DataTables for car history
function initializeHistoryTable() {
    if (historyTableInitialized) {
        return;
    }

    // Check if DataTable is already initialized on this element
    if ($.fn.DataTable.isDataTable('#carHistoryTable')) {
        historyTableInitialized = true;
        return;
    }

    // Get car ID from config or DOM
    let carId = null;

    if (window.carDetailsConfig && window.carDetailsConfig.carId) {
        carId = window.carDetailsConfig.carId;
    } else if ($('#car_id').length && $('#car_id').val()) {
        carId = $('#car_id').val();
    } else if ($('input[name="car_id"]').length && $('input[name="car_id"]').first().val()) {
        carId = $('input[name="car_id"]').first().val();
    }

    if (!carId) {
        console.error('Car ID not provided for history table');
        return;
    }

    historyTableInitialized = true;

    try {
        const table = $('#carHistoryTable').DataTable({
            responsive: true,
            order: [
                [1, 'desc']
            ],
            language: {
                'emptyTable': 'No history'
            },
            ajax: {
                url: window.carDetailsConfig.urlRoot + 'app/api/cars/history.php',
                dataSrc: 'history',
                type: 'POST',
                data: function(d) {
                    d.csrf = window.carDetailsConfig?.csrf;
                    d.car_id = carId;
                }
            },
            columns: [{
                    data: "operation",
                    responsivePriority: 1
                },
                {
                    data: "mtime",
                    responsivePriority: 1
                },
                {
                    data: "year",
                    responsivePriority: 2
                },
                {
                    data: "type",
                    responsivePriority: 2
                },
                {
                    data: "chassis",
                    responsivePriority: 1
                },
                {
                    data: "series",
                    responsivePriority: 3
                },
                {
                    data: "variant",
                    responsivePriority: 3
                },
                {
                    data: "color",
                    responsivePriority: 3
                },
                {
                    data: "engine",
                    responsivePriority: 3
                },
                {
                    data: "purchasedate",
                    responsivePriority: 3
                },
                {
                    data: "solddate",
                    responsivePriority: 3
                },
                {
                    data: "comments",
                    responsivePriority: 3
                },
                {
                    data: "image",
                    searchable: false,
                    responsivePriority: 3,
                    render: function(data, type, row) {
                        if (data) {
                            return carousel(row, row.car_id);
                        }
                        return '';
                    }
                },
                {
                    data: "fname",
                    responsivePriority: 3
                },
                {
                    data: "city",
                    responsivePriority: 3
                },
                {
                    data: "state",
                    responsivePriority: 3
                },
                {
                    data: "country",
                    responsivePriority: 3
                }
            ]
        });

        // Run highlight on every draw (initial load, sort, page, search)
        table.on('draw.dt', function() {
            if (typeof highlightDifferences === 'function') {
                highlightDifferences();
            }
        });

    } catch (error) {
        console.error('Failed to initialize DataTable:', error);
        historyTableInitialized = false;
        throw error;
    }
}

// Initialize when DOM is ready
$(document).ready(function() {
    initializeHistoryTable();
});
