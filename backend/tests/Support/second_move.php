<?php

// The second change in BookingLifecycleTest: two changes to the same booking at once.
// Usage: php second_move.php <bookingId> <status>
// Prints 200 when the move was saved, or the error status (409 if it is no longer allowed).

declare(strict_types=1);

use GrandGala\Services\BookingLifecycle;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

try {
    Db::transaction(function () use ($argv) {
        BookingLifecycle::move((int) $argv[1], $argv[2], null, '[test] second change');
    });
    echo 200;
} catch (HttpError $error) {
    echo $error->status();
}
