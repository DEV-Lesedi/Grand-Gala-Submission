<?php

// Second copy of one PayFast notice in PaymentsTwoAtOnceTest.
// Usage: php second_itn.php '<notification as JSON>'
// Prints the status Payments answered with (e.g. already_processed), or the error status.

declare(strict_types=1);

use GrandGala\Services\PaymentGateway\PayFast;
use GrandGala\Services\PaymentGateway\Payments;
use GrandGala\Utils\HttpError;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

// The same test settings as PaymentsTestCase, and no real call to PayFast
$_ENV['PAYFAST_MERCHANT_ID'] = '10000100';
$_ENV['PAYFAST_PASSPHRASE'] = 'test phrase';
$_ENV['PAYFAST_VALID_IPS'] = '127.0.0.1';
PayFast::fakeValidationAnswer('VALID');

try {
    $result = Payments::processPayFastItn(json_decode($argv[1], true), '127.0.0.1');
    echo $result['status'];
} catch (HttpError $error) {
    echo $error->status();
}
