<?php

// Second tab in PasswordResetTwoAtOnceTest, using the same reset link in its own process.
// Usage: php second_reset.php <token> <new password>
// Prints the HTTP status it got: 200 if the password was changed, otherwise the error's status.

declare(strict_types=1);

use GrandGala\API\Auth\AuthController;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;

putenv('APP_ENV=testing');
require dirname(__DIR__, 2) . '/config/bootstrap.php';

// $argv[0] is the script name; the values come after it
$token = $argv[1];
$password = $argv[2];

ob_start();
try {
    (new AuthController())->resetPassword(new Request('POST', '/api/auth/reset-password', [], [
        'token' => $token,
        'password' => $password,
    ]));
    $status = 200;
} catch (HttpError $error) {
    $status = $error->status();
}
ob_end_clean();

echo $status;
