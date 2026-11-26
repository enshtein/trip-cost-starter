<?php

/**
 * Доли клиентов в стоимости рейса.
 *
 * Стоимость рейса делится между клиентами пропорционально объёму их груза.
 * Грузы без положительного объёма не участвуют в распределении. Остаток от
 * округления распределяется по наибольшим дробным частям, затем по ID клиента.
 * Возвращает [client_id => [name, weight_kg, volume_m3, share, amount]],
 * где share — доля от 0 до 1.
 *
 * @param int $trip_id
 * @return array<int, array{name: string, cargo_count: int, weight_kg: float, volume_m3: float, share: float, amount: float}>
 * @throws DomainException Если рейс нельзя распределить по объёму.
 */
function calc_share($trip_id)
{
    $trip_id = (int)$trip_id;
    $trip = db()->query("SELECT cost FROM trips WHERE id = $trip_id")->fetch();
    $invalid_volume_count = (int)db()->query("SELECT COUNT(*) FROM cargo WHERE trip_id = $trip_id AND volume_m3 < 0")->fetchColumn();
    if ($invalid_volume_count > 0) {
        throw new DomainException('Невозможно распределить стоимость рейса: объём груза не может быть отрицательным.');
    }
    $rows = db()->query("SELECT c.client_id, cl.name, COUNT(*) AS cargo_count, SUM(c.weight_kg) AS weight_kg,
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
            'cargo_count' => (int)$row['cargo_count'],
            'weight_kg' => $row['weight_kg'],
            'volume_m3' => $row['volume_m3'],
            'share' => $share,
            'amount' => $row['amount_cents'] / 100,
        );
    }

    return $result;
}

/**
 * Расчётный план счетов за месяц без записи в базу.
 *
 * @param string $period Месяц в формате ГГГГ-ММ.
 * @return array<string, mixed>
 */
