(function ($) {
    'use strict';

    const settings = $.extend({
        ajaxUrl: '',
        nonce: '',
        successText: 'Connexion réussie.',
        errorText: 'Erreur de connexion.',
        loadingText: '',
        testButtonSelector: '#genius-infast-test-connection',
        clientIdSelector: '#genius_infast_client_id',
        clientSecretSelector: '#genius_infast_client_secret',
        feedbackSelector: '#genius-infast-connection-status'
    }, window.GeniusInfastAdmin || {});

    function updateStatus(type, message) {
        const $status = $(settings.feedbackSelector);
        if (!$status.length) {
            return;
        }

        let icon = '';
        if ('success' === type) {
            icon = '<span class="dashicons dashicons-yes genius-infast-status--success"></span>';
        } else if ('error' === type) {
            icon = '<span class="dashicons dashicons-no-alt genius-infast-status--error"></span>';
        } else {
            icon = '<span class="dashicons dashicons-update"></span>';
        }

        $status
            .removeClass('genius-infast-status--success genius-infast-status--error genius-infast-status--loading is-visible')
            .addClass('genius-infast-status--' + type + ' is-visible')
            .html(icon + ' ' + (message || ''));
    }

    function disableButton($button, disabled) {
        $button.prop('disabled', disabled);
        if (disabled) {
            $button.addClass('disabled');
        } else {
            $button.removeClass('disabled');
        }
    }

    $(function () {
        const $button = $(settings.testButtonSelector);
        if (!$button.length) {
            return;
        }

        $button.on('click', function (event) {
            event.preventDefault();

            const $clientId = $(settings.clientIdSelector);
            const $clientSecret = $(settings.clientSecretSelector);

            updateStatus('loading', settings.loadingText || '');
            disableButton($button, true);

            $.ajax({
                url: settings.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'genius_infast_test_credentials',
                    nonce: settings.nonce,
                    client_id: $clientId.val(),
                    client_secret: $clientSecret.val(),
                },
            })
                .done(function (response) {
                    if (response.success) {
                        updateStatus('success', response.data && response.data.message ? response.data.message : settings.successText);
                    } else {
                        const message = response.data && response.data.message ? response.data.message : settings.errorText;
                        updateStatus('error', message);
                    }
                })
                .fail(function () {
                    updateStatus('error', settings.errorText);
                })
                .always(function () {
                    disableButton($button, false);
                });
        });
    });
})(jQuery);
