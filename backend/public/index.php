<?php

// Front controller: settings, CORS, then the route. Errors go back as JSON.

declare(strict_types=1);

use GrandGala\Middleware\Authentication\Auth;
use GrandGala\Middleware\Cors;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Request;
use GrandGala\Utils\Response;

// php -S: let PHP serve real files under public/ itself.
if (PHP_SAPI === 'cli-server') {
    $requested = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($requested) && $requested !== '/' && is_file(__DIR__ . $requested)) {
        return false;
    }
}

require dirname(__DIR__) . '/config/bootstrap.php';

Cors::apply();

// OPTIONS is the CORS preflight. The headers above are enough.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $request = Request::fromGlobals();
    Auth::attach($request);
    $router = require dirname(__DIR__) . '/Routes/api.php';
    $router->dispatch($request);
} catch (HttpError $error) {
    // Expected errors (404, 422) return their message.
    Response::error($error->getMessage(), $error->status());
} catch (Throwable $error) {
    // Hide unexpected errors unless APP_DEBUG=1, so guests never see database details.
    $message = 'Something went wrong';
    if (($_ENV['APP_DEBUG'] ?? '0') === '1') {
        $message = $error->getMessage();
    }
    Response::error($message, 500);
}
