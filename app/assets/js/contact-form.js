(function() {
    'use strict';

    document.addEventListener('click', function(e) {
        if (e.target.closest('[data-action="goBack"]')) {
            history.back();
        }
    });

    $('form[name="contactform"]').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('button[type="submit"]');
        var originalHtml = $btn.html();
        var apiUrl = $form.data('api-url');

        if (!apiUrl) {
            console.error('[contact-form] Missing data-api-url attribute on form');
            window.usError('Configuration error — please refresh and try again.');
            return;
        }

        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Sending...');

        var data = {};
        $form.serializeArray().forEach(function(item) {
            data[item.name] = item.value;
        });

        new ElanRegistryAPI().post(apiUrl, data)
            .then(function(response) {
                window.usSuccess(response.message);
                $form[0].reset();
            }).catch(function(error) {
                console.error('Contact form submission failed:', error);
                window.usError(error.message || 'Failed to send.');
            }).finally(function() {
                $btn.prop('disabled', false).html(originalHtml);
            });
    });
}());
