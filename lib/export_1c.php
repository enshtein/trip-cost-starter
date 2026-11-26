<?php

/**
 * Счета, которые нужно передать в 1С: отправленные и ещё не выгруженные.
 */
function export_1c_invoices()
{
    return db()->query("SELECT i.*, cl.name AS client_name, cl.inn
        FROM invoices i
        JOIN clients cl ON cl.id = i.client_id
        WHERE i.status = 'sent' AND i.exported_at IS NULL
        ORDER BY i.id")->fetchAll();
}

/**
 * Строки счёта для выгрузки в 1С в порядке их создания.
 */
function export_1c_invoice_lines($invoice_id)
{
    $invoice_id = (int)$invoice_id;
    return db()->query("SELECT l.amount, t.number
        FROM invoice_lines l
        JOIN trips t ON t.id = l.trip_id
        WHERE l.invoice_id = $invoice_id
        ORDER BY l.id")->fetchAll();
}

/**
 * Отметить переданные в 1С счета. Возвращает количество обновлённых счетов.
 */
function mark_invoices_exported($invoice_ids)
{
    $ids = array_values(array_unique(array_map('intval', $invoice_ids)));
    $ids = array_filter($ids, function ($id) {
        return $id > 0;
    });
    if (!$ids) {
        return 0;
    }

    db()->exec("UPDATE invoices SET exported_at = datetime('now', 'localtime') WHERE id IN (" . implode(',', $ids) . ")");
    return count($ids);
}
