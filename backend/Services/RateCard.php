<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Amenity;
use GrandGala\Models\Space;

/*
 * Today's prices staff pick from when building a quotation.
 * Catering and décor are priced per job, so they are not on the card.
 */
class RateCard
{
    // What each kind of price is "per", for the note: 'per night'
    private const UNITS = ['per_night' => 'night', 'per_event' => 'event', 'per_hour' => 'hour', 'per_head' => 'head'];

    // Today's visible-space prices, then switched-on amenities.
    public static function items(): array
    {
        return array_merge(self::spaceItems(), self::amenityItems());
    }

    // One line per kind of price each space has today
    private static function spaceItems(): array
    {
        $spaces = Space::allActive();
        $spaceIds = [];
        foreach ($spaces as $space) {
            $spaceIds[] = $space['id'];
        }
        $ratesBySpace = RateSchedule::currentRates($spaceIds);

        $items = [];
        foreach ($spaces as $space) {
            foreach ($ratesBySpace[$space['id']] as $rate) {
                // The standard price is just "The Villa"; any other is "The Villa — Peak season"
                $description = $space['name'];
                if ($rate['label'] !== 'Standard') {
                    $description = $space['name'] . ' — ' . $rate['label'];
                }

                $items[] = [
                    'kind' => 'space',
                    'spaceId' => $space['id'],
                    'amenityId' => null,
                    'basis' => $rate['basis'],
                    'description' => $description,
                    'note' => 'per ' . self::UNITS[$rate['basis']],
                    'unitPrice' => $rate['price'],
                    'perHead' => $rate['basis'] === 'per_head',
                ];
            }
        }

        return $items;
    }

    // One line per switched-on amenity, at today's price
    private static function amenityItems(): array
    {
        $items = [];
        foreach (Amenity::activeWithPriceOn(Clock::today()) as $amenity) {
            $items[] = [
                'kind' => 'amenity',
                'spaceId' => null,
                'amenityId' => $amenity['id'],
                'basis' => null,
                'description' => $amenity['name'],
                'note' => 'extra',
                'unitPrice' => $amenity['price'],
                'perHead' => false,
            ];
        }

        return $items;
    }
}
