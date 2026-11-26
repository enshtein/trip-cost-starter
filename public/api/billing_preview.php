<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$period = isset($_GET['period']) ? $_GET['period'] : '';
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    json_out(array('error' => 'Неверный месяц'), 400);
}

try {
    json_out(billing_preview_period($period));
} catch (DomainException $exception) {
    json_out(array('error' => $exception->getMessage()), 422);
}
