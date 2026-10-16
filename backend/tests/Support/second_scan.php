<?php

// The second gate scanner in TicketsTwoAtOnceTest: two gates scan the same ticket at once.
// Usage: php second_scan.php <eventId> <code> <YYYY-MM-DD HH:MM>
// Prints 200 (let in) or the error status, e.g. 409 (already used).

declare(strict_types=1);

use GrandGala\Services\Clock;
use GrandGala\Services\Tickets;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

// Scans only work on the event day, so this process uses the same frozen time as the test
Clock::freezeAt($argv[3]);

try {
    // The attendee list pins the snapshot; the scan must still see the first gate's use.
    Db::transaction(function () use ($argv) {
        Tickets::attendees((int) $argv[1], []);
        Tickets::scan((int) $argv[1], ['code' => $argv[2]]);
    });
    echo 200;
} catch (HttpError $error) {
    echo $error->status();
}
