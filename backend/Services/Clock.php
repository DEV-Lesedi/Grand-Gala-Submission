<?php

declare(strict_types=1);

namespace GrandGala\Services;

use DateTimeImmutable;
use DateTimeZone;
use GrandGala\Utils\Dates;
use InvalidArgumentException;

// Venue time (Johannesburg). Use Clock, not date(): tests freeze it.
class Clock
{
    // The frozen date in tests, or null in real use
    private static ?string $frozen = null;

    // The frozen time of day in tests. Midday unless a test sets another with freezeAt().
    private static string $frozenTime = '12:00:00';

    // Today's date, e.g. '2026-09-04'
    public static function today(): string
    {
        if (self::$frozen !== null) {
            return self::$frozen;
        }

        return self::venueNow()->format('Y-m-d');
    }

    // Now as YYYY-MM-DD HH:MM:SS. Tests use midday unless freezeAt() set a time.
    public static function now(): string
    {
        if (self::$frozen !== null) {
            return self::$frozen . ' ' . self::$frozenTime;
        }

        return self::venueNow()->format('Y-m-d H:i:s');
    }

    // Fixes "today" to $date. Pass null to go back to the real date.
    public static function freeze(?string $date): void
    {
        if ($date !== null && !Dates::isValid($date)) {
            throw new InvalidArgumentException('Frozen date must be YYYY-MM-DD');
        }

        self::$frozen = $date;
        self::$frozenTime = '12:00:00';
    }

    // Freeze date and time (YYYY-MM-DD HH:MM) for holds, sale windows and gate scans.
    public static function freezeAt(string $dateTime): void
    {
        $parts = explode(' ', $dateTime);
        $isTime = count($parts) === 2 && preg_match('/^\d{2}:\d{2}$/', $parts[1]) === 1;
        if (!$isTime || !Dates::isValid($parts[0])) {
            throw new InvalidArgumentException('Frozen time must be YYYY-MM-DD HH:MM');
        }

        self::$frozen = $parts[0];
        self::$frozenTime = $parts[1] . ':00';
    }

    private static function venueNow(): DateTimeImmutable
    {
        $zone = new DateTimeZone($_ENV['APP_TIMEZONE'] ?? 'Africa/Johannesburg');

        return new DateTimeImmutable('now', $zone);
    }
}
