<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;

final class BillingTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM invoice_lines; DELETE FROM invoices; DELETE FROM cargo; DELETE FROM trips; DELETE FROM clients;');
    }

    #[TestDox('Стоимость рейса делится между клиентами пропорционально общему объёму')]
    public function testCalcShareSplitsCostByTotalClientVolume(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addTrip(1, '2026-09-01 10:00', 1001.00);
        $this->addCargo(1, 1, 1, 1000, 1);
        $this->addCargo(2, 1, 1, 2000, 2);
        $this->addCargo(3, 1, 2, 100, 7);

        $shares = calc_share(1);

        self::assertEqualsWithDelta(3, $shares[1]['volume_m3'], 0.001);
        self::assertEqualsWithDelta(0.3, $shares[1]['share'], 0.00001);
        self::assertEqualsWithDelta(300.30, $shares[1]['amount'], 0.001);
        self::assertEqualsWithDelta(0.7, $shares[2]['share'], 0.00001);
        self::assertEqualsWithDelta(700.70, $shares[2]['amount'], 0.001);
    }

    #[TestDox('Расчёт прекращается, если в рейсе нет положительного объёма')]
    public function testCalcShareFailsWhenTripHasNoPositiveVolume(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addTrip(1, '2026-09-01 10:00', 1000.00);
        $this->addCargo(1, 1, 1, 100, null);
        $this->addCargo(2, 1, 2, 100, 0);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('не указан положительный объём');
        calc_share(1);
    }

    #[TestDox('Расчёт прекращается для рейса без грузов')]
    public function testCalcShareFailsForTripWithoutCargo(): void
    {
        $this->addTrip(1, '2026-09-01 10:00', 1000.00);

        $this->expectException(DomainException::class);
        calc_share(1);
    }

    #[TestDox('Остаточные копейки распределяются точно и одинаково при повторном расчёте')]
    public function testCalcShareDistributesRoundingCentsDeterministically(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addClient(3, 'Клиент В');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 10, 1);
        $this->addCargo(2, 1, 2, 10, 1);
        $this->addCargo(3, 1, 3, 10, 1);

        $firstCalculation = calc_share(1);
        $secondCalculation = calc_share(1);

        self::assertSame($firstCalculation, $secondCalculation);
        self::assertEqualsWithDelta(33.34, $firstCalculation[1]['amount'], 0.001);
        self::assertEqualsWithDelta(33.33, $firstCalculation[2]['amount'], 0.001);
        self::assertEqualsWithDelta(33.33, $firstCalculation[3]['amount'], 0.001);
        self::assertEqualsWithDelta(100.00, array_sum(array_column($firstCalculation, 'amount')), 0.001);
    }

    #[TestDox('Грузы без объёма получают нулевую долю, а стоимость распределяется полностью')]
    public function testCalcShareExcludesZeroAndMissingVolumeFromDistribution(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addClient(3, 'Клиент В');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 100, 2);
        $this->addCargo(2, 1, 2, 100, 0);
        $this->addCargo(3, 1, 3, 100, null);

        $shares = calc_share(1);

        self::assertEqualsWithDelta(100.00, $shares[1]['amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $shares[2]['amount'], 0.001);
        self::assertEqualsWithDelta(0.00, $shares[3]['amount'], 0.001);
        self::assertEqualsWithDelta(100.00, array_sum(array_column($shares, 'amount')), 0.001);
    }

    #[TestDox('Расчёт прекращается при отрицательном объёме груза')]
    public function testCalcShareFailsWhenCargoHasNegativeVolume(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 10, -1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('не может быть отрицательным');
        calc_share(1);
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
        $this->addCargo(1, 1, 1, 10, 1);
        $this->addCargo(2, 1, 2, 30, 3);
        $this->addCargo(3, 2, 1, 10, 1);
        $this->addCargo(4, 3, 3, 10, 1);
        $this->addCargo(5, 4, 1, 10, 1);

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

    #[TestDox('Сумма строк счетов по рейсу совпадает со стоимостью до копейки')]
    public function testBillingKeepsTripCostExactInInvoiceLines(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addClient(3, 'Клиент В');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 10, 1);
        $this->addCargo(2, 1, 2, 10, 1);
        $this->addCargo(3, 1, 3, 10, 1);

        billing_run_period('2026-09');

        self::assertEqualsWithDelta(100.00, (float) db()->query('SELECT SUM(amount) FROM invoice_lines WHERE trip_id = 1')->fetchColumn(), 0.001);
        self::assertEqualsWithDelta(33.34, (float) db()->query("SELECT total FROM invoices WHERE client_id = 1 AND period = '2026-09'")->fetchColumn(), 0.001);
    }

    #[TestDox('Пересчёт счета заменяет начисления и сохраняет служебные даты')]
    public function testBillingRecalculatesChangedAmountsAndPreservesInvoiceDates(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 10, 1);
        billing_run_period('2026-09');

        $invoiceId = (int) db()->query("SELECT id FROM invoices WHERE client_id = 1 AND period = '2026-09'")->fetchColumn();
        db()->exec("UPDATE invoices SET status = 'sent', sent_at = '2026-10-01 09:00', exported_at = '2026-10-02 10:00' WHERE id = $invoiceId");
        db()->exec('UPDATE trips SET cost = 175.55 WHERE id = 1');

        self::assertSame(array('created' => 0, 'updated' => 1), billing_run_period('2026-09'));

        $invoice = db()->query("SELECT status, sent_at, exported_at, total FROM invoices WHERE id = $invoiceId")->fetch();
        self::assertSame('sent', $invoice['status']);
        self::assertSame('2026-10-01 09:00', $invoice['sent_at']);
        self::assertSame('2026-10-02 10:00', $invoice['exported_at']);
        self::assertEqualsWithDelta(175.55, (float)$invoice['total'], 0.001);
        self::assertEqualsWithDelta(175.55, (float) db()->query("SELECT amount FROM invoice_lines WHERE invoice_id = $invoiceId")->fetchColumn(), 0.001);
    }

    #[TestDox('Месяц без подходящих рейсов не создаёт счета')]
    public function testBillingDoesNotCreateInvoicesWhenThereAreNoEligibleTrips(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addTrip(1, '2026-10-01 10:00', 100.00);
        $this->addCargo(1, 1, 1, 10, 1);

        self::assertSame(array('created' => 0, 'updated' => 0), billing_run_period('2026-09'));
        self::assertSame(0, (int) db()->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
    }

    #[TestDox('Ошибка объёма откатывает формирование всех счетов за месяц')]
    public function testBillingRollsBackAllInvoicesWhenTripHasNoPositiveVolume(): void
    {
        $this->addClient(1, 'Клиент А');
        $this->addClient(2, 'Клиент Б');
        $this->addTrip(1, '2026-09-01 10:00', 100.00);
        $this->addTrip(2, '2026-09-02 10:00', 200.00);
        $this->addCargo(1, 1, 1, 10, 1);
        $this->addCargo(2, 2, 2, 10, null);

        $this->expectException(DomainException::class);
        try {
            billing_run_period('2026-09');
        } finally {
            self::assertSame(0, (int) db()->query('SELECT COUNT(*) FROM invoices')->fetchColumn());
            self::assertSame(0, (int) db()->query('SELECT COUNT(*) FROM invoice_lines')->fetchColumn());
        }
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

    private function addCargo(int $id, int $tripId, int $clientId, float $weight, ?float $volume): void
    {
        $statement = db()->prepare('INSERT INTO cargo (id, trip_id, client_id, weight_kg, volume_m3) VALUES (?, ?, ?, ?, ?)');
        $statement->execute(array($id, $tripId, $clientId, $weight, $volume));
    }
}
