$(function () {
    var $table = $('#trip-split');
    var $tbody = $table.find('tbody');
    var $message = $('#trip-split-message');
    var $clientsTotal = $table.find('[data-role="clients-total"]');
    var $tripCost = $table.find('[data-role="trip-cost"]');

    function showError(message) {
        $tbody.html('<tr><td colspan="6" class="empty">Распределение недоступно</td></tr>');
        $clientsTotal.text('—');
        $tripCost.text('—');
        $message.text(message).prop('hidden', false);
    }

    $.getJSON('api/trip_split.php', { trip_id: $table.data('trip-id') })
        .done(function (data) {
            var total = 0;
            $tbody.empty();

            $.each(data.clients, function (index, client) {
                total += Number(client.amount);
                $tbody.append(
                    '<tr>' +
                    '<td>' + esc(client.name) + '</td>' +
                    '<td class="r">' + num(client.cargo_count) + '</td>' +
                    '<td class="r">' + num(client.weight_kg) + '</td>' +
                    '<td class="r">' + num(client.volume_m3) + '</td>' +
                    '<td class="r">' + num(client.share * 100) + '</td>' +
                    '<td class="r">' + money(client.amount) + '</td>' +
                    '</tr>'
                );
            });

            $clientsTotal.text(money(total));
            $tripCost.text(money(data.cost));
        })
        .fail(function (jqXHR) {
            var error = jqXHR.responseJSON && jqXHR.responseJSON.error
                ? jqXHR.responseJSON.error
                : 'Не удалось загрузить распределение стоимости.';
            showError(error);
        });
});
