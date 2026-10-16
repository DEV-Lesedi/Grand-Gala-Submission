<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Space;
use GrandGala\Models\SpaceRate;
use GrandGala\Utils\Dates;
use GrandGala\Utils\Db;
use GrandGala\Utils\HttpError;
use GrandGala\Utils\Money;

/*
 * Prices are never edited, only added or switched off, so an old booking price stays traceable.
 * A normal price has no end date; a season has one and sits on top of the normal price.
 */
class Rates
{
    // null $validTo is a normal price: it closes the open one, and ends today's season.
    public static function add(int $spaceId, string $basis, string $label, float $price, string $validFrom, ?string $validTo): array
    {
        $isSeason = $validTo !== null;
        $label = self::checkNewRate($basis, $label, $price, $validFrom, $validTo);

        return Db::transaction(function () use ($spaceId, $basis, $label, $price, $validFrom, $validTo, $isSeason) {
            // One price change per space at a time, so two staff can't both close the same price
            if (!Space::lock($spaceId)) {
                throw new HttpError(404, 'Space not found');
            }

            // Open normal prices, found before insert so the new row is not in the list.
            $openPrices = [];
            if (!$isSeason) {
                $openPrices = SpaceRate::openNormal($spaceId, $basis);
            }

            $newId = SpaceRate::insert($spaceId, $basis, $label, $price, $validFrom, $validTo);

            foreach ($openPrices as $open) {
                if ($open['validFrom'] < $validFrom) {
                    SpaceRate::closeFor($open['id'], Dates::addDays($validFrom, -1), $newId);
                } else {
                    // Same start day or later: the new price replaces it completely.
                    SpaceRate::switchOff($open['id']);
                }
            }

            // A normal price starting today ends a season running today. A later start waits.
            $today = Clock::today();
            if (!$isSeason && $validFrom === $today) {
                foreach (SpaceRate::seasonsOverlapping($spaceId, $basis, $today, $today) as $season) {
                    if ($season['validFrom'] < $today) {
                        SpaceRate::endOn($season['id'], Dates::addDays($today, -1));
                    } else {
                        SpaceRate::switchOff($season['id']);
                    }
                }
            }

            return SpaceRate::find($newId);
        });
    }

    // Cancels a price that has not started. Reopens the normal price it closed.
    public static function cancel(int $rateId): void
    {
        Db::transaction(function () use ($rateId) {
            // Lock the space first, same order as add(), so two price changes cannot deadlock.
            $rate = SpaceRate::find($rateId);
            if ($rate === null) {
                throw new HttpError(404, 'Price not found');
            }
            Space::lock($rate['spaceId']);
            $rate = SpaceRate::find($rateId, true); // re-read under the lock
            if (!$rate['isActive'] || $rate['validFrom'] <= Clock::today()) {
                throw new HttpError(422, 'Only upcoming prices can be cancelled');
            }

            SpaceRate::switchOff($rateId);
            SpaceRate::reopenClosedBy($rateId);
        });
    }

    // Ends yesterday, so today uses the normal price. A season that started today is switched off.
    public static function endNow(int $rateId): void
    {
        Db::transaction(function () use ($rateId) {
            // Lock the space first, same order as add(), so two price changes cannot deadlock.
            $rate = SpaceRate::find($rateId);
            if ($rate === null) {
                throw new HttpError(404, 'Price not found');
            }
            Space::lock($rate['spaceId']);
            $rate = SpaceRate::find($rateId, true); // re-read under the lock

            // A normal price closed for a change has an end date too, but it isn't a season
            $isSeasonal = $rate['validTo'] !== null && $rate['closedFor'] === null;
            if (!$isSeasonal) {
                throw new HttpError(422, 'Only a seasonal price can be ended now');
            }

            $today = Clock::today();
            $isRunning = $rate['isActive'] && $rate['validFrom'] <= $today && $today <= $rate['validTo'];
            if (!$isRunning) {
                throw new HttpError(422, 'This season is not running today');
            }

            if ($rate['validFrom'] < $today) {
                SpaceRate::endOn($rateId, Dates::addDays($today, -1));
            } else {
                SpaceRate::switchOff($rateId);
            }
        });
    }

    // Overlapping seasons of the same kind. On shared days the later start wins.
    public static function overlaps(int $spaceId, string $basis, string $from, string $to): array
    {
        RateSchedule::checkBasis($basis);
        Dates::check($from, 'Start');
        Dates::check($to, 'End');
        if ($to < $from) {
            throw new HttpError(422, 'The end date must be on or after the start date');
        }

        return SpaceRate::seasonsOverlapping($spaceId, $basis, $from, $to);
    }

    // First problem is a 422. A blank normal-price name becomes "Standard".
    private static function checkNewRate(string $basis, string $label, float $price, string $validFrom, ?string $validTo): string
    {
        $isSeason = $validTo !== null;
        $label = trim($label);

        RateSchedule::checkBasis($basis);
        Dates::check($validFrom, 'Start');
        if ($isSeason) {
            Dates::check($validTo, 'End');
        }
        Money::check($price);
        if ($validFrom < Clock::today()) {
            throw new HttpError(422, 'Choose a start date from today onwards');
        }
        if ($isSeason && $label === '') {
            throw new HttpError(422, 'Give the seasonal price a name');
        }
        if ($isSeason && $validTo < $validFrom) {
            throw new HttpError(422, 'The end date must be on or after the start date');
        }
        if ($label === '') {
            $label = 'Standard';
        }
        if (mb_strlen($label) > 80) {
            throw new HttpError(422, 'The name must be 80 characters or fewer');
        }

        return $label;
    }
}
