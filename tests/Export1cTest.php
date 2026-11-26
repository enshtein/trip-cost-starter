<?php

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class Export1cTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM invoice_lines; DELETE FROM invoices; DELETE FROM cargo; DELETE FROM trips; DELETE FROM clients;');
        db()->exec("INSERT INTO clients (id, name, inn) VALUES (1, 'Клиент А', '1234567890')");
        db()->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES (1, 'РЕЙС-1', '2026-09-01 10:00', 100, 'delivered')");
        db()->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES (2, 'РЕЙС-2', '2026-09-02 10:00', 200, 'delivered')");
    }

    #[TestDox('Для выгрузки отбираются только отправленные и ещё не выгруженные счета')]
    public function testExportSelectsOnlySentUnexportedInvoices(): void
    {
        $this->addInvoice(1, 'sent', null);
        $this->addInvoice(2, 'draft', null);
        $this->addInvoice(3, 'sent', '2026-10-01 10:00');
        db()->exec('INSERT INTO invoice_lines (invoice_id, trip_id, amount) VALUES (1, 2, 80), (1, 1, 20)');

        $invoices = export_1c_invoices();

        self::assertCount(1, $invoices);
        self::assertSame(1, $invoices[0]['id']);
        self::assertSame('Клиент А', $invoices[0]['client_name']);
        self::assertSame('1234567890', $invoices[0]['inn']);
        self::assertSame(array(
            array('amount' => 80.0, 'number' => 'РЕЙС-2'),
            array('amount' => 20.0, 'number' => 'РЕЙС-1'),
        ), export_1c_invoice_lines(1));
    }

    #[TestDox('После выгрузки отмечаются только переданные счета')]
    public function testExportMarksOnlyProvidedInvoicesAsExported(): void
    {
        $this->addInvoice(1, 'sent', null);
        $this->addInvoice(2, 'sent', null);

        self::assertSame(1, mark_invoices_exported(array(1, 1, 0)));
        self::assertNotEmpty(db()->query('SELECT exported_at FROM invoices WHERE id = 1')->fetchColumn());
        self::assertFalse((bool) db()->query('SELECT exported_at FROM invoices WHERE id = 2')->fetchColumn());
        self::assertSame(0, mark_invoices_exported(array()));
    }

    private function addInvoice(int $id, string $status, ?string $exportedAt): void
    {
        $statement = db()->prepare('INSERT INTO invoices (id, client_id, period, status, total, exported_at) VALUES (?, 1, ?, ?, ?, ?)');
        $statement->execute(array($id, '2026-09', $status, $id * 100, $exportedAt));
    }
}
