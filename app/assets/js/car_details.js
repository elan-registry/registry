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
        const textRender = $.fn.dataTable.render.text();
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
                },
                error: function(xhr, error, thrown) {
                    console.error('History table load failed (car ID: ' + carId + '):', error, thrown, xhr.status);
                    const tableContainer = document.getElementById('carHistoryTable');
                    if (tableContainer) {
                        const warning = document.createElement('div');
                        warning.className = 'alert alert-warning';
                        warning.textContent = 'Car history could not be loaded. Please refresh the page to try again.';
                        tableContainer.insertAdjacentElement('beforebegin', warning);
                    }
                }
            },
            columns: [{
                    data: "operation",
                    responsivePriority: 1,
                    render: textRender
                },
                {
                    data: "mtime",
                    responsivePriority: 1,
                    render: textRender
                },
                {
                    data: "year",
                    responsivePriority: 2,
                    render: textRender
                },
                {
                    data: "type",
                    responsivePriority: 2,
                    render: textRender
                },
                {
                    data: "chassis",
                    responsivePriority: 1,
                    render: textRender
                },
                {
                    data: "series",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "variant",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "color",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "engine",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "purchasedate",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "solddate",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "comments",
                    responsivePriority: 3,
                    render: textRender
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
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "city",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "state",
                    responsivePriority: 3,
                    render: textRender
                },
                {
                    data: "country",
                    responsivePriority: 3,
                    render: textRender
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
        console.error('Failed to initialize history table (car ID: ' + carId + '):', error);
        const tableContainer = document.getElementById('carHistoryTable');
        if (tableContainer) {
            const warning = document.createElement('div');
            warning.className = 'alert alert-warning';
            warning.textContent = 'Car history could not be loaded.';
            tableContainer.insertAdjacentElement('beforebegin', warning);
        } else {
            console.warn('initializeHistoryTable: #carHistoryTable not found — warning could not be shown');
        }
    }
}

// Initialize when DOM is ready
$(document).ready(function() {
    initializeHistoryTable();
});
