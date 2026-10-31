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

    // The switch is absent when the settings probe fails; the queue and
    // activity forms below must still bind.
    if (toggle) {
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
    }

    // Window/Show/Activity-window controls are plain GET forms: the browser
    // can submit them with no JS at all. The one gap is that a plain GET
    // submit reloads at the top of this long page, while the filter pills
    // above (server-rendered links) keep the reader's place with a URL
    // fragment. This reproduces that fragment for the two forms, without
    // changing how they build or submit their query string.
    [
        ['verificationQueueControls', 'verificationQueue'],
        ['verificationActivityControls', 'verificationRecentActivity']
    ].forEach(function (pair) {
        var form = document.getElementById(pair[0]);
        if (!form) return;

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var params = new URLSearchParams(new FormData(form));
            window.location.assign(form.action.split('#')[0] + '?' + params.toString() + '#' + pair[1]);
        });
    });
})();

/**
 * Verification System tab — Clear Block and Clear Opt-out confirm step (#2357).
 *
 * The row button opens the confirm modal. Confirm adds the hidden `command`
 * and `owner_requested=1` inputs to the row's owner-action form and submits
 * it. The server refuses a POST without `owner_requested=1`, so with no
 * script the action fails closed.
 */
(function () {
    'use strict';

    var modalEl   = document.getElementById('ownerRequestConfirmModal');
    var confirmEl = document.getElementById('ownerRequestConfirmButton');

    if (!modalEl || !confirmEl) return;

    var pending = null;

    function addHidden(form, name, value) {
        var input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = name;
        input.value = value;
        form.appendChild(input);
    }

    document.querySelectorAll('[data-owner-request-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = document.getElementById(button.getAttribute('form'));
            if (!form) return;

            pending = { form: form, command: button.getAttribute('data-owner-request-command') };
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        if (!confirmEl.disabled) pending = null;
    });

    confirmEl.addEventListener('click', function () {
        if (!pending) return;

        // Disabled so a double click cannot submit the form twice.
        confirmEl.disabled = true;
        addHidden(pending.form, 'command', pending.command);
        addHidden(pending.form, 'owner_requested', '1');
        pending.form.submit();
    });
})();
