<?php

require __DIR__ . '/../../lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('error' => 'Метод не поддерживается'), 405);
}

$period = isset($_POST['period']) ? $_POST['period'] : '';
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    json_out(array('error' => 'Неверный месяц'), 400);
}

try {
    json_out(billing_apply_period($period));
} catch (DomainException $exception) {
    json_out(array('error' => $exception->getMessage()), 422);
}
