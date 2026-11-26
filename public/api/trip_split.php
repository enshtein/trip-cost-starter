<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$trip_id = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;
try {
    $data = trip_split_data($trip_id);
    if ($data === null) {
        json_out(array('error' => 'Рейс не найден'), 404);
    }
    json_out($data);
} catch (DomainException $exception) {
    json_out(array('error' => $exception->getMessage()), 422);
}
