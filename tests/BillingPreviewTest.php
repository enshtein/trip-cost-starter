<?php

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class BillingPreviewTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM invoice_lines; DELETE FROM invoices; DELETE FROM cargo; DELETE FROM trips; DELETE FROM clients;');
        db()->exec("INSERT INTO clients (id, name) VALUES (1, 'Клиент А'), (2, 'Клиент Б')");
        db()->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES (1, 'РЕЙС-1', '2026-09-01 10:00', 100, 'delivered')");
        db()->exec('INSERT INTO cargo (id, trip_id, client_id, weight_kg, volume_m3) VALUES (1, 1, 1, 100, 1), (2, 1, 2, 100, 3)');
        db()->exec("INSERT INTO invoices (id, client_id, period, status, total) VALUES (10, 1, '2026-09', 'sent', 10)");
        db()->exec('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (10, 1, 10)');
    }

    #[TestDox('Предпросмотр показывает изменения счетов и не изменяет базу')]
    public function testPreviewShowsExpectedChangesWithoutWritingToDatabase(): void
    {
        $preview = billing_preview_period('2026-09');

        self::assertSame(array(
            'period' => '2026-09',
            'clients' => array(
                array('client_id' => 1, 'name' => 'Клиент А', 'invoice_id' => 10, 'before' => '10.00', 'after' => '25.00', 'diff' => '15.00'),
                array('client_id' => 2, 'name' => 'Клиент Б', 'invoice_id' => null, 'before' => '0.00', 'after' => '75.00', 'diff' => '75.00'),
            ),
            'total_before' => '10.00',
            'total_after' => '100.00',
        ), $preview);
        self::assertEqualsWithDelta(10.0, (float)db()->query('SELECT total FROM invoices WHERE id = 10')->fetchColumn(), 0.001);
        self::assertSame(1, (int)db()->query('SELECT COUNT(*) FROM invoice_lines')->fetchColumn());
    }

    #[TestDox('Применение создаёт и обновляет счета, а повторный вызов не меняет данные')]
    public function testApplyCreatesOrUpdatesInvoicesAndIsIdempotent(): void
    {
        $firstApply = billing_apply_period('2026-09');

        self::assertSame(1, $firstApply['created']);
        self::assertSame(1, $firstApply['updated']);
        self::assertSame(0, $firstApply['unchanged']);
        self::assertEqualsWithDelta(25.0, (float)db()->query("SELECT total FROM invoices WHERE client_id = 1 AND period = '2026-09'")->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(75.0, (float)db()->query("SELECT total FROM invoices WHERE client_id = 2 AND period = '2026-09'")->fetchColumn(), 0.001);
        self::assertSame('sent', db()->query('SELECT status FROM invoices WHERE id = 10')->fetchColumn());

        $secondApply = billing_apply_period('2026-09');

        self::assertSame(0, $secondApply['created']);
        self::assertSame(0, $secondApply['updated']);
        self::assertSame(2, $secondApply['unchanged']);
        self::assertSame(2, (int)db()->query('SELECT COUNT(*) FROM invoice_lines')->fetchColumn());
    }
}
