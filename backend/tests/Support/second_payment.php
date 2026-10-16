<?php

// Second payment notice in TicketsTwoAtOnceTest: the same PayFast notice twice at once.
// Usage: php second_payment.php <orderReference> <paymentId>
// Prints 200 when the hook finished, or the error status.

declare(strict_types=1);

use GrandGala\Services\Tickets;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

try {
    // amountDue() pins the snapshot; onPaid must still see the tickets the first notice issued.
    Db::transaction(function () use ($argv) {
        Tickets::amountDue($argv[1]);
        Tickets::onPaid($argv[1], (int) $argv[2]);
    });
    echo 200;
} catch (HttpError $error) {
    echo $error->status();
}
