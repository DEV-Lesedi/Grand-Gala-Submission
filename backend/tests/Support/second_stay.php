<?php

// Second guest in StayBookingsTest, booking the same nights in its own process.
// Usage: php second_stay.php <spaceId> <checkIn> <checkOut> <userId>
// Prints 201 and the reference, or the error status (409 when the dates are taken).

declare(strict_types=1);

use GrandGala\Services\StayBookings;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

try {
    // Pricing reads pin the snapshot; the check must still see the first guest's stay.
    $booking = StayBookings::create([
        'spaceId' => (int) $argv[1],
        'checkIn' => $argv[2],
        'checkOut' => $argv[3],
        'guests' => 2,
    ], (int) $argv[4]);
    echo '201 ' . $booking['reference'];
} catch (HttpError $error) {
    echo $error->status();
}
