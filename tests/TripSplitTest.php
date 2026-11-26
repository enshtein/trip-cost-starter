<?php

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class TripSplitTest extends TestCase
{
    protected function setUp(): void
    {
        db()->exec('DELETE FROM invoice_lines; DELETE FROM invoices; DELETE FROM cargo; DELETE FROM trips; DELETE FROM clients;');
    }

    #[TestDox('API-данные рейса содержат объёмные доли и сведения о грузах клиентов')]
    public function testTripSplitDataReturnsExpectedPayload(): void
    {
        db()->exec("INSERT INTO clients (id, name) VALUES (1, 'Клиент А'), (2, 'Клиент Б')");
        db()->exec("INSERT INTO trips (id, number, departure_date, cost, status) VALUES (11, 'МСК-1111', '2026-10-24 15:30', 48750.50, 'delivered')");
        db()->exec('INSERT INTO cargo (id, trip_id, client_id, weight_kg, volume_m3) VALUES
            (1, 11, 1, 70, 4.5),
            (2, 11, 1, 50, 3.5),
            (3, 11, 2, 2000, 2.0)');

        $data = trip_split_data(11);

        self::assertSame(11, $data['trip_id']);
        self::assertSame('МСК-1111', $data['number']);
        self::assertSame('48750.50', $data['cost']);
        self::assertSame(array(
            array(
                'client_id' => 1,
                'name' => 'Клиент А',
                'cargo_count' => 2,
                'weight_kg' => 120.0,
                'volume_m3' => 8.0,
                'share' => 0.8,
                'amount' => '39000.40',
            ),
            array(
                'client_id' => 2,
                'name' => 'Клиент Б',
                'cargo_count' => 1,
                'weight_kg' => 2000.0,
                'volume_m3' => 2.0,
                'share' => 0.2,
                'amount' => '9750.10',
            ),
        ), $data['clients']);
    }

    #[TestDox('API-данные отсутствуют для несуществующего рейса')]
    public function testTripSplitDataReturnsNullForUnknownTrip(): void
    {
        self::assertNull(trip_split_data(999));
    }
}
