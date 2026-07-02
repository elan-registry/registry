/**
 * backup-operations.js
 * Handles AJAX requests for backup management in the admin panel
 * Includes proper CSRF token handling via ElanRegistryAPI
 *
 * These functions are intentionally declared in global scope as they are
 * called from HTML onclick handlers in the admin panel.
 */

/* eslint-disable no-implicit-globals */
/* exported createManualBackup, listBackupFiles, downloadBackup, deleteBackup, performBackupCleanup */
/* global showInputDialog, showNotification, escapeHtml, ElanRegistryAPI */

/**
 * Create a manual backup via AJAX
 */
function createManualBackup(triggerButton) {
    'use strict';

    showInputDialog(
        'Create Manual Backup',
        'Enter a reason for this backup (or leave blank for default):',
        'Admin Panel Manual Backup',
        function (reason) {
            triggerButton.disabled = true;
            const originalText = triggerButton.innerHTML;
            triggerButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating backup...';

            const endpoint = window.elanUrlRoot
                ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php'
                : '/app/admin/includes/system/backup-operations.php';

            new ElanRegistryAPI().post(endpoint, {
                action: 'create_manual_backup',
                reason: reason
            })
                .then(response => {
                    if (response.success) {
                        listBackupFiles();
                    } else {
                        showNotification(`Backup failed: ${response.message}`, 'error');
                    }
                })
                .catch(error => {
                    console.error('Backup creation error:', error);
                    showNotification(`Error: ${error.message || 'Failed to create backup'}`, 'error');
                })
                .finally(() => {
                    triggerButton.disabled = false;
                    triggerButton.innerHTML = originalText;
                });
        }
    );
}

/**
 * List all backup files via AJAX
 */
function listBackupFiles() {
    'use strict';

    const modalContent = document.getElementById('backupListContent');
    if (!modalContent) {
        console.error('[listBackupFiles] #backupListContent not found in DOM');
        showNotification('Backup list unavailable. Please reload the page.', 'danger');
        return;
    }
    modalContent.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading backups...</div>';

    // Use ElanRegistryAPI for proper CSRF token handling
    const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
    new ElanRegistryAPI().post(endpoint, {
        action: 'list_backups'
    })
    .then(response => {
        if (response.success) {
            displayBackupList(response.backups);
            // Show modal
            bootstrap.Modal.getOrCreateInstance(document.getElementById('backupListModal')).show();
        } else {
            showNotification(`Error loading backups: ${response.message}`, 'error');
            modalContent.innerHTML = `<div class="alert alert-danger">${escapeHtml(response.message)}</div>`;
        }
    })
    .catch(error => {
        console.error('List backups error:', error);
        showNotification(`Error: ${error.message || 'Failed to load backups'}`, 'error');
        modalContent.innerHTML = `<div class="alert alert-danger">Error loading backups: ${escapeHtml(error.message || 'Unknown error')}</div>`;
    });
}

/**
 * Display backup list in modal
 * @param {Object} backups - Backup data organized by type
 */
