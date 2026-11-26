<?php

/**
 * Доли клиентов в стоимости рейса.
 *
 * Стоимость рейса делится между клиентами пропорционально объёму их груза.
 * Грузы без положительного объёма не участвуют в распределении. Остаток от
 * округления распределяется по наибольшим дробным частям, затем по ID клиента.
 * Возвращает [client_id => [name, weight_kg, volume_m3, share, amount]],
 * где share — доля от 0 до 1.
 */
function calc_share($trip_id)
{
    $trip_id = (int)$trip_id;
    $trip = db()->query("SELECT cost FROM trips WHERE id = $trip_id")->fetch();
    $invalid_volume_count = (int)db()->query("SELECT COUNT(*) FROM cargo WHERE trip_id = $trip_id AND volume_m3 < 0")->fetchColumn();
    if ($invalid_volume_count > 0) {
        throw new DomainException('Невозможно распределить стоимость рейса: объём груза не может быть отрицательным.');
    }
    $rows = db()->query("SELECT c.client_id, cl.name, SUM(c.weight_kg) AS weight_kg,
            SUM(COALESCE(c.volume_m3, 0)) AS volume_m3
        FROM cargo c
        JOIN clients cl ON cl.id = c.client_id
        WHERE c.trip_id = $trip_id
        GROUP BY c.client_id, cl.name")->fetchAll();

    $total_volume = 0;
    foreach ($rows as $row) {
        $total_volume += (float)$row['volume_m3'];
    }
    if ($total_volume <= 0) {
        throw new DomainException('Невозможно распределить стоимость рейса: у грузов не указан положительный объём.');
    }

    $cost_cents = (int)round((float)$trip['cost'] * 100);
    $distributed_cents = 0;
    foreach ($rows as &$row) {
        $volume = (float)$row['volume_m3'];
        $raw_cents = $volume > 0 ? $cost_cents * $volume / $total_volume : 0;
        $row['amount_cents'] = (int)floor($raw_cents);
        $row['remainder'] = $raw_cents - $row['amount_cents'];
        $distributed_cents += $row['amount_cents'];
    }
    unset($row);

    usort($rows, function ($a, $b) {
        $remainder_comparison = $b['remainder'] <=> $a['remainder'];
        if ($remainder_comparison !== 0) {
            return $remainder_comparison;
        }
        return $a['client_id'] <=> $b['client_id'];
    });
    $remaining_cents = $cost_cents - $distributed_cents;
    for ($index = 0; $index < $remaining_cents; $index++) {
        $rows[$index]['amount_cents']++;
    }

    $result = array();
    foreach ($rows as $row) {
        $volume = (float)$row['volume_m3'];
        $share = $volume > 0 ? $volume / $total_volume : 0;
        $result[$row['client_id']] = array(
            'name' => $row['name'],
            'weight_kg' => $row['weight_kg'],
            'volume_m3' => $row['volume_m3'],
            'share' => $share,
            'amount' => $row['amount_cents'] / 100,
        );
    }

    return $result;
}

/**
 * Сформировать счета клиентов за месяц.
 *
 * Для каждого клиента берутся рейсы месяца (по дате отправления, кроме отменённых) и его доля в каждом из них.
 * Если счёт за месяц уже есть, его строки пересчитываются заново.
 */
function billing_run_period($period)
{
    $db = db();
    $created = 0;
    $updated = 0;

    $db->beginTransaction();
    try {
        foreach (get_clients() as $client) {
            $trips = $db->query("SELECT DISTINCT t.id FROM trips t
                JOIN cargo c ON c.trip_id = t.id
                WHERE c.client_id = {$client['id']}
                  AND t.departure_date LIKE '{$period}%'
                  AND t.status <> 'cancelled'
                ORDER BY t.departure_date")->fetchAll(PDO::FETCH_COLUMN);
            if (!$trips) {
                continue;
            }

            $invoice = $db->query("SELECT * FROM invoices WHERE client_id = {$client['id']} AND period = '{$period}'")->fetch();
            if ($invoice) {
                $invoice_id = $invoice['id'];
                $db->exec("DELETE FROM invoice_lines WHERE invoice_id = $invoice_id");
                $updated++;
            } else {
                $db->exec("INSERT INTO invoices (client_id, period, status, total, created_at)
                    VALUES ({$client['id']}, '{$period}', 'draft', 0, datetime('now', 'localtime'))");
                $invoice_id = $db->lastInsertId();
                $created++;
            }

            $total = 0;
            foreach ($trips as $trip_id) {
                $shares = calc_share($trip_id);
                $amount = $shares[$client['id']]['amount'];
                $db->exec("INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES ($invoice_id, $trip_id, $amount)");
                $total += $amount;
            }
            $db->exec("UPDATE invoices SET total = $total WHERE id = $invoice_id");
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return array('created' => $created, 'updated' => $updated);
}
