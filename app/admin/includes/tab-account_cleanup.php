<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Threshold values — read from GET so URL is bookmarkable / shareable
// ---------------------------------------------------------------------------
$acThreshold  = max(30,  (int) ($_GET['ac_threshold']  ?? 30));
$acvThreshold = max(1,   (int) ($_GET['acv_threshold'] ?? 365));

// ---------------------------------------------------------------------------
// Handle POST delete actions — same TOCTOU-protected logic, then PRG redirect
// ---------------------------------------------------------------------------
if ($method === 'POST' && isset($_POST['ac_action'])) {
    require_once __DIR__ . '/account-cleanup-helpers.php';

    if (!isset($_POST['csrf']) || !Token::check($_POST['csrf'])) {
        logger($currentUserId, LogCategories::LOG_CATEGORY_SECURITY,
            'CSRF validation failed on account cleanup tab');
        $acFlashError = 'Security token invalid. Please refresh the page and try again.';
    } elseif (!isAdmin()) {
        logger($currentUserId, LogCategories::LOG_CATEGORY_SECURITY,
            'Non-admin attempted account cleanup action on ' . $php_self);
        $acFlashError = 'Insufficient permissions.';
    } elseif ($_POST['ac_action'] === 'restore_account') {
        $archiveId = max(0, (int) ($_POST['archive_id'] ?? 0));
        if ($archiveId <= 0) {
            $acFlashError = 'Invalid archive record.';
        } else {
            try {
                $newUserId = restoreArchivedAccount($db, $archiveId, $currentUserId);
                logger(
                    $currentUserId,
                    LogCategories::LOG_CATEGORY_USER_DELETION,
                    "Admin restored archived account: archive_id={$archiveId}, new_user_id={$newUserId}"
                );
                $qs = http_build_query([
                    'tab'          => 'account-cleanup',
                    'ac_threshold' => max(30, (int) ($_POST['ac_threshold']  ?? 30)),
                    'acv_threshold'=> max(1,  (int) ($_POST['acv_threshold'] ?? 365)),
                    'restored'     => $newUserId,
                ]);
                header('Location: ?' . $qs);
                exit;
            } catch (\Throwable $e) {
                logger(
                    $currentUserId,
                    LogCategories::LOG_CATEGORY_USER_DELETION,
                    "Admin restore of archive_id={$archiveId} failed: " . $e->getMessage()
                );
                $acFlashError = 'Restore failed. See application log for details.';
            }
        }
    } else {
        // Delete (delete_unverified or delete_verified)
        $postThreshold  = max(30, (int) ($_POST['ac_threshold']  ?? 30));
        $postVThreshold = max(1,  (int) ($_POST['acv_threshold'] ?? 365));
        $isVerified     = $_POST['ac_action'] === 'delete_verified';
        $idsField       = $isVerified ? 'acv_ids' : 'acu_ids';
        $submittedIds   = array_values(array_filter(
            array_map('intval', (array) ($_POST[$idsField] ?? [])),
            fn(int $id): bool => $id > 0
        ));

        if (empty($submittedIds)) {
            $acFlashError = 'No accounts selected for deletion.';
        } else {
            $eligible    = $isVerified
                ? findVerifiedOwnerlessAccounts($db, $postVThreshold)
                : findUnverifiedOwnerlessAccounts($db, $postThreshold);

            // CRITICAL-2: Abort if the re-query itself failed silently (UserSpice swallows DB errors)
            if ($db->error()) {
                logger(
                    $currentUserId,
                    LogCategories::LOG_CATEGORY_USER_DELETION,
                    'Account cleanup eligibility re-query failed — deletion aborted: ' . $db->errorString()
                );
                $acFlashError = 'Could not verify account eligibility. Deletion aborted. Please try again.';
            } else {
            $eligibleMap = [];
            foreach ($eligible as $acct) {
                $eligibleMap[(int) $acct->id] = $acct;
            }
            $toDelete = array_values(array_intersect($submittedIds, array_keys($eligibleMap)));

            // Archive before permanent deletion — abort if archive fails
            try {
                archiveAccounts($db, $toDelete, $currentUserId, $isVerified ? 'verified' : 'unverified');
            } catch (\Throwable $e) {
                logger(
                    $currentUserId,
                    LogCategories::LOG_CATEGORY_USER_DELETION,
                    'Account archive failed — deletion aborted: ' . $e->getMessage()
                );
                $acFlashError = 'Could not archive accounts before deletion. Deletion aborted to prevent data loss.';
            }

            if (!isset($acFlashError)) {
                deleteUsers($toDelete);

                // CRITICAL-1: Re-query AFTER deletion so we log only accounts actually removed.
                // deleteUsers() returns an iteration count, not a success count — log from confirmed state.
                $acPostAccounts = $isVerified
                    ? findVerifiedOwnerlessAccounts($db, $postVThreshold)
                    : findUnverifiedOwnerlessAccounts($db, $postThreshold);
                $stillExistIds  = array_map(fn($a): int => (int) $a->id, $acPostAccounts);
                $confirmedIds   = array_diff($toDelete, $stillExistIds);
                $deleted        = count($confirmedIds);

                foreach ($confirmedIds as $deletedId) {
                    $acct = $eligibleMap[$deletedId];
                    logger(
                        $currentUserId,
                        LogCategories::LOG_CATEGORY_USER_DELETION,
                        sprintf(
                            'Admin deleted %s ownerless account: id=%d, email=%s',
                            $isVerified ? 'verified' : 'unverified',
                            $acct->id,
                            $acct->email
                        )
                    );
                }

                // PRG: redirect so a browser refresh doesn't re-POST
                $qs = http_build_query([
                    'tab'          => 'account-cleanup',
                    'ac_threshold' => $postThreshold,
                    'acv_threshold'=> $postVThreshold,
                    'deleted'      => $deleted,
                    'label'        => $isVerified ? 'verified' : 'unverified',
                ]);
                header('Location: ?' . $qs);
                exit;
            }
        }
    }
}

// Flash values from PRG redirect
$acFlashDeleted  = isset($_GET['deleted'])  ? (int) $_GET['deleted']  : null;
$acFlashRestored = isset($_GET['restored']) ? (int) $_GET['restored'] : null;
$acFlashLabel    = in_array($_GET['label'] ?? '', ['verified', 'unverified'], true)
    ? $_GET['label']
    : null;

?>

<link rel="stylesheet" href="<?= $us_url_root ?>usersc/css/datatables.min.css">

<!-- Account Cleanup Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="text-primary mb-1">
            <i class="fas fa-user-slash"></i> Account Cleanup
        </h2>
        <p class="text-muted mb-0">Report and delete accounts with no car associations</p>
    </div>
</div>

<?php if (isset($acFlashError)) { ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-triangle"></i>
        <?= htmlspecialchars($acFlashError, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php } ?>

<?php if ($acFlashDeleted !== null && $acFlashLabel !== null) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle"></i>
        Deleted <?= (int) $acFlashDeleted ?>
        <?= htmlspecialchars($acFlashLabel, ENT_QUOTES, 'UTF-8') ?> account(s). Snapshots saved to archive.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<?php if ($acFlashRestored !== null) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-user-check"></i>
        Account restored — new user ID: <strong><?= (int) $acFlashRestored ?></strong>.
        Email verification required before login.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<!-- Threshold controls -->
<div class="card registry-card mb-4">
    <div class="card-body py-3">
        <form method="GET" action="" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="account-cleanup">
            <div class="col-sm-4">
                <label for="ac_threshold" class="form-label mb-1 small">
                    Unverified — account age at least (days)
                </label>
                <input type="number" class="form-control form-control-sm"
                       id="ac_threshold" name="ac_threshold"
                       min="30" value="<?= (int) $acThreshold ?>">
            </div>
            <div class="col-sm-4">
                <label for="acv_threshold" class="form-label mb-1 small">
                    Verified — no login for at least (days)
                </label>
                <input type="number" class="form-control form-control-sm"
                       id="acv_threshold" name="acv_threshold"
                       min="1" value="<?= (int) $acvThreshold ?>">
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="fas fa-sync-alt"></i> Apply
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Helper: render one section (table skeleton + hidden delete form)
$renderSection = function (
    string $prefix,          // 'acu' | 'acv'
    string $tableId,         // 'acuTable' | 'acvTable'
    string $sectionTitle,
    array  $rules,
    string $deleteAction,    // 'delete_unverified' | 'delete_verified'
    string $idsField,        // 'acu_ids' | 'acv_ids'
    bool   $isVerified
) use ($acThreshold, $acvThreshold, $csrfToken): void {
?>
<div class="card registry-card mb-4">
    <div class="card-header card-header-er-primary">
        <div class="d-flex justify-content-between align-items-start">
            <h5 class="mb-0 card-header-er-primary-text">
                <i class="fas fa-user-slash"></i>
                <?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?>
                <span id="<?= $prefix ?>Count" class="ms-1 badge bg-light text-dark fw-normal fs-6"></span>
            </h5>
            <ul class="list-unstyled mb-0 ms-4 small text-nowrap" style="opacity:.85">
                <?php foreach ($rules as $rule) { ?>
                    <li><i class="fas fa-circle-dot me-1" style="font-size:.6rem;vertical-align:middle"></i><?= htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') ?></li>
                <?php } ?>
            </ul>
        </div>
    </div>
    <div class="card-body">

        <!-- Batch + delete controls -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <label for="<?= $prefix ?>BatchLimit" class="form-label mb-0 text-nowrap small">Batch limit:</label>
                <input type="number" id="<?= $prefix ?>BatchLimit"
                       class="form-control form-control-sm" style="width:80px"
                       min="1" value="10">
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="<?= $prefix ?>SelectTopBtn">
                Select All (top <span id="<?= $prefix ?>LimitDisplay">10</span>)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="<?= $prefix ?>DeselectBtn">
                Deselect All
            </button>
            <button type="button" class="btn btn-sm btn-danger" id="<?= $prefix ?>DeleteBtn" disabled>
                <i class="fas fa-trash"></i> Delete Selected
                (<span id="<?= $prefix ?>SelCount">0</span>)
            </button>
        </div>

        <div class="table-responsive">
            <table id="<?= $tableId ?>" class="table table-hover table-sm w-100">
                <thead class="thead-light">
                    <tr>
                        <th></th>
                        <th>ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>City</th>
                        <th>State</th>
                        <th>Country</th>
                        <?php if ($isVerified) { ?>
                            <th>Account Created</th>
                            <th>Last Login</th>
                            <th>Logins</th>
                        <?php } else { ?>
                            <th>Joined</th>
                            <th>Age (days)</th>
                        <?php } ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- Hidden delete form — JS populates ID inputs before submit -->
        <form id="<?= $prefix ?>DeleteForm" method="POST" action="?tab=account-cleanup" class="d-none">
            <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="ac_action"     value="<?= htmlspecialchars($deleteAction, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="ac_threshold"  value="<?= (int) $acThreshold ?>">
            <input type="hidden" name="acv_threshold" value="<?= (int) $acvThreshold ?>">
        </form>

    </div>
</div>
<?php
}; // end $renderSection

$renderSection(
    'acu', 'acuTable',
    'Unverified accounts with no car',
    [
        'Email not verified',
        'No cars — current or historical',
        'Account age ≥ ' . $acThreshold . ' days',
        'Active, unprotected, non-system account',
    ],
    'delete_unverified', 'acu_ids', false
);

$renderSection(
    'acv', 'acvTable',
    'Verified accounts with no car',
    [
        'Email verified',
        'No cars — current or historical',
        'No login for ≥ ' . $acvThreshold . ' days (or never logged in)',
        'Active, unprotected, non-system account',
    ],
    'delete_verified', 'acv_ids', true
);
?>

<!-- Archive section -->
<div class="card registry-card mb-4">
    <div class="card-header card-header-er-secondary">
        <h5 class="mb-0">
            <i class="fas fa-archive"></i> Deleted Accounts Archive
            <span id="arcCount" class="ms-1 badge bg-light text-dark fw-normal fs-6"></span>
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Every account deleted via this tool is preserved here. Restoring creates a new account
            with the same details and requires the user to re-verify their email before logging in.
        </p>
        <div class="table-responsive">
            <table id="arcTable" class="table table-hover table-sm w-100">
                <thead>
                    <tr>
                        <th>Archive ID</th>
                        <th>Orig. User ID</th>
                        <th>Email</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Deleted At</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <!-- Single restore form — JS populates archive_id before submit -->
        <form id="arcRestoreForm" method="POST" action="?tab=account-cleanup" class="d-none">
            <input type="hidden" name="csrf"          value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="ac_action"     value="restore_account">
            <input type="hidden" name="ac_threshold"  value="<?= (int) $acThreshold ?>">
            <input type="hidden" name="acv_threshold" value="<?= (int) $acvThreshold ?>">
            <input type="hidden" name="archive_id"    id="arcRestoreId" value="">
        </form>
    </div>
</div>

<script src="<?= $us_url_root ?>usersc/js/datatables.min.js"></script>
<script>
(function () {
    'use strict';

    var DATA_URL    = <?= json_encode($us_url_root . 'app/api/admin/account-cleanup-data.php') ?>;
    var AC_THRESH   = <?= (int) $acThreshold ?>;
    var ACV_THRESH  = <?= (int) $acvThreshold ?>;

    // Escape a value for safe insertion into HTML markup
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

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
              render: function (d) { return d ? esc(d) : '—'; } },
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
                var by = row.restored_by ? ' by ' + esc(row.restored_by) : '';
                return '<span class="badge bg-success">Restored ' + esc(row.restored_at.substring(0, 10)) + by + '</span>';
            }
            return '<span class="badge bg-secondary">Archived</span>';
        };

        var locationRender = function (data, type, row) {
            return [row.city, row.state, row.country].filter(Boolean).map(esc).join(', ') || '—';
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
</script>
