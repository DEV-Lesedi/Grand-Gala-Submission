<?php

// Second staff member in RatesTwoAtOnceTest, adding a price in its own process.
// Usage: php second_staff.php <spaceId> <validFrom> <price>
// Prints the new price's id.

declare(strict_types=1);

use GrandGala\Services\Rates;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

// $argv[0] is the script name; the values come after it
$spaceId = $argv[1];
$validFrom = $argv[2];
$price = $argv[3];

$rate = Rates::add((int) $spaceId, 'per_night', 'Standard', (float) $price, $validFrom, null);
echo $rate['id'];
