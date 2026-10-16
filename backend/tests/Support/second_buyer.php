<?php

// Second buyer in TicketsTwoAtOnceTest, in its own process and connection.
// Usage: php second_buyer.php <eventId> <tierId> <quantity> <userId>
// Prints the status it got: 201 (order placed) or the error status, e.g. 409 (sold out).

declare(strict_types=1);

use GrandGala\Services\Events;
use GrandGala\Services\Tickets;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

try {
    // publicDetail() pins the snapshot, so the seat count must still see the first order.
    Db::transaction(function () use ($argv) {
        Events::publicDetail((int) $argv[1]);
        // A signed-in buyer, so no receipt token (and no TICKET_ACCESS_SECRET) is needed
        Tickets::createOrder([
            'eventId' => (int) $argv[1],
            'name' => '[test] Second',
            'surname' => 'Buyer',
            'email' => 'second@example.com',
            'items' => [['tierId' => (int) $argv[2], 'quantity' => (int) $argv[3]]],
        ], (int) $argv[4]);
    });
    echo 201;
} catch (HttpError $error) {
    echo $error->status();
}
