(function() {
    'use strict';

    const textRender = $.fn.dataTable.render.text();
    const table = $('#cartable').DataTable({
        fixedHeader: true,
        responsive: true,
        pageLength: 15,
        lengthMenu: [
            [10, 25, 50, 100, -1],
            [10, 25, 50, 100, 'All']
        ],
        order: [
            [1, 'asc'],
            [2, 'asc'],
            [3, 'asc']
        ],
        language: {
            emptyTable: 'No Cars'
        },
        processing: true,
        serverSide: true,
        serverMethod: 'post',
        ajax: {
            url: '../../api/cars/list.php',
            dataSrc: 'data',
            data: function(d) {
                d.csrf = window.carListConfig.csrf;
            },
            error: function(xhr, error, thrown) {
                console.error('Car list table load failed:', error, xhr.status, thrown);
                const wrapper = $('#cartable').closest('.dataTables_wrapper');
                if (!wrapper.find('.alert-danger').length) {
                    wrapper.prepend('<div class="alert alert-danger mt-2">Could not load car list. Please refresh the page.</div>');
                }
            }
        },
        columnDefs: [
            { visible: false, targets: [12] }
        ],
        columns: [{
            data: 'id',
            searchable: false,
            orderable: false,
            responsivePriority: 1,
            render: function(data, type, row) {
                const carId = parseInt(data, 10);
                if (!Number.isFinite(carId) || carId <= 0) { return ''; }
                const isNew = window.carListConfig.newCarIds?.includes(carId);
                const badge = isNew ? ' <span class="badge er-badge-yellow badge-sm">NEW</span>' : '';
                // carId is a validated integer; urlRoot is a system-controlled path — concatenation is safe
                return '<a class="btn btn-primary btn-sm" href="' + window.carListConfig.urlRoot + 'app/owner/cars/details.php?car_id=' + carId + '"><i class="fas fa-eye"></i> Details' + badge + '</a>';
            }
        }, {
            data: 'year',
            responsivePriority: 1,
            render: textRender
        }, {
            data: 'type',
            responsivePriority: 1,
            render: textRender
        }, {
            data: 'chassis',
            responsivePriority: 1,
            render: textRender
        }, {
            data: 'series',
            responsivePriority: 2,
            render: textRender
        }, {
            data: 'variant',
            responsivePriority: 2,
            render: textRender
        }, {
            data: 'color',
            responsivePriority: 2,
            render: textRender
        }, {
            data: 'image',
            searchable: false,
            orderable: false,
            responsivePriority: 3,
            render: function(data, type, row) {
                if (data) {
                    return carousel(row);
                } else {
                    return '<img src="' + window.carListConfig.urlRoot + 'app/assets/img/elan-placeholder.svg" alt="No photo" style="height:50px;opacity:0.5;" title="No photo available">';
                }
            }
        }, {
            data: 'fname',
            responsivePriority: 3,
            render: textRender
        }, {
            data: 'city',
            responsivePriority: 3,
            render: textRender
        }, {
            data: 'state',
            responsivePriority: 3,
            render: textRender
        }, {
            data: 'country',
            responsivePriority: 3,
            render: textRender
        }, {
            data: 'ctime',
            searchable: true,
            responsivePriority: 3,
            render: textRender
        }]
    });

    document.querySelectorAll('.filter-pill').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const col = this.dataset.col;
            const val = this.dataset.value;
            document.querySelectorAll('.filter-pill[data-col="' + col + '"]').forEach(function(b) {
                b.classList.remove('active', 'btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.add('active', 'btn-primary');
            this.classList.remove('btn-outline-secondary');
            table.column(parseInt(col)).search(val).draw();
        });
    });

    document.getElementById('toggle-date-added').addEventListener('click', function() {
        const col = table.column(12);
        col.visible(!col.visible());
        this.innerHTML = col.visible()
            ? '<i class="fas fa-calendar-alt"></i> Hide Date Added'
            : '<i class="fas fa-calendar-alt"></i> Show Date Added';
    });
}());
