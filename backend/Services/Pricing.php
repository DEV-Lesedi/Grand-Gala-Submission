<?php

declare(strict_types=1);

namespace GrandGala\Services;

use GrandGala\Models\Stay;
use GrandGala\Utils\Dates;
use GrandGala\Utils\HttpError;

/*
 * Each night is priced on its own, so a season applies only on those nights.
 * Amenities are priced on check-in. A stay promo comes off the subtotal.
 */
class Pricing
{
    private const MAX_NIGHTS = 365;
    private const MAX_AMENITY_QUANTITY = 10;

    // Nights, amenities and an optional promo. The request does not set the total.
    public static function stay(array $input, ?int $userId): array
    {
        $spaceId = self::positiveInt($input['spaceId'] ?? null, 'Space');
        $guests = self::positiveInt($input['guests'] ?? null, 'Guest count');
        $checkIn = self::date($input['checkIn'] ?? null, 'Check-in');
        $checkOut = self::date($input['checkOut'] ?? null, 'Check-out');
        if ($checkIn < Clock::today()) {
            throw new HttpError(422, 'Check-in cannot be in the past');
        }
        if ($checkOut <= $checkIn) {
            throw new HttpError(422, 'Check-out must be after check-in');
        }

        $space = Stay::space($spaceId);
        if ($space === null || !$space['isActive']) {
            throw new HttpError(404, 'Space not found');
        }
        if (!$space['isAccommodation']) {
            throw new HttpError(422, 'The selected space is not available for overnight stays');
        }
        if ($guests > $space['capacity']) {
            throw new HttpError(422, 'Guest count exceeds the space capacity');
        }

        // One line per night, from check-in up to the night before check-out
        $lines = [];
        $nights = 0;
        $date = $checkIn;
        while ($date < $checkOut) {
            $nights = $nights + 1;
            if ($nights > self::MAX_NIGHTS) {
                throw new HttpError(422, 'A stay cannot exceed 365 nights');
            }
            $rate = RateSchedule::rateOn($spaceId, 'per_night', $date);
            if ($rate === null) {
                throw new HttpError(422, 'No stay rate is available for ' . $date);
            }
            $price = round((float) $rate['price'], 2);
            $lines[] = [
                'kind' => 'space',
                'description' => $space['name'] . ' - ' . $date,
                'quantity' => 1,
                'unitPrice' => $price,
                'total' => $price,
                'metadata' => ['spaceId' => $spaceId, 'date' => $date, 'rateId' => $rate['id']],
            ];
            $date = Dates::addDays($date, 1);
        }

        foreach (self::amenityLines($input['amenities'] ?? [], $checkIn) as $line) {
            $lines[] = $line;
        }

        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal = $subtotal + $line['total'];
        }
        $subtotal = round($subtotal, 2);

        $promoCode = $input['promoCode'] ?? null;
        if ($promoCode !== null && !is_string($promoCode)) {
            throw new HttpError(422, 'Promotion code must be text');
        }
        $promotion = Promotions::quote($promoCode, 'stay', $subtotal, $userId);
        $discount = round((float) $promotion['discount'], 2);
        $total = round($subtotal - $discount, 2);
        if ($total < 0) {
            $total = 0.0;
        }

        return [
            'spaceId' => $spaceId,
            'spaceName' => $space['name'],
            'checkIn' => $checkIn,
            'checkOut' => $checkOut,
            'guests' => $guests,
            'nights' => $nights,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'promoId' => $promotion['promoId'],
        ];
    }

    // A whole number from 1 up, or a 422 naming $label
    public static function positiveInt(mixed $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            throw new HttpError(422, $label . ' must be a positive integer');
        }

        return (int) $value;
    }

    // One line per amenity asked for, priced on the check-in day
    private static function amenityLines(mixed $amenities, string $checkIn): array
    {
        if (!is_array($amenities)) {
            throw new HttpError(422, 'Amenities must be an array');
        }

        $lines = [];
        $seen = [];
        foreach ($amenities as $amenity) {
            if (!is_array($amenity)) {
                throw new HttpError(422, 'Each amenity must include an id and quantity');
            }
            $amenityId = self::positiveInt($amenity['amenityId'] ?? null, 'Amenity');
            $quantity = self::positiveInt($amenity['quantity'] ?? null, 'Amenity quantity');
            if ($quantity > self::MAX_AMENITY_QUANTITY || in_array($amenityId, $seen, true)) {
                throw new HttpError(422, 'Amenity quantity must be at most 10 and each amenity may appear once');
            }
            $seen[] = $amenityId;

            $name = Stay::amenityName($amenityId);
            if ($name === null) {
                throw new HttpError(422, 'An amenity is unavailable');
            }
            $price = Stay::amenityPrice($amenityId, $checkIn);
            if ($price === null) {
                throw new HttpError(422, 'No rate is available for amenity ' . $name);
            }
            $unitPrice = round($price, 2);
            $lines[] = [
                'kind' => 'amenity',
                'description' => $name,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'total' => round($unitPrice * $quantity, 2),
                'metadata' => ['amenityId' => $amenityId],
            ];
        }

        return $lines;
    }

    private static function date(mixed $value, string $label): string
    {
        if (!is_string($value) || !Dates::isValid($value)) {
            throw new HttpError(422, $label . ' must be a valid date (YYYY-MM-DD)');
        }

        return $value;
    }
}
