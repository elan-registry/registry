(function () {
    'use strict';

    var DATA_URL    = window.elanUrlRoot + 'app/api/admin/account-cleanup-data.php';
    var AC_THRESH   = Math.max(30, parseInt(new URLSearchParams(window.location.search).get('ac_threshold') || '30', 10));
    var ACV_THRESH  = Math.max(1, parseInt(new URLSearchParams(window.location.search).get('acv_threshold') || '365', 10));

    // -------------------------------------------------------------------------
    // Generic section setup
    // -------------------------------------------------------------------------
    function setupSection(opts) {
        var selected  = new Set();
        var tableInst = null;

        var batchInput  = document.getElementById(opts.prefix + 'BatchLimit');
        var limitDisp   = document.getElementById(opts.prefix + 'LimitDisplay');
        var selectBtn   = document.getElementById(opts.prefix + 'SelectTopBtn');
        var deselBtn    = document.getElementById(opts.prefix + 'DeselectBtn');
        var deleteBtn   = document.getElementById(opts.prefix + 'DeleteBtn');
        var selCountDisp= document.getElementById(opts.prefix + 'SelCount');
        var countBadge  = document.getElementById(opts.prefix + 'Count');
        var deleteForm  = document.getElementById(opts.prefix + 'DeleteForm');

        if (!batchInput || !selCountDisp || !deleteBtn || !deleteForm) return;

        batchInput.addEventListener('input', function () {
            limitDisp.textContent = this.value;
        });

        function updateSelCount() {
            var n = selected.size;
            selCountDisp.textContent = n;
            deleteBtn.disabled       = n === 0;
        }

        function syncCheckboxes() {
            document.querySelectorAll('.' + opts.prefix + '-chk').forEach(function (cb) {
                cb.checked = selected.has(parseInt(cb.value, 10));
            });
            updateSelCount();
        }

        // data: null columns receive null as first arg — use row param for the actual data
        var checkboxRender = function (data, type, row) {
            return '<input type="checkbox" class="form-check-input ' + opts.prefix + '-chk" value="' + parseInt(row.id, 10) + '">';
        };

        var idRender = function (data, type, row) {
            return '<a href="../../users/admin.php?view=user&amp;id=' + parseInt(row.id, 10) + '" target="_blank" title="View in UserSpice admin">' + parseInt(row.id, 10) + '</a>';
        };

        var textRender = $.fn.dataTable.render.text();

        var dtColumns = [{ data: null, orderable: false, searchable: false, render: checkboxRender }]
            .concat(opts.columns.map(function (col) {
                if (col.render) return col;
                return { data: col.data, title: col.title, render: textRender };
            }));

        // Replace the id column with a link
        dtColumns[1] = { data: null, title: 'ID', render: idRender };

        tableInst = $('#' + opts.tableId).DataTable({
            ajax: {
                url: DATA_URL,
                dataSrc: 'data',
                data: { type: opts.type, threshold: opts.threshold }
            },
            columns: dtColumns,
            pageLength: 10,
            order: [[opts.defaultSortCol + 1, 'asc']],
            language: { emptyTable: 'No accounts match the current criteria.' },
            initComplete: function () {
                if (countBadge) {
                    countBadge.textContent = this.api().rows().count();
                }
            },
            drawCallback: function () {
                syncCheckboxes();
                if (countBadge) {
                    countBadge.textContent = this.api().rows().count();
                }
            }
        });

        // Checkbox click — delegated (rows added/replaced on redraw)
        $('#' + opts.tableId + ' tbody').on('change', '.' + opts.prefix + '-chk', function () {
            var id = parseInt(this.value, 10);
            if (this.checked) selected.add(id);
            else              selected.delete(id);
            updateSelCount();
        });

        // Select top-N across ALL rows (not just current page)
        selectBtn.addEventListener('click', function () {
            var limit = parseInt(batchInput.value, 10) || 10;
            selected.clear();
            tableInst.rows().data().each(function (row, idx) {
                if (idx < limit) selected.add(parseInt(row.id, 10));
            });
            syncCheckboxes();
        });

        deselBtn.addEventListener('click', function () {
            selected.clear();
            syncCheckboxes();
        });

        // Delete button — always confirm before submitting
        deleteBtn.addEventListener('click', function () {
            var n = selected.size;
            if (n === 0) return;

            function doSubmit() {
                // Remove stale ID inputs
                deleteForm.querySelectorAll('input[name$="[]"]').forEach(function (el) { el.remove(); });
                // Add current selection
                selected.forEach(function (id) {
                    var inp = document.createElement('input');
                    inp.type  = 'hidden';
                    inp.name  = opts.idsField + '[]';
                    inp.value = id;
                    deleteForm.appendChild(inp);
                });
                deleteForm.submit();
            }

            showConfirmDialog(
                'Confirm Deletion',
                'Delete ' + n + ' selected account' + (n === 1 ? '' : 's') + '? Accounts will be archived and can be restored if needed.',
                doSubmit
            );
        });
    }

    // -------------------------------------------------------------------------
    // Unverified section
    // -------------------------------------------------------------------------
    setupSection({
        prefix:         'acu',
        tableId:        'acuTable',
        type:           'unverified',
        threshold:      AC_THRESH,
        defaultSortCol: 6,   // Joined
        idsField:       'acu_ids',
        columns: [
            { data: 'id',      title: 'ID'         },
            { data: 'email',   title: 'Email'       },
            { data: 'name',    title: 'Name'        },
            { data: 'city',    title: 'City'        },
            { data: 'state',   title: 'State'       },
            { data: 'country', title: 'Country'     },
            { data: 'joined',  title: 'Joined'      },
            { data: 'age_days',title: 'Age (days)'  },
        ]
    });

    // -------------------------------------------------------------------------
    // Verified section
    // -------------------------------------------------------------------------
    setupSection({
        prefix:         'acv',
        tableId:        'acvTable',
        type:           'verified',
        threshold:      ACV_THRESH,
        defaultSortCol: 7,   // Last Login (index in opts.columns; +1 added for checkbox col)
        idsField:       'acv_ids',
        columns: [
            { data: 'id',         title: 'ID'               },
            { data: 'email',      title: 'Email'            },
            { data: 'name',       title: 'Name'             },
            { data: 'city',       title: 'City'             },
            { data: 'state',      title: 'State'            },
            { data: 'country',    title: 'Country'          },
            { data: 'joined',     title: 'Account Created'  },
            { data: 'last_login', title: 'Last Login',
              render: function (d) { return d ? escapeHtml(d) : '—'; } },
            { data: 'logins',     title: 'Logins'           },
        ]
    });

    // -------------------------------------------------------------------------
    // Archive section
    // -------------------------------------------------------------------------
    (function () {
        var restoreForm = document.getElementById('arcRestoreForm');
        var restoreIdInput = document.getElementById('arcRestoreId');
        var countBadge = document.getElementById('arcCount');

        var restoreRender = function (data, type, row) {
            if (row.restored_at) return '';
            // data-id is numeric only; email looked up from DataTables row on click
            return '<button type="button" class="btn btn-sm btn-outline-success arc-restore-btn" '
                + 'data-id="' + parseInt(row.id, 10) + '">'
                + '<i class="fas fa-undo"></i> Restore</button>';
        };

        var statusRender = function (data, type, row) {
            if (row.restored_at) {
                var by = row.restored_by ? ' by ' + escapeHtml(row.restored_by) : '';
                return '<span class="badge bg-success">Restored ' + escapeHtml(row.restored_at.substring(0, 10)) + by + '</span>';
            }
            return '<span class="badge bg-secondary">Archived</span>';
        };

        var locationRender = function (data, type, row) {
            return [row.city, row.state, row.country].filter(Boolean).map(escapeHtml).join(', ') || '—';
        };

        var textRender = $.fn.dataTable.render.text();
        var arcTable = $('#arcTable').DataTable({
            ajax: { url: DATA_URL, dataSrc: 'data', data: { type: 'archive' } },
            columns: [
                { data: 'id',               title: 'Archive ID'    },
                { data: 'original_user_id', title: 'Orig. User ID' },
                { data: 'email',            title: 'Email',       render: textRender },
                { data: 'name',             title: 'Name',        render: textRender },
                { data: 'deletion_type',    title: 'Type',        render: textRender },
                { data: 'deleted_at',       title: 'Deleted At',  render: textRender },
                { data: null,               title: 'Location',    render: locationRender, orderable: false },
                { data: null,               title: 'Status',      render: statusRender },
                { data: null,               title: '',            render: restoreRender, orderable: false, searchable: false },
            ],
            pageLength: 10,
            order: [[5, 'desc']],
            language: { emptyTable: 'No archived accounts.' },
            initComplete: function () {
                if (countBadge) countBadge.textContent = this.api().rows().count();
            },
            drawCallback: function () {
                if (countBadge) countBadge.textContent = this.api().rows().count();
            }
        });

        // Restore button click — email from DataTables row data (never from DOM attribute)
        $('#arcTable tbody').on('click', '.arc-restore-btn', function () {
            var archiveId = parseInt(this.dataset.id, 10);
            var rowData   = arcTable.row($(this).closest('tr')).data();
            var email     = rowData ? rowData.email : '';

            showConfirmDialog(
                'Restore Account',
                'Restore ' + email + '?\nA new account will be created. The user will need to re-verify their email before logging in.',
                function () { restoreIdInput.value = archiveId; restoreForm.submit(); }
            );
        });
    }());

}());
