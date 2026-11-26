$(function () {
    var $form = $('#billing-form');
    var $period = $('#billing-period');
    var $results = $('#billing-results');
    var $table = $('#billing-preview');
    var $tbody = $table.find('tbody');
    var $message = $('#billing-message');
    var $exclusions = $('#billing-exclusions');
    var $apply = $('#billing-apply');
    var preview = null;

    function showMessage(text, isError) {
        $message.text(text).toggleClass('error', Boolean(isError)).prop('hidden', false);
    }

    function signedMoney(value) {
        var amount = Number(value);
        return (amount > 0 ? '+' : '') + money(amount);
    }

    function render(data) {
        $tbody.empty();
        $.each(data.clients, function (index, client) {
            $tbody.append(
                '<tr>' +
                '<td>' + esc(client.name) + '</td>' +
                '<td class="r">' + money(client.before) + '</td>' +
                '<td class="r">' + money(client.after) + '</td>' +
                '<td class="r">' + signedMoney(client.diff) + '</td>' +
                '</tr>'
            );
        });
        if (!data.clients.length) {
            $tbody.append('<tr><td colspan="4" class="empty">Нет рейсов для формирования счетов</td></tr>');
        }
        $table.find('[data-role="total-before"]').text(money(data.total_before));
        $table.find('[data-role="total-after"]').text(money(data.total_after));
        $table.find('[data-role="total-diff"]').text(signedMoney(Number(data.total_after) - Number(data.total_before)));
        if (data.excluded_trips.length) {
            $exclusions.text(
                'Не пересчитаны рейсы без положительного объёма: ' +
                $.map(data.excluded_trips, function (trip) { return trip.number; }).join(', ') + '.'
            ).prop('hidden', false);
        } else {
            $exclusions.prop('hidden', true);
        }
        $results.prop('hidden', false);
        $apply.prop('disabled', !data.clients.length);
    }

    function loadPreview(keepMessage) {
        if (!keepMessage) {
            $message.prop('hidden', true);
        }
        $apply.prop('disabled', true);
        $.getJSON('api/billing_preview.php', { period: $period.val() })
            .done(function (data) {
                preview = data;
                render(data);
            })
            .fail(function (jqXHR) {
                preview = null;
                $results.prop('hidden', true);
                showMessage(
                    jqXHR.responseJSON && jqXHR.responseJSON.error
                        ? jqXHR.responseJSON.error
                        : 'Не удалось получить предпросмотр счетов.',
                    true
                );
            });
    }

    $form.on('submit', function (event) {
        event.preventDefault();
        loadPreview();
    });

    $apply.on('click', function () {
        if (!preview) {
            return;
        }
        if (!window.confirm('Применить расчёт счетов за ' + preview.period + '?')) {
            return;
        }

        $apply.prop('disabled', true);
        $.ajax({
            url: 'api/billing_apply.php',
            method: 'POST',
            dataType: 'json',
            data: { period: preview.period },
        })
            .done(function (data) {
                showMessage(
                    'Счета за ' + data.period + ': создано ' + data.created +
                    ', обновлено ' + data.updated + ', без изменений ' + data.unchanged + '.',
                    false
                );
                loadPreview(true);
            })
            .fail(function (jqXHR) {
                showMessage(
                    jqXHR.responseJSON && jqXHR.responseJSON.error
                        ? jqXHR.responseJSON.error
                        : 'Не удалось применить расчёт счетов.',
                    true
                );
                $apply.prop('disabled', false);
            });
    });
});
