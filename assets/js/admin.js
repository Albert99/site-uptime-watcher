(function ($) {
    'use strict';

    $(document).ready(function () {
        var $selectAll = $('#suw-select-all');
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

        // Selección masiva (solo filas visibles)
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

        // Filtrado por pills (Todos, Operativos, Caídos, Verificando)
        $('.suw-pill-tab').on('click', function () {
            $('.suw-pill-tab').removeClass('is-active');
            $(this).addClass('is-active');

            var filter = $(this).data('filter');
            $('.suw-row-card').each(function () {
                var $row = $(this);
                $row.toggle(filter === 'all' || $row.attr('data-status') === filter);
            });

            $selectAll.prop('checked', false);
            $('.suw-site-checkbox').prop('checked', false);
            updateDeleteButton();
        });

        // Confirmación borrado múltiple
        $bulkForm.on('submit', function (e) {
            var count = $('.suw-site-checkbox:checked').length;
            if (!count) {
                e.preventDefault();
                return;
            }
            if (!window.confirm('¿Deseas eliminar ' + count + ' web(s) de la monitorización?')) {
                e.preventDefault();
            }
        });

        // Borrado rápido individual: una sola confirmación y envío nativo
        // (form.submit() nativo no dispara el handler de arriba, evitando el doble confirm).
        $(document).on('click', '.suw-delete-single', function () {
            var url = String($(this).data('url'));
            if (!window.confirm('¿Eliminar "' + url + '" de la monitorización?')) {
                return;
            }

            $('.suw-site-checkbox').prop('checked', false);
            $('.suw-site-checkbox').filter(function () {
                return this.value === url;
            }).prop('checked', true);

            $bulkForm[0].submit();
        });

        // Ordenación (en el navegador, sin recargar). Se recuerda la última elección.
        var $stack = $('.suw-list-stack');
        var $sort = $('#suw-sort');
        var collator = (typeof Intl !== 'undefined' && Intl.Collator)
            ? new Intl.Collator('es', { sensitivity: 'base', numeric: true })
            : { compare: function (a, b) { return a < b ? -1 : (a > b ? 1 : 0); } };

        function applySort(mode) {
            var cards = $stack.children('.suw-row-card').get();

            cards.sort(function (a, b) {
                var hostA = a.getAttribute('data-host') || '';
                var hostB = b.getAttribute('data-host') || '';

                if (mode === 'name-asc') {
                    return collator.compare(hostA, hostB);
                }
                if (mode === 'name-desc') {
                    return collator.compare(hostB, hostA);
                }
                return Number(a.getAttribute('data-order')) - Number(b.getAttribute('data-order'));
            });

            $.each(cards, function (_, card) {
                $stack.append(card);
            });
        }

        if ($sort.length && $stack.length) {
            var saved = 'status';
            try {
                saved = window.localStorage.getItem('suw_sort') || 'status';
            } catch (err) {
                saved = 'status';
            }

            if ($sort.find('option[value="' + saved + '"]').length && saved !== 'status') {
                $sort.val(saved);
                applySort(saved);
            }

            $sort.on('change', function () {
                var mode = $(this).val();
                applySort(mode);
                try {
                    window.localStorage.setItem('suw_sort', mode);
                } catch (err) { /* sin almacenamiento: no pasa nada */ }
            });
        }

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
