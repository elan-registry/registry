/**
 * backup-operations.js
 * Handles AJAX requests for backup management in the admin panel
 * Includes proper CSRF token handling via ElanRegistryAPI
 */

/* eslint-disable no-unused-vars, no-undef */
/* exported createManualBackup, listBackupFiles, downloadBackup, deleteBackup, performBackupCleanup, runSchemaValidation */

/**
 * Create a manual backup via AJAX
 */
// eslint-disable-next-line no-unused-vars
function createManualBackup() {
    'use strict';

    // eslint-disable-next-line no-undef
    const reason = window.prompt('Enter backup reason (or leave blank for default):', 'Admin Panel Manual Backup');
    if (reason === null) {
        return; // User cancelled
    }

    // eslint-disable-next-line no-undef
    const button = event.target;
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating backup...';

    // Use ElanRegistryAPI for proper CSRF token handling
    const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
    // eslint-disable-next-line no-undef
    new ElanRegistryAPI().post(endpoint, {
        action: 'create_manual_backup',
        reason: reason
    })
        .then(response => {
            if (response.success) {
                // Refresh backup list
                listBackupFiles();
            } else {
                // eslint-disable-next-line no-undef
                showNotification(`Backup failed: ${response.message}`, 'error');
            }
        })
        .catch(error => {
            console.error('Backup creation error:', error);
            // eslint-disable-next-line no-undef
            showNotification(`Error: ${error.message || 'Failed to create backup'}`, 'error');
        })
        .finally(() => {
            button.disabled = false;
            button.innerHTML = originalText;
        });
}

/**
 * List all backup files via AJAX
 */
function listBackupFiles() {
    'use strict';

    const modalContent = document.getElementById('backupListContent');
    modalContent.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading backups...</div>';

    // Use ElanRegistryAPI for proper CSRF token handling
    const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
    // eslint-disable-next-line no-undef
    new ElanRegistryAPI().post(endpoint, {
        action: 'list_backups'
    })
    .then(response => {
        if (response.success) {
            displayBackupList(response.backups);
            // Show modal
            $('#backupListModal').modal('show');
        } else {
            showNotification(`Error loading backups: ${response.message}`, 'error');
            modalContent.innerHTML = `<div class="alert alert-danger">${response.message}</div>`;
        }
    })
    .catch(error => {
        console.error('List backups error:', error);
        showNotification(`Error: ${error.message || 'Failed to load backups'}`, 'error');
        modalContent.innerHTML = `<div class="alert alert-danger">Error loading backups: ${error.message}</div>`;
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
// eslint-disable-next-line no-unused-vars
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
        // eslint-disable-next-line no-undef
        $('#confirmationModal').modal('hide');

        // Perform deletion
        const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
        // eslint-disable-next-line no-undef
        new ElanRegistryAPI().post(endpoint, {
            action: 'delete_backup',
            filename: filename
        })
            .then(response => {
                if (response.success) {
                    listBackupFiles(); // Refresh list
                } else {
                    // eslint-disable-next-line no-undef
                    showNotification(`Delete failed: ${response.message}`, 'error');
                }
            })
            .catch(error => {
                console.error('Delete backup error:', error);
                // eslint-disable-next-line no-undef
                showNotification(`Error: ${error.message || 'Failed to delete backup'}`, 'error');
            });
    });

    // Show modal
    // eslint-disable-next-line no-undef
    $('#confirmationModal').modal('show');
}

/**
 * Perform backup cleanup (remove old backups)
 */
// eslint-disable-next-line no-unused-vars
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
        // Close modal
        // eslint-disable-next-line no-undef
        $('#confirmationModal').modal('hide');

        const button = event.target;
        const originalText = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cleaning up...';

    const endpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/backup-operations.php' : '/app/admin/includes/system/backup-operations.php';
    // eslint-disable-next-line no-undef
    new ElanRegistryAPI().post(endpoint, {
        action: 'cleanup_backups'
    })
    .then(response => {
        if (response.success) {
            // Refresh stats
            location.reload();
        } else {
            // eslint-disable-next-line no-undef
            showNotification(`Cleanup failed: ${response.message}`, 'error');
        }
    })
    .catch(error => {
        console.error('Cleanup error:', error);
        showNotification(`Error: ${error.message || 'Failed to cleanup backups'}`, 'error');
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalText;
    });
    });

    // Show modal
    // eslint-disable-next-line no-undef
    $('#confirmationModal').modal('show');
}

/**
 * Run schema validation
 */
// eslint-disable-next-line no-unused-vars
function runSchemaValidation(button) {
    'use strict';

    if (!button) {
        // eslint-disable-next-line no-undef
        console.error('Button element required for validation');
        return;
    }

    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating...';

    const schemaEndpoint = window.elanUrlRoot ? window.elanUrlRoot.replace(/\/$/, '') + '/app/admin/includes/system/schema-operations.php' : '/app/admin/includes/system/schema-operations.php';
    // eslint-disable-next-line no-undef
    new ElanRegistryAPI().post(schemaEndpoint, {
        action: 'validate_schema'
    })
    .then(response => {
        if (response.success) {
            displayValidationResults(response);
        } else {
            // eslint-disable-next-line no-undef
            showNotification(`Validation failed: ${response.message}`, 'error');
        }
    })
    .catch(error => {
        console.error('Validation error:', error);
        showNotification(`Error: ${error.message || 'Failed to validate schema'}`, 'error');
    })
    .finally(() => {
        button.disabled = false;
        button.innerHTML = originalText;
    });
}

/**
 * Display schema validation results
 * @param {Object} response - Validation response
 */
function displayValidationResults(response) {
    'use strict';

    const container = document.getElementById('validation-result-container');
    let html = '<div class="alert alert-info"><h6>Validation Results</h6>';

    if (response.issues && response.issues.length > 0) {
        html += '<ul>';
        response.issues.forEach(issue => {
            html += `<li>${escapeHtml(issue)}</li>`;
        });
        html += '</ul>';
    } else {
        html += '<p>No issues found. Schema is valid.</p>';
    }

    html += '</div>';
    container.innerHTML = html;
}

/**
 * Escape HTML to prevent XSS
 * @param {string} unsafe - String to escape
 * @return {string} - Escaped string
 */
function escapeHtml(unsafe) {
    'use strict';

    if (typeof unsafe !== 'string') {
        return unsafe;
    }

    return unsafe
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
