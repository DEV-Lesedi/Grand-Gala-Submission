<?php

declare(strict_types=1);

namespace GrandGala\Middleware;

// Allows the frontend (CORS_ORIGIN) to call the API from a browser. CORS_ORIGIN can list several,
// comma separated, e.g. "https://your-frontend.example,http://localhost:5173".
class Cors
{
    public static function apply(): void
    {
        $listed = $_ENV['CORS_ORIGIN'] ?? 'http://localhost:5173';
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        header('Access-Control-Allow-Origin: ' . self::allowedOrigin($requestOrigin, $listed));
        header('Vary: Origin');
        // X-Ticket-Access is for guest ticket orders.
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Ticket-Access');
        // PUT: saving an event's ticket tiers
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }

    // The origin to send back: the caller's, if it's one of the listed ones. Otherwise the first listed one,
    // which the browser won't match, so it blocks the page (a browser only accepts its own origin back).
    public static function allowedOrigin(string $requestOrigin, string $listed): string
    {
        $origins = [];
        foreach (explode(',', $listed) as $origin) {
            if (trim($origin) !== '') {
                $origins[] = rtrim(trim($origin), '/');
            }
        }
        if (in_array($requestOrigin, $origins, true)) {
            return $requestOrigin;
        }

        return $origins[0] ?? '';
    }
}
