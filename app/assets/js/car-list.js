(function() {
    'use strict';

    const textRender = $.fn.dataTable.render.text();
    let badgeTooltips = [];

    /**
     * Build the badge row for one car from the server badge keys.
     *
     * The server sets the keys and their order (CarBadges::resolve()), so this
     * function only draws them. The badges are outside the Details link: a
     * focusable span inside <a> is a nested interactive element (WCAG 4.1.2).
     * Definition text goes in through textContent and setAttribute, never
     * through string concatenation.
     *
     * @param {string[]|undefined} badgeKeys Badge keys from the row, highest priority first
     * @returns {string} HTML for the badge row, or '' when there are no badges
     */
    function renderBadges(badgeKeys) {
        const defs = window.carListConfig.badgeDefs || {};
        const keys = Array.isArray(badgeKeys)
            ? badgeKeys.filter(function(key) { return Object.hasOwn(defs, key); })
            : [];
        if (keys.length === 0) { return ''; }

        const container = document.createElement('div');
        container.className = 'er-badges d-flex flex-wrap gap-1 mt-1';
        keys.forEach(function(key) {
            const def = defs[key];
            const badge = document.createElement('span');
            badge.className = 'er-badge er-badge--' + def.tone;
            badge.setAttribute('data-bs-toggle', 'tooltip');
            badge.setAttribute('data-bs-title', def.tooltip);
            badge.setAttribute('tabindex', '0');
            if (def.icon) {
                const icon = document.createElement('span');
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = def.icon;
                badge.append(icon, ' ');
            }
            badge.append(def.label);
            container.append(badge);
        });
        return container.outerHTML;
    }

    const table = $('#cartable').DataTable({
        fixedHeader: true,
        responsive: true,
        pageLength: 15,
        lengthMenu: [
            [10, 25, 50, 100],
            [10, 25, 50, 100]
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
            error: function(xhr, error, thrown) {
                console.error('Car list table load failed:', error, xhr.status, thrown);
                // DataTables 3.x (pinned in package.json) wraps the table in .dt-container.
                // .dataTables_wrapper is the legacy 1.x name, retained as a fallback so the
                // banner still lands if a build ever resolves an older version.
                const wrapper = $('#cartable').closest('.dt-container, .dataTables_wrapper');
                if (!wrapper.find('.alert-danger').length) {
                    // Reloading re-fires the request, so it is the wrong advice for a 429.
                    const message = xhr.status === 429
                        ? 'Too many requests. Please wait a few minutes before searching again.'
                        : 'Could not load the car list. Please reload the page to try again.';
                    wrapper.prepend($('<div class="alert alert-danger mt-2"></div>').text(message));
                }
            }
        },
        columnDefs: [
            { visible: false, targets: [12] }
        ],
        // The footer starts tooltips once, on page load. Each draw replaces the
        // rows, so dispose the tooltips of the old rows (their nodes are already
        // out of the table, and an open tooltip stays in <body> until disposed)
        // and start tooltips on the new badges.
        drawCallback: function() {
            badgeTooltips.forEach(function(tooltip) { tooltip.dispose(); });
            badgeTooltips = Array.from(
                this.api().table().node().querySelectorAll('[data-bs-toggle="tooltip"]'),
                function(el) { return bootstrap.Tooltip.getOrCreateInstance(el); }
            );
        },
        columns: [{
            data: 'id',
            searchable: false,
            orderable: false,
            responsivePriority: 1,
            render: function(data, type, row) {
                if (type !== 'display') { return data; }
                const carId = parseInt(data, 10);
                if (!Number.isFinite(carId) || carId <= 0) { return ''; }
                // carId is a validated integer; urlRoot is a system-controlled path — concatenation is safe
                const link = '<a class="btn btn-primary btn-sm" href="' + window.carListConfig.urlRoot + 'app/owner/cars/details.php?car_id=' + carId + '"><i class="fas fa-eye"></i> Details</a>';
                return link + renderBadges(row.badges);
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
