<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\SpaceRate;
use GrandGala\Utils\Dates;
use GrandGala\Utils\HttpError;

/*
 * Which price a space has on a date. A season beats a normal price.
 * Same type: the later start wins. Same start day: the one added last wins.
 */
class RateSchedule
{
    // The rate on $date, or null if the space has no price that day
    public static function rateOn(int $spaceId, string $basis, string $date): ?array
    {
        self::checkBasis($basis);
        if (!Dates::isValid($date)) {
            throw new HttpError(422, 'Date must be YYYY-MM-DD');
        }

        return self::winnerOn(SpaceRate::forSpaceBasis($spaceId, $basis), $date);
    }

    // Today's prices for several spaces, keyed by space id.
    public static function currentRates(array $spaceIds): array
    {
        $today = Clock::today();
        $ratesBySpace = SpaceRate::activeForSpaces($spaceIds);

        $current = [];
        foreach ($spaceIds as $spaceId) {
            $current[$spaceId] = [];
            $rates = $ratesBySpace[$spaceId] ?? [];

            foreach (['per_night', 'per_event', 'per_hour', 'per_head'] as $basis) {
                // Keep only the rates of this basis (still oldest first)
                $ofThisKind = [];
                foreach ($rates as $rate) {
                    if ($rate['basis'] === $basis) {
                        $ofThisKind[] = $rate;
                    }
                }

                $winner = self::winnerOn($ofThisKind, $today);
                if ($winner !== null) {
                    $current[$spaceId][] = ['basis' => $basis, 'label' => $winner['label'], 'price' => $winner['price']];
                }
            }
        }

        return $current;
    }

    // Winning rate. Same basis, oldest first, so later in the list was added later.
    private static function winnerOn(array $rates, string $date): ?array
    {
        $winner = null;
        foreach ($rates as $rate) {
            if (!self::coversDate($rate, $date)) {
                continue;
            }
            if ($winner === null || self::beats($rate, $winner)) {
                $winner = $rate;
            }
        }

        return $winner;
    }

    // True if the rate is switched on and $date is between its start and end dates
    private static function coversDate(array $rate, string $date): bool
    {
        if (!$rate['isActive']) {
            return false;
        }
        if ($date < $rate['validFrom']) {
            return false;
        }
        if ($rate['validTo'] !== null && $date > $rate['validTo']) {
            return false;
        }

        return true;
    }

    // True if $rate should replace the current winner
    private static function beats(array $rate, array $winner): bool
    {
        $rateIsSeasonal = self::isSeasonal($rate);
        $winnerIsSeasonal = self::isSeasonal($winner);

        // Rule 1: seasonal beats normal
        if ($rateIsSeasonal && !$winnerIsSeasonal) {
            return true;
        }
        if (!$rateIsSeasonal && $winnerIsSeasonal) {
            return false;
        }

        // Same type: the later start wins. On a tie, the one added later wins.
        return $rate['validFrom'] >= $winner['validFrom'];
    }

    // A season has an end date. A normal price closed by a later change still counts as normal.
    private static function isSeasonal(array $rate): bool
    {
        return $rate['validTo'] !== null && $rate['closedFor'] === null;
    }

    // Throws a 422 unless $basis is one of the four kinds of price. Rates uses it too.
    public static function checkBasis(string $basis): void
    {
        $allowed = ['per_night', 'per_event', 'per_hour', 'per_head'];
        if (!in_array($basis, $allowed)) {
            throw new HttpError(422, 'Unknown rate basis');
        }
    }
}
