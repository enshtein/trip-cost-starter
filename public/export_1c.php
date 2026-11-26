<?php
// Выгрузка отправленных счетов для 1С.
// Формат файла согласован с бухгалтерией: его разбирает обработка загрузки счетов в 1С. Не менять без согласования.
require __DIR__ . '/../lib/bootstrap.php';

$invoices = export_1c_invoices();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="invoices_' . date('Ymd_His') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, array('invoice', 'inn', 'client', 'period', 'trip', 'amount'), ';', '"', '');

$ids = array();
foreach ($invoices as $invoice) {
    $lines = export_1c_invoice_lines($invoice['id']);
    foreach ($lines as $line) {
        fputcsv($out, array(
            $invoice['id'],
            $invoice['inn'],
            $invoice['client_name'],
            $invoice['period'],
            $line['number'],
            number_format($line['amount'], 2, ',', ''),
        ), ';', '"', '');
    }
    $ids[] = (int)$invoice['id'];
}
fclose($out);

mark_invoices_exported($ids);
