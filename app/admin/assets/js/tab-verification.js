/**
 * Verification System tab — feature switch control.
 *
 * The checkbox is optimistic-free: it is reverted to its prior state unless the
 * server confirms the write. The `disabled` attribute rendered server-side is a
 * UI affordance only; the endpoint re-checks permissions and the Brevo gate.
 */
(function () {
    'use strict';

    var ENDPOINT = window.elanUrlRoot + 'app/api/admin/verification-toggle.php';

    var toggle   = document.getElementById('verificationEnabledSwitch');
    var feedback = document.getElementById('verificationToggleFeedback');

    if (!toggle) return;

    function showFeedback(message, variant) {
        if (!feedback) return;
        feedback.innerHTML = '';
        if (!message) return;

        var alert = document.createElement('div');
        alert.className = 'alert alert-' + variant + ' d-flex align-items-center mb-0';

        var icon = document.createElement('i');
        icon.className = 'fas me-2 ' + (variant === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle');
        alert.appendChild(icon);

        var text = document.createElement('span');
        text.textContent = message;
        alert.appendChild(text);

        feedback.appendChild(alert);
    }

    toggle.addEventListener('change', function () {
        var desired  = toggle.checked;
        var previous = !desired;

        toggle.disabled = true;
        showFeedback('', 'info');

        new ElanRegistryAPI()
            .post(ENDPOINT, { enabled: desired ? 1 : 0 })
            .then(function (result) {
                showFeedback(
                    result.message || (desired ? 'Verification enabled.' : 'Verification disabled.'),
                    'success'
                );
                toggle.disabled = false;
            })
            .catch(function (error) {
                // Never leave the checkbox showing a state the server did not accept.
                toggle.checked  = previous;
                toggle.disabled = false;
                showFeedback(
                    error && error.message ? error.message : 'Could not change the verification setting.',
                    'danger'
                );
            });
    });
})();