function billing_period_plan($period)
{
    $db = db();
    $eligible_trips = billing_eligible_trips($period);
    $excluded_trip_ids = array_fill_keys($eligible_trips['excluded_ids'], true);
    $trips_statement = $db->prepare("SELECT DISTINCT t.id FROM trips t
        JOIN cargo c ON c.trip_id = t.id
        WHERE c.client_id = ?
          AND t.departure_date LIKE ?
          AND t.status <> 'cancelled'
        ORDER BY t.departure_date");
    $invoice_statement = $db->prepare('SELECT id, total FROM invoices WHERE client_id = ? AND period = ?');
    $invoice_lines_statement = $db->prepare('SELECT trip_id, amount FROM invoice_lines WHERE invoice_id = ?');
    $clients = array();
    $total_before_cents = 0;
    $total_after_cents = 0;

    foreach (get_clients() as $client) {
        $trips_statement->execute(array($client['id'], $period . '%'));
        $trip_ids = $trips_statement->fetchAll(PDO::FETCH_COLUMN);
        if (!$trip_ids) {
            continue;
        }

        $invoice_statement->execute(array($client['id'], $period));
        $invoice = $invoice_statement->fetch();
        $existing_lines = array();
        if ($invoice) {
            $invoice_lines_statement->execute(array($invoice['id']));
            foreach ($invoice_lines_statement->fetchAll() as $line) {
                $existing_lines[(int)$line['trip_id']] = (int)round((float)$line['amount'] * 100);
            }
        }
        $lines = array();
        $after_cents = 0;
        foreach ($trip_ids as $trip_id) {
            $trip_id = (int)$trip_id;
            if (isset($excluded_trip_ids[$trip_id])) {
                if (isset($existing_lines[$trip_id])) {
                    $lines[] = array('trip_id' => $trip_id, 'amount_cents' => $existing_lines[$trip_id]);
                    $after_cents += $existing_lines[$trip_id];
                }
                continue;
            }
            $shares = calc_share($trip_id);
            $amount_cents = (int)round($shares[$client['id']]['amount'] * 100);
            $lines[] = array('trip_id' => $trip_id, 'amount_cents' => $amount_cents);
            $after_cents += $amount_cents;
        }

        if (!$invoice && !$lines) {
            continue;
        }

        $before_cents = $invoice ? (int)round((float)$invoice['total'] * 100) : 0;
        $clients[] = array(
            'client_id' => (int)$client['id'],
            'name' => $client['name'],
            'invoice_id' => $invoice ? (int)$invoice['id'] : null,
            'before_cents' => $before_cents,
            'after_cents' => $after_cents,
            'lines' => $lines,
        );
        $total_before_cents += $before_cents;
        $total_after_cents += $after_cents;
    }

    return array(
        'period' => $period,
        'clients' => $clients,
        'total_before_cents' => $total_before_cents,
        'total_after_cents' => $total_after_cents,
        'excluded_trips' => $eligible_trips['excluded_trips'],
    );
}

/**
 * Рейсы месяца, которые можно пересчитать по объёму.
 *
 * Рейс без единого положительного объёма исключается из пересчёта: его
 * существующие строки счёта сохраняются, а новый счёт только ради него не
 * создаётся. Это не скрывает ошибку отрицательного объёма — она по-прежнему
 * прерывает расчёт в calc_share().
 *
 * @param string $period Месяц в формате ГГГГ-ММ.
 * @return array{excluded_ids: array<int, int>, excluded_trips: array<int, array<string, mixed>>}
 */
function billing_eligible_trips($period)
{
    $statement = db()->prepare("SELECT t.id, t.number,
        EXISTS(SELECT 1 FROM cargo c WHERE c.trip_id = t.id AND c.volume_m3 > 0) AS has_positive_volume
        FROM trips t
        WHERE t.departure_date LIKE ? AND t.status <> 'cancelled'
        ORDER BY t.departure_date, t.id");
    $statement->execute(array($period . '%'));

    $excluded_ids = array();
    $excluded_trips = array();
    foreach ($statement->fetchAll() as $trip) {
        if ((int)$trip['has_positive_volume'] === 1) {
            continue;
        }
        $excluded_ids[] = (int)$trip['id'];
        $excluded_trips[] = array(
            'trip_id' => (int)$trip['id'],
            'number' => $trip['number'],
            'reason' => 'Нет грузов с положительным объёмом',
        );
    }

    return array('excluded_ids' => $excluded_ids, 'excluded_trips' => $excluded_trips);
}

/**
 * Данные предпросмотра счетов в формате API.
 *
 * @param string $period
 * @return array<string, mixed>
 */
function billing_preview_period($period)
{
    $plan = billing_period_plan($period);
    $clients = array();
    foreach ($plan['clients'] as $client) {
        $clients[] = array(
            'client_id' => $client['client_id'],
            'name' => $client['name'],
            'invoice_id' => $client['invoice_id'],
            'before' => billing_format_cents($client['before_cents']),
            'after' => billing_format_cents($client['after_cents']),
            'diff' => billing_format_cents($client['after_cents'] - $client['before_cents']),
        );
    }

    return array(
        'period' => $plan['period'],
        'clients' => $clients,
        'total_before' => billing_format_cents($plan['total_before_cents']),
        'total_after' => billing_format_cents($plan['total_after_cents']),
        'excluded_trips' => $plan['excluded_trips'],
    );
}

/**
 * Применить расчётный план счетов за месяц.
 *
 * @param string $period
 * @return array<string, mixed>
 */
function billing_apply_period($period)
{
    $db = db();
    $plan = billing_period_plan($period);
    $result = array(
        'period' => $period,
        'created' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'clients' => array(),
        'excluded_trips' => $plan['excluded_trips'],
    );

    $db->beginTransaction();
    try {
        foreach ($plan['clients'] as $client) {
            $invoice_id = $client['invoice_id'];
            $action = 'unchanged';
            if ($invoice_id === null) {
                $statement = $db->prepare("INSERT INTO invoices (client_id, period, status, total, created_at)
                    VALUES (?, ?, 'draft', ?, datetime('now', 'localtime'))");
                $statement->execute(array($client['client_id'], $period, $client['after_cents'] / 100));
                $invoice_id = (int)$db->lastInsertId();
                $action = 'created';
                $result['created']++;
            } elseif (!billing_invoice_matches_plan($invoice_id, $client)) {
                $db->prepare('DELETE FROM invoice_lines WHERE invoice_id = ?')->execute(array($invoice_id));
                $db->prepare('UPDATE invoices SET total = ? WHERE id = ?')->execute(array($client['after_cents'] / 100, $invoice_id));
                $action = 'updated';
                $result['updated']++;
            } else {
                $result['unchanged']++;
            }

            if ($action !== 'unchanged') {
                $line_statement = $db->prepare('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (?, ?, ?)');
                foreach ($client['lines'] as $line) {
                    $line_statement->execute(array($invoice_id, $line['trip_id'], $line['amount_cents'] / 100));
                }
            }

            $result['clients'][] = array(
                'client_id' => $client['client_id'],
                'name' => $client['name'],
                'invoice_id' => $invoice_id,
                'action' => $action,
                'before' => billing_format_cents($client['before_cents']),
                'after' => billing_format_cents($client['after_cents']),
            );
        }
        $db->commit();
    } catch (Throwable $exception) {
        $db->rollBack();
        throw $exception;
    }

    return $result;
}

/**
 * Совпадают ли сохранённые строки и итог счёта с расчётным планом.
 *
 * @param int $invoice_id
 * @param array<string, mixed> $client_plan
 * @return bool
 */
function billing_invoice_matches_plan($invoice_id, $client_plan)
{
    $invoice_statement = db()->prepare('SELECT total FROM invoices WHERE id = ?');
    $invoice_statement->execute(array($invoice_id));
    $invoice = $invoice_statement->fetch();
    if (!$invoice || (int)round((float)$invoice['total'] * 100) !== $client_plan['after_cents']) {
        return false;
    }

    $lines_statement = db()->prepare('SELECT trip_id, amount FROM invoice_lines WHERE invoice_id = ? ORDER BY id');
    $lines_statement->execute(array($invoice_id));
    $lines = $lines_statement->fetchAll();
    if (count($lines) !== count($client_plan['lines'])) {
        return false;
    }
    foreach ($lines as $index => $line) {
        $expected = $client_plan['lines'][$index];
        if ((int)$line['trip_id'] !== $expected['trip_id'] || (int)round((float)$line['amount'] * 100) !== $expected['amount_cents']) {
            return false;
        }
    }

    return true;
}

/** @param int $cents @return string */
function billing_format_cents($cents)
{
    return number_format($cents / 100, 2, '.', '');
}

/**
 * Обратная совместимость для существующей формы формирования счетов.
 *
 * @param string $period
 * @return array{created: int, updated: int}
 */
function billing_run_period($period)
{
    $result = billing_apply_period($period);
    return array('created' => $result['created'], 'updated' => $result['updated']);
}
