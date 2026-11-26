<?php
require __DIR__ . '/../lib/bootstrap.php';

$period = isset($_POST['period']) ? $_POST['period'] : '';
if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
    die('Неверный месяц');
}

try {
    $result = billing_apply_period($period);
} catch (DomainException $exception) {
    header('Location: invoices.php?period=' . urlencode($period) . '&msg=' . urlencode($exception->getMessage()));
    exit;
}

$msg = 'Счета за ' . $period . ': создано ' . $result['created'] . ', обновлено ' . $result['updated'] . ', без изменений ' . $result['unchanged'];
header('Location: invoices.php?period=' . urlencode($period) . '&msg=' . urlencode($msg));
