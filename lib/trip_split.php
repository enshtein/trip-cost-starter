<?php

/**
 * Данные рейса и долей клиентов для API.
 * Возвращает null, если рейс не найден.
 */
function trip_split_data($trip_id)
{
    $trip_id = (int)$trip_id;
    $statement = db()->prepare('SELECT id, number, cost FROM trips WHERE id = ?');
    $statement->execute(array($trip_id));
    $trip = $statement->fetch();
    if (!$trip) {
        return null;
    }

    $clients = array();
    foreach (calc_share($trip_id) as $client_id => $share) {
        $clients[] = array(
            'client_id' => (int)$client_id,
            'name' => $share['name'],
            'cargo_count' => (int)$share['cargo_count'],
            'weight_kg' => (float)$share['weight_kg'],
            'volume_m3' => (float)$share['volume_m3'],
            'share' => (float)$share['share'],
            'amount' => number_format((float)$share['amount'], 2, '.', ''),
        );
    }

    return array(
        'trip_id' => (int)$trip['id'],
        'number' => $trip['number'],
        'cost' => number_format((float)$trip['cost'], 2, '.', ''),
        'clients' => $clients,
    );
}
