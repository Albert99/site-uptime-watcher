(function ($) {
    'use strict';

    $(document).ready(function () {
        var $selectAll = $('#suw-select-all');
        var $checkboxes = $('.suw-site-checkbox');
        var $btnDelete = $('#suw-btn-delete-selected');
        var $bulkForm = $('#suw-bulk-form');

        // Modal de importación masiva
        var $bulkModal = $('#suw-bulk-modal');
        var $btnOpenBulk = $('#suw-btn-open-bulk');
        var $btnCloseBulk = $('#suw-btn-close-bulk, #suw-btn-cancel-bulk, .suw-modal-backdrop');

        $btnOpenBulk.on('click', function () {
            $bulkModal.fadeIn(150);
            $bulkModal.find('textarea').focus();
        });

        $btnCloseBulk.on('click', function () {
            $bulkModal.fadeOut(150);
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $bulkModal.is(':visible')) {
                $bulkModal.fadeOut(150);
            }
        });

        // Actualizar botón de eliminar
        function updateDeleteButton() {
            var selectedCount = $('.suw-site-checkbox:checked').length;
            if (selectedCount > 0) {
                $btnDelete.prop('disabled', false);
                $btnDelete.find('span').text('Eliminar (' + selectedCount + ')');
            } else {
                $btnDelete.prop('disabled', true);
                $btnDelete.find('span').text('Eliminar seleccionadas');
            }
        }

        // Selección masiva
        $selectAll.on('change', function () {
            var isChecked = $(this).is(':checked');
            $('.suw-row-card:visible .suw-site-checkbox').prop('checked', isChecked);
            updateDeleteButton();
        });

        // Selección individual
        $(document).on('change', '.suw-site-checkbox', function () {
            var visibleBoxes = $('.suw-row-card:visible .suw-site-checkbox');
            var checkedBoxes = $('.suw-row-card:visible .suw-site-checkbox:checked');
            $selectAll.prop('checked', visibleBoxes.length > 0 && visibleBoxes.length === checkedBoxes.length);
            updateDeleteButton();
        });

        // Filtrado por Pills (Todos, Operativos, Caídos)
        $('.suw-pill-tab').on('click', function () {
            $('.suw-pill-tab').removeClass('is-active');
            $(this).addClass('is-active');

            var filter = $(this).data('filter');
            if (filter === 'all') {
                $('.suw-row-card').show();
            } else if (filter === 'up') {
                $('.suw-row-card[data-status="up"]').show();
                $('.suw-row-card[data-status="down"]').hide();
            } else if (filter === 'down') {
                $('.suw-row-card[data-status="down"]').show();
                $('.suw-row-card[data-status="up"]').hide();
            }

            $selectAll.prop('checked', false);
            $checkboxes.prop('checked', false);
            updateDeleteButton();
        });

        // Confirmación borrado múltiple
        $bulkForm.on('submit', function (e) {
            var count = $('.suw-site-checkbox:checked').length;
            if (!count) {
                e.preventDefault();
                return false;
            }
            if (!confirm('¿Deseas eliminar ' + count + ' web(s) de la monitorización?')) {
                e.preventDefault();
                return false;
            }
        });

        // Borrado rápido individual
        $(document).on('click', '.suw-delete-single', function () {
            var url = $(this).data('url');
            if (confirm('¿Eliminar "' + url + '" de la monitorización?')) {
                $checkboxes.prop('checked', false);
                $('.suw-site-checkbox[value="' + url + '"]').prop('checked', true);
                $bulkForm.submit();
            }
        });

        // Animación comprobar ahora
        var $manualForm = $('#suw-manual-check-form');
        var $btnCheck = $('#suw-btn-check');

        if ($manualForm.length && $btnCheck.length) {
            $manualForm.on('submit', function () {
                $btnCheck.prop('disabled', true);
                $btnCheck.find('.suw-sync-target').addClass('suw-spin');
                $btnCheck.find('span').text('Comprobando...');
            });
        }
    });
})(jQuery);
