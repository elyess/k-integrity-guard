(function ($) {
    'use strict';

    $(function () {
        if (typeof window.wpigSettings === 'undefined') {
            return;
        }

        function runChecksumRequest($btn, $status, $spinner, payload) {
            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.text(window.wpigSettings.l10n.working);

            $.post(window.wpigSettings.ajaxUrl, payload)
                .done(function (resp) {
                    if (resp && resp.success && resp.data && resp.data.status) {
                        $status.text(resp.data.status);
                    } else {
                        $status.text(window.wpigSettings.l10n.error);
                    }
                })
                .fail(function () {
                    $status.text(window.wpigSettings.l10n.error);
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
                action: 'wpig_generate_checksum',
                nonce: window.wpigSettings.nonce,
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
                action: 'wpig_generate_theme_checksum',
                nonce: window.wpigSettings.nonce,
                stylesheet: theme
            });
        });
    });
})(jQuery);
