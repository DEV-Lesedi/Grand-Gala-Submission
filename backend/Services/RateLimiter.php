<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\RateLimit;
use GrandGala\Utils\HttpError;

/*
 * At most $limit hits from one address per window, for one scope.
 * Over the limit is a 429, so passwords and codes cannot be guessed in bulk.
 */
class RateLimiter
{
    // The limits used by the controllers: [scope => [requests, seconds]]
    public const LIMITS = [
        'guest_login' => [10, 60],
        'staff_login' => [10, 60],
        'register' => [5, 600],
        'forgot_password' => [5, 600],
        'promotion_validate' => [30, 60],
    ];

    // Counts this request and throws 429 once it is over the scope's limit.
    public static function check(string $scope, string $clientIp): void
    {
        if (!isset(self::LIMITS[$scope])) {
            throw new HttpError(500, 'Unknown rate limit ' . $scope);
        }

        self::enforce($scope, $clientIp, self::LIMITS[$scope][0], self::LIMITS[$scope][1]);
    }

    public static function enforce(string $scope, string $clientIp, int $limit, int $windowSeconds): void
    {
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $scope) !== 1 || filter_var($clientIp, FILTER_VALIDATE_IP) === false
            || $limit < 1 || $windowSeconds < 1) {
            throw new HttpError(500, 'Rate limit configuration is invalid');
        }

        $hits = RateLimit::hit(self::bucket($scope, $clientIp), $windowSeconds);
        if ($hits > $limit) {
            throw new HttpError(429, 'Too many requests. Please try again later.');
        }
    }

    // Resets this scope and address. Tests use it so the suite never hits a limit.
    public static function forget(string $scope, string $clientIp): void
    {
        RateLimit::forget(self::bucket($scope, $clientIp));
    }

    // The address is hashed, so the table never stores who called
    private static function bucket(string $scope, string $clientIp): string
    {
        return hash('sha256', $scope . '|' . $clientIp);
    }
}
