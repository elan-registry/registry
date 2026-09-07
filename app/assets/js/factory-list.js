(function() {
  'use strict';

  const textRender = $.fn.dataTable.render.text();
  $('#cartable').DataTable({
    fixedHeader: true,
    responsive: true,
    pageLength: 25,
    scrollX: true,
    "lengthMenu": [
      [25, 50, 100],
      [25, 50, 100]
    ],
    "order": [
      [0, "asc"]
    ],
    "language": {
      "emptyTable": "No Data"
    },
    'processing': true,
    'serverSide': true,
    'serverMethod': 'post',

    "ajax": {
      "url": "../../api/cars/factory-list.php",
      "dataSrc": "data",
      error: function(xhr, error, thrown) {
        console.error('Factory table load failed:', error, xhr.status, thrown);
        // DataTables 3.x (pinned in package.json) wraps the table in .dt-container.
        // .dataTables_wrapper is the legacy 1.x name, retained as a fallback so the
        // banner still lands if a build ever resolves an older version.
        const wrapper = $('#cartable').closest('.dt-container, .dataTables_wrapper');
        if (!wrapper.find('.alert-danger').length) {
          // Reloading re-fires the request, so it is the wrong advice for a 429.
          const message = xhr.status === 429
            ? 'Too many requests. Please wait a few minutes before trying again.'
            : 'Could not load factory data. Please reload the page to try again.';
          wrapper.prepend($('<div class="alert alert-danger mt-2"></div>').text(message));
        }
      }
    },
    'columns': [{
        data: "id",
        'searchable': false,
        'orderable': false,
        visible: false,
        render: textRender
      },
      {
        data: "year",
        render: textRender
      },
      {
        data: "month",
        render: textRender
      },
      {
        data: "batch",
        render: textRender
      },
      {
        data: "type",
        render: textRender
      },
      {
        data: "serial",
        render: textRender
      },
      {
        data: "suffix",
        render: textRender
      },
      {
        data: "engineletter",
        render: textRender
      },
      {
        data: "enginenumber",
        render: textRender
      },
      {
        data: "gearbox",
        render: textRender
      },
      {
        data: "color",
        render: textRender
      },
      {
        data: "builddate",
        render: textRender
      }, {
        data: "note",
        render: textRender
      }, {
        // car_id is not a table column — injected as a correlated subquery alias by CarDataTablesService
        data: "car_id",
        render: function(data, type, row) {
          if (type !== 'display') {
            return data || '';
          }
          const carId = parseInt(data, 10);
          // carId is a validated integer; urlRoot is a system-controlled path — concatenation is safe
          const inner = (Number.isFinite(carId) && carId > 0)
            ? '<a href="' + window.factoryListConfig.urlRoot + 'app/owner/cars/details.php?car_id=' + carId + '" class="btn btn-sm btn-primary" target="_blank"><i class="fas fa-car"></i> View Car #' + carId + '</a>'
            : '<span class="text-muted small"><i class="fas fa-times-circle"></i> Not in registry</span>';
          return '<div class="registry-link-container">' + inner + '</div>';
        },
        orderable: false,
        searchable: false
      }
    ]
  });
}());