function displayBackupList(backups) {
    'use strict';

    const modalContent = document.getElementById('backupListContent');
    let html = '';

    // Automated backups
    if (backups.automated && backups.automated.length > 0) {
        html += '<h6 class="mt-3 mb-2"><i class="fas fa-cog"></i> Automated Backups</h6>';
        html += '<div class="list-group mb-3">';
        backups.automated.forEach(backup => {
            html += `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <h6 class="mb-1">${escapeHtml(backup.filename)}</h6>
                        <small class="text-muted">${escapeHtml(backup.size_formatted)}</small>
                    </div>
                    <small class="text-muted">${escapeHtml(backup.created)}</small>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-info" onclick="downloadBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-download"></i> Download
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
    }

    // Manual backups
    if (backups.manual && backups.manual.length > 0) {
        html += '<h6 class="mt-3 mb-2"><i class="fas fa-user"></i> Manual Backups</h6>';
        html += '<div class="list-group mb-3">';
        backups.manual.forEach(backup => {
            html += `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <h6 class="mb-1">${escapeHtml(backup.filename)}</h6>
                        <small class="text-muted">${escapeHtml(backup.size_formatted)}</small>
                    </div>
                    <small class="text-muted">${escapeHtml(backup.created)}</small>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-info" onclick="downloadBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-download"></i> Download
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
    }

    // Rollback backups
    if (backups.rollback && backups.rollback.length > 0) {
        html += '<h6 class="mt-3 mb-2"><i class="fas fa-undo"></i> Rollback Backups</h6>';
        html += '<div class="list-group mb-3">';
        backups.rollback.forEach(backup => {
            html += `
                <div class="list-group-item">
                    <div class="d-flex w-100 justify-content-between align-items-center">
                        <h6 class="mb-1">${escapeHtml(backup.filename)}</h6>
                        <small class="text-muted">${escapeHtml(backup.size_formatted)}</small>
                    </div>
                    <small class="text-muted">${escapeHtml(backup.created)}</small>
                    <div class="mt-2">
                        <button type="button" class="btn btn-sm btn-info" onclick="downloadBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-download"></i> Download
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteBackup('${escapeHtml(backup.filename)}')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
            `;
        });
        html += '</div>';
    }

    if (!html) {
        html = '<div class="alert alert-info">No backups found</div>';
    }

    modalContent.innerHTML = html;
}

/**
 * Download a backup file
 * @param {string} filename - Backup filename
 */
function downloadBackup(filename) {
    'use strict';

    // Redirect to download endpoint (you'll need to implement this)
    window.location.href = `app/admin/includes/system/backup-operations.php?action=download&file=${encodeURIComponent(filename)}`;
}

/**
 * Delete a backup file
 * @param {string} filename - Backup filename
 */
function deleteBackup(filename) {
    'use strict';

    // Show confirmation modal
    const modal = document.getElementById('confirmationModal');
    if (!modal) {
        console.error('Confirmation modal not found');
        return;
    }

    // Set modal title and message
    document.getElementById('confirmTitle').textContent = 'Delete Backup';
    document.getElementById('confirmMessage').textContent = `Are you sure you want to delete "${filename}"? This action cannot be undone.`;

    // Set up confirm button action
    const confirmButton = document.getElementById('confirmButton');
    const newConfirmButton = confirmButton.cloneNode(true);
    confirmButton.parentNode.replaceChild(newConfirmButton, confirmButton);

    newConfirmButton.addEventListener('click', function performDelete() {
        // Close modal
        const _confirmEl = document.getElementById('confirmationModal');
        if (_confirmEl) bootstrap.Modal.getInstance(_confirmEl)?.hide();

        // Perform deletion
        const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
        new ElanRegistryAPI().post(endpoint, {
            action: 'delete_backup',
            filename: filename
        })
            .then(response => {
                if (response.success) {
                    listBackupFiles(); // Refresh list
                } else {
                    showNotification(`Delete failed: ${response.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('Delete backup error:', error);
                showNotification(`Error: ${error.message || 'Failed to delete backup'}`, 'error');
            });
    });

    // Show modal
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmationModal')).show();
}

/**
 * Perform backup cleanup (remove old backups)
 */
function performBackupCleanup() {
    'use strict';

    // Show confirmation modal
    const modal = document.getElementById('confirmationModal');
    if (!modal) {
        console.error('Confirmation modal not found');
        return;
    }

    // Set modal title and message
    document.getElementById('confirmTitle').textContent = 'Clean Up Old Backups';
    document.getElementById('confirmMessage').innerHTML = 'This will delete backups older than 30 days.<br><br><strong>This action cannot be undone.</strong>';

    // Set up confirm button action
    const confirmButton = document.getElementById('confirmButton');
    const newConfirmButton = confirmButton.cloneNode(true);
    confirmButton.parentNode.replaceChild(newConfirmButton, confirmButton);

    newConfirmButton.addEventListener('click', function performCleanup() {
        const _confirmEl = document.getElementById('confirmationModal');
        if (_confirmEl) bootstrap.Modal.getInstance(_confirmEl)?.hide();

        const originalText = newConfirmButton.innerHTML;
        newConfirmButton.disabled = true;
        newConfirmButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning up...';

        const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
        new ElanRegistryAPI().post(endpoint, {
            action: 'cleanup_backups'
        })
        .then(response => {
            if (response.success) {
                location.reload();
            } else {
                showNotification(`Cleanup failed: ${response.message}`, 'error');
            }
        })
        .catch(error => {
            console.error('Cleanup error:', error);
            showNotification(`Error: ${error.message || 'Failed to cleanup backups'}`, 'error');
        })
        .finally(() => {
            newConfirmButton.disabled = false;
            newConfirmButton.innerHTML = originalText;
        });
    });

    // Show modal
    bootstrap.Modal.getOrCreateInstance(document.getElementById('confirmationModal')).show();
}


