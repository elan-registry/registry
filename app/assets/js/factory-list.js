(function() {
  'use strict';

  const textRender = $.fn.dataTable.render.text();
  const table = $('#cartable').DataTable({
    fixedHeader: true,
    responsive: true,
    pageLength: 25,
    scrollX: true,
    "lengthMenu": [
      [25, 50, 100, -1],
      [25, 50, 100, "All"]
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
      data: function(d) {
        d.csrf = window.factoryListConfig.csrf;
      },
      error: function(xhr, error, thrown) {
        console.error('Factory table load failed:', error, xhr.status, thrown);
        const wrapper = $('#cartable').closest('.dataTables_wrapper');
        if (!wrapper.find('.alert-danger').length) {
          wrapper.prepend('<div class="alert alert-danger mt-2">Could not load factory data. Please refresh the page.</div>');
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
