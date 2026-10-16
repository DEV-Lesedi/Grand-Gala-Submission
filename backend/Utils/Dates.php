<?php

declare(strict_types=1);

namespace GrandGala\Utils;

use DateTimeImmutable;

// Helpers for YYYY-MM-DD, the format the API uses.
class Dates
{
    // A real calendar date in YYYY-MM-DD. '2026-02-30' is false.
    public static function isValid(string $date): bool
    {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return false;
        }

        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    public static function check(string $date, string $label): void
    {
        if (!self::isValid($date)) {
            throw new HttpError(422, $label . ' date must be YYYY-MM-DD');
        }
    }

    // A negative $days moves backward.
    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify($days . ' day')->format('Y-m-d');
    }
}
