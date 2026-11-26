<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class BillingTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM invoice_lines; DELETE FROM invoices; DELETE FROM cargo; DELETE FROM trips; DELETE FROM clients;');
    }

    #[TestDox('Стоимость рейса делится между клиентами пропорционально общему весу')]
    public function testCalcShareSplitsCostByTotalClientWeight(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addTrip(1, '2026-09-01 10:00', 1001.00);
        $this->addCargo(1, 1, 1, 100);
        $this->addCargo(2, 1, 1, 200);
        $this->addCargo(3, 1, 2, 700);

        $shares = calc_share(1);

        self::assertEqualsWithDelta(300, $shares[1]['weight_kg'], 0.001);
        self::assertEqualsWithDelta(0.3, $shares[1]['share'], 0.00001);
        self::assertEqualsWithDelta(300.30, $shares[1]['amount'], 0.001);
        self::assertEqualsWithDelta(0.7, $shares[2]['share'], 0.00001);
        self::assertEqualsWithDelta(700.70, $shares[2]['amount'], 0.001);
    }

    #[TestDox('При нулевом общем весе начисления равны нулю')]
    public function testCalcShareReturnsZeroAmountsWhenTotalWeightIsZero(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addTrip(1, '2026-09-01 10:00', 1000.00);
        $this->addCargo(1, 1, 1, 0);
        $this->addCargo(2, 1, 2, 0);

        $shares = calc_share(1);

        self::assertSame(0, $shares[1]['share']);
        self::assertEqualsWithDelta(0.0, $shares[1]['amount'], 0.001);
        self::assertSame(0, $shares[2]['share']);
        self::assertEqualsWithDelta(0.0, $shares[2]['amount'], 0.001);
    }

    #[TestDox('Счета создаются только по подходящим рейсам и пересчитываются без дублирования')]
    public function testBillingCreatesInvoicesOnlyForEligibleTripsAndRecalculatesThem(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addClient(3, 'Клиент В');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addTrip(2, '2026-09-15 10:00', 50.00);
        $this->addTrip(3, '2026-09-20 10:00', 500.00, 'cancelled');
        $this->addTrip(4, '2026-10-01 10:00', 200.00);
        $this->addCargo(1, 1, 1, 10);
        $this->addCargo(2, 1, 2, 30);
        $this->addCargo(3, 2, 1, 10);
        $this->addCargo(4, 3, 3, 10);
        $this->addCargo(5, 4, 1, 10);

        self::assertSame(array('created' => 2, 'updated' => 0), billing_run_period('2026-09'));

        $invoices = db()->query("SELECT client_id, total FROM invoices WHERE period = '2026-09' ORDER BY client_id")->fetchAll();
        self::assertSame(array(
            array('client_id' => 1, 'total' => 75.0),
            array('client_id' => 2, 'total' => 75.0),
        ), $invoices);

        $firstInvoiceId = (int) db()->query("SELECT id FROM invoices WHERE client_id = 1 AND period = '2026-09'")->fetchColumn();
        db()->exec("UPDATE invoices SET status = 'sent' WHERE id = $firstInvoiceId");

        self::assertSame(array('created' => 0, 'updated' => 2), billing_run_period('2026-09'));
        self::assertSame(2, (int) db()->query("SELECT COUNT(*) FROM invoice_lines WHERE invoice_id = $firstInvoiceId")->fetchColumn());
        self::assertSame('sent', db()->query("SELECT status FROM invoices WHERE id = $firstInvoiceId")->fetchColumn());
        self::assertEqualsWithDelta(75.0, (float) db()->query("SELECT total FROM invoices WHERE id = $firstInvoiceId")->fetchColumn(), 0.001);
    }

    private function addClient(int $id, string $name): void
    {
        $statement = db()->prepare('INSERT INTO clients (id, name) VALUES (?, ?)');
        $statement->execute(array($id, $name));
    }

    private function addTrip(int $id, string $departureDate, float $cost, string $status = 'delivered'): void
    {
        $statement = db()->prepare('INSERT INTO trips (id, number, departure_date, cost, status) VALUES (?, ?, ?, ?, ?)');
        $statement->execute(array($id, 'РЕЙС-' . $id, $departureDate, $cost, $status));
    }

    private function addCargo(int $id, int $tripId, int $clientId, float $weight): void
    {
        $statement = db()->prepare('INSERT INTO cargo (id, trip_id, client_id, weight_kg) VALUES (?, ?, ?, ?)');
        $statement->execute(array($id, $tripId, $clientId, $weight));
    }
}
