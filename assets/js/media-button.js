(function ($) {
    'use strict';

    function setStatus($status, text, state) {
        $status
            .removeClass('dgz-loading dgz-success dgz-error')
            .addClass('dgz-' + state)
            .text(text);
    }

    function refreshPreviews() {
        $('.attachment-preview img, .details-image, .thumbnail img').each(function () {
            var src = $(this).attr('src');
            if (!src) { return; }
            var base = src.split('?')[0];
            $(this).attr('src', base + '?t=' + Date.now());
        });
    }

    $(document).on('click', '.dgz-remove-btn', function (e) {
        e.preventDefault();
        var $btn     = $(this);
        var $wrap    = $btn.closest('.dgz-wrap');
        var $status  = $wrap.find('.dgz-status');
        var $restore = $wrap.find('.dgz-restore-btn');
        var id       = $wrap.data('attachment-id');
        var position = $wrap.find('.dgz-position-select').val() || 'bottom-right';

        if ($btn.prop('disabled')) { return; }

        $btn.prop('disabled', true);
        $restore.prop('disabled', true);
        setStatus($status, DGZ.i18n.processing, 'loading');

        $.post(DGZ.ajaxUrl, {
            action: 'dgz_remove_watermark',
            nonce: DGZ.nonce,
            attachment_id: id,
            position: position
        })
        .done(function (res) {
            if (res && res.success) {
                setStatus($status, DGZ.i18n.success, 'success');
                refreshPreviews();
                $restore.prop('disabled', false);
            } else {
                var msg = (res && res.data && res.data.message) || DGZ.i18n.error;
                setStatus($status, msg, 'error');
            }
        })
        .fail(function () {
            setStatus($status, DGZ.i18n.error, 'error');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.dgz-restore-btn', function (e) {
        e.preventDefault();
        var $btn    = $(this);
        var $wrap   = $btn.closest('.dgz-wrap');
        var $status = $wrap.find('.dgz-status');
        var id      = $wrap.data('attachment-id');

        if ($btn.prop('disabled')) { return; }

        $btn.prop('disabled', true);
        setStatus($status, DGZ.i18n.processing, 'loading');

        $.post(DGZ.ajaxUrl, {
            action: 'dgz_restore_original',
            nonce: DGZ.nonce,
            attachment_id: id
        })
        .done(function (res) {
            if (res && res.success) {
                setStatus($status, DGZ.i18n.restored, 'success');
                refreshPreviews();
            } else {
                var msg = (res && res.data && res.data.message) || DGZ.i18n.error;
                setStatus($status, msg, 'error');
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            setStatus($status, DGZ.i18n.error, 'error');
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
