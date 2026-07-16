/* exported testEmailConfiguration */

$(document).ready(function() {
    const settingsEndpoint = window.elanUrlRoot + 'app/api/admin/process-settings.php';
    const api = new ElanRegistryAPI();

    function postSetting(elem, value) {
        api.post(settingsEndpoint, {
            field: elem.attr('id'),
            value: value,
            desc:  elem.data('desc') || elem.attr('id'),
            table: elem.data('table') || 'settings',
        })
        .then(function(r) { showNotification(r.message || 'Setting updated successfully', 'success'); })
        .catch(function(e) {
            if (!(e instanceof ApiCancelledError)) {
                showNotification(e.message || 'Error updating setting', 'danger');
            }
        });
    }

    $('.toggle').change(function() { postSetting($(this), $(this).prop('checked')); });
    $('.ajxnum').change(function() { postSetting($(this), $(this).val()); });
    $('.ajxtxt').change(function() { postSetting($(this), $(this).val()); });
});

// Test Email Configuration
function testEmailConfiguration() {
    const emails = $('#elan_admin_emails').val();

    if (!emails.trim()) {
        showNotification('Please enter at least one admin email address.', 'danger');
        return;
    }

    // Validate email format
    const emailList = emails.split(',');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    let invalidEmails = [];

    emailList.forEach(email => {
        const trimmedEmail = email.trim();
        if (trimmedEmail && !emailRegex.test(trimmedEmail)) {
            invalidEmails.push(trimmedEmail);
        }
    });

    const btn = $('button[onclick="testEmailConfiguration()"]');
    const originalText = btn.html();

    if (invalidEmails.length > 0) {
        btn.html('<i class="fas fa-exclamation-triangle text-warning"></i> Invalid Format').removeClass('btn-outline-primary').addClass('btn-warning');
        showNotification('Invalid email format detected: ' + invalidEmails.join(', '), 'danger');
        setTimeout(() => {
            btn.html(originalText).removeClass('btn-warning').addClass('btn-outline-primary');
        }, 3000);
    } else {
        btn.html('<i class="fas fa-check text-success"></i> Format Valid').removeClass('btn-outline-primary').addClass('btn-success');
        setTimeout(() => {
            btn.html(originalText).removeClass('btn-success').addClass('btn-outline-primary');
        }, 3000);
    }
}
