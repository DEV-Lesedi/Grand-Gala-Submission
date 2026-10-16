<?php

// Second guest in ReservationTest, reserving the same night in its own process.
// Usage: php second_guest.php <spaceId> <checkIn> <checkOut>
// Prints the status it got: 200 (booked) or 409 (taken).

declare(strict_types=1);

use GrandGala\Services\Availability;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

// $argv[0] is the script name; the values come after it
$spaceId = $argv[1];
$checkIn = $argv[2];
$checkOut = $argv[3];

try {
    // The space read pins MySQL's snapshot; the check must still see the first guest's booking.
    Availability::reserve([(int) $spaceId], $checkIn, $checkOut, 'stay', function () {
        return null;
    });
    echo 200;
} catch (HttpError $error) {
    echo $error->status();
}
