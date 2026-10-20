(function ($) {
    'use strict';

    $(document).ready(function () {
        var $form = $('#suw-manual-check-form');
        var $btn = $('#suw-btn-check');

        if ($form.length && $btn.length) {
            $form.on('submit', function () {
                $btn.prop('disabled', true);
                $btn.find('.dashicons').addClass('suw-spin');
                $btn.find('.suw-btn-text').text('Comprobando...');
            });
        }
    });
})(jQuery);