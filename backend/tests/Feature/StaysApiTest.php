<?php

declare(strict_types=1);

namespace GrandGala\Tests\Feature;

use GrandGala\Tests\Support\CreatesTestAccounts;
use GrandGala\Utils\Db;

// Pricing and booking a stay over HTTP. The rules are in Unit/StayBookingsTest.php.
class StaysApiTest extends FeatureTestCase
{
    use CreatesTestAccounts;

    public function testAnyoneCanPriceAStayAndASignedInGuestBooksIt(): void
    {
        $villaId = $this->createSpace(['sp_is_accommodation' => 1, 'sp_capacity' => 4]);
        $this->addRate($villaId, 'per_night', 2800, '2026-01-01', null);
        $stay = ['spaceId' => $villaId, 'checkIn' => '2026-12-12', 'checkOut' => '2026-12-14', 'guests' => 2];
        $guest = $this->createAccount('customer');

        $price = $this->send('POST', '/api/stays/price', $stay);
        $booked = $this->sendAuthed('POST', '/api/bookings', $this->tokenFor($guest['id']), $stay);
        $statement = Db::pdo()->prepare('SELECT b_id FROM booking WHERE b_reference = ?');
        $statement->execute([$booked['body']['reference'] ?? '']);
        $bookingId = $statement->fetchColumn();
        if ($bookingId !== false) {
            $this->track('booking', 'b_id', (int) $bookingId);
        }

        self::assertSame(200, $price['status']);
        self::assertSame(2, $price['body']['nights']);
        self::assertSame(5600, $price['body']['total']);
        self::assertSame(201, $booked['status']);
        self::assertSame('pending_payment', $booked['body']['status']);
        self::assertSame(5600, $booked['body']['total']);
    }

    public function testBadInputIsA422(): void
    {
        $response = $this->send('POST', '/api/stays/price', ['spaceId' => 'x']);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => 'Space must be a positive integer'], $response['body']);
    }
}
