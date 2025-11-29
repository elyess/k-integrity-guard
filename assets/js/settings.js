(function ($) {
    'use strict';

    $(function () {
        if (typeof window.kigSettings === 'undefined') {
            return;
        }

        function runChecksumRequest($btn, $status, $spinner, payload) {
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(window.kigSettings.l10n.working);

            $.post(window.kigSettings.ajaxUrl, payload)
                .done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.status) {
                        $status.text(resp.data.status);
                    } else {
                        $status.text(window.kigSettings.l10n.error);
                    }
                })
                .fail(function () {
                    $status.text(window.kigSettings.l10n.error);
                })
                .always(function () {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
        }

        $(document).on('click', '.wpig-generate', function () {
            var $btn = $(this);
            var file = $btn.data('plugin');
            var $row = $btn.closest('tr');
            var $status = $row.find('.wpig-status');
            var $spin = $row.find('.spinner');

            runChecksumRequest($btn, $status, $spin, {
                action: 'kig_generate_checksum',
                nonce: window.kigSettings.nonce,
                plugin_file: file
            });
        });

        $(document).on('click', '.wpig-theme-generate', function () {
            var $btn = $(this);
            var theme = $btn.data('theme');
            var $row = $btn.closest('tr');
            var $status = $row.find('.wpig-theme-status');
            var $spin = $row.find('.spinner');

            runChecksumRequest($btn, $status, $spin, {
                action: 'kig_generate_theme_checksum',
                nonce: window.kigSettings.nonce,
                stylesheet: theme
            });
        });
    });
})(jQuery);
